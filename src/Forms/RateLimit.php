<?php

declare(strict_types=1);

namespace PrimeBuilder\Forms;

use Prime\Database\Connection;
use Prime\Database\Exception\QueryException;
use Prime\Database\Identifier;

/** Atomic, fixed-window per-IP counters, kept in a plugin-owned table. */
final class RateLimit
{
    public const TABLE = 'prime_builder_form_limits';

    public function __construct(private readonly Connection $db, private readonly Tokens $tokens)
    {
    }

    public function allow(string $scope, string $ip, int $maximum, int $seconds, int $now): bool
    {
        $bucket = intdiv($now, $seconds);
        $key = $this->tokens->limitKey($scope, $ip, $bucket);
        $table = Identifier::quoteSimple($this->db->prefixed(self::TABLE));
        $sql = 'UPDATE ' . $table . ' SET `hits` = `hits` + 1 WHERE `key_hash` = ? AND `hits` < ?';
        if ($this->db->update($sql, [$key, $maximum]) === 1) {
            return true;
        }
        if ($this->db->table(self::TABLE)->where('key_hash', '=', $key)->exists()) {
            return false;
        }
        try {
            $this->db->table(self::TABLE)->insert([
                'key_hash' => $key,
                'hits' => 1,
                'expires_at' => gmdate('Y-m-d H:i:s', ($bucket + 1) * $seconds + 60),
            ]);
            return true;
        } catch (QueryException $exception) {
            // Another request may have created the bucket after our first UPDATE.
            return $this->retryAfterInsertRace($sql, $key, $maximum, $exception);
        }
    }

    private function retryAfterInsertRace(string $sql, string $key, int $maximum, QueryException $exception): bool
    {
        if ($this->db->update($sql, [$key, $maximum]) === 1) {
            return true;
        }
        if ($this->db->table(self::TABLE)->where('key_hash', '=', $key)->exists()) {
            return false;
        }
        throw $exception;
    }

    public static function purge(Connection $db, int $now): int
    {
        return $db->table(self::TABLE)->where('expires_at', '<=', gmdate('Y-m-d H:i:s', $now))->delete();
    }
}
