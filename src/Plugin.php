<?php

declare(strict_types=1);

namespace PrimeBuilder;

use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Menu\MenuItem;
use Prime\Admin\Notice;
use Prime\Api\Token\ApiTokenService;
use Prime\Auth\Auth;
use Prime\Auth\Middleware\ApiAuthenticate;
use Prime\Auth\User;
use Prime\Auth\UserRepository;
use Prime\Content\ContentType;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\Entry;
use Prime\Content\EntryQuery;
use Prime\Editor\BlockDefinition;
use Prime\Editor\BlockRegistry;
use Prime\Hooks\Hooks;
use Prime\Http\Kernel;
use Prime\Http\Router;
use Prime\I18n\Translator;
use Prime\Plugins\AbstractPlugin;
use Prime\Plugins\PluginContext;
use Prime\Scheduler\Schedule;
use Prime\Security\Csrf;
use Prime\Security\HtmlSanitizer;
use Prime\Site\PageContext;
use Prime\Site\UrlGenerator;
use Prime\Support\Str;
use Prime\Themes\ThemeAssetRegistry;
use PrimeBuilder\Controller\Dashboard;
use PrimeBuilder\Controller\Documents;
use PrimeBuilder\Controller\Settings;
use PrimeBuilder\Controller\Templates;
use PrimeBuilder\Document\HtmlContent;
use PrimeBuilder\Document\Layout;
use PrimeBuilder\Document\Validator;
use PrimeBuilder\Security\EffectiveApiAuthentication;
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
        if (version_compare($context->app()->version(), '1.0.2', '<')) {
            // Prime 1.0.1 trusts token abilities captured at issuance. Wrap, rather than
            // replace, core authentication; the correction applies to all API routes while
            // this plugin is active. Prime 1.0.2+ already performs this intersection.
            $app = $context->app();
            $app->get(Kernel::class)->registerMiddlewareAlias('api.auth', static fn (): EffectiveApiAuthentication => new EffectiveApiAuthentication(
                new ApiAuthenticate($app->get(ApiTokenService::class), $app->get(Auth::class), $app->get(Csrf::class), $app->get(Translator::class)),
                $app->get(UserRepository::class),
            ));
        }
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
        $htmlContent = HtmlContent::fromAppKey($context->app()->config()->get('app.key'));
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
            validator: static fn (array $data, HtmlSanitizer $sanitizer): array => (new Validator($registry, $sanitizer, $blocks, $originals, html: $htmlContent, requireSealedHtml: true))->validate($data),
            renderer: static fn (array $data, HtmlSanitizer $sanitizer, array $renderContext): string => (new Renderer($registry, $sanitizer, $context->logger(), $content, ($renderContext['entry'] ?? null) instanceof Entry ? $renderContext['entry'] : null, false, $blocks, $originals, $htmlContent))->render($data),
            // Text extraction has no entry context; it never emits HTML and still sanitizes markup.
            textExtractor: static fn (array $data): string => Str::plainText((new Renderer($registry, $context->app()->get(HtmlSanitizer::class), $context->logger(), $content, null, false, $blocks, $originals, $htmlContent, true))->render($data)),
            keywords: ['builder', 'layout'],
        ));
        // The core content service can also save builder blocks. Its owned filters cannot
        // reject by throwing (the hook engine isolates plugin exceptions), so preserve the
        // old content if an unprivileged caller replays a previously signed HTML node.
        $context->hooks()->filter('content.saving', static function (mixed $payload, mixed $existing, mixed $type, mixed $actor) use ($htmlContent, $context): mixed {
            if (!is_array($payload) || !$htmlContent instanceof HtmlContent) {
                return $payload;
            }
            $candidate = is_string($payload['content'] ?? null) ? json_decode($payload['content'], true) : null;
            if (!is_array($candidate) || !HtmlContent::documentContains($candidate)) {
                return $payload;
            }
            if (!$existing instanceof Entry) {
                // Core creates and duplicates before a destination ID exists. Preserve
                // filtered markup as ordinary rich text rather than an unusable seal.
                $candidate = HtmlContent::asTextDocument($candidate);
            } elseif ($actor instanceof User && $actor->can('prime_builder.unfiltered_html')) {
                if ($htmlContent->documentIsBound($candidate, $existing->id)) {
                    return $payload;
                }
                // A privileged core edit may copy an authenticated widget from another entry.
                $candidate = $htmlContent->sealDocument($candidate, $existing->id);
            } elseif ($htmlContent->allowsDocument($candidate, $existing->decodedContent(), $existing->id)) {
                return $payload;
            } else {
                $payload['content'] = $existing->content;
                $payload['content_html'] = $existing->contentHtml;
                $payload['content_text'] = $existing->contentText;
                return $payload;
            }
            $renderer = $context->app()->get(\Prime\Editor\Renderer::class);
            $payload['content'] = json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"version":1,"blocks":[]}';
            $payload['content_html'] = $renderer->renderHtml($candidate, $existing instanceof Entry ? $existing : null, allowUnfilteredHtml: $actor instanceof User && $actor->can('content.unfiltered_html'));
            $payload['content_text'] = $renderer->extractText($candidate);
            return $payload;
        });
        $context->hooks()->filter('admin.menu', static function (mixed $menu): mixed {
            if ($menu instanceof AdminMenu) {
                $menu->add(new MenuItem(id: 'plugin.prime-builder', label: 'plugin::prime-builder.menu', icon: 'layout-dashboard', url: '/admin/prime-builder', position: 60, accent: 'brand', capability: 'prime_builder.edit', group: 'admin.menu.groups.content'));
            }
            return $menu;
        });
        $controller = new Documents($context);
        $dashboard = new Dashboard($context);
        $settings = new Settings($context);
        $templates = new Templates($context);
        $context->router()->adminGroup(static function (Router $router) use ($controller, $dashboard): void {
            $router->get('/', [$dashboard, 'index'])->name('index');
            $router->get('/edit/{id:\d+}', [$controller, 'edit'])->name('edit');
        }, capability: 'prime_builder.edit');
        // Keep the plugin's dedicated settings page and capability; Prime 1.0.2 also
        // enforces section capabilities for settings registered in its general catalogue.
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
        $context->api()->post('/documents/{id}/import', [$controller::class, 'import'], permission: 'prime_builder.templates');
        $context->api()->get('/documents/{id}/live', [$controller::class, 'live'], public: true);
        $context->api()->get('/templates', [$templates::class, 'index'], permission: 'prime_builder.edit');
        $context->api()->post('/templates', [$templates::class, 'create'], permission: 'prime_builder.templates');
        $context->api()->delete('/templates/{id}', [$templates::class, 'delete'], permission: 'prime_builder.templates');
        // PluginContext is not a container service, so API controllers need explicit bindings.
        $context->app()->container()->singleton(Documents::class, static fn (): Documents => $controller);
        $context->app()->container()->singleton(Templates::class, static fn (): Templates => $templates);
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
            if (version_compare($context->app()->version(), '1.0.2', '<')) {
                $checks[] = ['id' => 'prime-builder.legacy-auth', 'group' => 'plugins', 'label' => t('plugin::prime-builder.system.legacy_auth'), 'status' => 'warning', 'value' => t('plugin::prime-builder.system.legacy_auth_warning')];
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
        (new Snapshots($context->app()->storagePath('prime-builder/styles')))->clear();
    }
}
