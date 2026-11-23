<?php

declare(strict_types=1);

namespace PrimeBuilder\Document;

use Prime\Database\Connection;
use Prime\Security\HtmlSanitizer;
use PrimeBuilder\Widgets\MediaCarousel;
use PrimeBuilder\Widgets\Portfolio;
use PrimeBuilder\Widgets\Slideshow;
use PrimeBuilder\Widgets\Testimonials;

/** Resolve validated, local original-file URLs in Builder fields to core media IDs. */
final class MediaReferences
{
    private readonly string $prefix;
    private readonly ?string $origin;

    public function __construct(private readonly Connection $db, string $urlPrefix, string $siteUrl)
    {
        $this->prefix = rtrim(rawurldecode($urlPrefix), '/') . '/';
        $this->origin = self::origin($siteUrl);
    }

    /**
     * Never consult the client-supplied index. Walk normalized settings instead,
     * then resolve distinct paths in batches against the CMS's media table.
     *
     * @param list<array<string, mixed>> $elements
     * @param array<string, array<string, mixed>> $definitions
     * @return list<int>
     */
    public function ids(array $elements, array $definitions, HtmlSanitizer $sanitizer): array
    {
        /** @var array<string, string> $paths */
        $paths = [];
        $pending = $elements;
        while ($pending !== []) {
            $element = array_pop($pending);
            foreach (is_array($element['children'] ?? null) ? $element['children'] : [] as $child) {
                if (is_array($child)) {
                    $pending[] = $child;
                }
            }
            $type = is_string($element['type'] ?? null) ? $element['type'] : '';
            $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];
            $fields = is_array($definitions[$type]['fields'] ?? null) ? $definitions[$type]['fields'] : [];
            foreach ($settings as $key => $value) {
                $field = $fields[$key] ?? null;
                if (!is_string($value) || !is_array($field) || !is_string($field['type'] ?? null)) {
                    continue;
                }
                $this->collect($field['type'], $value, $paths, $sanitizer);
            }
            // The bridge contains validated, non-privileged core block data as JSON.
            // Code is rendered as escaped text, not as live links.
            if ($type === 'prime_block' && ($settings['block_type'] ?? '') !== 'code' && is_string($settings['block_data'] ?? null)) {
                $this->collectBlockLinks($settings['block_data'], $paths);
            }
        }

        $ids = [];
        // Both supported CMS versions expose Connection::table and the unique
        // media.disk_path column. Small batches also work with SQLite's bind limit.
        foreach (array_chunk(array_values($paths), 200) as $chunk) {
            $rows = $this->db->table('media')->select(['id', 'disk_path'])->whereIn('disk_path', $chunk)->get();
            foreach ($rows as $row) {
                $path = $row['disk_path'] ?? null;
                $id = $row['id'] ?? null;
                // MySQL's collation can be case-insensitive; require the real path.
                if (is_string($path) && isset($paths[$path]) && is_numeric($id) && (int) $id > 0) {
                    $ids[(int) $id] = (int) $id;
                }
            }
        }
        return array_values($ids);
    }

    /** @param array<string, string> $paths */
    private function collect(string $type, string $value, array &$paths, HtmlSanitizer $sanitizer): void
    {
        if ($type === 'url' || $type === 'href') {
            $this->add($value, $paths);
        } elseif ($type === 'rich') {
            $this->collectLinks($value, $paths);
        } elseif ($type === 'media_list') {
            foreach (explode("\n", $value) as $line) {
                $this->add(explode('|', $line, 2)[0], $paths);
            }
        } elseif ($type === 'media_carousel_items') {
            foreach (MediaCarousel::parse($value, $sanitizer) ?? [] as $item) {
                $this->add($item['url'], $paths);
                $this->add($item['captions'], $paths);
            }
        } elseif ($type === 'slideshow_items') {
            foreach (Slideshow::parse($value, $sanitizer) ?? [] as $item) {
                $this->add($item['image'], $paths);
                $this->add($item['link'], $paths);
            }
        } elseif ($type === 'portfolio_items') {
            foreach (Portfolio::parse($value, $sanitizer) ?? [] as $item) {
                $this->add($item['image'], $paths);
                $this->add($item['link'], $paths);
            }
        } elseif ($type === 'testimonial_items') {
            foreach (Testimonials::parse($value, $sanitizer) ?? [] as $item) {
                $this->add($item['image'], $paths);
            }
        }
    }

    /** @param array<string, string> $paths */
    private function collectBlockLinks(string $json, array &$paths): void
    {
        $pending = [json_decode($json, true)];
        while ($pending !== []) {
            $value = array_pop($pending);
            if (is_array($value)) {
                foreach ($value as $child) {
                    $pending[] = $child;
                }
            } elseif (is_string($value)) {
                $this->collectLinks($value, $paths);
            }
        }
    }

    /** @param array<string, string> $paths */
    private function collectLinks(string $html, array &$paths): void
    {
        // HtmlSanitizer serializes allowed anchors as <a href="...">, escaping
        // attribute values. This does not inspect code or arbitrary raw markup.
        if (preg_match_all('/<a href="([^"]*)"/', $html, $matches) === false) {
            return;
        }
        foreach ($matches[1] as $href) {
            $this->add(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $paths);
        }
    }

    /** @param array<string, string> $paths */
    private function add(string $url, array &$paths): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return;
        }
        if (isset($parts['scheme']) || isset($parts['host'])) {
            if ($this->origin === null || self::origin($url) !== $this->origin) {
                return;
            }
        } elseif (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return;
        }
        $path = is_string($parts['path'] ?? null) ? rawurldecode($parts['path']) : '';
        if (!str_starts_with($path, $this->prefix)) {
            return;
        }
        $diskPath = substr($path, strlen($this->prefix));
        if ($diskPath === '' || strlen($diskPath) > 1020 || str_contains($diskPath, "\0") || str_contains($diskPath, '\\')
            || preg_match('~(?:^|/)\.\.?(/|$)~', $diskPath) === 1) {
            return;
        }
        $paths[$diskPath] = $diskPath;
    }

    private static function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])
            || !is_string($parts['scheme'] ?? null) || !is_string($parts['host'] ?? null)) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        return $scheme . '://' . $host . ':' . $port;
    }
}
