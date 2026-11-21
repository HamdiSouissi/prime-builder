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
use Prime\Editor\BlockRegistry;
use Prime\Hooks\Hooks;
use Prime\Http\Router;
use Prime\Plugins\AbstractPlugin;
use Prime\Plugins\PluginContext;
use Prime\Scheduler\Schedule;
use Prime\Security\HtmlSanitizer;
use Prime\Site\PageContext;
use Prime\Site\UrlGenerator;
use Prime\Themes\ThemeAssetRegistry;
use PrimeBuilder\Controller\Dashboard;
use PrimeBuilder\Controller\Documents;
use PrimeBuilder\Controller\Settings;
use PrimeBuilder\Document\Layout;
use PrimeBuilder\Document\Validator;
use PrimeBuilder\Styles\Compiler;
use PrimeBuilder\Styles\Kit;
use PrimeBuilder\Styles\Snapshots;
use PrimeBuilder\Widgets\Content;
use PrimeBuilder\Widgets\OriginalContent;
use PrimeBuilder\Widgets\Registry;
use PrimeBuilder\Widgets\Renderer;

final class Plugin extends AbstractPlugin
{
    public function register(PluginContext $context): void
    {
        foreach (['edit', 'templates', 'theme_builder', 'forms', 'settings', 'unfiltered_html'] as $cap) {
            $context->capabilities()->register('prime_builder.' . $cap, 'plugin::prime-builder.capabilities.' . $cap);
        }
        $context->hooks()->filter('content.editor', static function (mixed $editor, mixed $type = null) use ($context): mixed {
            if (!$type instanceof ContentType) {
                return $editor;
            }
            $key = match ($type->name) {
                'page' => 'pages', 'post' => 'posts', default => null
            };
            if ($key === null) {
                $other = Configuration::get($context->settings(), 'other_types', '');
                $names = is_string($other) ? array_map('trim', explode(',', $other)) : [];
                return in_array($type->name, $names, true) ? 'prime-builder' : $editor;
            }
            $value = Configuration::get($context->settings(), $key, $key === 'pages');
            return in_array($value, [true, 1, '1', 'true'], true) ? 'prime-builder' : $editor;
        });
        $context->editors()->register('prime-builder', 'plugin::prime-builder.editor.label', static function (array $state) use ($context): string {
            return $context->views()->render('prime-builder::admin/alternate', [
                'entry' => $state['entry'], 'contentJson' => $state['contentJson'],
            ]);
        }, styles: ['css/alternate.css']);

        $registry = new Registry($context->app()->get(Hooks::class));
        $blocks = $context->app()->get(BlockRegistry::class);
        $originals = OriginalContent::fromAppKey($context->app()->config()->get('app.key'));
        $content = new Content($context->app()->get(EntryQuery::class), $context->app()->get(ContentTypeRegistry::class), $context->app()->get(UrlGenerator::class));
        $context->app()->container()->singleton(Content::class, static fn (): Content => $content);
        foreach (['content.saved', 'content.updated', 'content.published', 'content.deleted', 'content.trashed', 'content.restored', 'content.status_changed'] as $event) {
            $context->hooks()->on($event, static function () use ($content): void {
                $content->invalidate();
            });
        }
        $context->blocks()->register(new BlockDefinition(
            type: 'prime-builder/layout',
            title: 'plugin::prime-builder.block.title',
            description: 'plugin::prime-builder.block.description',
            icon: 'layout-dashboard',
            category: 'plugins',
            validator: static fn (array $data, HtmlSanitizer $sanitizer): array => (new Validator($registry, $sanitizer, $blocks, $originals))->validate($data),
            renderer: static fn (array $data, HtmlSanitizer $sanitizer, array $renderContext): string => (new Renderer($registry, $sanitizer, $context->logger(), $content, ($renderContext['entry'] ?? null) instanceof Entry ? $renderContext['entry'] : null, false, $blocks, $originals))->render($data),
            textExtractor: static fn (array $data): string => trim(strip_tags((new Renderer($registry, $context->app()->get(HtmlSanitizer::class), $context->logger(), $content, null, false, $blocks, $originals))->render($data))),
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
        $settings = new Settings($context);
        $context->router()->adminGroup(static function (Router $router) use ($controller, $dashboard): void {
            $router->get('/', [$dashboard, 'index'])->name('index');
            $router->get('/edit/{id:\d+}', [$controller, 'edit'])->name('edit');
        }, capability: 'prime_builder.edit');
        // The CMS settings screens/API only check settings.manage, not a section's capability.
        // A separate route prevents that broader permission from bypassing our settings capability.
        $context->router()->adminGroup(static function (Router $router) use ($settings): void {
            $router->get('/settings', [$settings, 'show'])->name('settings');
            $router->post('/settings', [$settings, 'save'])->name('settings.save');
        }, capability: 'prime_builder.settings');
        $context->router()->frontGroup(static function (Router $router) use ($controller): void {
            $router->get('/prime-builder/css/{file}', [$controller, 'stylesheet'])->name('css');
            $router->get('/prime-builder/kit/{file}', [$controller, 'kitStylesheet'])->name('kit');
        });
        $context->api()->patch('/documents/{id}', [$controller::class, 'update'], permission: 'prime_builder.edit');
        $context->api()->post('/documents/{id}/render', [$controller::class, 'render'], permission: 'prime_builder.edit');
        $context->api()->get('/documents/{id}/live', [$controller::class, 'live'], public: true);
        // PluginContext is not a container service, so API controllers need an explicit binding.
        $context->app()->container()->singleton(Documents::class, static fn (): Documents => $controller);
        $context->hooks()->filter('scheduler.register', static function (mixed $schedule) use ($context): mixed {
            if ($schedule instanceof Schedule) {
                $schedule->call(
                    name: 'prime-builder.prune-styles',
                    action: static fn (): int => (new Snapshots($context->app()->storagePath('prime-builder/styles')))->prune(),
                    expression: '30 4 * * *',
                    description: t('plugin::prime-builder.scheduler.prune_styles'),
                    owner: 'prime-builder',
                );
            }
            return $schedule;
        });
        $context->hooks()->on('site.assets', static function (mixed $assets, mixed $page = null) use ($context): void {
            if (!$assets instanceof ThemeAssetRegistry || !$page instanceof PageContext) {
                return;
            }
            $kitCss = Kit::fromSettings($context->settings());
            $snapshots = new Snapshots($context->app()->storagePath('prime-builder/styles'));
            $kitHash = substr(hash('sha256', $kitCss), 0, 16);
            $snapshots->remember('kit-' . $kitHash . '.css', $kitCss);
            $assets->style('prime-builder-base', $context->url('css/public.css'));
            $assets->style('prime-builder-kit', '/prime-builder/kit/' . $kitHash . '.css', ['prime-builder-base']);
            if ($page->entry === null) {
                return;
            }
            $data = Layout::fromDocument($page->entry->decodedContent());
            if ($data === null) {
                return;
            }
            $css = (new Compiler((int) Configuration::get($context->settings(), 'breakpoint_tablet', 1024), (int) Configuration::get($context->settings(), 'breakpoint_mobile', 767)))->compile($data);
            $file = $page->entry->id . '-' . substr(hash('sha256', $css), 0, 16) . '.css';
            $type = $context->app()->get(ContentTypeRegistry::class)->get($page->entry->type);
            if (!$page->isPreview && $type !== null && $type->public && \Prime\Site\UrlResolver::isPubliclyVisible($page->entry)) {
                $snapshots->remember($file, $css);
            }
            $assets->style('prime-builder-document', '/prime-builder/css/' . $file, ['prime-builder-kit']);
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
            $tablet = (int) Configuration::get($context->settings(), 'breakpoint_tablet', 1024);
            $mobile = (int) Configuration::get($context->settings(), 'breakpoint_mobile', 767);
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
        (new Snapshots($context->app()->storagePath('prime-builder/styles')))->clear();
    }
}
