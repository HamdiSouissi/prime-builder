<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Security\HtmlSanitizer;

/** Semantic links using the CMS breadcrumb data, without trusting labels or URLs as HTML. */
final class Breadcrumbs
{
    /**
     * @param array<string, mixed> $settings
     * @param list<array{label: string, url: string}> $crumbs
     */
    public static function render(array $settings, array $crumbs, HtmlSanitizer $sanitizer): string
    {
        $items = [];
        foreach ($crumbs as $crumb) {
            $label = trim($crumb['label']);
            if ($label !== '') {
                $items[] = ['label' => $label, 'url' => $crumb['url']];
            }
        }
        if ($items === []) {
            return '';
        }
        $last = count($items) - 1;
        $html = '<nav class="pb-breadcrumb" aria-label="' . Renderer::escape(self::label($settings)) . '"><ol>';
        foreach ($items as $index => $item) {
            $label = Renderer::escape($item['label']);
            $href = $sanitizer->sanitizeHref($item['url']);
            if ($index === $last) {
                $html .= '<li><span aria-current="page">' . $label . '</span></li>';
            } elseif ($href !== null) {
                $html .= '<li><a href="' . Renderer::escape($href) . '">' . $label . '</a></li>';
            } else {
                $html .= '<li><span>' . $label . '</span></li>';
            }
        }
        return $html . '</ol></nav>';
    }

    /** @param array<string, mixed> $settings */
    public static function fallback(array $settings): string
    {
        // No saved labels or entry URLs: they can become private or move after saving.
        return '<nav class="pb-breadcrumb" aria-label="' . Renderer::escape(self::label($settings)) . '"><ol><li><a href="/">'
            . Renderer::escape(t('plugin::prime-builder.ui.breadcrumb_home')) . '</a></li></ol></nav>';
    }

    /** @param array<string, mixed> $settings */
    private static function label(array $settings): string
    {
        $label = is_string($settings['label'] ?? null) ? trim(strip_tags($settings['label'])) : '';
        return $label !== '' ? $label : t('plugin::prime-builder.ui.breadcrumb_default_label');
    }
}
