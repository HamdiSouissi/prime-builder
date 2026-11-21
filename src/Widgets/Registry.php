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
        ];
        $definitions = [];
        foreach ($fields as $type => $schema) {
            $definitions[$type] = [
                'label' => 'plugin::prime-builder.widgets.' . $type,
                'category' => $type === 'container' ? 'structure' : 'base',
                'fields' => $schema,
                'container' => $type === 'container',
            ];
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
