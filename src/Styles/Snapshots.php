<?php

declare(strict_types=1);

namespace PrimeBuilder\Styles;

use Prime\Plugins\AssetPublisher;
use Throwable;

/** Private, content-addressed CSS snapshots for previously advertised public URLs. */
final class Snapshots
{
    private const MAX_BYTES = 2097152;
    private const RETENTION_SECONDS = 366 * 86400;

    public function __construct(private readonly string $directory)
    {
    }

    /** Save only data matching the fingerprint in the filename; fail closed on I/O errors. */
    public function remember(string $name, string $css): bool
    {
        if (!$this->matches($name, $css) || strlen($css) > self::MAX_BYTES || is_link($this->directory)) {
            return false;
        }
        if (is_file($this->directory . '/' . $name)) {
            return $this->read($name) === $css;
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            return false;
        }
        try {
            $temporary = $this->directory . '/.write-' . bin2hex(random_bytes(12));
            if (@file_put_contents($temporary, $css, LOCK_EX) !== strlen($css)) {
                @unlink($temporary);
                return false;
            }
            if (!@rename($temporary, $this->directory . '/' . $name)) {
                @unlink($temporary);
                return $this->read($name) === $css;
            }
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function read(string $name): ?string
    {
        if (!$this->validName($name) || is_link($this->directory)) {
            return null;
        }
        $path = $this->directory . '/' . $name;
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        $size = @filesize($path);
        if (!is_int($size) || $size > self::MAX_BYTES) {
            return null;
        }
        $css = @file_get_contents($path);
        if (!is_string($css) || !$this->matches($name, $css)) {
            return null;
        }
        // Keep snapshots in use by cached pages for another full browser-cache lifetime.
        @touch($path);
        return $css;
    }

    /** Remove only unused, recognized snapshots after the public max-age. */
    public function prune(): int
    {
        if (is_link($this->directory) || !is_dir($this->directory)) {
            return 0;
        }
        $removed = 0;
        foreach (glob($this->directory . '/*.css') ?: [] as $path) {
            if (!$this->validName(basename($path)) || is_link($path)) {
                continue;
            }
            clearstatcache(true, $path);
            $modified = @filemtime($path);
            if (is_int($modified) && $modified < time() - self::RETENTION_SECONDS && @unlink($path)) {
                $removed++;
            }
        }
        return $removed;
    }

    public function clear(): void
    {
        if (is_link($this->directory)) {
            @unlink($this->directory);
        } elseif (is_dir($this->directory)) {
            AssetPublisher::deleteDirectory($this->directory);
        }
    }

    private function validName(string $name): bool
    {
        return preg_match('/^(?:kit|[1-9][0-9]*)-[a-f0-9]{16}\.css$/D', $name) === 1;
    }

    private function matches(string $name, string $css): bool
    {
        return $this->validName($name) && hash_equals(substr(hash('sha256', $css), 0, 16), substr($name, -20, 16));
    }
}
