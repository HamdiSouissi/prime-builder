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
            'gallery' => ['images' => ['type' => 'media_list', 'max' => 10000]],
            'video' => ['url' => $url, 'captions' => $url, 'caption_language' => ['type' => 'enum', 'values' => ['fr', 'en']], 'transcript' => ['type' => 'rich', 'max' => 20000]],
            'audio' => ['url' => $url, 'transcript' => ['type' => 'rich', 'max' => 20000]],
            'posts' => ['limit' => ['type' => 'integer', 'min' => 1, 'max' => 12], 'show_excerpt' => ['type' => 'enum', 'values' => ['show', 'hide']]],
            'current_content' => ['source' => ['type' => 'enum', 'values' => ['title', 'excerpt', 'published', 'author']]],
            'prime_block' => ['block_type' => ['type' => 'enum', 'values' => PrimeBlock::ALLOWED], 'block_data' => ['type' => 'json_object', 'max' => 20000], 'block_anchor' => ['type' => 'text', 'max' => 100]],
            'html' => [
                'markup' => ['type' => 'rich', 'max' => 20000],
                'signed_entry' => ['type' => 'integer', 'min' => 1, 'max' => PHP_INT_MAX, 'internal' => true],
                'signature' => ['type' => 'signature', 'max' => 64, 'internal' => true],
            ],
            'original_content' => ['payload' => ['type' => 'protected', 'max' => 1048576]],
            'original_group' => ['payload' => ['type' => 'protected', 'max' => 1048576]],
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
                'category' => $type === 'container' ? 'structure' : (in_array($type, ['accordion', 'card', 'icon_box', 'counter', 'tabs', 'gallery', 'video', 'audio', 'posts', 'current_content', 'prime_block'], true) ? 'pro' : 'base'),
                'fields' => $schema,
                'container' => $type === 'container',
            ];
            if (in_array($type, ['original_content', 'original_group'], true)) {
                $definitions[$type]['hidden'] = true;
                $definitions[$type]['read_only'] = true;
            }
            if ($type === 'tabs') {
                $definitions[$type]['script'] = '/assets/plugins/prime-builder/js/tabs.js';
            } elseif (in_array($type, ['posts', 'current_content'], true)) {
                $definitions[$type]['script'] = '/assets/plugins/prime-builder/js/content.js';
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
