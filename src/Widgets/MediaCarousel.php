<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Security\HtmlSanitizer;

/** Bounded image and native-video rows for the media carousel. */
final class MediaCarousel
{
    /** @return list<array{type: string, url: string, description: string, caption: string, captions: string, transcript: string}>|null */
    public static function parse(string $input, HtmlSanitizer $sanitizer): ?array
    {
        if (strlen($input) > 15000) {
            return null;
        }
        $lines = preg_split('/\R/u', $input);
        if (!is_array($lines)) {
            return null;
        }
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (count($rows) >= 20) {
                return null;
            }
            $parts = array_map('trim', explode('|', $line));
            $type = $parts[0];
            if (!in_array($type, ['image', 'video'], true) || count($parts) < 3 || count($parts) > ($type === 'image' ? 4 : 5)) {
                return null;
            }
            $rawUrl = $parts[1];
            $description = trim(strip_tags($parts[2]));
            if ($rawUrl === '' || strlen($rawUrl) > 2048 || strlen($description) > 300 || ($type === 'video' && $description === '')) {
                return null;
            }
            $url = $sanitizer->sanitizeResourceUrl($rawUrl);
            if ($url === null) {
                return null;
            }
            $caption = $type === 'image' ? trim(strip_tags($parts[3] ?? '')) : '';
            $rawCaptions = $type === 'video' ? ($parts[3] ?? '') : '';
            $transcript = $type === 'video' ? trim(strip_tags($parts[4] ?? '')) : '';
            if (strlen($caption) > 1000 || strlen($rawCaptions) > 2048 || strlen($transcript) > 2000) {
                return null;
            }
            $captions = $rawCaptions === '' ? '' : $sanitizer->sanitizeResourceUrl($rawCaptions);
            if ($captions === null) {
                return null;
            }
            $rows[] = ['type' => $type, 'url' => $url, 'description' => $description, 'caption' => $caption, 'captions' => $captions, 'transcript' => $transcript];
        }
        return $rows;
    }

    /** @param list<array{type: string, url: string, description: string, caption: string, captions: string, transcript: string}> $rows */
    public static function serialize(array $rows): string
    {
        $readable = [];
        $compact = [];
        foreach ($rows as $row) {
            $parts = $row['type'] === 'image'
                ? [$row['type'], $row['url'], $row['description'], $row['caption']]
                : [$row['type'], $row['url'], $row['description'], $row['captions'], $row['transcript']];
            while (count($parts) > 3 && end($parts) === '') {
                array_pop($parts);
            }
            $readable[] = implode(' | ', $parts);
            $compact[] = implode('|', $parts);
        }
        $output = implode("\n", $readable);
        return strlen($output) <= 15000 ? $output : implode("\n", $compact);
    }
}
