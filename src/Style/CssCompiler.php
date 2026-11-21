<?php

declare(strict_types=1);

namespace PrimeBuilder\Style;

use PrimeBuilder\Document\DocumentValidator;

final class CssCompiler
{
    public function __construct(
        private readonly int $tabletBreakpoint = 1024,
        private readonly int $mobileBreakpoint = 767,
    ) {
    }

    /**
     * @param array<string, mixed> $kit
     */
    public function compileKit(array $kit): string
    {
        $colors = is_array($kit['colors'] ?? null) ? $kit['colors'] : [];
        $typography = is_array($kit['typography'] ?? null) ? $kit['typography'] : [];
        $layout = is_array($kit['layout'] ?? null) ? $kit['layout'] : [];

        $primary = $this->sanitizeCssValue((string) ($colors['primary'] ?? '#ED6647'));
        $secondary = $this->sanitizeCssValue((string) ($colors['secondary'] ?? '#1E293B'));
        $text = $this->sanitizeCssValue((string) ($colors['text'] ?? '#334155'));
        $accent = $this->sanitizeCssValue((string) ($colors['accent'] ?? '#C9441F'));
        $bg = $this->sanitizeCssValue((string) ($colors['background'] ?? '#FFFFFF'));

        $fontHeading = $this->sanitizeCssValue((string) ($typography['heading_font'] ?? 'Plus Jakarta Sans, sans-serif'));
        $fontBody = $this->sanitizeCssValue((string) ($typography['body_font'] ?? 'Inter, sans-serif'));
        $contentWidth = max(600, min(1920, (int) ($layout['content_width'] ?? 1200)));
        $containerGap = max(0, min(120, (int) ($layout['container_gap'] ?? 20)));

        return sprintf(
            ":root{--pb-primary:%s;--pb-secondary:%s;--pb-text:%s;--pb-accent:%s;--pb-bg:%s;--pb-font-heading:%s;--pb-font-body:%s;--pb-content-width:%dpx;--pb-container-gap:%dpx;}\n",
            $primary,
            $secondary,
            $text,
            $accent,
            $bg,
            $fontHeading,
            $fontBody,
            $contentWidth,
            $containerGap,
        );
    }

    /**
     * @param array<string, mixed> $document
     */
    public function compileDocument(array $document): string
    {
        $desktop = [];
        $tablet = [];
        $mobile = [];

        $elements = is_array($document['elements'] ?? null) ? $document['elements'] : [];
        $this->collectStyles($elements, $desktop, $tablet, $mobile);

        $css = implode("\n", $desktop);
        if ($tablet !== []) {
            $css .= "\n@media (max-width: " . $this->tabletBreakpoint . "px) {\n" . implode("\n", $tablet) . "\n}";
        }
        if ($mobile !== []) {
            $css .= "\n@media (max-width: " . $this->mobileBreakpoint . "px) {\n" . implode("\n", $mobile) . "\n}";
        }

        return trim($css);
    }

    public function hash(string $css): string
    {
        return substr(hash('sha256', $css), 0, 12);
    }

    /**
     * @param array<mixed> $elements
     * @param list<string> $desktop
     * @param list<string> $tablet
     * @param list<string> $mobile
     */
    private function collectStyles(array $elements, array &$desktop, array &$tablet, array &$mobile): void
    {
        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }
            $id = (string) ($el['id'] ?? '');
            $settings = is_array($el['settings'] ?? null) ? $el['settings'] : [];
            if ($id !== '') {
                $sel = '[data-pb-id="' . $id . '"]';
                $rules = $this->buildRules($settings, '');
                if ($rules !== '') {
                    $desktop[] = $sel . '{' . $rules . '}';
                }
                $hoverRules = $this->buildHoverRules($settings);
                if ($hoverRules !== '') {
                    $desktop[] = $sel . ':hover{' . $hoverRules . '}';
                }
                $tabletRules = $this->buildRules($settings, '_tablet');
                if ($tabletRules !== '') {
                    $tablet[] = $sel . '{' . $tabletRules . '}';
                }
                $mobileRules = $this->buildRules($settings, '_mobile');
                if ($mobileRules !== '') {
                    $mobile[] = $sel . '{' . $mobileRules . '}';
                }
                if (!empty($settings['hide_desktop'])) {
                    $desktop[] = '@media (min-width: ' . ($this->tabletBreakpoint + 1) . 'px){' . $sel . '{display:none !important;}}';
                }
                if (!empty($settings['hide_tablet'])) {
                    $tablet[] = '@media (min-width: ' . ($this->mobileBreakpoint + 1) . 'px){' . $sel . '{display:none !important;}}';
                }
                if (!empty($settings['hide_mobile'])) {
                    $mobile[] = $sel . '{display:none !important;}';
                }
                if (is_string($settings['custom_css'] ?? null) && $settings['custom_css'] !== '') {
                    $desktop[] = str_replace('selector', $sel, DocumentValidator::sanitizeCustomCss($settings['custom_css']));
                }
            }

            if (is_array($el['elements'] ?? null)) {
                $this->collectStyles($el['elements'], $desktop, $tablet, $mobile);
            }
        }
    }

    /**
     * @param array<string, mixed> $s
     */
    private function buildRules(array $s, string $suffix): string
    {
        $props = [];
        $map = [
            'margin' => 'margin',
            'padding' => 'padding',
            'background_color' => 'background-color',
            'text_color' => 'color',
            'font_size' => 'font-size',
            'font_weight' => 'font-weight',
            'line_height' => 'line-height',
            'text_align' => 'text-align',
            'border_radius' => 'border-radius',
            'border_width' => 'border-width',
            'border_color' => 'border-color',
            'border_style' => 'border-style',
            'box_shadow' => 'box-shadow',
            'width' => 'width',
            'max_width' => 'max-width',
            'min_height' => 'min-height',
            'gap' => 'gap',
            'flex_direction' => 'flex-direction',
            'justify_content' => 'justify-content',
            'align_items' => 'align-items',
            'flex_wrap' => 'flex-wrap',
            'grid_columns' => 'grid-template-columns',
            'z_index' => 'z-index',
        ];

        foreach ($map as $key => $cssProp) {
            $k = $key . $suffix;
            if (isset($s[$k]) && (is_string($s[$k]) || is_numeric($s[$k]))) {
                $val = $this->sanitizeCssValue((string) $s[$k]);
                if ($val !== '') {
                    if ($key === 'grid_columns' && is_numeric($val)) {
                        $val = 'repeat(' . max(1, min(12, (int) $val)) . ', minmax(0, 1fr))';
                    }
                    $props[] = $cssProp . ':' . $val;
                }
            }
        }

        if ($suffix === '' && is_string($s['background_image'] ?? null) && $s['background_image'] !== '') {
            $url = DocumentValidator::sanitizeUrl($s['background_image']);
            if ($url !== '') {
                $props[] = 'background-image:url("' . addslashes($url) . '")';
                $props[] = 'background-size:cover;background-position:center';
            }
        }

        return implode(';', $props);
    }

    /**
     * @param array<string, mixed> $s
     */
    private function buildHoverRules(array $s): string
    {
        $props = [];
        if (is_string($s['hover_background_color'] ?? null) && $s['hover_background_color'] !== '') {
            $props[] = 'background-color:' . $this->sanitizeCssValue($s['hover_background_color']);
        }
        if (is_string($s['hover_text_color'] ?? null) && $s['hover_text_color'] !== '') {
            $props[] = 'color:' . $this->sanitizeCssValue($s['hover_text_color']);
        }

        return implode(';', $props);
    }

    private function sanitizeCssValue(string $value): string
    {
        $clean = preg_replace('/[;{}<>]/', '', trim($value)) ?? '';
        $clean = preg_replace('/expression\s*\(|javascript:|vbscript:/i', '', $clean) ?? '';

        return mb_substr($clean, 0, 200);
    }
}
