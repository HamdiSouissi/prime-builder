<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Security\HtmlSanitizer;

/** Bounded, text-only testimonial rows with an optional safe portrait URL. */
final class Testimonials
{
    /** @return list<array{text: string, author: string, job: string, image: string}>|null */
    public static function parse(string $input, HtmlSanitizer $sanitizer): ?array
    {
        if (strlen($input) > 10000) {
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
            $parts = explode('|', $line);
            if (count($parts) > 4) {
                return null;
            }
            $text = trim(strip_tags($parts[0]));
            $author = trim(strip_tags($parts[1] ?? ''));
            $job = trim(strip_tags($parts[2] ?? ''));
            $rawImage = trim($parts[3] ?? '');
            if ($text === '' || strlen($text) > 1500 || strlen($author) > 200 || strlen($job) > 200 || strlen($rawImage) > 2048) {
                return null;
            }
            $image = $rawImage === '' ? '' : $sanitizer->sanitizeResourceUrl($rawImage);
            if ($image === null) {
                return null;
            }
            $rows[] = ['text' => $text, 'author' => $author, 'job' => $job, 'image' => $image];
        }
        return $rows;
    }

    /** @param list<array{text: string, author: string, job: string, image: string}> $rows */
    public static function serialize(array $rows): string
    {
        $lines = [];
        $compact = [];
        foreach ($rows as $row) {
            $parts = [$row['text'], $row['author'], $row['job'], $row['image']];
            while (count($parts) > 1 && end($parts) === '') {
                array_pop($parts);
            }
            $lines[] = implode(' | ', $parts);
            $compact[] = implode('|', $parts);
        }
        $readable = implode("\n", $lines);
        // Normalization must remain within the schema limit when whitespace is added.
        return strlen($readable) <= 10000 ? $readable : implode("\n", $compact);
    }
}
