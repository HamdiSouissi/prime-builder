<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Security\Encrypter;
use Throwable;

/** Authenticated, entry-bound copies of already persisted HTML; never accepts new client HTML. */
final class OriginalContent
{
    public function __construct(private readonly Encrypter $encrypter)
    {
    }

    /** Derive a separate key so ciphertext from another CMS subsystem is never accepted here. */
    public static function fromAppKey(mixed $appKey): ?self
    {
        if (!is_string($appKey)) {
            return null;
        }
        try {
            // First apply the CMS's own APP_KEY validation; never derive from an empty key.
            new Encrypter($appKey);
            $key = hash_hkdf('sha256', $appKey, 32, 'prime-builder:original-content:v1');
            return new self(new Encrypter('base64:' . base64_encode($key)));
        } catch (Throwable) {
            return null;
        }
    }

    public function protect(int $entryId, string $type, string $html): ?string
    {
        if ($entryId < 1 || strlen($html) > 1048576) {
            return null;
        }
        try {
            $json = json_encode(['entry' => $entryId, 'type' => $type, 'html' => $html], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $this->encrypter->encrypt($json);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{entry: int, type: string, html: string}|null */
    public function open(string $payload): ?array
    {
        if (strlen($payload) > 1048576) {
            return null;
        }
        try {
            $value = json_decode($this->encrypter->decrypt($payload), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($value) || !is_int($value['entry'] ?? null) || $value['entry'] < 1 || !is_string($value['type'] ?? null) || !is_string($value['html'] ?? null) || strlen($value['html']) > 1048576) {
                return null;
            }
            return ['entry' => $value['entry'], 'type' => $value['type'], 'html' => $value['html']];
        } catch (Throwable) {
            return null;
        }
    }
}
