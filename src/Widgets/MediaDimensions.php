<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Security\HtmlSanitizer;

/** Validated intrinsic dimensions copied from media selections for image lists. */
final class MediaDimensions
{
    public const MAX_BYTES = 25000;

    /** @return list<array{url: string, width: int, height: int}>|null */
    public static function parse(string $input, HtmlSanitizer $sanitizer): ?array
    {
        if (strlen($input) > self::MAX_BYTES || !is_array(json_decode($input))) {
            return null;
        }
        $decoded = json_decode($input, true);
        if (!is_array($decoded) || !array_is_list($decoded) || count($decoded) > 20) {
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
        return array_values(array_filter($rows, static fn (array $row): bool => isset($urls[$row['url']])));
    }

    /** @param list<array{url: string, width: int, height: int}> $rows */
    public static function serialize(array $rows): ?string
    {
        $output = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($output) && strlen($output) <= self::MAX_BYTES ? $output : null;
    }
}
