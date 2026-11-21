<?php

declare(strict_types=1);

namespace PrimeBuilder;

use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Menu\MenuItem;
use Prime\Admin\Notice;
use Prime\Content\ContentType;
use Prime\Editor\BlockDefinition;
use Prime\Hooks\Hooks;
use Prime\Http\Request;
use Prime\Http\Router;
use Prime\Plugins\AbstractPlugin;
use Prime\Plugins\PluginContext;
use Prime\Security\HtmlSanitizer;
use Prime\Settings\SettingsRegistry;
use Prime\Site\PageContext;
use Prime\Themes\ThemeAssetRegistry;
use PrimeBuilder\Controller\Documents;
use PrimeBuilder\Document\Layout;
use PrimeBuilder\Document\Validator;
use PrimeBuilder\Styles\Compiler;
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
            }
            return $registry;
        });
        $context->hooks()->filter('content.editor', static function (mixed $editor, mixed $type = null) use ($context): mixed {
            if (!$type instanceof ContentType) {
                return $editor;
            }
            $key = match ($type->name) { 'page' => 'pages', 'post' => 'posts', default => null };
            if ($key === null) {
                return $editor;
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
        $context->blocks()->register(new BlockDefinition(
            type: 'prime-builder/layout',
            title: 'plugin::prime-builder.block.title',
            description: 'plugin::prime-builder.block.description',
            icon: 'layout-dashboard',
            category: 'plugins',
            validator: static fn (array $data, HtmlSanitizer $sanitizer): array => (new Validator($registry, $sanitizer))->validate($data),
            renderer: static fn (array $data, HtmlSanitizer $sanitizer): string => (new Renderer($registry, $sanitizer, $context->logger()))->render($data),
            textExtractor: static fn (array $data): string => trim(strip_tags((new Renderer($registry, $context->app()->get(HtmlSanitizer::class), $context->logger()))->render($data))),
            keywords: ['builder', 'layout'],
        ));
        $context->hooks()->filter('admin.menu', static function (mixed $menu): mixed {
            if ($menu instanceof AdminMenu) {
                $menu->add(new MenuItem(id: 'plugin.prime-builder', label: 'plugin::prime-builder.menu', icon: 'layout-dashboard', url: '/admin/prime-builder', position: 60, accent: 'brand', capability: 'prime_builder.edit', group: 'admin.menu.groups.content'));
            }
            return $menu;
        });
        $controller = new Documents($context);
        $context->router()->adminGroup(static function (Router $router) use ($controller, $context): void {
            $router->get('/', static fn (): \Prime\Http\Response => \Prime\Http\Response::redirect('/admin/settings/general#prime-builder'))->name('index');
            $router->get('/edit/{id:\d+}', [$controller, 'edit'])->name('edit');
        }, capability: 'prime_builder.edit');
        $context->router()->frontGroup(static function (Router $router) use ($controller): void {
            $router->get('/prime-builder/css/{file}', [$controller, 'stylesheet'])->name('css');
        });
        $context->api()->patch('/documents/{id}', [$controller::class, 'update'], permission: 'prime_builder.edit');
        $context->api()->post('/documents/{id}/render', [$controller::class, 'render'], permission: 'prime_builder.edit');
        // PluginContext is not a container service, so API controllers need an explicit binding.
        $context->app()->container()->singleton(Documents::class, static fn (): Documents => $controller);
        $context->hooks()->on('site.assets', static function (mixed $assets, mixed $page = null) use ($context): void {
            if (!$assets instanceof ThemeAssetRegistry || !$page instanceof PageContext || $page->entry === null) {
                return;
            }
            $data = Layout::fromDocument($page->entry->decodedContent());
            if ($data === null) {
                return;
            }
            $css = (new Compiler())->compile($data);
            $assets->style('prime-builder-base', $context->url('css/public.css'));
            $assets->style('prime-builder-document', '/prime-builder/css/' . $page->entry->id . '-' . substr(hash('sha256', $css), 0, 16) . '.css', ['prime-builder-base']);
        });
        $context->hooks()->filter('system.checks', static function (mixed $checks): mixed {
            if (is_array($checks)) {
                $checks[] = ['id' => 'prime-builder.status', 'group' => 'plugins', 'label' => t('plugin::prime-builder.system.label'), 'status' => 'ok', 'value' => t('plugin::prime-builder.system.active')];
            }
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
