<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Hooks\Hooks;

/** Widget definitions are declarative; extensions may add definitions via prime_builder.widgets. */
final class Registry
{
    public function __construct(private readonly ?Hooks $hooks = null)
    {
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $text = ['type' => 'text', 'max' => 1000];
        $url = ['type' => 'url', 'max' => 2048];
        $fields = [
            'container' => ['layout' => ['type' => 'enum', 'values' => ['flex', 'grid']], 'direction' => ['type' => 'enum', 'values' => ['column', 'row']], 'columns' => ['type' => 'integer', 'min' => 1, 'max' => 6]],
            'heading' => ['text' => $text, 'level' => ['type' => 'integer', 'min' => 1, 'max' => 6]],
            'text' => ['text' => ['type' => 'rich', 'max' => 20000]],
            'image' => ['url' => $url, 'alt' => $text, 'width' => ['type' => 'integer', 'min' => 1, 'max' => 10000], 'height' => ['type' => 'integer', 'min' => 1, 'max' => 10000]],
            'button' => ['text' => $text, 'url' => ['type' => 'href', 'max' => 2048]],
            'separator' => [],
            'spacer' => [],
            'quote' => ['text' => ['type' => 'rich', 'max' => 5000], 'author' => $text],
            'alert' => ['text' => ['type' => 'rich', 'max' => 5000]],
            'progress' => ['text' => $text, 'value' => ['type' => 'integer', 'min' => 0, 'max' => 100]],
            'anchor' => ['anchor' => ['type' => 'slug', 'max' => 64]],
            'list' => ['items' => ['type' => 'multiline', 'max' => 5000], 'list_type' => ['type' => 'enum', 'values' => ['unordered', 'ordered']]],
            'accordion' => ['title' => $text, 'text' => ['type' => 'rich', 'max' => 10000]],
            'card' => ['title' => $text, 'text' => ['type' => 'rich', 'max' => 10000], 'image_url' => $url, 'alt' => $text, 'url' => ['type' => 'href', 'max' => 2048], 'button_text' => $text],
            'icon_box' => ['icon' => ['type' => 'enum', 'values' => ['star', 'check', 'heart', 'spark']], 'title' => $text, 'text' => ['type' => 'rich', 'max' => 5000]],
            'counter' => ['value' => ['type' => 'integer', 'min' => 0, 'max' => 1000000000], 'prefix' => $text, 'suffix' => $text, 'title' => $text],
            'tabs' => [
                'label' => $text,
                'title_1' => $text, 'text_1' => ['type' => 'rich', 'max' => 10000],
                'title_2' => $text, 'text_2' => ['type' => 'rich', 'max' => 10000],
                'title_3' => $text, 'text_3' => ['type' => 'rich', 'max' => 10000],
            ],
        ];
        $definitions = [];
        foreach ($fields as $type => $schema) {
            $definitions[$type] = [
                'label' => 'plugin::prime-builder.widgets.' . $type,
                'category' => $type === 'container' ? 'structure' : (in_array($type, ['accordion', 'card', 'icon_box', 'counter', 'tabs'], true) ? 'pro' : 'base'),
                'fields' => $schema,
                'container' => $type === 'container',
            ];
            if ($type === 'tabs') {
                $definitions[$type]['script'] = '/assets/plugins/prime-builder/js/tabs.js';
            }
        }
        $filtered = $this->hooks?->apply('prime_builder.widgets', $definitions) ?? $definitions;
        if (!is_array($filtered)) {
            return $definitions;
        }
        // Only named, schema-bearing definitions are usable. Executable extension callbacks are trusted PHP.
        foreach ($filtered as $type => $definition) {
            if (!is_string($type) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $type) !== 1 || !is_array($definition) || !is_array($definition['fields'] ?? null)) {
                unset($filtered[$type]);
            }
        }

        return $filtered;
    }
}
