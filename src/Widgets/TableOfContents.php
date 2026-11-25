<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use DOMDocument;
use DOMElement;
use Prime\Security\HtmlSanitizer;
use Prime\Support\Str;

/** A static table of supported headings in the document, usable without JavaScript. */
final class TableOfContents
{
    // A valid document can contain 500 widgets and 1 MiB of text. Without
    // bounds, repeated tables can multiply that input into hundreds of MiB.
    private const MAX_HEADINGS = 200;
    private const MAX_LABEL_BYTES = 300;

    /** @var list<array{anchor: string, level: int, text: string}> */
    private array $headings = [];

    /** @var array<string, string> */
    private array $targets = [];

    /** @var array<string, true> */
    private array $reserved = [];

    /** @var array<string, string> Sanitized text widgets with generated heading targets. */
    private array $richText = [];

    private bool $truncated = false;

    /** @param array<mixed> $elements */
    public function __construct(array $elements, private readonly HtmlSanitizer $sanitizer)
    {
        $this->reserveAnchors($elements);
        $this->collect($elements);
    }

    public function target(string $id): ?string
    {
        return $this->targets[$id] ?? null;
    }

    public function richText(string $id): ?string
    {
        return $this->richText[$id] ?? null;
    }

    /** @param array<string, mixed> $settings */
    public function render(array $settings): string
    {
        $label = is_string($settings['label'] ?? null) ? trim(strip_tags($settings['label'])) : '';
        if ($label === '') {
            $label = t('plugin::prime-builder.ui.toc_default_label');
        }
        $min = max(1, min(6, (int) ($settings['min_level'] ?? 2)));
        $max = max($min, min(6, (int) ($settings['max_level'] ?? 6)));
        $headings = array_values(array_filter($this->headings, static fn (array $heading): bool => $heading['level'] >= $min && $heading['level'] <= $max));
        $notice = $this->truncated ? '<p class="pb-toc__limit">' . Renderer::escape(t('plugin::prime-builder.ui.toc_limit')) . '</p>' : '';
        if ($headings === []) {
            return '<p class="pb-toc-empty">' . Renderer::escape(t('plugin::prime-builder.ui.toc_empty')) . '</p>' . $notice;
        }
        $index = 0;
        return '<nav class="pb-toc" aria-label="' . Renderer::escape($label) . '"><p class="pb-toc__title">' . Renderer::escape($label) . '</p>'
            . self::list($headings, $index, 0) . $notice . '</nav>';
    }

    /** @param array<mixed> $elements */
    private function reserveAnchors(array $elements): void
    {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }
            $type = $element['type'] ?? null;
            $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];
            $anchor = $type === 'anchor' ? ($settings['anchor'] ?? null) : ($type === 'prime_block' ? ($settings['block_anchor'] ?? null) : null);
            if (is_string($anchor) && $anchor !== '') {
                $this->reserved[$anchor] = true;
            }
            if ($type === 'prime_block' && is_string($element['id'] ?? null)) {
                $this->reserved['pb-' . $element['id']] = true;
            }
            if (is_array($element['children'] ?? null)) {
                $this->reserveAnchors($element['children']);
            }
        }
    }

    /** @param array<mixed> $elements */
    private function collect(array $elements): void
    {
        foreach ($elements as $element) {
            if (!is_array($element) || ($element['hidden'] ?? false) === true) {
                continue;
            }
            $id = $element['id'] ?? null;
            $type = $element['type'] ?? null;
            $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];
            $validId = is_string($id) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id) === 1;
            if ($type === 'text' && $validId) {
                $html = $this->sanitizer->sanitizeBasic(is_string($settings['text'] ?? null) ? $settings['text'] : '');
                $this->richText[$id] = $this->collectRichText($id, $html);
            }
            $text = '';
            $level = 2;
            if ($type === 'heading') {
                $text = is_string($settings['text'] ?? null) ? trim($settings['text']) : '';
                $level = max(1, min(6, (int) ($settings['level'] ?? 2)));
            } elseif ($type === 'animated_heading') {
                $parts = [];
                foreach (['before', 'highlight', 'after'] as $key) {
                    if (is_string($settings[$key] ?? null) && trim($settings[$key]) !== '') {
                        $parts[] = trim($settings[$key]);
                    }
                }
                $text = implode(' ', $parts);
                $level = max(1, min(6, (int) ($settings['level'] ?? 2)));
            } elseif ($type === 'prime_block' && ($settings['block_type'] ?? null) === 'heading') {
                $data = is_string($settings['block_data'] ?? null) ? json_decode($settings['block_data'], true) : null;
                if (is_array($data)) {
                    $text = Str::plainText(is_string($data['text'] ?? null) ? $data['text'] : '');
                    $level = in_array($data['level'] ?? null, [2, 3, 4], true) ? $data['level'] : 2;
                }
            } elseif (in_array($type, ['card', 'icon_box', 'image_box', 'form'], true)) {
                // These widgets render a single optional h3 from the title.
                $text = is_string($settings['title'] ?? null) ? trim($settings['title']) : '';
                $level = 3;
            } elseif (in_array($type, ['call_to_action', 'price_table', 'price_list', 'review'], true)) {
                $text = is_string($settings['title'] ?? null) ? trim($settings['title']) : '';
                $level = max(2, min(6, (int) ($settings['level'] ?? 2)));
            } elseif ($type === 'countdown') {
                // Without a valid deadline the renderer shows a prompt instead of a heading.
                $deadline = is_string($settings['deadline'] ?? null) ? $settings['deadline'] : '';
                if (Countdown::canonical($deadline) !== null) {
                    $text = is_string($settings['title'] ?? null) && trim($settings['title']) !== ''
                        ? trim($settings['title']) : t('plugin::prime-builder.widgets.countdown');
                    $level = max(2, min(6, (int) ($settings['level'] ?? 2)));
                }
            }
            if ($validId && self::hasVisibleText($text)) {
                if (count($this->headings) >= self::MAX_HEADINGS) {
                    $this->truncated = true;
                } else {
                    $anchor = self::uniqueAnchor($this->reserved, 'pb-toc-' . $id);
                    $this->targets[$id] = $anchor;
                    $this->headings[] = ['anchor' => $anchor, 'level' => $level, 'text' => self::label($text)];
                }
            }
            if (is_array($element['children'] ?? null)) {
                $this->collect($element['children']);
            }
        }
    }

    /**
     * Add targets only to already-sanitized rich text. Parsing and serializing a
     * fragment must succeed before any links are committed to the TOC.
     */
    private function collectRichText(string $id, string $html): string
    {
        if ((count($this->headings) >= self::MAX_HEADINGS && $this->truncated) || preg_match('/<h[234]>/', $html) !== 1) {
            return $html;
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div id="__pb_toc_root__">' . $html . '</div></body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $loaded ? $document->getElementById('__pb_toc_root__') : null;
        if (!$root instanceof DOMElement || $root->nextSibling !== null) {
            return $html;
        }
        // Work on local copies so an unexpected serialization failure cannot
        // leave links pointing at headings absent from the returned fragment.
        $reserved = $this->reserved;
        $headings = [];
        $truncated = false;
        $number = 0;
        foreach ($root->getElementsByTagName('*') as $node) {
            $tag = strtolower($node->tagName);
            if (!in_array($tag, ['h2', 'h3', 'h4'], true)) {
                continue;
            }
            ++$number;
            $rendered = $document->saveHTML($node);
            $text = is_string($rendered) ? Str::plainText($rendered) : '';
            if (!self::hasVisibleText($text)) {
                continue;
            }
            if (count($this->headings) + count($headings) >= self::MAX_HEADINGS) {
                $truncated = true;
                break;
            }
            $anchor = self::uniqueAnchor($reserved, 'pb-toc-' . $id . '-rich-' . $number);
            $node->setAttribute('id', $anchor);
            $node->setAttribute('tabindex', '-1');
            $headings[] = ['anchor' => $anchor, 'level' => (int) substr($tag, 1), 'text' => self::label($text)];
        }
        $output = '';
        foreach ($root->childNodes as $child) {
            $part = $document->saveHTML($child);
            if (!is_string($part)) {
                return $html;
            }
            $output .= $part;
        }
        $this->reserved = $reserved;
        array_push($this->headings, ...$headings);
        $this->truncated = $this->truncated || $truncated;
        return $output;
    }

    private static function hasVisibleText(string $text): bool
    {
        // Whitespace and invisible Unicode controls alone create unnamed links.
        return preg_match('/[^\s\p{Z}\p{C}]/u', $text) === 1;
    }

    private static function label(string $text): string
    {
        if (strlen($text) <= self::MAX_LABEL_BYTES) {
            return $text;
        }
        // Leave room for the ellipsis and never split a UTF-8 character.
        return rtrim(mb_strcut($text, 0, self::MAX_LABEL_BYTES - strlen('…'), 'UTF-8')) . '…';
    }

    /** @param array<string, true> $reserved */
    private static function uniqueAnchor(array &$reserved, string $base): string
    {
        $anchor = $base;
        $suffix = 2;
        while (isset($reserved[$anchor])) {
            $anchor = $base . '-' . $suffix++;
        }
        $reserved[$anchor] = true;
        return $anchor;
    }

    /**
     * Build nested lists from heading levels, without empty list items for skipped levels.
     *
     * @param list<array{anchor: string, level: int, text: string}> $headings
     */
    private static function list(array $headings, int &$index, int $parentLevel): string
    {
        $html = '<ol>';
        $count = count($headings);
        while ($index < $count && $headings[$index]['level'] > $parentLevel) {
            $item = $headings[$index++];
            $html .= '<li><a href="#' . Renderer::escape($item['anchor']) . '">' . Renderer::escape($item['text']) . '</a>';
            if ($index < $count && $headings[$index]['level'] > $item['level']) {
                $html .= self::list($headings, $index, $item['level']);
            }
            $html .= '</li>';
        }
        return $html . '</ol>';
    }
}
