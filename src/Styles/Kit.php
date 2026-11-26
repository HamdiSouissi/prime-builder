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
            'font_loading' => Configuration::get($settings, 'font_loading', 'theme'),
        ]);
    }

    /** @param array<string, mixed> $values */
    public static function compile(array $values): string
    {
        $primary = self::color($values['primary'] ?? null, '#c9441f');
        $text = self::color($values['text'] ?? null, '#1a1d29');
        $size = $values['font_size'] ?? 16;
        $size = is_numeric($size) ? max(12, min(28, (int) $size)) : 16;
        // The only downloadable option is the local font shipped by the CMS.
        // An unknown value always preserves the theme's inherited typography.
        $localFont = ($values['font_loading'] ?? 'theme') === 'inter_local';
        $fontFace = $localFont ? "@font-face{font-family:PrimeBuilderInter;src:url('/assets/fonts/inter/InterVariable.woff2') format('woff2');font-weight:100 900;font-display:swap}" : '';
        return $fontFace . '.pb-layout{--pb-kit-primary:' . $primary . ';--pb-kit-text:' . $text
            . ';--pb-kit-font-size:' . $size . 'px;color:var(--pb-kit-text);font-size:var(--pb-kit-font-size)'
            . ($localFont ? ';font-family:PrimeBuilderInter,system-ui,sans-serif' : '') . '}'
            . '.pb-layout .pb-button-link{background-color:var(--pb-kit-primary)}';
    }

    private static function color(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[a-f0-9]{3}(?:[a-f0-9]{3})?$/i', $value) === 1
            ? strtolower($value) : $fallback;
    }
}
