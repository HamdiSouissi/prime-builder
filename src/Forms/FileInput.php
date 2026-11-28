<?php

declare(strict_types=1);

namespace PrimeBuilder\Forms;

use finfo;
use ZipArchive;

/** Bounded, type-checked attachment data; client-supplied MIME types are never trusted. */
final class FileInput
{
    public const MAX_FILES = 4;
    public const MAX_BYTES = 512 * 1024;
    public const MAX_BODY_BYTES = 750000;
    public const ACCEPT = '.pdf,.png,.jpg,.jpeg,.txt,.docx';
    public const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    /** @return array{filename: string, mime: string, bytes: int, content_base64: string}|null */
    public static function validate(mixed $input, int $maxBytes = self::MAX_BYTES): ?array
    {
        if (!is_array($input) || count($input) !== 2 || array_diff(array_keys($input), ['name', 'content']) !== []
            || !is_string($input['name'] ?? null) || !is_string($input['content'] ?? null)) {
            return null;
        }
        $maxBytes = max(1, min(UploadLimits::MAX_KB * 1024, $maxBytes));
        $name = trim($input['name']);
        $encoded = $input['content'];
        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > 160 || strlen($name) > 640
            || preg_match('/[\\x00-\\x1f\\x7f\\/\\\\]/', $name) === 1
            || preg_match('/^.+\\.(pdf|png|jpe?g|txt|docx)$/iuD', $name, $parts) !== 1
            || strlen($encoded) > (int) (4 * ceil($maxBytes / 3))) {
            return null;
        }
        $bytes = base64_decode($encoded, true);
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > $maxBytes || !hash_equals(base64_encode($bytes), $encoded)
            || !extension_loaded('fileinfo')) {
            return null;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $expected = match (strtolower($parts[1])) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'txt' => 'text/plain',
            'docx' => self::DOCX_MIME,
            default => null,
        };
        if ($expected === self::DOCX_MIME) {
            // libmagic versions disagree between application/zip and the OOXML MIME.
            if (!in_array($mime, ['application/zip', self::DOCX_MIME], true) || !self::isDocx($bytes)) {
                return null;
            }
        } elseif ($expected === null || $mime !== $expected || ($expected === 'text/plain' && (!mb_check_encoding($bytes, 'UTF-8')
            || preg_match('/[\\x00-\\x08\\x0b\\x0c\\x0e-\\x1f\\x7f]/', $bytes) === 1))) {
            return null;
        }
        return ['filename' => $name, 'mime' => $expected, 'bytes' => strlen($bytes), 'content_base64' => $encoded];
    }

    /**
     * Convert a genuine PHP upload to the legacy validated representation. Never
     * trust its MIME, client-reported size, file path, or upload status.
     *
     * @return array{name: string, content: string}|null
     */
    public static function uploaded(mixed $upload, int $maxBytes): ?array
    {
        if (!is_array($upload) || !is_string($upload['name'] ?? null) || !is_string($upload['tmp_name'] ?? null)
            || !is_int($upload['error'] ?? null) || $upload['error'] !== UPLOAD_ERR_OK
            || !is_int($upload['size'] ?? null) || $upload['size'] <= 0 || $upload['size'] > $maxBytes
            || !is_uploaded_file($upload['tmp_name'])) {
            return null;
        }
        $contents = @file_get_contents($upload['tmp_name'], false, null, 0, $maxBytes + 1);
        if (!is_string($contents) || strlen($contents) !== $upload['size'] || strlen($contents) > $maxBytes) {
            return null;
        }
        return ['name' => $upload['name'], 'content' => base64_encode($contents)];
    }

    /** Check the OOXML package without extracting it or trusting archive metadata unboundedly. */
    private static function isDocx(string $bytes): bool
    {
        if (!class_exists(ZipArchive::class)) {
            return false;
        }
        $temporary = tmpfile();
        if ($temporary === false) {
            return false;
        }
        try {
            if (fwrite($temporary, $bytes) !== strlen($bytes)) {
                return false;
            }
            $path = stream_get_meta_data($temporary)['uri'] ?? null;
            if (!is_string($path)) {
                return false;
            }
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
                return false;
            }
            try {
                if ($zip->numFiles < 2 || $zip->numFiles > 2048) {
                    return false;
                }
                $seen = [];
                $uncompressed = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    if ($stat === false) {
                        return false;
                    }
                    $name = $stat['name'];
                    $size = $stat['size'];
                    if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\')
                        || str_contains($name, "\0") || preg_match('~(?:^|/)\.\.(?:/|$)~', $name) === 1
                        || isset($seen[strtolower($name)]) || $size < 0
                        || $stat['encryption_method'] !== 0 || str_ends_with(strtolower($name), '/vbaproject.bin')) {
                        return false;
                    }
                    $seen[strtolower($name)] = true;
                    $uncompressed += $size;
                    if ($uncompressed > 32 * 1024 * 1024) {
                        return false;
                    }
                }
                $types = $zip->statName('[Content_Types].xml');
                $document = $zip->statName('word/document.xml');
                if ($types === false || $types['size'] <= 0 || $types['size'] > 131072
                    || $document === false || $document['size'] <= 0) {
                    return false;
                }
                $contentTypes = $zip->getFromName('[Content_Types].xml');
                return is_string($contentTypes)
                    && str_contains($contentTypes, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml')
                    && !str_contains(strtolower($contentTypes), 'macroenabled');
            } finally {
                $zip->close();
            }
        } finally {
            fclose($temporary);
        }
    }
}
