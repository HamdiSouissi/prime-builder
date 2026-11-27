<?php

declare(strict_types=1);

namespace PrimeBuilder\Forms;

use Prime\Database\Connection;
use RuntimeException;
use Throwable;

/** Retryable migration and collection of private form files. */
final class FileMaintenance
{
    private const ORPHAN_GRACE_SECONDS = 3600;

    public function __construct(private readonly Connection $db, private readonly PrivateFiles $storage)
    {
    }

    /**
     * Record deletion intent in the same transaction that removes the rows. If
     * unlink fails or the process dies, the scheduler can retry after commit.
     *
     * @param list<int|string> $bindings
     */
    public static function queue(Connection $db, string $where, array $bindings): void
    {
        $files = '`' . $db->prefixed(Submissions::FILE_TABLE) . '`';
        $garbage = '`' . $db->prefixed(Submissions::FILE_GC_TABLE) . '`';
        $db->statement('INSERT INTO ' . $garbage . ' (`storage_key`) SELECT DISTINCT f.`storage_key` FROM ' . $files . ' f '
            . $where . ' AND f.`storage_key` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ' . $garbage . ' g WHERE g.`storage_key` = f.`storage_key`)', $bindings);
    }

    /**
     * Only delete a just-written file when the database confirms it is unreferenced.
     *
     * @param list<string> $keys
     */
    public function discardUnreferenced(array $keys): void
    {
        foreach ($keys as $key) {
            try {
                if (!$this->db->table(Submissions::FILE_TABLE)->where('storage_key', '=', $key)->exists()) {
                    $this->storage->remove($key);
                }
            } catch (Throwable) {
                // Commit state may be ambiguous or storage may be unavailable.
                // Leave the file for a later, locked orphan sweep.
            }
        }
    }

    /** Migrate a bounded batch, preserving every legacy value if any step fails. */
    public function migrate(int $limit = 50): int
    {
        if ($this->db->transactionLevel() !== 0 || $this->db->pdo()->inTransaction()) {
            throw new RuntimeException('File migration requires an outermost transaction.');
        }
        $limit = max(1, min(100, $limit));
        $pending = $this->db->select('SELECT `id` FROM `' . $this->db->prefixed(Submissions::FILE_TABLE)
            . '` WHERE `storage_key` IS NULL AND `migration_error` IS NULL LIMIT 1');
        if ($pending === []) {
            return 0;
        }
        return $this->storage->locked(function () use ($limit): int {
            $rows = $this->db->select('SELECT `id`, `submission_id` FROM `' . $this->db->prefixed(Submissions::FILE_TABLE)
                . '` WHERE `storage_key` IS NULL AND `migration_error` IS NULL ORDER BY `id` ASC LIMIT ' . $limit);
            $migrated = 0;
            foreach ($rows as $candidate) {
                $written = [];
                try {
                    $moved = $this->db->transaction(function () use ($candidate, &$written): bool {
                        $id = (int) $candidate['id'];
                        $submissionId = (int) $candidate['submission_id'];
                        // Take the same lock and in the same order as deletion/purge.
                        (new Submissions($this->db))->lock($submissionId);
                        $row = $this->db->table(Submissions::FILE_TABLE)->where('id', '=', $id)->where('submission_id', '=', $submissionId)->first();
                        if ($row === null || ($row['storage_key'] ?? null) !== null || ($row['migration_error'] ?? null) !== null) {
                            return false;
                        }
                        $submissionExists = $this->db->table(Submissions::TABLE)->where('id', '=', $submissionId)->exists();
                        $checked = FileInput::validate(['name' => $row['filename'] ?? null, 'content' => $row['content_base64'] ?? null], UploadLimits::MAX_KB * 1024);
                        if (!$submissionExists || $checked === null || $checked['bytes'] !== (int) ($row['bytes'] ?? 0) || $checked['mime'] !== ($row['mime'] ?? null)) {
                            // Invalid legacy data is retained for manual recovery and
                            // does not prevent later valid files from being migrated.
                            $this->db->table(Submissions::FILE_TABLE)->where('id', '=', $id)->update(['migration_error' => 'invalid']);
                            return false;
                        }
                        $bytes = base64_decode($checked['content_base64'], true);
                        if (!is_string($bytes)) {
                            throw new RuntimeException('Cannot decode a validated legacy file.');
                        }
                        $saved = $this->storage->write($bytes);
                        $written[] = $saved['key'];
                        $this->db->table(Submissions::FILE_TABLE)->where('id', '=', $id)->update([
                            'storage_key' => $saved['key'], 'content_sha256' => $saved['sha256'], 'content_base64' => '',
                        ]);
                        return true;
                    });
                    $migrated += $moved ? 1 : 0;
                } catch (Throwable $exception) {
                    $this->discardUnreferenced($written);
                    throw $exception;
                }
            }
            return $migrated;
        });
    }

    /** Retry committed removals and, when scheduled, sweep crash leftovers in bounded passes. */
    public function collect(int $now, int $limit = 100, bool $sweep = true): int
    {
        if ($this->db->transactionLevel() !== 0 || $this->db->pdo()->inTransaction()) {
            throw new RuntimeException('File collection requires an outermost transaction.');
        }
        $limit = max(1, min(500, $limit));
        if (!$this->storage->exists() && !$this->db->table(Submissions::FILE_GC_TABLE)->exists()) {
            return 0;
        }
        return $this->storage->locked(function () use ($now, $limit, $sweep): int {
            $files = '`' . $this->db->prefixed(Submissions::FILE_TABLE) . '`';
            $garbage = '`' . $this->db->prefixed(Submissions::FILE_GC_TABLE) . '`';
            $base = 'SELECT g.`storage_key` FROM ' . $garbage . ' g WHERE NOT EXISTS '
                . '(SELECT 1 FROM ' . $files . ' f WHERE f.`storage_key` = g.`storage_key`)';
            // Reserve part of scheduled work for orphan recovery even when a
            // large queue contains entries that cannot currently be removed.
            $queueLimit = $sweep && $limit > 1 ? intdiv($limit, 2) : $limit;
            $cursor = $this->storage->queueCursor();
            $rows = $this->db->select($base . ($cursor !== '' ? ' AND g.`storage_key` > ?' : '')
                . ' ORDER BY g.`storage_key` ASC LIMIT ' . $queueLimit, $cursor !== '' ? [$cursor] : []);
            if ($cursor !== '' && count($rows) < $queueLimit) {
                // Fill the remaining budget from the start, without selecting
                // a row twice in the same pass.
                $rows = [...$rows, ...$this->db->select($base . ' AND g.`storage_key` <= ? ORDER BY g.`storage_key` ASC LIMIT '
                    . ($queueLimit - count($rows)), [$cursor])];
            }
            $removed = 0;
            foreach ($rows as $row) {
                $key = (string) $row['storage_key'];
                if (!PrivateFiles::validKey($key) || $this->storage->remove($key)) {
                    $this->db->table(Submissions::FILE_GC_TABLE)->where('storage_key', '=', $key)->delete();
                    $removed++;
                }
            }
            if ($rows !== []) {
                // Advance even over an unlink failure so one blocked key cannot
                // indefinitely prevent later committed deletions from being tried.
                $this->storage->setQueueCursor((string) $rows[array_key_last($rows)]['storage_key']);
            }
            $remaining = $limit - count($rows);
            if ($sweep && $remaining > 0) {
                foreach ($this->storage->oldKeys($now - self::ORPHAN_GRACE_SECONDS, $remaining) as $key) {
                    if (!$this->db->table(Submissions::FILE_TABLE)->where('storage_key', '=', $key)->exists()
                        && $this->storage->remove($key)) {
                        $this->db->table(Submissions::FILE_GC_TABLE)->where('storage_key', '=', $key)->delete();
                        $removed++;
                    }
                }
            }
            return $removed;
        });
    }
}
