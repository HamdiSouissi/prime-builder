<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Security\HtmlSanitizer;

/** Bounded image slides with escaped text and optional safe links. */
final class Slideshow
{
    /** @return list<array{image: string, alt: string, title: string, description: string, link_text: string, link: string}>|null */
    public static function parse(string $input, HtmlSanitizer $sanitizer): ?array
    {
        if (strlen($input) > 15000) {
            return null;
        }
        $lines = preg_split('/\R/u', $input);
        if (!is_array($lines)) {
            return null;
        }
        $slides = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (count($slides) >= 20) {
                return null;
            }
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 2 || count($parts) > 6) {
                return null;
            }
            $rawImage = $parts[0];
            $alt = trim(strip_tags($parts[1]));
            $title = trim(strip_tags($parts[2] ?? ''));
            $description = trim(strip_tags($parts[3] ?? ''));
            $linkText = trim(strip_tags($parts[4] ?? ''));
            $rawLink = $parts[5] ?? '';
            if ($rawImage === '' || strlen($rawImage) > 2048 || strlen($alt) > 300 || strlen($title) > 300
                || strlen($description) > 2000 || strlen($linkText) > 200 || strlen($rawLink) > 2048
                || (($linkText === '') !== ($rawLink === ''))) {
                return null;
            }
            $image = $sanitizer->sanitizeResourceUrl($rawImage);
            $link = $rawLink === '' ? '' : $sanitizer->sanitizeHref($rawLink);
            if ($image === null || $image === '' || $link === null || ($rawLink !== '' && $link === '')) {
                return null;
            }
            $slides[] = ['image' => $image, 'alt' => $alt, 'title' => $title, 'description' => $description, 'link_text' => $linkText, 'link' => $link];
        }
        return $slides;
    }

    /** @param list<array{image: string, alt: string, title: string, description: string, link_text: string, link: string}> $slides */
    public static function serialize(array $slides): string
    {
        $readable = [];
        $compact = [];
        foreach ($slides as $slide) {
            $parts = [$slide['image'], $slide['alt'], $slide['title'], $slide['description'], $slide['link_text'], $slide['link']];
            while (count($parts) > 2 && end($parts) === '') {
                array_pop($parts);
            }
            $readable[] = implode(' | ', $parts);
            $compact[] = implode('|', $parts);
        }
        $output = implode("\n", $readable);
        return strlen($output) <= 15000 ? $output : implode("\n", $compact);
    }
}
