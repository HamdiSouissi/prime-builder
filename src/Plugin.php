<?php

declare(strict_types=1);

namespace PrimeBuilder;

use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Menu\MenuItem;
use Prime\Admin\Notice;
use Prime\Content\ContentType;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\Entry;
use Prime\Content\EntryQuery;
use Prime\Editor\BlockDefinition;
use Prime\Hooks\Hooks;
use Prime\Http\Router;
use Prime\Plugins\AbstractPlugin;
use Prime\Plugins\PluginContext;
use Prime\Security\HtmlSanitizer;
use Prime\Settings\SettingsRegistry;
use Prime\Site\PageContext;
use Prime\Site\UrlGenerator;
use Prime\Themes\ThemeAssetRegistry;
use PrimeBuilder\Controller\Dashboard;
use PrimeBuilder\Controller\Documents;
use PrimeBuilder\Document\Layout;
use PrimeBuilder\Document\Validator;
use PrimeBuilder\Styles\Compiler;
use PrimeBuilder\Styles\Kit;
use PrimeBuilder\Widgets\Content;
use PrimeBuilder\Widgets\Registry;
use PrimeBuilder\Widgets\Renderer;

final class Plugin extends AbstractPlugin
{
    public function register(PluginContext $context): void
    {
        foreach (['edit', 'templates', 'theme_builder', 'forms', 'settings', 'unfiltered_html'] as $cap) {
            $context->capabilities()->register('prime_builder.' . $cap, 'plugin::prime-builder.capabilities.' . $cap);
        }
        $context->hooks()->filter('settings.register', static function (mixed $registry): mixed {
            if ($registry instanceof SettingsRegistry) {
                $registry->section('prime-builder', ['label' => 'plugin::prime-builder.settings.title', 'description' => 'plugin::prime-builder.settings.description', 'icon' => 'layout-dashboard', 'priority' => 210, 'page' => 'general']);
                foreach (['pages' => true, 'posts' => false] as $name => $default) {
                    $registry->add([
                        'name' => 'plugin.prime-builder.' . $name,
                        'label' => 'plugin::prime-builder.settings.' . $name,
                        'description' => 'plugin::prime-builder.settings.' . $name . '_help',
                        'type' => 'toggle', 'section' => 'prime-builder', 'default' => $default, 'rules' => '', 'priority' => 10,
                    ], 'prime-builder');
                }
                foreach ([
                    ['other_types', 'text', '', 'max:500'],
                    ['breakpoint_tablet', 'number', 1024, 'required|min:600|max:2000'],
                    ['breakpoint_mobile', 'number', 767, 'required|min:320|max:1600'],
                ] as [$name, $type, $default, $rules]) {
                    $registry->add([
                        'name' => 'plugin.prime-builder.' . $name,
                        'label' => 'plugin::prime-builder.settings.' . $name,
                        'description' => 'plugin::prime-builder.settings.' . $name . '_help',
                        'type' => $type, 'section' => 'prime-builder', 'default' => $default, 'rules' => $rules, 'priority' => 20,
                    ], 'prime-builder');
                }
                $registry->section('prime-builder-kit', ['label' => 'plugin::prime-builder.settings.kit', 'description' => 'plugin::prime-builder.settings.kit_help', 'icon' => 'palette', 'priority' => 220, 'page' => 'general']);
                foreach ([
                    ['kit_primary', 'color', '#c9441f', 'required'],
                    ['kit_text', 'color', '#1a1d29', 'required'],
                    ['kit_font_size', 'number', 16, 'required|min:12|max:28'],
                ] as [$name, $type, $default, $rules]) {
                    $registry->add([
                        'name' => 'plugin.prime-builder.' . $name,
                        'label' => 'plugin::prime-builder.settings.' . $name,
                        'type' => $type, 'section' => 'prime-builder-kit', 'default' => $default, 'rules' => $rules, 'priority' => 10,
                    ], 'prime-builder');
                }
            }
            return $registry;
        });
        $context->hooks()->filter('content.editor', static function (mixed $editor, mixed $type = null) use ($context): mixed {
            if (!$type instanceof ContentType) {
                return $editor;
            }
            $key = match ($type->name) {
                'page' => 'pages', 'post' => 'posts', default => null
            };
            if ($key === null) {
                $other = $context->settings()->get('other_types', '');
                $names = is_string($other) ? array_map('trim', explode(',', $other)) : [];
                return in_array($type->name, $names, true) ? 'prime-builder' : $editor;
            }
            $value = $context->settings()->get($key, $key === 'pages');
            return in_array($value, [true, 1, '1', 'true'], true) ? 'prime-builder' : $editor;
        });
        $context->editors()->register('prime-builder', 'plugin::prime-builder.editor.label', static function (array $state) use ($context): string {
            return $context->views()->render('prime-builder::admin/alternate', [
                'entry' => $state['entry'], 'contentJson' => $state['contentJson'],
            ]);
        }, styles: ['css/alternate.css']);

        $registry = new Registry($context->app()->get(Hooks::class));
        $content = new Content($context->app()->get(EntryQuery::class), $context->app()->get(ContentTypeRegistry::class), $context->app()->get(UrlGenerator::class));
        $context->app()->container()->singleton(Content::class, static fn (): Content => $content);
        foreach (['content.saved', 'content.updated', 'content.published', 'content.deleted', 'content.trashed', 'content.restored', 'content.status_changed'] as $event) {
            $context->hooks()->on($event, static function () use ($content): void {
                $content->invalidate();
            });
        }
        // Prime 1.0's EntryView uses a raw `"dynamic"` substring as a dynamic-block
        // sentinel. A plain builder field whose value is exactly that word must not
        // trigger a live re-render (which would erase the fallback when disabled).
        // Escaping one character changes only the JSON representation, never its value.
        $context->hooks()->filter('content.saving', static function (mixed $payload): mixed {
            if (!is_array($payload) || !is_string($payload['content'] ?? null)) {
                return $payload;
            }
            $document = json_decode($payload['content'], true);
            $blocks = is_array($document) ? ($document['blocks'] ?? null) : null;
            if (is_array($blocks) && count($blocks) === 1 && is_array($blocks[0] ?? null) && ($blocks[0]['type'] ?? null) === 'prime-builder/layout') {
                $payload['content'] = str_replace('"dynamic"', '"\\u0064ynamic"', $payload['content']);
            }
            return $payload;
        });
        $context->blocks()->register(new BlockDefinition(
            type: 'prime-builder/layout',
            title: 'plugin::prime-builder.block.title',
            description: 'plugin::prime-builder.block.description',
            icon: 'layout-dashboard',
            category: 'plugins',
            validator: static fn (array $data, HtmlSanitizer $sanitizer): array => (new Validator($registry, $sanitizer))->validate($data),
            renderer: static fn (array $data, HtmlSanitizer $sanitizer, array $renderContext): string => (new Renderer($registry, $sanitizer, $context->logger(), $content, ($renderContext['entry'] ?? null) instanceof Entry ? $renderContext['entry'] : null))->render($data),
            textExtractor: static fn (array $data): string => trim(strip_tags((new Renderer($registry, $context->app()->get(HtmlSanitizer::class), $context->logger(), $content))->render($data))),
            keywords: ['builder', 'layout'],
        ));
        $context->hooks()->filter('admin.menu', static function (mixed $menu): mixed {
            if ($menu instanceof AdminMenu) {
                $menu->add(new MenuItem(id: 'plugin.prime-builder', label: 'plugin::prime-builder.menu', icon: 'layout-dashboard', url: '/admin/prime-builder', position: 60, accent: 'brand', capability: 'prime_builder.edit', group: 'admin.menu.groups.content'));
            }
            return $menu;
        });
        $controller = new Documents($context);
        $dashboard = new Dashboard($context);
        $context->router()->adminGroup(static function (Router $router) use ($controller, $dashboard): void {
            $router->get('/', [$dashboard, 'index'])->name('index');
            $router->get('/edit/{id:\d+}', [$controller, 'edit'])->name('edit');
        }, capability: 'prime_builder.edit');
        $context->router()->frontGroup(static function (Router $router) use ($controller): void {
            $router->get('/prime-builder/css/{file}', [$controller, 'stylesheet'])->name('css');
            $router->get('/prime-builder/kit/{file}', [$controller, 'kitStylesheet'])->name('kit');
        });
        $context->api()->patch('/documents/{id}', [$controller::class, 'update'], permission: 'prime_builder.edit');
        $context->api()->post('/documents/{id}/render', [$controller::class, 'render'], permission: 'prime_builder.edit');
        $context->api()->get('/documents/{id}/live', [$controller::class, 'live'], public: true);
        // PluginContext is not a container service, so API controllers need an explicit binding.
        $context->app()->container()->singleton(Documents::class, static fn (): Documents => $controller);
        $context->hooks()->on('site.assets', static function (mixed $assets, mixed $page = null) use ($context): void {
            if (!$assets instanceof ThemeAssetRegistry || !$page instanceof PageContext) {
                return;
            }
            $kitCss = Kit::fromSettings($context->settings());
            $assets->style('prime-builder-base', $context->url('css/public.css'));
            $assets->style('prime-builder-kit', '/prime-builder/kit/' . substr(hash('sha256', $kitCss), 0, 16) . '.css', ['prime-builder-base']);
            if ($page->entry === null) {
                return;
            }
            $data = Layout::fromDocument($page->entry->decodedContent());
            if ($data === null) {
                return;
            }
            $css = (new Compiler((int) $context->settings()->get('breakpoint_tablet', 1024), (int) $context->settings()->get('breakpoint_mobile', 767)))->compile($data);
            $assets->style('prime-builder-document', '/prime-builder/css/' . $page->entry->id . '-' . substr(hash('sha256', $css), 0, 16) . '.css', ['prime-builder-kit']);
            $types = [];
            $collect = static function (array $elements) use (&$collect, &$types): void {
                foreach ($elements as $element) {
                    if (!is_array($element)) {
                        continue;
                    }
                    if (is_string($element['type'] ?? null)) {
                        $types[$element['type']] = true;
                    }
                    if (is_array($element['children'] ?? null)) {
                        $collect($element['children']);
                    }
                }
            };
            $collect(is_array($data['elements'] ?? null) ? $data['elements'] : []);
            $definitions = (new Registry($context->app()->get(Hooks::class)))->all();
            $registeredScripts = [];
            foreach (array_keys($types) as $type) {
                $script = $definitions[$type]['script'] ?? null;
                if (is_string($script) && !isset($registeredScripts[$script]) && !str_contains($script, '..') && preg_match('#^/assets/plugins/[a-z0-9-]+/[a-zA-Z0-9_./-]+\.js(?:\?[a-zA-Z0-9_=&.-]+)?$#', $script) === 1) {
                    $assets->script('prime-builder-widget-' . $type, $script);
                    $registeredScripts[$script] = true;
                }
            }
        });
        $context->hooks()->on('settings.updated', static function (mixed $name) use ($context): void {
            if (is_string($name) && str_starts_with($name, 'plugin.prime-builder.')) {
                $context->app()->get(\Prime\Cache\CacheManager::class)->clearPages();
            }
        });
        $context->hooks()->filter('system.checks', static function (mixed $checks) use ($context): mixed {
            if (!is_array($checks)) {
                return $checks;
            }
            $checks[] = ['id' => 'prime-builder.status', 'group' => 'plugins', 'label' => t('plugin::prime-builder.system.label'), 'status' => 'ok', 'value' => t('plugin::prime-builder.system.active')];
            $assetsPresent = is_file($context->path('assets/js/editor.js')) && is_file($context->path('assets/css/editor.css')) && is_file($context->path('assets/css/public.css'));
            $checks[] = ['id' => 'prime-builder.assets', 'group' => 'plugins', 'label' => t('plugin::prime-builder.system.assets'), 'status' => $assetsPresent ? 'ok' : 'error', 'value' => t($assetsPresent ? 'plugin::prime-builder.system.present' : 'plugin::prime-builder.system.missing')];
            $tablet = (int) $context->settings()->get('breakpoint_tablet', 1024);
            $mobile = (int) $context->settings()->get('breakpoint_mobile', 767);
            $breakpointsValid = $tablet >= 600 && $tablet <= 2000 && $mobile >= 320 && $mobile < $tablet;
            $checks[] = ['id' => 'prime-builder.breakpoints', 'group' => 'plugins', 'label' => t('plugin::prime-builder.system.breakpoints'), 'status' => $breakpointsValid ? 'ok' : 'warning', 'value' => t($breakpointsValid ? 'plugin::prime-builder.system.valid' : 'plugin::prime-builder.system.adjusted')];
            return $checks;
        });
    }

    public function activate(PluginContext $context): void
    {
        $context->notices()->add(id: 'welcome', type: Notice::TYPE_SUCCESS, title: 'plugin::prime-builder.notice.title', message: 'plugin::prime-builder.notice.message', capability: 'prime_builder.edit', dismissible: true);
    }

    public function deactivate(PluginContext $context): void
    {
        $context->notices()->remove('welcome');
    }

    public function uninstall(PluginContext $context): void
    {
        $context->notices()->clearAll();
    }
}
