<?php

declare(strict_types=1);

namespace PrimeBuilder\Forms;

use Prime\Plugins\PluginSettings;
use PrimeBuilder\Configuration;

/** Validated, bounded upload settings. PHP and the CMS may impose stricter limits. */
final class UploadLimits
{
    public const DEFAULT_KB = 512;
    public const MAX_KB = 8192;
    public const OVERHEAD_BYTES = 65536;

    /** @return array{file_kb: int, total_kb: int, count: int} */
    public static function defaults(): array
    {
        return ['file_kb' => self::DEFAULT_KB, 'total_kb' => self::DEFAULT_KB, 'count' => FileInput::MAX_FILES];
    }

    /** @return array{file_kb: int, total_kb: int, count: int} */
    public static function fromSettings(PluginSettings $settings): array
    {
        $file = self::integer(Configuration::get($settings, 'upload_file_kb', self::DEFAULT_KB), self::DEFAULT_KB, self::MAX_KB);
        $total = self::integer(Configuration::get($settings, 'upload_total_kb', self::DEFAULT_KB), self::DEFAULT_KB, self::MAX_KB);
        return [
            'file_kb' => min($file, $total),
            'total_kb' => $total,
            'count' => self::integer(Configuration::get($settings, 'upload_max_files', FileInput::MAX_FILES), FileInput::MAX_FILES, FileInput::MAX_FILES),
        ];
    }

    private static function integer(mixed $value, int $default, int $max): int
    {
        if ((is_int($value) || is_string($value)) && preg_match('/^[0-9]{1,4}$/D', (string) $value) === 1
            && (int) $value >= 1 && (int) $value <= $max) {
            return (int) $value;
        }
        return $default;
    }

    /** Parse PHP's K/M/G shorthand; zero denotes no finite limit. */
    public static function iniBytes(string $value): ?int
    {
        $value = trim($value);
        if (preg_match('/^([0-9]+)\s*([kmg])?(?:b)?$/iD', $value, $parts) !== 1) {
            return null;
        }
        $number = (float) $parts[1];
        $multiplier = match (strtolower($parts[2] ?? '')) {
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            default => 1,
        };
        if ($number === 0.0) {
            return null;
        }
        // Saturation avoids integer overflow even with malformed or enormous INI values.
        return (int) min(2147483647, $number * $multiplier);
    }

    /**
     * @param array{file_kb: int, total_kb: int, count: int} $limits
     * @return list<string> Machine-readable warning keys, not sensitive runtime values.
     */
    public static function warnings(array $limits, int $apiMaxKb, ?string $uploadMax = null, ?string $postMax = null, ?string $maxUploads = null, ?string $fileUploads = null): array
    {
        $warnings = [];
        $upload = self::iniBytes($uploadMax ?? (string) ini_get('upload_max_filesize'));
        $post = self::iniBytes($postMax ?? (string) ini_get('post_max_size'));
        $maxUploads ??= (string) ini_get('max_file_uploads');
        $fileUploads ??= (string) ini_get('file_uploads');
        if (!in_array(strtolower(trim($fileUploads)), ['1', 'on', 'true', 'yes'], true)) {
            $warnings[] = 'disabled';
        }
        if ($upload !== null && $upload < $limits['file_kb'] * 1024) {
            $warnings[] = 'upload_max';
        }
        if ($post !== null && $post < $limits['total_kb'] * 1024 + self::OVERHEAD_BYTES) {
            $warnings[] = 'post_max';
        }
        $apiBytes = max(16, $apiMaxKb) * 1024;
        $totalBytes = $limits['total_kb'] * 1024;
        if ($apiBytes < $totalBytes + self::OVERHEAD_BYTES) {
            $warnings[] = 'api_max';
        } elseif ($apiBytes < (int) (4 * ceil($totalBytes / 3)) + self::OVERHEAD_BYTES) {
            // JSON/base64 has greater overhead than multipart, even when the
            // configured API limit is sufficient for the current browser.
            $warnings[] = 'api_json_max';
        }
        if (ctype_digit($maxUploads) && (int) $maxUploads < $limits['count']) {
            $warnings[] = 'file_count';
        }
        if (!extension_loaded('fileinfo')) {
            $warnings[] = 'fileinfo';
        }
        if (!class_exists(\ZipArchive::class)) {
            $warnings[] = 'zip';
        }
        return $warnings;
    }
}
