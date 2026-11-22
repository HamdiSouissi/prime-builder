<?php

declare(strict_types=1);

namespace PrimeBuilder\Forms;

use Prime\Security\Encrypter;
use Throwable;

/** Short-lived, anonymous form starts; nothing visitor-specific is embedded in cached HTML. */
final class Tokens
{
    public const MIN_SECONDS = 3;
    public const MAX_SECONDS = 1800;

    private function __construct(private readonly string $key)
    {
    }

    public static function fromAppKey(mixed $appKey): ?self
    {
        if (!is_string($appKey)) {
            return null;
        }
        try {
            // Follow the same key requirements as the CMS and do not accept an empty secret.
            new Encrypter($appKey);
            return new self(hash_hkdf('sha256', $appKey, 32, 'prime-builder:contact-form:v1'));
        } catch (Throwable) {
            return null;
        }
    }

    public function issue(int $entryId, string $node, string $ip, int $now): string
    {
        $timestamp = (string) $now;
        $nonce = bin2hex(random_bytes(16));
        return $timestamp . '.' . $nonce . '.' . $this->signature($entryId, $node, $ip, $timestamp, $nonce);
    }

    public function valid(string $token, int $entryId, string $node, string $ip, int $now): bool
    {
        if (preg_match('/^([0-9]{1,12})\.([a-f0-9]{32})\.([a-f0-9]{64})$/D', $token, $parts) !== 1) {
            return false;
        }
        $timestamp = $parts[1];
        $nonce = $parts[2];
        $age = $now - (int) $timestamp;
        if ($age < self::MIN_SECONDS || $age > self::MAX_SECONDS) {
            return false;
        }
        return hash_equals($this->signature($entryId, $node, $ip, $timestamp, $nonce), $parts[3]);
    }

    public function usedHash(string $token): string
    {
        return hash_hmac('sha256', 'used:' . $token, $this->key);
    }

    public function limitKey(string $scope, string $ip, int $bucket): string
    {
        return hash_hmac('sha256', "limit\0" . $scope . "\0" . $ip . "\0" . $bucket, $this->key);
    }

    private function signature(int $entryId, string $node, string $ip, string $timestamp, string $nonce): string
    {
        return hash_hmac('sha256', "start\0" . $entryId . "\0" . $node . "\0" . $ip . "\0" . $timestamp . "\0" . $nonce, $this->key);
    }
}
