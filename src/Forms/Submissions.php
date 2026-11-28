<?php

declare(strict_types=1);

namespace PrimeBuilder\Forms;

use Prime\Database\Connection;
use Prime\Database\Exception\QueryException;
use RuntimeException;
use Throwable;

/** Fixed contact-form submissions, independent of public files and never storing an IP address. */
final class Submissions
{
    public const TABLE = 'prime_builder_submissions';
    public const TOKEN_TABLE = 'prime_builder_form_tokens';
    public const FILE_TABLE = 'prime_builder_form_files';
    public const FILE_GC_TABLE = 'prime_builder_form_file_gc';
    public const NOTIFICATION_TABLE = 'prime_builder_form_notifications';
    public const RETENTION_DAYS = 30;
    public const MAX_RETENTION_DAYS = 3650;
    public const PAGE_SIZE = 100;
    public const EXPORT_MAX_ROWS = 5000;
    public const EXPORT_MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly Connection $db, private readonly ?PrivateFiles $storage = null)
    {
    }

    /**
     * @param array{name: string, email: string, message: string, fields_json?: string, files?: list<array{field_id: string, field_label: string, filename: string, mime: string, bytes: int, content_base64: string}>} $values
     * @param (callable(int): void)|null $enqueue Called inside the same transaction as the submission and token.
     * @return bool False when the same start token has already been used.
     */
    public function create(int $entryId, string $node, array $values, string $tokenHash, int $now, ?callable $enqueue = null, ?string $modelId = null): bool
    {
        $hasFiles = ($values['files'] ?? []) !== [];
        if ($hasFiles && ($this->storage === null || $this->db->transactionLevel() !== 0 || $this->db->pdo()->inTransaction())) {
            // The lock must span the actual database commit, not just a nested
            // savepoint, or another process could collect an uncommitted file.
            throw new RuntimeException('Private file writes require storage and an outermost transaction.');
        }
        $create = function () use ($entryId, $node, $values, $tokenHash, $now, $enqueue, $modelId): bool {
            $written = [];
            try {
                $this->db->transaction(function () use ($entryId, $node, $values, $tokenHash, $now, $enqueue, $modelId, &$written): void {
                    // A used token, the submission, file references and queued jobs
                    // commit together; file contents themselves never enter SQL.
                    $this->db->table(self::TOKEN_TABLE)->insert([
                        'token_hash' => $tokenHash,
                        'expires_at' => gmdate('Y-m-d H:i:s', $now + Tokens::MAX_SECONDS),
                    ]);
                    $submissionId = $this->db->table(self::TABLE)->insertGetId([
                        'entry_id' => $entryId,
                        'node_id' => $node,
                        'model_id' => $modelId,
                        'fields_json' => $values['fields_json'] ?? null,
                        'sender_name' => $values['name'],
                        'sender_email' => $values['email'],
                        'message' => $values['message'],
                        'created_at' => gmdate('Y-m-d H:i:s', $now),
                    ]);
                    foreach ($values['files'] ?? [] as $file) {
                        $bytes = base64_decode($file['content_base64'], true);
                        if (!is_string($bytes) || strlen($bytes) !== $file['bytes'] || $this->storage === null) {
                            throw new RuntimeException('Invalid private file data.');
                        }
                        $saved = $this->storage->write($bytes);
                        $written[] = $saved['key'];
                        $this->db->table(self::FILE_TABLE)->insert([
                            'submission_id' => $submissionId, 'field_id' => $file['field_id'], 'field_label' => $file['field_label'],
                            'filename' => $file['filename'], 'mime' => $file['mime'], 'bytes' => $file['bytes'],
                            'content_base64' => '', 'storage_key' => $saved['key'], 'content_sha256' => $saved['sha256'],
                        ]);
                    }
                    if ($enqueue !== null) {
                        $enqueue($submissionId);
                    }
                });
                return true;
            } catch (Throwable $exception) {
                $this->discardUnreferenced($written);
                // The unique digest also prevents concurrent duplicate submissions.
                if ($exception instanceof QueryException && $this->db->table(self::TOKEN_TABLE)->where('token_hash', '=', $tokenHash)->exists()) {
                    return false;
                }
                throw $exception;
            }
        };
        return $hasFiles ? $this->storage->locked($create) : $create();
    }

    /**
     * @return list<array{id: int, entry_id: int, node_id: string, model_id: ?string, fields_json: ?string, name: string, email: string, message: string, created_at: string}>
     */
    public function latest(): array
    {
        return $this->search('', self::PAGE_SIZE);
    }

    /**
     * Search all historical messages, not just the first page. The same literal
     * search is used for the inbox and export, with a keyset cursor for export.
     *
     * @return list<array{id: int, entry_id: int, node_id: string, model_id: ?string, fields_json: ?string, name: string, email: string, message: string, created_at: string}>
     */
    public function search(string $search, int $limit, int $offset = 0, ?int $beforeId = null): array
    {
        $where = [];
        $bindings = [];
        if ($search !== '') {
            // The HTTP validator rejects controls in search terms. A control
            // escape avoids MySQL 5.7's unicode-collation bug when the literal
            // search contains the escape character itself (for example '!').
            $escape = "\x1f";
            $pattern = '%' . str_replace([$escape, '%', '_'], [$escape . $escape, $escape . '%', $escape . '_'], $search) . '%';
            $where[] = '(sender_name LIKE ? ESCAPE ? OR sender_email LIKE ? ESCAPE ? OR message LIKE ? ESCAPE ? OR node_id LIKE ? ESCAPE ? OR model_id LIKE ? ESCAPE ?)';
            $bindings = [$pattern, $escape, $pattern, $escape, $pattern, $escape, $pattern, $escape, $pattern, $escape];
        }
        if ($beforeId !== null) {
            $where[] = 'id < ?';
            $bindings[] = $beforeId;
        }
        $sql = 'SELECT id, entry_id, node_id, model_id, fields_json, sender_name, sender_email, message, created_at FROM `'
            . $this->db->prefixed(self::TABLE) . '`'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY id DESC LIMIT ' . max(1, min(self::PAGE_SIZE + 1, $limit))
            . ' OFFSET ' . max(0, $offset);
        $rows = $this->db->select($sql, $bindings);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'entry_id' => (int) $row['entry_id'],
            'node_id' => (string) $row['node_id'],
            'model_id' => is_string($row['model_id'] ?? null) ? $row['model_id'] : null,
            'fields_json' => is_string($row['fields_json'] ?? null) ? $row['fields_json'] : null,
            'name' => (string) $row['sender_name'],
            'email' => (string) $row['sender_email'],
            'message' => (string) $row['message'],
            'created_at' => (string) $row['created_at'],
        ], $rows);
    }

    public static function retentionDays(mixed $value): int
    {
        if ((is_int($value) || is_string($value)) && preg_match('/^[0-9]{1,4}$/D', (string) $value) === 1
            && (int) $value >= 1 && (int) $value <= self::MAX_RETENTION_DAYS) {
            return (int) $value;
        }
        return self::RETENTION_DAYS;
    }

    /**
     * Metadata only, in one bounded query for the current inbox page. File contents
     * are read separately only after checking the download permission.
     *
     * @param list<int> $submissionIds
     * @return array<int, list<array{id: int, field_label: string, filename: string, bytes: int}>>
     */
    public function fileMetadata(array $submissionIds): array
    {
        if ($submissionIds === []) {
            return [];
        }
        $rows = $this->db->table(self::FILE_TABLE)
            ->select(['id', 'submission_id', 'field_label', 'filename', 'bytes'])
            ->whereIn('submission_id', array_slice($submissionIds, 0, self::PAGE_SIZE))->get();
        $files = [];
        foreach ($rows as $row) {
            $files[(int) $row['submission_id']][] = [
                'id' => (int) $row['id'], 'field_label' => (string) $row['field_label'],
                'filename' => (string) $row['filename'], 'bytes' => (int) $row['bytes'],
            ];
        }
        return $files;
    }

    /** @return array<string, mixed>|null */
    public function file(int $submissionId, int $fileId): ?array
    {
        return $this->db->table(self::FILE_TABLE)->where('submission_id', '=', $submissionId)->where('id', '=', $fileId)->first();
    }

    /**
     * @param array<string, mixed> $row
     * @return array{filename: string, bytes: int, content: string}|null
     */
    public function fileContents(array $row): ?array
    {
        $key = $row['storage_key'] ?? null;
        if (is_string($key) && $key !== '') {
            if ($this->storage === null || !is_string($row['content_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $row['content_sha256']) !== 1) {
                return null;
            }
            $contents = $this->storage->read($key);
            if ($contents === null || !hash_equals($row['content_sha256'], hash('sha256', $contents))) {
                return null;
            }
            $encoded = base64_encode($contents);
        } else {
            // A failed or pending migration never removes the legacy copy.
            $encoded = $row['content_base64'] ?? null;
        }
        $checked = FileInput::validate(['name' => $row['filename'] ?? null, 'content' => $encoded], UploadLimits::MAX_KB * 1024);
        if ($checked === null || $checked['bytes'] !== (int) ($row['bytes'] ?? 0) || $checked['mime'] !== ($row['mime'] ?? null)) {
            return null;
        }
        $contents = base64_decode($checked['content_base64'], true);
        return is_string($contents) ? ['filename' => $checked['filename'], 'bytes' => $checked['bytes'], 'content' => $contents] : null;
    }

    public function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $nested = $this->db->transactionLevel() !== 0 || $this->db->pdo()->inTransaction();
        $deleted = $this->db->transaction(function () use ($id): bool {
            // The worker takes this same row lock until delivery is complete. A
            // successful deletion cannot be followed by a previously reserved job.
            $this->lock($id);
            $this->queueFileGarbage('WHERE f.`submission_id` = ?', [$id]);
            $this->deleteJobs('WHERE `submission_id` = ?', [$id]);
            $this->db->table(self::NOTIFICATION_TABLE)->where('submission_id', '=', $id)->delete();
            $this->db->table(self::FILE_TABLE)->where('submission_id', '=', $id)->delete();
            return $this->db->table(self::TABLE)->where('id', '=', $id)->delete() > 0;
        });
        if (!$nested) {
            $this->drainAfterCommit();
        }
        return $deleted;
    }

    public function purge(int $now, int $retentionDays = self::RETENTION_DAYS): int
    {
        $days = self::retentionDays($retentionDays);
        $cutoff = gmdate('Y-m-d H:i:s', $now - $days * 86400);
        $nested = $this->db->transactionLevel() !== 0 || $this->db->pdo()->inTransaction();
        $messages = $this->db->transaction(function () use ($cutoff): int {
            // Serialize with a worker already sending one of these submissions.
            $this->db->update('UPDATE `' . $this->db->prefixed(self::TABLE) . '` SET `id` = `id` WHERE `created_at` < ?', [$cutoff]);
            $old = 'SELECT `id` FROM `' . $this->db->prefixed(self::TABLE) . '` WHERE `created_at` < ?';
            $this->queueFileGarbage('WHERE f.`submission_id` IN (' . $old . ')', [$cutoff]);
            $this->deleteJobs('WHERE `submission_id` IN (' . $old . ')', [$cutoff]);
            $this->db->delete('DELETE FROM `' . $this->db->prefixed(self::NOTIFICATION_TABLE) . '` WHERE `submission_id` IN (' . $old . ')', [$cutoff]);
            $this->db->delete('DELETE FROM `' . $this->db->prefixed(self::FILE_TABLE) . '` WHERE `submission_id` IN (' . $old . ')', [$cutoff]);
            return $this->db->table(self::TABLE)->where('created_at', '<', $cutoff)->delete();
        });
        if (!$nested) {
            $this->drainAfterCommit();
        }
        $tokens = $this->db->table(self::TOKEN_TABLE)->where('expires_at', '<=', gmdate('Y-m-d H:i:s', $now))->delete();
        return $messages + $tokens;
    }

    /** Cancel pending and reserved notifications before the CMS drops the tables on uninstall. */
    public function deleteAll(): void
    {
        $nested = $this->db->transactionLevel() !== 0 || $this->db->pdo()->inTransaction();
        $schema = $this->db->schema();
        $hasStorage = $schema->hasTable(self::FILE_GC_TABLE) && $schema->hasColumn(self::FILE_TABLE, 'storage_key');
        $this->db->transaction(function () use ($hasStorage): void {
            $this->db->update('UPDATE `' . $this->db->prefixed(self::TABLE) . '` SET `id` = `id`');
            if ($hasStorage) {
                $this->queueFileGarbage('WHERE 1 = 1', []);
            }
            $this->deleteJobs('', []);
            $this->db->delete('DELETE FROM `' . $this->db->prefixed(self::NOTIFICATION_TABLE) . '`');
            $this->db->delete('DELETE FROM `' . $this->db->prefixed(self::FILE_TABLE) . '`');
            $this->db->delete('DELETE FROM `' . $this->db->prefixed(self::TABLE) . '`');
        });
        if (!$nested && $hasStorage) {
            $this->drainAfterCommit();
        }
    }

    /** Take an exclusive row lock without altering the stored submission. Must be called inside a transaction. */
    public function lock(int $id): void
    {
        $this->db->update('UPDATE `' . $this->db->prefixed(self::TABLE) . '` SET `id` = `id` WHERE `id` = ?', [$id]);
    }

    /** @param list<int|string> $bindings */
    private function queueFileGarbage(string $where, array $bindings): void
    {
        FileMaintenance::queue($this->db, $where, $bindings);
    }

    /** @param list<string> $keys */
    private function discardUnreferenced(array $keys): void
    {
        if ($this->storage !== null && $keys !== []) {
            (new FileMaintenance($this->db, $this->storage))->discardUnreferenced($keys);
        }
    }

    private function drainAfterCommit(): void
    {
        if ($this->storage === null) {
            return;
        }
        try {
            if (!$this->db->table(self::FILE_GC_TABLE)->exists()) {
                return;
            }
            // The periodic job handles the directory sweep; deleting one
            // submission should only process committed queue entries.
            (new FileMaintenance($this->db, $this->storage))->collect(time(), sweep: false);
        } catch (Throwable) {
            // The durable queue remains for the scheduler. Do not imply that a
            // committed database deletion was rolled back by an I/O failure.
            error_log('Prime Builder: private file cleanup is pending.');
        }
    }

    /** @param list<int|string> $bindings */
    private function deleteJobs(string $where, array $bindings): void
    {
        // A DELETE by handler + subquery may lock the jobs handler index first,
        // deadlocking with the CMS worker completing by primary key. Collect
        // bounded pages and delete by unique id, always locking in that order.
        $cursor = 0;
        do {
            $condition = $where === '' ? 'WHERE' : $where . ' AND';
            $rows = $this->db->select('SELECT `job_id` FROM `' . $this->db->prefixed(self::NOTIFICATION_TABLE) . '` '
                . $condition . ' `job_id` > ? ORDER BY `job_id` ASC LIMIT 100', [...$bindings, $cursor]);
            foreach ($rows as $row) {
                $cursor = (int) $row['job_id'];
                $this->db->delete(
                    'DELETE FROM `' . $this->db->prefixed('jobs') . '` WHERE `id` = ? AND `handler` = ?',
                    [$cursor, Notifications::JOB_NAME],
                );
            }
        } while (count($rows) === 100);
    }
}
