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

    public function protect(int $entryId, string $type, string $html, bool $grouped = false): ?string
    {
        if ($entryId < 1 || strlen($html) > 1048576) {
            return null;
        }
        try {
            /** @var array<string, bool|int|string> $data */
            $data = ['entry' => $entryId, 'type' => $type, 'html' => $html];
            if ($grouped) {
                $data['grouped'] = true;
            }
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $this->encrypter->encrypt($json);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{entry: int, type: string, html: string, grouped: bool}|null */
    public function open(string $payload): ?array
    {
        if (strlen($payload) > 1048576) {
            return null;
        }
        try {
            $value = json_decode($this->encrypter->decrypt($payload), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($value) || !is_int($value['entry'] ?? null) || $value['entry'] < 1 || !is_string($value['type'] ?? null) || !is_string($value['html'] ?? null) || strlen($value['html']) > 1048576 || (array_key_exists('grouped', $value) && !is_bool($value['grouped']))) {
                return null;
            }
            return ['entry' => $value['entry'], 'type' => $value['type'], 'html' => $value['html'], 'grouped' => $value['grouped'] ?? false];
        } catch (Throwable) {
            return null;
        }
    }
}
