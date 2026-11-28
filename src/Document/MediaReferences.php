<?php

declare(strict_types=1);

namespace PrimeBuilder\Document;

use Prime\Database\Connection;
use Prime\Security\HtmlSanitizer;
use PrimeBuilder\Widgets\MediaCarousel;
use PrimeBuilder\Widgets\Portfolio;
use PrimeBuilder\Widgets\Slideshow;
use PrimeBuilder\Widgets\Testimonials;

/** Resolve validated, local media URLs in Builder fields to core media IDs. */
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

        $candidates = self::candidates($paths);
        $ids = [];
        // Both supported CMS versions expose Connection::table and the unique
        // media.disk_path column. Small batches also work with SQLite's bind limit.
        foreach (array_chunk(array_values($candidates), 200) as $chunk) {
            $rows = $this->db->table('media')->select(['id', 'disk_path', 'variants'])->whereIn('disk_path', $chunk)->get();
            foreach ($rows as $row) {
                $path = $row['disk_path'] ?? null;
                $id = $row['id'] ?? null;
                // MySQL's collation can be case-insensitive; require the real candidate.
                if (!is_string($path) || !isset($candidates[$path]) || !is_numeric($id) || (int) $id < 1) {
                    continue;
                }
                if (isset($paths[$path])) {
                    $ids[(int) $id] = (int) $id;
                }
                $variants = is_string($row['variants'] ?? null) ? json_decode($row['variants'], true) : null;
                if (!is_array($variants)) {
                    continue;
                }
                foreach ($variants as $variant) {
                    // Media::normalizeVariants ignores entries without a string path.
                    if (!is_array($variant) || !is_string($variant['path'] ?? null)) {
                        continue;
                    }
                    foreach ([$variant['path'], $variant['webp'] ?? null] as $variantPath) {
                        // Media::variantUrl strips leading slashes from stored paths.
                        if (is_string($variantPath) && isset($paths[ltrim($variantPath, '/')])) {
                            $ids[(int) $id] = (int) $id;
                        }
                    }
                }
            }
        }
        return array_values($ids);
    }

    /**
     * Resolve image sizes only from exact, local media records and declared
     * variants. An ambiguous path, invalid metadata or a foreign origin leaves
     * the image unsized; this method never fetches a URL or inspects a file.
     *
     * @param list<string> $urls
     * @return array<string, array{width: int, height: int}>
     */
    public function imageDimensions(array $urls): array
    {
        /** @var array<string, array<string, true>> $paths */
        $paths = [];
        foreach ($urls as $url) {
            $path = $this->localPath($url);
            if ($path !== null) {
                $paths[$path][$url] = true;
            }
        }
        $requested = [];
        foreach (array_keys($paths) as $path) {
            $requested[(string) $path] = (string) $path;
        }
        $candidates = self::candidates($requested);
        /** @var array<string, array{width: int, height: int}|null> $sizes */
        $sizes = [];
        /** @var array<string, string> $owners */
        $owners = [];
        foreach (array_chunk(array_values($candidates), 200) as $chunk) {
            $rows = $this->db->table('media')->select(['disk_path', 'kind', 'width', 'height', 'variants'])->whereIn('disk_path', $chunk)->get();
            foreach ($rows as $row) {
                $original = $row['disk_path'] ?? null;
                if (!is_string($original) || !isset($candidates[$original])) {
                    continue;
                }
                $image = ($row['kind'] ?? null) === 'image';
                self::recordSize($sizes, $owners, $paths, $original, $original, $image ? self::size($row) : null);
                $variants = is_string($row['variants'] ?? null) ? json_decode($row['variants'], true) : null;
                if (!is_array($variants)) {
                    continue;
                }
                foreach ($variants as $variant) {
                    if (!is_array($variant) || !is_string($variant['path'] ?? null)) {
                        continue;
                    }
                    $size = $image ? self::size($variant) : null;
                    foreach ([$variant['path'], $variant['webp'] ?? null] as $variantPath) {
                        if (is_string($variantPath)) {
                            self::recordSize($sizes, $owners, $paths, ltrim($variantPath, '/'), $original, $size);
                        }
                    }
                }
            }
        }
        $result = [];
        foreach ($paths as $path => $sources) {
            $size = $sizes[$path] ?? null;
            if ($size !== null) {
                foreach (array_keys($sources) as $url) {
                    $result[$url] = $size;
                }
            }
        }
        return $result;
    }

    /**
     * @param array<string, array{width: int, height: int}|null> $sizes
     * @param array<string, string> $owners
     * @param array<string, array<string, true>> $paths
     * @param array{width: int, height: int}|null $size
     */
    private static function recordSize(array &$sizes, array &$owners, array $paths, string $path, string $owner, ?array $size): void
    {
        if (!isset($paths[$path])) {
            return;
        }
        // Different records can name the same WebP file. Even when their
        // dimensions happen to agree, it is not safe to guess its owner.
        if (!array_key_exists($path, $sizes)) {
            $owners[$path] = $owner;
            $sizes[$path] = $size;
        } elseif ($owners[$path] !== $owner || $sizes[$path] !== $size) {
            $sizes[$path] = null;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{width: int, height: int}|null
     */
    private static function size(array $row): ?array
    {
        $width = $row['width'] ?? null;
        $height = $row['height'] ?? null;
        foreach ([$width, $height] as $value) {
            if (!(is_int($value) || (is_string($value) && preg_match('/^[0-9]{1,5}$/D', $value) === 1))
                || (int) $value < 1 || (int) $value > 10000) {
                return null;
            }
        }
        return ['width' => (int) $width, 'height' => (int) $height];
    }

    /**
     * Core's generated filenames suggest possible originals; variants must
     * confirm the path before it is used for either references or dimensions.
     *
     * @param array<string, string> $paths
     * @return array<string, string>
     */
    private static function candidates(array $paths): array
    {
        $candidates = $paths;
        foreach ($paths as $path) {
            foreach (self::originalCandidates($path) as $candidate) {
                $candidates[$candidate] = $candidate;
            }
        }
        return $candidates;
    }

    /** @return list<string> Candidate originals for the CMS's generated image filenames. */
    private static function originalCandidates(string $path): array
    {
        if (preg_match('~^(.+)\.(jpg|jpeg|png|gif|webp|avif)$~D', $path, $matches) !== 1) {
            return [];
        }
        $stem = $matches[1];
        $extension = $matches[2];
        $stems = [];
        if (preg_match('~^(.+)-[1-9][0-9]*x[1-9][0-9]*$~D', $stem, $sized) === 1) {
            $stems[] = $sized[1];
        }
        if ($extension === 'webp') {
            // A WebP URL can also be the original-size companion of any image.
            $stems[] = $stem;
        }
        $extensions = $extension === 'webp' ? ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'] : [$extension];
        $candidates = [];
        foreach ($stems as $base) {
            foreach ($extensions as $ext) {
                $candidate = $base . '.' . $ext;
                if (strlen($candidate) <= 1020 && $candidate !== $path) {
                    $candidates[$candidate] = $candidate;
                }
            }
        }
        return array_values($candidates);
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
        $diskPath = $this->localPath($url);
        if ($diskPath !== null) {
            $paths[$diskPath] = $diskPath;
        }
    }

    private function localPath(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }
        if (isset($parts['scheme']) || isset($parts['host'])) {
            if ($this->origin === null || self::origin($url) !== $this->origin) {
                return null;
            }
        } elseif (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }
        $path = is_string($parts['path'] ?? null) ? rawurldecode($parts['path']) : '';
        if (!str_starts_with($path, $this->prefix)) {
            return null;
        }
        $diskPath = substr($path, strlen($this->prefix));
        if ($diskPath === '' || strlen($diskPath) > 1020 || str_contains($diskPath, "\0") || str_contains($diskPath, '\\')
            || preg_match('~(?:^|/)\.\.?(/|$)~', $diskPath) === 1) {
            return null;
        }
        return $diskPath;
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
