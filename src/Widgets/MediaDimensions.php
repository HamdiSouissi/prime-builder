<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Security\HtmlSanitizer;

/** Validated intrinsic dimensions associated with image URLs in lists. */
final class MediaDimensions
{
    public const MAX_BYTES = 25000;
    public const STANDARD_MAX_ROWS = 20;
    public const MAX_ROWS = 24;

    /** @return list<array{url: string, width: int, height: int}>|null */
    public static function parse(string $input, HtmlSanitizer $sanitizer): ?array
    {
        if (strlen($input) > self::MAX_BYTES || !is_array(json_decode($input))) {
            return null;
        }
        $decoded = json_decode($input, true);
        return is_array($decoded) ? self::validateRows($decoded, $sanitizer) : null;
    }

    /** @return list<array{url: string, width: int, height: int}>|null */
    public static function parseManual(string $input, HtmlSanitizer $sanitizer): ?array
    {
        if (strlen($input) > self::MAX_BYTES || !is_array($lines = preg_split('/\R/u', $input))) {
            return null;
        }
        $items = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            if (count($items) >= self::MAX_ROWS || count($parts) !== 3
                || preg_match('/^[0-9]{1,5}$/D', $parts[1]) !== 1 || preg_match('/^[0-9]{1,5}$/D', $parts[2]) !== 1) {
                return null;
            }
            $items[] = ['url' => $parts[0], 'width' => (int) $parts[1], 'height' => (int) $parts[2]];
        }
        return self::validateRows($items, $sanitizer);
    }

    /**
     * @param array<mixed> $decoded
     * @return list<array{url: string, width: int, height: int}>|null
     */
    private static function validateRows(array $decoded, HtmlSanitizer $sanitizer): ?array
    {
        if (!array_is_list($decoded) || count($decoded) > self::MAX_ROWS) {
            return null;
        }
        $result = [];
        $seen = [];
        foreach ($decoded as $item) {
            if (!is_array($item) || count($item) !== 3 || !is_string($item['url'] ?? null)
                || !is_int($item['width'] ?? null) || !is_int($item['height'] ?? null)
                || $item['width'] < 1 || $item['width'] > 10000 || $item['height'] < 1 || $item['height'] > 10000) {
                return null;
            }
            $rawUrl = trim($item['url']);
            if (strlen($rawUrl) > 10000 || preg_match('/[|\x00-\x1f\x7f\x{0085}\x{2028}\x{2029}]/u', $rawUrl) !== 0) {
                return null;
            }
            $url = $sanitizer->sanitizeResourceUrl($rawUrl);
            if ($url === null || isset($seen[$url])) {
                return null;
            }
            $seen[$url] = true;
            $result[] = ['url' => $url, 'width' => $item['width'], 'height' => $item['height']];
        }
        return $result;
    }

    /**
     * @param list<array{url: string, width: int, height: int}> $rows
     * @return list<array{url: string, width: int, height: int}>
     */
    public static function onlyListed(array $rows, string $images, HtmlSanitizer $sanitizer): array
    {
        $urls = [];
        foreach (preg_split('/\R/u', $images) ?: [] as $line) {
            $url = $sanitizer->sanitizeResourceUrl(trim(explode('|', $line, 2)[0]));
            if ($url !== null) {
                $urls[$url] = true;
            }
        }
        return self::onlyUrls($rows, array_keys($urls));
    }

    /**
     * @param list<array{url: string, width: int, height: int}> $rows
     * @param list<string> $urls
     * @return list<array{url: string, width: int, height: int}>
     */
    public static function onlyUrls(array $rows, array $urls): array
    {
        $listed = array_fill_keys($urls, true);
        return array_values(array_filter($rows, static fn (array $row): bool => isset($listed[$row['url']])));
    }

    /** @param list<array{url: string, width: int, height: int}> $rows */
    public static function serialize(array $rows): ?string
    {
        $output = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($output) && strlen($output) <= self::MAX_BYTES ? $output : null;
    }

    /** @param list<array{url: string, width: int, height: int}> $rows */
    public static function serializeManual(array $rows): ?string
    {
        $readable = [];
        $compact = [];
        foreach ($rows as $row) {
            $parts = [$row['url'], (string) $row['width'], (string) $row['height']];
            $readable[] = implode(' | ', $parts);
            $compact[] = implode('|', $parts);
        }
        $output = implode("\n", $readable);
        if (strlen($output) <= self::MAX_BYTES) {
            return $output;
        }
        $output = implode("\n", $compact);
        return strlen($output) <= self::MAX_BYTES ? $output : null;
    }
}
