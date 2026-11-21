<?php

declare(strict_types=1);

namespace PrimeBuilder\Styles;

use Prime\Plugins\PluginSettings;
use PrimeBuilder\Configuration;

/** A small, allowlisted site kit. User input is never interpreted as arbitrary CSS. */
final class Kit
{
    public static function fromSettings(PluginSettings $settings): string
    {
        return self::compile([
            'primary' => Configuration::get($settings, 'kit_primary', '#c9441f'),
            'text' => Configuration::get($settings, 'kit_text', '#1a1d29'),
            'font_size' => Configuration::get($settings, 'kit_font_size', 16),
        ]);
    }

    /** @param array<string, mixed> $values */
    public static function compile(array $values): string
    {
        $primary = self::color($values['primary'] ?? null, '#c9441f');
        $text = self::color($values['text'] ?? null, '#1a1d29');
        $size = $values['font_size'] ?? 16;
        $size = is_numeric($size) ? max(12, min(28, (int) $size)) : 16;
        return '.pb-layout{--pb-kit-primary:' . $primary . ';--pb-kit-text:' . $text
            . ';--pb-kit-font-size:' . $size . 'px;color:var(--pb-kit-text);font-size:var(--pb-kit-font-size)}'
            . '.pb-layout .pb-button-link{background-color:var(--pb-kit-primary)}';
    }

    private static function color(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[a-f0-9]{3}(?:[a-f0-9]{3})?$/i', $value) === 1
            ? strtolower($value) : $fallback;
    }
}
