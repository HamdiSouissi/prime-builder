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
use Prime\Content\EntryRepository;
use Prime\Content\TaxonomyRegistry;
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
use Prime\Site\Zone\DynamicBlockRegistry;
use Prime\Site\Zone\ZoneRepository;
use Prime\Support\Str;
use Prime\Themes\ThemeAssetRegistry;
use Prime\Themes\ThemeManager;
use PrimeBuilder\Controller\ContactForms;
use PrimeBuilder\Controller\Dashboard;
use PrimeBuilder\Controller\Documents;
use PrimeBuilder\Controller\Settings;
use PrimeBuilder\Controller\Templates;
use PrimeBuilder\Controller\ThemeBuilder;
use PrimeBuilder\Document\HtmlContent;
use PrimeBuilder\Document\Layout;
use PrimeBuilder\Document\Validator;
use PrimeBuilder\Forms\RateLimit;
use PrimeBuilder\Forms\Submissions;
use PrimeBuilder\Security\EffectiveApiAuthentication;
use PrimeBuilder\Styles\Compiler;
use PrimeBuilder\Styles\Kit;
use PrimeBuilder\Styles\Snapshots;
use PrimeBuilder\Theme\Partials;
use PrimeBuilder\Widgets\Assets;
use PrimeBuilder\Widgets\Content;
use PrimeBuilder\Widgets\OriginalContent;
use PrimeBuilder\Widgets\Registry;
use PrimeBuilder\Widgets\Renderer;
use PrimeBuilder\Widgets\SiteIdentity;

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
        $partials = new Partials($context);
        $identity = new SiteIdentity($context->app());
        $content = new Content(
            $context->app()->get(EntryQuery::class),
            $context->app()->get(ContentTypeRegistry::class),
            $context->app()->get(UrlGenerator::class),
            $context->app()->get(ThemeManager::class),
            $context->app()->get(ZoneRepository::class),
            $context->app()->get(DynamicBlockRegistry::class),
            $context->app()->get(TaxonomyRegistry::class),
            $context->app()->get(EntryRepository::class),
            $context->app()->get(\Prime\Site\Menu\MenuRenderer::class),
            $context->app()->get(\Prime\Site\Seo\SeoManager::class),
            $context->app()->get(\Prime\Site\PermalinkSettings::class),
            $identity,
            $context->app()->get(\Prime\Site\View\EntryViewFactory::class),
            $context->app()->get(\Prime\Auth\UserRepository::class),
            $context->app()->get(Hooks::class),
            $context->app()->get(\Prime\Content\TermRepository::class),
        );
        $context->app()->container()->singleton(Content::class, static fn (): Content => $content);
        foreach (['settings.updated', 'theme.activated'] as $event) {
            $context->hooks()->on($event, static function () use ($identity): void {
                $identity->invalidate();
            });
        }
        foreach (['media.updated', 'media.deleted'] as $event) {
            $context->hooks()->on($event, static function () use ($identity, $content, $partials, $context): void {
                $identity->invalidate();
                $content->invalidate();
                // Core settings and theme changes purge pages, but media changes do
                // not. A logo in a theme snapshot can appear on every cached page.
                if ($partials->usesWidget('site_logo')) {
                    $context->app()->get(\Prime\Cache\PageCache::class)->purgeAll();
                }
            });
        }
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
        $context->hooks()->filter('site.partial', static function (mixed $previous, mixed $name, mixed $page) use ($context, $partials, $registry, $content, $blocks, $originals, $htmlContent): mixed {
            if (is_string($previous) || !is_string($name) || !in_array($name, ['header', 'footer'], true) || !$page instanceof PageContext) {
                return $previous;
            }
            $layout = $partials->layout($name, $page);
            if ($layout === null) {
                return $previous;
            }
            // Only validated library snapshots can reach here; HTML is still filtered.
            $renderer = new Renderer($registry, $context->app()->get(\Prime\Security\HtmlSanitizer::class), $context->logger(), $content, null, false, $blocks, $originals, $htmlContent, true, $page);
            return '<' . $name . ' class="pb-theme-' . $name . '">' . $renderer->render($layout) . '</' . $name . '>';
        });
        // Contextual snapshots depend on routes, terms, profiles, media and other
        // entries. Core has no complete invalidation events for all dependencies,
        // so favour freshness over page caching while any such widget is installed.
        $context->hooks()->filter('cache.page.cacheable', static function (mixed $cacheable) use ($partials): mixed {
            if ($cacheable === true && $partials->usesAnyWidget([
                'archive_title', 'archive_list', 'entry_title', 'entry_excerpt', 'entry_content', 'entry_featured_image',
                'entry_info', 'entry_navigation', 'entry_author', 'breadcrumbs', 'navigation',
            ])) {
                return false;
            }
            return $cacheable;
        });
        $context->hooks()->filter('admin.menu', static function (mixed $menu): mixed {
            if ($menu instanceof AdminMenu) {
                $menu->add(new MenuItem(id: 'plugin.prime-builder', label: 'plugin::prime-builder.menu', icon: 'layout-dashboard', url: '/admin/prime-builder', position: 60, accent: 'brand', capability: 'prime_builder.edit', group: 'admin.menu.groups.content'));
                $menu->add(new MenuItem(id: 'plugin.prime-builder-theme', label: 'plugin::prime-builder.theme.title', icon: 'layout-dashboard', url: '/admin/prime-builder/theme', position: 61, accent: 'brand', capability: 'prime_builder.theme_builder', group: 'admin.menu.groups.content'));
                $menu->add(new MenuItem(id: 'plugin.prime-builder-submissions', label: 'plugin::prime-builder.forms.inbox', icon: 'inbox', url: '/admin/prime-builder/submissions', position: 62, accent: 'brand', capability: 'prime_builder.forms', group: 'admin.menu.groups.content'));
            }
            return $menu;
        });
        $controller = new Documents($context);
        $dashboard = new Dashboard($context);
        $settings = new Settings($context);
        $templates = new Templates($context);
        $theme = new ThemeBuilder($context);
        $forms = new ContactForms($context);
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
        $context->router()->adminGroup(static function (Router $router) use ($theme): void {
            $router->get('/theme', [$theme, 'show'])->name('theme');
            $router->post('/theme', [$theme, 'save'])->name('theme.save');
        }, capability: 'prime_builder.theme_builder');
        $context->router()->adminGroup(static function (Router $router) use ($forms): void {
            $router->get('/submissions', [$forms, 'index'])->name('submissions');
            $router->post('/submissions/{id:\d+}/delete', [$forms, 'delete'])->name('submissions.delete');
        }, capability: 'prime_builder.forms');
        $context->router()->frontGroup(static function (Router $router) use ($controller, $theme): void {
            $router->get('/prime-builder/css/{file}', [$controller, 'stylesheet'])->name('css');
            $router->get('/prime-builder/kit/{file}', [$controller, 'kitStylesheet'])->name('kit');
            $router->get('/prime-builder/theme/{file}', [$theme, 'stylesheet'])->name('theme.css');
        });
        $context->api()->patch('/documents/{id}', [$controller::class, 'update'], permission: 'prime_builder.edit');
        $context->api()->post('/documents/{id}/render', [$controller::class, 'render'], permission: 'prime_builder.edit');
        $context->api()->post('/documents/{id}/import', [$controller::class, 'import'], permission: 'prime_builder.templates');
        $context->api()->get('/documents/{id}/live', [$controller::class, 'live'], public: true);
        $context->api()->get('/forms/{id}/start', [$forms::class, 'start'], public: true);
        $context->api()->post('/forms/{id}/submit', [$forms::class, 'submit'], public: true);
        $context->api()->get('/templates', [$templates::class, 'index'], permission: 'prime_builder.edit');
        $context->api()->post('/templates', [$templates::class, 'create'], permission: 'prime_builder.templates');
        $context->api()->delete('/templates/{id}', [$templates::class, 'delete'], permission: 'prime_builder.templates');
        // PluginContext is not a container service, so API controllers need explicit bindings.
        $context->app()->container()->singleton(Documents::class, static fn (): Documents => $controller);
        $context->app()->container()->singleton(Templates::class, static fn (): Templates => $templates);
        $context->app()->container()->singleton(ContactForms::class, static fn (): ContactForms => $forms);
        $context->hooks()->filter('scheduler.register', static function (mixed $schedule) use ($context): mixed {
            if ($schedule instanceof Schedule) {
                $schedule->call(
                    name: 'prime-builder.prune-styles',
                    action: static fn (): int => (new Snapshots($context->app()->storagePath('prime-builder/styles')))->prune(),
                    expression: '30 4 * * *',
                    description: t('plugin::prime-builder.scheduler.prune_styles'),
                    owner: 'prime-builder',
                );
                $schedule->call(
                    name: 'prime-builder.purge-submissions',
                    action: static function () use ($context): int {
                        $now = time();
                        return (new Submissions($context->db()))->purge($now) + RateLimit::purge($context->db(), $now);
                    },
                    expression: '15 4 * * *',
                    description: t('plugin::prime-builder.scheduler.purge_submissions'),
                    owner: 'prime-builder',
                );
            }
            return $schedule;
        });
        // The CSP hook runs after site.assets during a page render. Reset on every page,
        // then include only the exact map origin if a map occurs in the entry or partials.
        $hasMap = false;
        $context->hooks()->on('site.assets', static function (mixed $assets, mixed $page = null) use ($context, &$hasMap): void {
            if (!$assets instanceof ThemeAssetRegistry || !$page instanceof PageContext) {
                return;
            }
            $hasMap = false;
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
            $hasMap = isset($types['map']);
            Assets::register($assets, $types, (new Registry($context->app()->get(Hooks::class)))->all());
            $assets->style('prime-builder-document', '/prime-builder/css/' . $file, ['prime-builder-kit']);
        });
        $context->hooks()->on('site.assets', static function (mixed $assets, mixed $page = null) use ($context, $partials, $registry, &$hasMap): void {
            if (!$assets instanceof ThemeAssetRegistry || !$page instanceof PageContext) {
                return;
            }
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
            foreach (['header', 'footer'] as $slot) {
                $layout = $partials->layout($slot, $page);
                if ($layout === null) {
                    continue;
                }
                $css = (new Compiler((int) Configuration::get($context->settings(), 'breakpoint_tablet', 1024), (int) Configuration::get($context->settings(), 'breakpoint_mobile', 767)))->compile($layout);
                $file = substr(hash('sha256', $css), 0, 16) . '.css';
                (new Snapshots($context->app()->storagePath('prime-builder/styles')))->remember('theme-' . $file, $css);
                $assets->style('prime-builder-theme-' . $slot, '/prime-builder/theme/' . $file, ['prime-builder-kit']);
                $collect(is_array($layout['elements'] ?? null) ? $layout['elements'] : []);
            }
            $hasMap = $hasMap || isset($types['map']);
            $definitions = $registry->all();
            // These widgets are fully resolved in a theme partial; there is no
            // data-pb-live endpoint for the snapshot and no refresh script needed.
            foreach (['site_title', 'site_logo', 'entry_title', 'entry_excerpt', 'entry_featured_image', 'entry_info', 'entry_navigation', 'entry_author', 'breadcrumbs', 'search_form'] as $type) {
                if (($definitions[$type]['script'] ?? null) === '/assets/plugins/prime-builder/js/content.js') {
                    unset($definitions[$type]['script']);
                }
            }
            Assets::register($assets, $types, $definitions);
        });
        $context->hooks()->filter('site.csp', static function (mixed $directives) use (&$hasMap): mixed {
            if (!$hasMap || !is_array($directives)) {
                return $directives;
            }
            $current = $directives['frame-src'] ?? $directives['default-src'] ?? null;
            if (!is_string($current) || trim($current) === '') {
                return $directives;
            }
            $sources = preg_split('/\s+/', trim($current)) ?: [];
            if (in_array("'none'", $sources, true)) {
                return $directives;
            }
            if (!in_array('https://www.openstreetmap.org', $sources, true)) {
                $directives['frame-src'] = $current . ' https://www.openstreetmap.org';
            }
            return $directives;
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
