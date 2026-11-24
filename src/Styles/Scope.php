<?php

declare(strict_types=1);

namespace PrimeBuilder\Styles;

/** Stable ownership for the style-relevant parts of a Builder layout. */
final class Scope
{
    /** @param array<string, mixed> $layout */
    public static function forLayout(array $layout): string
    {
        $elements = is_array($layout['elements'] ?? null) ? $layout['elements'] : [];
        $encoded = json_encode(self::describe($elements), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return substr(hash('sha256', 'prime-builder-style-v1:' . (is_string($encoded) ? $encoded : '[]')), 0, 24);
    }

    /**
     * Content and authenticated fragments are deliberately excluded. The value is
     * a CSS namespace, not a secret or an authorization token. JSON round-trips
     * must preserve it so it matches the HTML persisted by the CMS.
     *
     * @param array<mixed> $elements
     * @return list<array<mixed>>
     */
    private static function describe(array $elements): array
    {
        $description = [];
        foreach ($elements as $element) {
            if (!is_array($element) || !is_string($element['id'] ?? null) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $element['id']) !== 1) {
                continue;
            }
            $type = is_string($element['type'] ?? null) ? $element['type'] : '';
            $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];
            $container = $type === 'container' ? [
                $settings['layout'] ?? null,
                $settings['direction'] ?? null,
                $settings['columns'] ?? null,
            ] : [];
            $description[] = [
                $element['id'], $type, $container,
                is_array($element['styles'] ?? null) ? $element['styles'] : [],
                self::describe(is_array($element['children'] ?? null) ? $element['children'] : []),
            ];
        }
        return $description;
    }
}
