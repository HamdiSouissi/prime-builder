<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Support\Str;

/** A static table of the document's own heading widgets, usable without JavaScript. */
final class TableOfContents
{
    /** @var list<array{anchor: string, level: int, text: string}> */
    private array $headings = [];

    /** @var array<string, string> */
    private array $targets = [];

    /** @var array<string, true> */
    private array $reserved = [];

    /** @param array<mixed> $elements */
    public function __construct(array $elements)
    {
        $this->reserveAnchors($elements);
        $this->collect($elements);
    }

    public function target(string $id): ?string
    {
        return $this->targets[$id] ?? null;
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
        if ($headings === []) {
            return '<p class="pb-toc-empty">' . Renderer::escape(t('plugin::prime-builder.ui.toc_empty')) . '</p>';
        }
        $index = 0;
        return '<nav class="pb-toc" aria-label="' . Renderer::escape($label) . '"><p class="pb-toc__title">' . Renderer::escape($label) . '</p>'
            . self::list($headings, $index, 0) . '</nav>';
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
            }
            if (is_string($id) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id) === 1 && trim($text) !== '') {
                $anchor = 'pb-toc-' . $id;
                $suffix = 2;
                while (isset($this->reserved[$anchor])) {
                    $anchor = 'pb-toc-' . $id . '-' . $suffix++;
                }
                $this->reserved[$anchor] = true;
                $this->targets[$id] = $anchor;
                $this->headings[] = ['anchor' => $anchor, 'level' => $level, 'text' => $text];
            }
            if (is_array($element['children'] ?? null)) {
                $this->collect($element['children']);
            }
        }
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
