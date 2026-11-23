<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Hooks\Hooks;
use Prime\Site\Menu\MenuRenderer;
use Throwable;

/** Public CMS menus with a conservative saved fallback. */
final class Navigation
{
    /**
     * @param array<string, mixed> $settings
     * @param (callable(array<mixed>): array<mixed>)|null $publicItems
     */
    public static function render(array $settings, ?MenuRenderer $menus, bool $live, string $path = '/', ?Hooks $hooks = null, ?callable $publicItems = null, int $mobileBreakpoint = 767): string
    {
        $label = is_string($settings['label'] ?? null) ? trim(strip_tags($settings['label'])) : '';
        if ($label === '') {
            $label = t('plugin::prime-builder.widgets.navigation');
        }
        $reference = is_string($settings['menu_id'] ?? null) ? $settings['menu_id'] : '';
        $list = '';
        if ($live && $menus !== null && $hooks !== null && $publicItems !== null && preg_match('/^[1-9][0-9]{0,8}$/D', $reference) === 1) {
            // Run the core's site.menu_items filters first, then check typed targets
            // ourselves: core does not check page ancestors or private taxonomies.
            // The marker confines this temporary filter to this particular render.
            $marker = new \stdClass();
            $filter = static function (mixed $items, mixed $location, mixed $menu, mixed $options) use ($marker, $publicItems): mixed {
                if (!is_array($options) || ($options['prime_builder_navigation'] ?? null) !== $marker) {
                    return $items;
                }
                if (!is_array($items)) {
                    return [];
                }
                try {
                    return $publicItems($items);
                } catch (Throwable) {
                    // Hooks isolate plugin exceptions: fail closed rather than
                    // returning a potentially private menu after an error.
                    return [];
                }
            };
            $hooks->filter('site.menu_items', $filter, PHP_INT_MAX);
            try {
                $list = $menus->renderLocation('', [
                    'menu_id' => (int) $reference,
                    'wrap_nav' => false,
                    'list_class' => 'pb-nav__list',
                    'current_path' => $path,
                    'prime_builder_navigation' => $marker,
                ]);
                $list = self::isolateClasses($list);
            } catch (Throwable) {
                $list = '';
            } finally {
                $hooks->off('site.menu_items', $filter);
            }
        }
        if ($list === '') {
            // Never persist a menu snapshot: a linked entry can later become private.
            $list = '<ul class="pb-nav__list"><li class="pb-nav__item"><a class="pb-nav__link" href="/">'
                . Renderer::escape(t('plugin::prime-builder.ui.navigation_home')) . '</a></li></ul>';
        }
        return '<nav class="pb-nav" aria-label="' . Renderer::escape($label) . '" data-pb-navigation'
            . ' data-pb-nav-breakpoint="' . max(320, min(1999, $mobileBreakpoint)) . '"'
            . ' data-pb-nav-menu="' . Renderer::escape(t('plugin::prime-builder.ui.navigation_mobile')) . '"'
            . ' data-pb-nav-submenu="' . Renderer::escape(t('plugin::prime-builder.ui.navigation_submenu')) . '">'
            . $list . '</nav>';
    }

    /** Prevent the active theme's own menu styles from changing this independent widget. */
    private static function isolateClasses(string $html): string
    {
        $map = [
            'site-nav__item' => 'pb-nav__item',
            'site-nav__item--has-children' => 'pb-nav__item--has-children',
            'site-nav__link' => 'pb-nav__link',
            'site-nav__submenu' => 'pb-nav__submenu',
        ];
        return preg_replace_callback('/\bclass="([^"]*)"/', static function (array $match) use ($map): string {
            $classes = explode(' ', $match[1]);
            foreach ($classes as $index => $class) {
                $classes[$index] = $map[$class] ?? $class;
            }
            return 'class="' . implode(' ', $classes) . '"';
        }, $html) ?? '';
    }
}
