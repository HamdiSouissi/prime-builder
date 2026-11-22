<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Security\HtmlSanitizer;

/** Bounded, text-only project cards with one category and safe image/link URLs. */
final class Portfolio
{
    /** @return list<array{image: string, alt: string, title: string, category: string, description: string, link: string}>|null */
    public static function parse(string $input, HtmlSanitizer $sanitizer): ?array
    {
        if (strlen($input) > 15000) {
            return null;
        }
        $lines = preg_split('/\R/u', $input);
        if (!is_array($lines)) {
            return null;
        }
        $items = [];
        $categories = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (count($items) >= 24) {
                return null;
            }
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 4 || count($parts) > 6) {
                return null;
            }
            $rawImage = $parts[0];
            $alt = trim(strip_tags($parts[1]));
            $title = trim(strip_tags($parts[2]));
            $category = trim(strip_tags($parts[3]));
            $description = trim(strip_tags($parts[4] ?? ''));
            $rawLink = $parts[5] ?? '';
            if ($rawImage === '' || $title === '' || $category === '' || strlen($rawImage) > 2048
                || strlen($alt) > 300 || strlen($title) > 300 || strlen($category) > 120
                || strlen($description) > 1000 || strlen($rawLink) > 2048) {
                return null;
            }
            $categories[$category] = true;
            if (count($categories) > 12) {
                return null;
            }
            $image = $sanitizer->sanitizeResourceUrl($rawImage);
            $link = $rawLink === '' ? '' : $sanitizer->sanitizeHref($rawLink);
            if ($image === null || $image === '' || $link === null || ($rawLink !== '' && $link === '')) {
                return null;
            }
            $items[] = ['image' => $image, 'alt' => $alt, 'title' => $title, 'category' => $category, 'description' => $description, 'link' => $link];
        }
        return $items;
    }

    /** @param list<array{image: string, alt: string, title: string, category: string, description: string, link: string}> $items */
    public static function serialize(array $items): string
    {
        $readable = [];
        $compact = [];
        foreach ($items as $item) {
            $parts = [$item['image'], $item['alt'], $item['title'], $item['category'], $item['description'], $item['link']];
            while (count($parts) > 4 && end($parts) === '') {
                array_pop($parts);
            }
            $readable[] = implode(' | ', $parts);
            $compact[] = implode('|', $parts);
        }
        $output = implode("\n", $readable);
        return strlen($output) <= 15000 ? $output : implode("\n", $compact);
    }
}
