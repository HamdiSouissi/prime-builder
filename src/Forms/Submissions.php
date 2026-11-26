<?php

declare(strict_types=1);

namespace PrimeBuilder\Forms;

use Prime\Database\Connection;
use Prime\Database\Exception\QueryException;

/** Fixed contact-form submissions, independent of public files and never storing an IP address. */
final class Submissions
{
    public const TABLE = 'prime_builder_submissions';
    public const TOKEN_TABLE = 'prime_builder_form_tokens';
    public const FILE_TABLE = 'prime_builder_form_files';
    public const RETENTION_DAYS = 30;
    public const MAX_RETENTION_DAYS = 3650;
    public const PAGE_SIZE = 100;
    public const EXPORT_MAX_ROWS = 5000;
    public const EXPORT_MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array{name: string, email: string, message: string, files?: list<array{field_id: string, field_label: string, filename: string, mime: string, bytes: int, content_base64: string}>} $values
     * @return bool False when the same start token has already been used.
     */
    public function create(int $entryId, string $node, array $values, string $tokenHash, int $now): bool
    {
        try {
            $this->db->transaction(function () use ($entryId, $node, $values, $tokenHash, $now): void {
                // Keep a short-lived digest separately, so deleting a message does not
                // make its still-valid token reusable. Both inserts succeed or neither does.
                $this->db->table(self::TOKEN_TABLE)->insert([
                    'token_hash' => $tokenHash,
                    'expires_at' => gmdate('Y-m-d H:i:s', $now + Tokens::MAX_SECONDS),
                ]);
                $submissionId = $this->db->table(self::TABLE)->insertGetId([
                    'entry_id' => $entryId,
                    'node_id' => $node,
                    'sender_name' => $values['name'],
                    'sender_email' => $values['email'],
                    'message' => $values['message'],
                    'created_at' => gmdate('Y-m-d H:i:s', $now),
                ]);
                foreach ($values['files'] ?? [] as $file) {
                    $this->db->table(self::FILE_TABLE)->insert(['submission_id' => $submissionId] + $file);
                }
            });
            return true;
        } catch (QueryException $exception) {
            // The unique digest also prevents concurrent duplicate submissions.
            if ($this->db->table(self::TOKEN_TABLE)->where('token_hash', '=', $tokenHash)->exists()) {
                return false;
            }
            throw $exception;
        }
    }

    /**
     * @return list<array{id: int, entry_id: int, node_id: string, name: string, email: string, message: string, created_at: string}>
     */
    public function latest(): array
    {
        return $this->search('', self::PAGE_SIZE);
    }

    /**
     * Search all historical messages, not just the first page. The same literal
     * search is used for the inbox and export, with a keyset cursor for export.
     *
     * @return list<array{id: int, entry_id: int, node_id: string, name: string, email: string, message: string, created_at: string}>
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
            $where[] = '(sender_name LIKE ? ESCAPE ? OR sender_email LIKE ? ESCAPE ? OR message LIKE ? ESCAPE ? OR node_id LIKE ? ESCAPE ?)';
            $bindings = [$pattern, $escape, $pattern, $escape, $pattern, $escape, $pattern, $escape];
        }
        if ($beforeId !== null) {
            $where[] = 'id < ?';
            $bindings[] = $beforeId;
        }
        $sql = 'SELECT id, entry_id, node_id, sender_name, sender_email, message, created_at FROM `'
            . $this->db->prefixed(self::TABLE) . '`'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY id DESC LIMIT ' . max(1, min(self::PAGE_SIZE + 1, $limit))
            . ' OFFSET ' . max(0, $offset);
        $rows = $this->db->select($sql, $bindings);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'entry_id' => (int) $row['entry_id'],
            'node_id' => (string) $row['node_id'],
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

    public function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        return $this->db->transaction(function () use ($id): bool {
            $this->db->table(self::FILE_TABLE)->where('submission_id', '=', $id)->delete();
            return $this->db->table(self::TABLE)->where('id', '=', $id)->delete() > 0;
        });
    }

    public function purge(int $now, int $retentionDays = self::RETENTION_DAYS): int
    {
        $days = self::retentionDays($retentionDays);
        $cutoff = gmdate('Y-m-d H:i:s', $now - $days * 86400);
        $messages = $this->db->transaction(function () use ($cutoff): int {
            $this->db->delete('DELETE FROM `' . $this->db->prefixed(self::FILE_TABLE) . '` WHERE submission_id IN '
                . '(SELECT id FROM `' . $this->db->prefixed(self::TABLE) . '` WHERE created_at < ?)', [$cutoff]);
            return $this->db->table(self::TABLE)->where('created_at', '<', $cutoff)->delete();
        });
        $tokens = $this->db->table(self::TOKEN_TABLE)->where('expires_at', '<=', gmdate('Y-m-d H:i:s', $now))->delete();
        return $messages + $tokens;
    }
}
