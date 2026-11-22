<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Site\Menu\MenuRenderer;
use Throwable;

/** Public CMS menus with a conservative saved fallback. */
final class Navigation
{
    /** @param array<string, mixed> $settings */
    public static function render(array $settings, ?MenuRenderer $menus, bool $live, string $path = '/'): string
    {
        $label = is_string($settings['label'] ?? null) ? trim(strip_tags($settings['label'])) : '';
        if ($label === '') {
            $label = t('plugin::prime-builder.widgets.navigation');
        }
        $reference = is_string($settings['menu_id'] ?? null) ? $settings['menu_id'] : '';
        $list = '';
        if ($live && $menus !== null && preg_match('/^[1-9][0-9]{0,8}$/D', $reference) === 1) {
            try {
                // The core renderer resolves only public menu targets and applies its hooks.
                $list = $menus->renderLocation('', [
                    'menu_id' => (int) $reference,
                    'wrap_nav' => false,
                    'list_class' => 'pb-nav__list',
                    'current_path' => $path,
                ]);
                $list = self::isolateClasses($list);
            } catch (Throwable) {
                $list = '';
            }
        }
        if ($list === '') {
            // Never persist a menu snapshot: a linked entry can later become private.
            $list = '<ul class="pb-nav__list"><li class="pb-nav__item"><a class="pb-nav__link" href="/">'
                . Renderer::escape(t('plugin::prime-builder.ui.navigation_home')) . '</a></li></ul>';
        }
        return '<nav class="pb-nav" aria-label="' . Renderer::escape($label) . '" data-pb-navigation'
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
