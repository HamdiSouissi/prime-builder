<?php

declare(strict_types=1);

namespace PrimeBuilder\Forms;

use finfo;

/** Bounded, type-checked attachment data; client-supplied MIME types are never trusted. */
final class FileInput
{
    public const MAX_FILES = 4;
    public const MAX_BYTES = 512 * 1024;
    // JSON/base64 is used so the existing public submit endpoint remains compatible.
    public const MAX_BODY_BYTES = 750000;
    public const ACCEPT = '.pdf,.png,.jpg,.jpeg,.txt';

    /** @return array{filename: string, mime: string, bytes: int, content_base64: string}|null */
    public static function validate(mixed $input): ?array
    {
        if (!is_array($input) || count($input) !== 2 || array_diff(array_keys($input), ['name', 'content']) !== []
            || !is_string($input['name'] ?? null) || !is_string($input['content'] ?? null)) {
            return null;
        }
        $name = trim($input['name']);
        $encoded = $input['content'];
        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > 160 || strlen($name) > 640
            || preg_match('/[\\x00-\\x1f\\x7f\\/\\\\]/', $name) === 1
            || preg_match('/^.+\\.(pdf|png|jpe?g|txt)$/iuD', $name, $parts) !== 1
            || strlen($encoded) > (int) (4 * ceil(self::MAX_BYTES / 3))) {
            return null;
        }
        $bytes = base64_decode($encoded, true);
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_BYTES || !hash_equals(base64_encode($bytes), $encoded)
            || !extension_loaded('fileinfo')) {
            return null;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $expected = match (strtolower($parts[1])) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'txt' => 'text/plain',
            default => null,
        };
        if ($expected === null || $mime !== $expected || ($expected === 'text/plain' && (!mb_check_encoding($bytes, 'UTF-8')
            || preg_match('/[\\x00-\\x08\\x0b\\x0c\\x0e-\\x1f\\x7f]/', $bytes) === 1))) {
            return null;
        }
        return ['filename' => $name, 'mime' => $expected, 'bytes' => strlen($bytes), 'content_base64' => $encoded];
    }
}
