<?php

declare(strict_types=1);

namespace PrimeBuilder\Forms;

use DirectoryIterator;
use OutOfBoundsException;
use Prime\Kernel\Application;
use RuntimeException;
use Throwable;

/** Private files are addressed only by random opaque keys, never by visitor filenames. */
final class PrivateFiles
{
    private bool $locked = false;
    private readonly string $syncRoot;

    /** @param list<string> $forbiddenRoots */
    public function __construct(private readonly string $directory, private readonly array $forbiddenRoots = [], ?string $syncRoot = null)
    {
        // The pre-existing root is managed by the CMS/operator, not this class.
        $this->syncRoot = $syncRoot ?? dirname($directory);
    }

    public static function forApp(Application $app): self
    {
        return new self($app->storagePath('prime-builder/form-files'), [$app->publicPath(), $app->contentPath('uploads')], $app->storagePath());
    }

    /**
     * The same shared lock protects writes until their database commit and
     * orphan collection. A shared filesystem must support flock across nodes.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function locked(callable $callback): mixed
    {
        if ($this->locked) {
            return $callback();
        }
        $this->directory(true);
        $path = $this->directory . '/.lock';
        if (is_link($path)) {
            throw new RuntimeException('Private upload lock is a symlink.');
        }
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Cannot open the private upload lock.');
        }
        try {
            if (!@chmod($path, 0600) || !flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot lock private uploads.');
            }
            $this->locked = true;
            try {
                return $callback();
            } finally {
                $this->locked = false;
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return array{key: string, sha256: string} */
    public function write(string $bytes): array
    {
        $this->requireLock();
        if ($bytes === '' || strlen($bytes) > UploadLimits::MAX_KB * 1024) {
            throw new RuntimeException('Invalid private upload length.');
        }
        $key = bin2hex(random_bytes(32));
        $path = $this->path($key);
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('Cannot create a private upload.');
        }
        try {
            if (!@chmod($path, 0600)) {
                throw new RuntimeException('Cannot restrict private upload permissions.');
            }
            $offset = 0;
            $size = strlen($bytes);
            while ($offset < $size) {
                $written = fwrite($handle, substr($bytes, $offset));
                if (!is_int($written) || $written <= 0) {
                    throw new RuntimeException('Cannot write a private upload.');
                }
                $offset += $written;
            }
            if (!fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('Cannot synchronize a private upload.');
            }
            fclose($handle);
            $handle = null;
            $sha = hash('sha256', $bytes);
            $stored = $this->read($key);
            if ($stored === null || !hash_equals($sha, hash('sha256', $stored))) {
                throw new RuntimeException('Private upload verification failed.');
            }
            // fsync(file) alone does not guarantee that the new directory entry
            // survives a power failure. Never commit a database reference, or
            // erase legacy bytes, until the directory has been synced as well.
            $this->syncTree();
            return ['key' => $key, 'sha256' => $sha];
        } catch (Throwable $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($path);
            throw $exception;
        }
    }

    /** Return null for a missing, invalid, linked or oversized file. */
    public function read(string $key): ?string
    {
        if (!self::validKey($key) || !$this->directory(false)) {
            return null;
        }
        $path = $this->path($key);
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        clearstatcache(true, $path);
        $size = @filesize($path);
        if (!is_int($size) || $size < 1 || $size > UploadLimits::MAX_KB * 1024) {
            return null;
        }
        $bytes = @file_get_contents($path, false, null, 0, UploadLimits::MAX_KB * 1024 + 1);
        return is_string($bytes) && strlen($bytes) === $size ? $bytes : null;
    }

    /** Missing files count as removed; symlinks are unlinked, never followed. */
    public function remove(string $key): bool
    {
        $this->requireLock();
        if (!self::validKey($key)) {
            return false;
        }
        $path = $this->path($key);
        if ((file_exists($path) || is_link($path)) && (!(is_file($path) || is_link($path)) || !@unlink($path))) {
            return false;
        }
        // Even a missing file may be the result of a previous unlink whose
        // directory sync failed; retain the queue until that sync succeeds.
        $this->syncDirectory($this->directory);
        return true;
    }

    /**
     * Inspect at most $limit directory entries per pass. The cursor wraps; if
     * concurrent external directory changes shift offsets, a later pass retries.
     *
     * @return list<string>
     */
    public function oldKeys(int $before, int $limit): array
    {
        $this->requireLock();
        if ($limit < 1) {
            return [];
        }
        $cursor = $this->cursor('sweep');
        $position = ctype_digit($cursor) && strlen($cursor) <= 9 ? (int) $cursor : 0;
        $entries = new DirectoryIterator($this->directory);
        try {
            $entries->seek($position);
        } catch (OutOfBoundsException) {
            $entries->rewind();
        }
        $keys = [];
        for ($visited = 0; $entries->valid() && $visited < $limit; $visited++, $entries->next()) {
            $key = $entries->getFilename();
            if (self::validKey($key) && !$entries->isLink() && $entries->isFile() && $entries->getMTime() < $before) {
                $keys[] = $key;
            }
        }
        $this->saveCursor('sweep', $entries->valid() ? (string) $entries->key() : '0');
        return $keys;
    }

    /** Last attempted garbage key, for fair rotation past blocked removals. */
    public function queueCursor(): string
    {
        $cursor = $this->cursor('queue');
        return self::validKey($cursor) ? $cursor : '';
    }

    public function setQueueCursor(string $key): void
    {
        $this->saveCursor('queue', self::validKey($key) ? $key : '');
    }

    /** Remove the dedicated directory at uninstall, with traffic and workers stopped. */
    public function clear(): void
    {
        if (!is_dir($this->directory) && !is_link($this->directory)) {
            return;
        }
        $this->locked(function (): void {
            foreach (new DirectoryIterator($this->directory) as $entry) {
                if ($entry->isDot() || $entry->getFilename() === '.lock') {
                    continue;
                }
                if (($entry->isFile() || $entry->isLink()) && @unlink($entry->getPathname())) {
                    continue;
                }
                throw new RuntimeException('Cannot remove an item in private upload storage.');
            }
        });
        if (!@unlink($this->directory . '/.lock') || !@rmdir($this->directory)) {
            throw new RuntimeException('Cannot remove private upload storage.');
        }
        $this->syncDirectory(dirname($this->directory));
    }

    public function exists(): bool
    {
        return is_dir($this->directory) || is_link($this->directory);
    }

    public static function validKey(string $key): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $key) === 1;
    }

    private function path(string $key): string
    {
        if (!self::validKey($key)) {
            throw new RuntimeException('Invalid private upload key.');
        }
        return $this->directory . '/' . $key;
    }

    /** Ensure private storage cannot resolve into the public or media roots. */
    private function directory(bool $create): bool
    {
        if (is_link($this->directory)) {
            throw new RuntimeException('Private upload directory is a symlink.');
        }
        if (!is_dir($this->directory)) {
            if (!$create) {
                return false;
            }
            if (!is_dir($this->syncRoot)) {
                throw new RuntimeException('The private upload storage root must already exist.');
            }
            if (!@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
                throw new RuntimeException('Cannot create private upload storage.');
            }
        }
        $resolved = realpath($this->directory);
        if (!is_string($resolved)) {
            throw new RuntimeException('Cannot resolve private upload storage.');
        }
        foreach ($this->forbiddenRoots as $root) {
            $forbidden = realpath($root);
            if (is_string($forbidden) && ($resolved === $forbidden || str_starts_with($resolved, rtrim($forbidden, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('Private uploads must be outside public and media storage.');
            }
        }
        if ($create && !@chmod($this->directory, 0700)) {
            throw new RuntimeException('Cannot restrict private upload directory permissions.');
        }
        clearstatcache(true, $this->directory);
        $permissions = @fileperms($this->directory);
        if (!is_int($permissions) || ($permissions & 0077) !== 0) {
            throw new RuntimeException('Private upload directory permissions are too broad.');
        }
        return true;
    }

    /** Cursors are only performance hints; corruption or a crash may repeat work. */
    private function cursor(string $name): string
    {
        $this->requireLock();
        $path = $this->directory . '/.' . $name . '-cursor';
        if (is_link($path)) {
            throw new RuntimeException('Private upload cursor is a symlink.');
        }
        if (!file_exists($path)) {
            return '';
        }
        $value = @file_get_contents($path, false, null, 0, 128);
        if (!is_string($value)) {
            throw new RuntimeException('Cannot read private upload cursor.');
        }
        return trim($value);
    }

    private function saveCursor(string $name, string $value): void
    {
        $this->requireLock();
        $path = $this->directory . '/.' . $name . '-cursor';
        if (is_link($path)) {
            throw new RuntimeException('Private upload cursor is a symlink.');
        }
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Cannot open private upload cursor.');
        }
        try {
            if (!@chmod($path, 0600) || !ftruncate($handle, 0)
                || fwrite($handle, $value) !== strlen($value) || !fflush($handle)) {
                throw new RuntimeException('Cannot write private upload cursor.');
            }
        } finally {
            fclose($handle);
        }
    }

    /** Synchronize each newly created directory entry down to the existing storage root. */
    private function syncTree(): void
    {
        $path = realpath($this->directory);
        $root = realpath($this->syncRoot);
        if (!is_string($path) || !is_string($root)
            || ($path !== $root && !str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Invalid private upload synchronization root.');
        }
        do {
            $this->syncDirectory($path);
            if ($path === $root) {
                break;
            }
            $path = dirname($path);
        } while (true);
    }

    /** Fail closed on filesystems that cannot durably synchronize directories. */
    private function syncDirectory(string $directory): void
    {
        $handle = @fopen($directory, 'r');
        if ($handle === false) {
            throw new RuntimeException('Cannot open a private upload directory for synchronization.');
        }
        try {
            if (!@fsync($handle)) {
                throw new RuntimeException('Cannot synchronize a private upload directory.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function requireLock(): void
    {
        if (!$this->locked) {
            throw new RuntimeException('Private upload storage must be locked.');
        }
    }
}
