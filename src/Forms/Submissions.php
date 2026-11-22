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
    public const RETENTION_DAYS = 30;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array{name: string, email: string, message: string} $values
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
                $this->db->table(self::TABLE)->insert([
                    'entry_id' => $entryId,
                    'node_id' => $node,
                    'sender_name' => $values['name'],
                    'sender_email' => $values['email'],
                    'message' => $values['message'],
                    'created_at' => gmdate('Y-m-d H:i:s', $now),
                ]);
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
        $rows = $this->db->table(self::TABLE)->orderBy('id', 'DESC')->limit(100)->get();
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

    public function delete(int $id): bool
    {
        return $id > 0 && $this->db->table(self::TABLE)->where('id', '=', $id)->delete() > 0;
    }

    public function purge(int $now): int
    {
        $messages = $this->db->table(self::TABLE)
            ->where('created_at', '<', gmdate('Y-m-d H:i:s', $now - self::RETENTION_DAYS * 86400))
            ->delete();
        $tokens = $this->db->table(self::TOKEN_TABLE)->where('expires_at', '<=', gmdate('Y-m-d H:i:s', $now))->delete();
        return $messages + $tokens;
    }
}
