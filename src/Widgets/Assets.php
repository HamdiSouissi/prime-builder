<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Themes\ThemeAssetRegistry;

/** Registers only the public assets needed by widget types used on the page. */
final class Assets
{
    public static function safeUrl(mixed $url, string $extension): ?string
    {
        if (!is_string($url) || !in_array($extension, ['js', 'css'], true) || str_contains($url, '..')) {
            return null;
        }
        $pattern = '#^/assets/plugins/[a-z0-9-]+/[a-zA-Z0-9_./-]+\.' . $extension . '(?:\?[a-zA-Z0-9_=&.-]+)?$#';
        return preg_match($pattern, $url) === 1 ? $url : null;
    }

    /**
     * @param array<string, true> $types
     * @param array<string, array<string, mixed>> $definitions
     */
    public static function register(ThemeAssetRegistry $assets, array $types, array $definitions): void
    {
        $styles = [];
        foreach ($assets->resolvedStyles() as $asset) {
            $styles[explode('?', $asset['url'], 2)[0]] = true;
        }
        $scripts = [];
        foreach ($assets->resolvedScripts() as $asset) {
            $scripts[explode('?', $asset['url'], 2)[0]] = true;
        }
        foreach (array_keys($types) as $type) {
            $style = self::safeUrl($definitions[$type]['style'] ?? null, 'css');
            if ($style !== null && !isset($styles[explode('?', $style, 2)[0]])) {
                $assets->style('prime-builder-widget-' . $type, $style, ['prime-builder-base']);
                $styles[explode('?', $style, 2)[0]] = true;
            }
            $script = self::safeUrl($definitions[$type]['script'] ?? null, 'js');
            if ($script !== null && !isset($scripts[explode('?', $script, 2)[0]])) {
                $assets->script('prime-builder-widget-' . $type, $script);
                $scripts[explode('?', $script, 2)[0]] = true;
            }
        }
    }
}
