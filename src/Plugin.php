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
use Prime\Http\Request;
use Prime\Http\Router;
use Prime\I18n\Translator;
use Prime\Jobs\Job;
use Prime\Jobs\JobHandlerRegistry;
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
use PrimeBuilder\Controller\ThemeModels;
use PrimeBuilder\Document\HtmlContent;
use PrimeBuilder\Document\Layout;
use PrimeBuilder\Document\MediaReferences;
use PrimeBuilder\Document\Validator;
use PrimeBuilder\Dynamic\CacheGuard;
use PrimeBuilder\Dynamic\RenderScope;
use PrimeBuilder\Dynamic\RenderState;
use PrimeBuilder\Forms\FileMaintenance;
use PrimeBuilder\Forms\Notifications;
use PrimeBuilder\Forms\PrivateFiles;
use PrimeBuilder\Forms\RateLimit;
use PrimeBuilder\Forms\Submissions;
use PrimeBuilder\Popup\Popups;
use PrimeBuilder\Security\EffectiveApiAuthentication;
use PrimeBuilder\Styles\Compiler;
use PrimeBuilder\Styles\Kit;
use PrimeBuilder\Styles\Snapshots;
use PrimeBuilder\Styles\ZoneLayouts;
use PrimeBuilder\Theme\GlobalWidgets;
use PrimeBuilder\Theme\LiveEntry;
use PrimeBuilder\Theme\Models;
use PrimeBuilder\Theme\PageRequest;
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
        $mediaReferences = new MediaReferences(
            $context->db(),
            $context->app()->get(\Prime\Media\MediaConfig::class)->urlPrefix(),
            $context->app()->get(UrlGenerator::class)->absolute('/'),
        );
        $context->app()->container()->singleton(MediaReferences::class, static fn (): MediaReferences => $mediaReferences);
        $globals = new GlobalWidgets($context);
        $context->app()->container()->singleton(GlobalWidgets::class, static fn (): GlobalWidgets => $globals);
        $partials = new Partials($context);
        $popups = new Popups($context);
        $zoneLayouts = new ZoneLayouts($context->app()->get(ThemeManager::class), $context->app()->get(ZoneRepository::class), $context->db());
        $renderState = new RenderState();
        $context->app()->container()->instance(RenderState::class, $renderState);
        $identity = new SiteIdentity($context->app());
        $context->app()->container()->singleton(SiteIdentity::class, static fn (): SiteIdentity => $identity);
        $pageRequest = new PageRequest();
        $context->app()->container()->singleton(PageRequest::class, static fn (): PageRequest => $pageRequest);
        $dynamicScope = new RenderScope();
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
            $context->settings(),
            $dynamicScope,
        );
        $context->app()->container()->singleton(Content::class, static fn (): Content => $content);
        foreach (['settings.updated', 'theme.activated'] as $event) {
            $context->hooks()->on($event, static function () use ($identity): void {
                $identity->invalidate();
            });
        }
        // Core settings may affect a model on every route, and the core's
        // settings subscriber only flushes data-cache namespaces, not pages.
        $context->hooks()->on('settings.updated', static function (mixed $name) use ($context): void {
            if (!is_string($name) || !str_starts_with($name, 'plugin.prime-builder.')) {
                $context->app()->get(\Prime\Cache\PageCache::class)->purgeAll();
            }
        });
        foreach (['media.updated', 'media.deleted'] as $event) {
            $context->hooks()->on($event, static function () use ($identity, $content, $context): void {
                $identity->invalidate();
                $content->invalidate();
                // Models and snapshots can expose media on unrelated pages.
                $context->app()->get(\Prime\Cache\PageCache::class)->purgeAll();
            });
        }
        // A rendered entry or model can depend on another entry, menu, author
        // or term. Purge on mutations, without scanning layouts on page views.
        $purgeContextualPages = static function () use ($context): void {
            $context->app()->get(\Prime\Cache\PageCache::class)->purgeAll();
        };
        foreach (['content.saved', 'content.updated', 'content.published', 'content.deleted', 'content.trashed', 'content.restored', 'content.status_changed'] as $event) {
            $context->hooks()->on($event, static function () use ($content, $purgeContextualPages): void {
                $content->invalidate();
                $purgeContextualPages();
            });
        }
        foreach (['menu.updated', 'menu.item_created', 'menu.item_deleted'] as $event) {
            $context->hooks()->on($event, $purgeContextualPages);
        }
        // The observed CMS menu editor calls its repository directly and emits
        // none of those events. Cover state-changing admin/API HTTP requests as
        // a conservative fallback, including profile and taxonomy edits.
        $context->hooks()->filter('http.response', static function (mixed $response, mixed $request) use ($purgeContextualPages): mixed {
            if ($request instanceof Request && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
                && (str_starts_with($request->path(), '/admin/') || str_starts_with($request->path(), '/api/v1/'))) {
                $purgeContextualPages();
            }
            return $response;
        });
        $context->blocks()->register(new BlockDefinition(
            type: 'prime-builder/layout',
            title: 'plugin::prime-builder.block.title',
            description: 'plugin::prime-builder.block.description',
            icon: 'layout-dashboard',
            category: 'plugins',
            validator: static fn (array $data, HtmlSanitizer $sanitizer): array => (new Validator($registry, $sanitizer, $blocks, $originals, html: $htmlContent, requireSealedHtml: true, mediaReferences: $mediaReferences))->validate($data),
            renderer: static function (array $data, HtmlSanitizer $sanitizer, array $renderContext) use ($registry, $context, $content, $blocks, $originals, $htmlContent, $mediaReferences, $globals, $dynamicScope, $pageRequest, $renderState): string {
                $entry = ($renderContext['entry'] ?? null) instanceof Entry ? $renderContext['entry'] : null;
                // The CMS invokes this same callback while saving entries and zones.
                // Runtime context is only supplied by our explicit zone-render scope.
                $page = $entry === null ? $dynamicScope->page() : null;
                // Native zones keep their safe static fallback, but a date rule
                // still marks the current response. No dynamic values are exposed
                // during a save; scoped copies resolve them only at render time.
                $renderer = new Renderer($registry, $sanitizer, $context->logger(), $content, $entry, false, $blocks, $originals, $htmlContent, mediaReferences: $mediaReferences, dynamicPlugin: $page !== null ? $context : null, request: $page !== null ? ($dynamicScope->request() ?? $pageRequest->request($page)) : null, dynamicPageContext: $page, cacheState: $entry === null ? $renderState : null);
                return $renderer->render($globals->expand($data));
            },
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
        // A public plugin page and entry preview can render without emitting
        // site.context. Scope the request to the route, including nested calls,
        // rather than retaining one request's pagination for the next.
        $context->app()->get(Kernel::class)->addRouteMiddleware($pageRequest);
        $context->hooks()->on('site.context', $pageRequest->resolved(...));
        $context->hooks()->filter('site.partial', static function (mixed $previous, mixed $name, mixed $page) use ($context, $partials, $registry, $content, $blocks, $originals, $htmlContent, $mediaReferences, $pageRequest): mixed {
            if (is_string($previous) || !is_string($name) || !in_array($name, ['header', 'footer'], true) || !$page instanceof PageContext) {
                return $previous;
            }
            // On the authenticated model canvas, replace only the requested
            // partial, never a guessed element in an arbitrary theme's markup.
            if ($page->isPlugin() && $page->isPreview && $page->isCustomizerPreview
                && preg_match('~^/admin/prime-builder/theme/[a-f0-9]{32}/preview$~D', $page->path) === 1
                && ($page->extra['pb_theme_preview'] ?? null) === $name) {
                return '<' . $name . ' class="pb-theme-' . $name . '" data-pb-theme-preview-target></' . $name . '>';
            }
            $layout = $partials->layout($name, $page);
            if ($layout === null) {
                return $previous;
            }
            // Only capability-protected, revalidated theme layouts can reach
            // this public path. HTML is filtered without enabling preview trust.
            $renderer = new Renderer($registry, $context->app()->get(HtmlSanitizer::class), $context->logger(), $content, null, false, $blocks, $originals, $htmlContent, false, $page, $mediaReferences, allowThemeHtml: true, pageQuery: $pageRequest->query($page), dynamicPlugin: $context, request: $pageRequest->request($page));
            return '<' . $name . ' class="pb-theme-' . $name . '">' . $renderer->render($layout) . '</' . $name . '>';
        });
        // Prime 1.0.1/1.0.2 expose candidates and plugin paths, but not a
        // site.template filter. A uniquely named full-document candidate avoids
        // being wrapped a second time by the theme's layout.
        $context->hooks()->filter('site.template_candidates', static function (mixed $candidates, mixed $page) use ($partials): mixed {
            if (!is_array($candidates) || !$page instanceof PageContext || $partials->pageLayout($page) === null) {
                return $candidates;
            }
            array_unshift($candidates, 'prime-builder-theme/content.php');
            return $candidates;
        });
        $context->hooks()->filter('site.template_paths', static function (mixed $paths, mixed $page) use ($context, $partials): mixed {
            if (is_array($paths) && $page instanceof PageContext && $partials->pageLayout($page) !== null) {
                $paths[] = $context->path('views/site');
            }
            return $paths;
        });
        // Core EntryView normally returns immutable stored HTML. For a canonical
        // Builder entry with shared references, render a fresh immutable context
        // through the original theme hierarchy. The stored HTML remains a fallback.
        $liveEntry = new LiveEntry($context, $globals);
        $context->app()->container()->singleton(LiveEntry::class, static fn (): LiveEntry => $liveEntry);
        $context->hooks()->filter('site.template_candidates', static function (mixed $candidates, mixed $page) use ($liveEntry): mixed {
            if (is_array($candidates) && $page instanceof PageContext && $liveEntry->needsRefresh($page)) {
                array_unshift($candidates, 'prime-builder-runtime/entry.php');
            }
            return $candidates;
        });
        $context->hooks()->filter('site.template_paths', static function (mixed $paths, mixed $page) use ($context, $liveEntry): mixed {
            if (is_array($paths) && $page instanceof PageContext && $liveEntry->needsRefresh($page)) {
                $paths[] = $context->path('views/site');
            }
            return $paths;
        });
        $context->hooks()->filter('prime_builder.theme_content', static function (mixed $previous, mixed $page) use ($context, $partials, $registry, $content, $blocks, $originals, $htmlContent, $mediaReferences, $pageRequest): mixed {
            if (!$page instanceof PageContext) {
                return $previous;
            }
            $layout = $partials->pageLayout($page);
            if ($layout === null) {
                return $previous;
            }
            $renderer = new Renderer($registry, $context->app()->get(HtmlSanitizer::class), $context->logger(), $content, null, false, $blocks, $originals, $htmlContent, false, $page, $mediaReferences, allowThemeHtml: true, pageQuery: $pageRequest->query($page), dynamicPlugin: $context, request: $pageRequest->request($page));
            return '<div class="pb-theme-content">' . $renderer->render($layout) . '</div>';
        });
        // Before Prime 1.0.3, zone saves have no purge event: check them before
        // lookup. Newer cores purge on zone.saved and need no zone preflight.
        // The filter itself does no SQL. The wrapper owns the render state until
        // storage is decided and protects custom authentication cookies.
        $cacheGuard = new CacheGuard($context, $zoneLayouts, $renderState);
        $context->app()->container()->instance(CacheGuard::class, $cacheGuard);
        $context->app()->get(Kernel::class)->registerMiddlewareAlias('page_cache', static fn (): CacheGuard => $cacheGuard);
        $context->hooks()->filter('cache.page.cacheable', $cacheGuard->cacheable(...));
        // Proxies must never retain HTML generated for an authenticated request.
        $context->hooks()->filter('http.response', static function (mixed $response, mixed $request) use ($context): mixed {
            if (!$response instanceof \Prime\Http\Response || !$request instanceof Request
                || !in_array($request->method(), ['GET', 'HEAD'], true)
                || !str_contains(strtolower((string) $response->header('Content-Type')), 'text/html')
                || str_contains(strtolower((string) $response->header('Cache-Control')), 'no-store')) {
                return $response;
            }
            try {
                // Runtime form markup may identify an authenticated visitor even
                // on an otherwise static page. Never offer that HTML to a proxy.
                $session = $context->app()->get(\Prime\Http\Session::class)->name();
                $remember = $context->app()->get(Auth::class)->rememberCookieName();
                $private = $request->header('authorization') !== null;
                foreach ([$session, $remember, \Prime\Http\Session::DEFAULT_NAME, 'prime_remember', 'prime_auth', 'PHPSESSID'] as $cookie) {
                    $private = $private || ($request->cookie($cookie) ?? '') !== '';
                }
            } catch (\Throwable) {
                $private = true;
            }
            return $private ? $response->withHeader('Cache-Control', 'private, no-store') : $response;
        });
        $context->hooks()->filter('admin.menu', static function (mixed $menu): mixed {
            if ($menu instanceof AdminMenu) {
                $menu->add(new MenuItem(id: 'plugin.prime-builder', label: 'plugin::prime-builder.menu', icon: 'layout-dashboard', url: '/admin/prime-builder', position: 60, accent: 'brand', capability: 'prime_builder.edit', group: 'admin.menu.groups.content'));
                // Prime's menu keeps the parent visible for a user with a permitted
                // child, and redirects its link to that child if edit is denied.
                $menu->addChild('plugin.prime-builder', new MenuItem(id: 'plugin.prime-builder-theme', label: 'plugin::prime-builder.theme.menu', icon: 'palette', url: '/admin/prime-builder/theme', position: 10, accent: 'brand', capability: 'prime_builder.theme_builder'));
                $menu->addChild('plugin.prime-builder', new MenuItem(id: 'plugin.prime-builder-submissions', label: 'plugin::prime-builder.forms.inbox', icon: 'inbox', url: '/admin/prime-builder/submissions', position: 20, accent: 'brand', capability: 'prime_builder.forms'));
                $menu->addChild('plugin.prime-builder', new MenuItem(id: 'plugin.prime-builder-kit', label: 'plugin::prime-builder.settings.kit_menu', icon: 'palette', url: '/admin/prime-builder/kit#pb-kit-title', position: 30, accent: 'brand', capability: 'prime_builder.settings'));
                $menu->addChild('plugin.prime-builder', new MenuItem(id: 'plugin.prime-builder-settings', label: 'plugin::prime-builder.settings.settings_menu', icon: 'layout-dashboard', url: '/admin/prime-builder/settings', position: 40, accent: 'brand', capability: 'prime_builder.settings'));
            }
            return $menu;
        });
        $controller = new Documents($context);
        $dashboard = new Dashboard($context);
        $settings = new Settings($context);
        $templates = new Templates($context);
        $theme = new ThemeBuilder($context);
        $themeModels = new ThemeModels($context);
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
            // A distinct path lets the core menu mark Kit and Settings correctly;
            // both views edit the same validated, atomic configuration bundle.
            $router->get('/kit', [$settings, 'show'])->name('kit');
            $router->post('/kit', [$settings, 'save'])->name('kit.save');
        }, capability: 'prime_builder.settings');
        $context->router()->adminGroup(static function (Router $router) use ($theme, $themeModels, $controller): void {
            $router->get('/theme', [$theme, 'show'])->name('theme');
            $router->post('/theme', [$theme, 'save'])->name('theme.save');
            $router->post('/theme/create', [$themeModels, 'create'])->name('theme.create');
            $router->get('/theme/content-search', [$themeModels, 'search'])->name('theme.search');
            $router->get('/theme/{id:[a-f0-9]+}', [$themeModels, 'show'])->name('theme.model');
            $router->post('/theme/{id:[a-f0-9]+}', [$themeModels, 'save'])->name('theme.model.save');
            $router->post('/theme/{id:[a-f0-9]+}/delete', [$themeModels, 'delete'])->name('theme.model.delete');
            $router->get('/theme/{id:[a-f0-9]+}/edit', [$controller, 'editTheme'])->name('theme.model.edit');
            $router->get('/theme/{id:[a-f0-9]+}/preview', [$themeModels, 'preview'])->name('theme.model.preview');
        }, capability: 'prime_builder.theme_builder');
        $context->router()->adminGroup(static function (Router $router) use ($forms): void {
            $router->get('/submissions', [$forms, 'index'])->name('submissions');
            $router->get('/submissions/export', [$forms, 'export'])->name('submissions.export');
            $router->get('/submissions/{id:\d+}/files/{file:\d+}', [$forms, 'download'])->name('submissions.file');
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
        $context->api()->patch('/theme-models/{id}', [$themeModels::class, 'updateLayout'], permission: 'prime_builder.theme_builder');
        $context->api()->post('/theme-models/{id}/render', [$themeModels::class, 'render'], permission: 'prime_builder.theme_builder');
        $context->api()->post('/theme-models/{id}/import', [$themeModels::class, 'import'], permission: 'prime_builder.theme_builder');
        $context->api()->get('/documents/{id}/live', [$controller::class, 'live'], public: true);
        $context->api()->get('/forms/{id}/start', [$forms::class, 'start'], public: true);
        $context->api()->post('/forms/{id}/submit', [$forms::class, 'submit'], public: true);
        $context->api()->post('/forms/{id}/submit-multipart', [$forms::class, 'submitMultipart'], public: true, options: ['body' => \Prime\Api\ApiRoute::BODY_MULTIPART]);
        $context->api()->get('/forms/models/{id}/start', [$forms::class, 'startModel'], public: true);
        $context->api()->post('/forms/models/{id}/submit', [$forms::class, 'submitModel'], public: true);
        $context->api()->post('/forms/models/{id}/submit-multipart', [$forms::class, 'submitModelMultipart'], public: true, options: ['body' => \Prime\Api\ApiRoute::BODY_MULTIPART]);
        $context->api()->get('/templates', [$templates::class, 'index'], permission: 'prime_builder.edit');
        $context->api()->post('/templates', [$templates::class, 'create'], permission: 'prime_builder.templates');
        $context->api()->delete('/templates/{id}', [$templates::class, 'delete'], permission: 'prime_builder.templates');
        // PluginContext is not a container service, so API controllers need explicit bindings.
        $context->app()->container()->singleton(Documents::class, static fn (): Documents => $controller);
        $context->app()->container()->singleton(Templates::class, static fn (): Templates => $templates);
        $context->app()->container()->singleton(ThemeModels::class, static fn (): ThemeModels => $themeModels);
        $context->app()->container()->singleton(ContactForms::class, static fn (): ContactForms => $forms);
        $context->hooks()->filter('jobs.register_handlers', static function (mixed $registry) use ($context): mixed {
            if ($registry instanceof JobHandlerRegistry) {
                $registry->register(Notifications::JOB_NAME, static function (array $payload, Job $job) use ($context): void {
                    (new Notifications($context))->handle($payload, $job);
                });
            }
            return $registry;
        });
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
                        $days = Submissions::retentionDays(Configuration::get($context->settings(), 'submission_retention_days', Submissions::RETENTION_DAYS));
                        return (new Submissions($context->db(), PrivateFiles::forApp($context->app())))->purge($now, $days) + RateLimit::purge($context->db(), $now);
                    },
                    expression: '15 4 * * *',
                    description: t('plugin::prime-builder.scheduler.purge_submissions'),
                    owner: 'prime-builder',
                );
                $schedule->call(
                    name: 'prime-builder.maintain-form-files',
                    action: static function () use ($context): int {
                        $maintenance = new FileMaintenance($context->db(), PrivateFiles::forApp($context->app()));
                        return $maintenance->migrate() + $maintenance->collect(time());
                    },
                    expression: '*/15 * * * *',
                    description: t('plugin::prime-builder.scheduler.maintain_form_files'),
                    owner: 'prime-builder',
                );
            }
            return $schedule;
        });
        // The CSP hook runs after site.assets during a page render. Reset on every page,
        // then include only the exact map origin if a map occurs in the entry,
        // partials, or a zone declared by the active theme.
        $hasMap = false;
        $context->hooks()->on('site.assets', static function (mixed $assets, mixed $page = null) use ($context, $globals, &$hasMap): void {
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
            $data = $globals->expand($data);
            $css = (new Compiler((int) Configuration::get($context->settings(), 'breakpoint_tablet', 1024), (int) Configuration::get($context->settings(), 'breakpoint_mobile', 767)))->compile($data);
            $file = $page->entry->id . '-' . substr(hash('sha256', $css), 0, 16) . '.css';
            $type = $context->app()->get(ContentTypeRegistry::class)->get($page->entry->type);
            if (!$page->isPreview && $type !== null && $type->public && \Prime\Site\UrlResolver::isPubliclyVisible($page->entry)) {
                $snapshots->remember($file, $css);
            }
            $types = Assets::collectTypes(is_array($data['elements'] ?? null) ? $data['elements'] : []);
            $hasMap = isset($types['map']);
            Assets::register($assets, $types, (new Registry($context->app()->get(Hooks::class)))->all());
            $assets->style('prime-builder-document', '/prime-builder/css/' . $file, ['prime-builder-kit']);
        });
        $context->hooks()->on('site.assets', static function (mixed $assets, mixed $page = null) use ($context, $partials, $registry, &$hasMap): void {
            if (!$assets instanceof ThemeAssetRegistry || !$page instanceof PageContext) {
                return;
            }
            $types = [];
            foreach (['header', 'footer', 'content'] as $slot) {
                $layout = $slot === 'content' ? $partials->pageLayout($page) : $partials->layout($slot, $page);
                if ($layout === null) {
                    continue;
                }
                $css = (new Compiler((int) Configuration::get($context->settings(), 'breakpoint_tablet', 1024), (int) Configuration::get($context->settings(), 'breakpoint_mobile', 767)))->compile($layout, legacy: false);
                $file = substr(hash('sha256', $css), 0, 16) . '.css';
                (new Snapshots($context->app()->storagePath('prime-builder/styles')))->remember('theme-' . $file, $css);
                $assets->style('prime-builder-theme-' . $slot, '/prime-builder/theme/' . $file, ['prime-builder-kit']);
                $types += Assets::collectTypes(is_array($layout['elements'] ?? null) ? $layout['elements'] : []);
            }
            $hasMap = $hasMap || isset($types['map']);
            $definitions = $registry->all();
            // These widgets are fully resolved in a theme partial; there is no
            // data-pb-live endpoint for the snapshot and no refresh script needed.
            foreach (['posts', 'current_content', 'theme_zone', 'site_title', 'site_logo', 'entry_title', 'entry_excerpt', 'entry_featured_image', 'entry_info', 'entry_navigation', 'entry_author', 'breadcrumbs', 'search_form'] as $type) {
                if (($definitions[$type]['script'] ?? null) === '/assets/plugins/prime-builder/js/content.js') {
                    unset($definitions[$type]['script']);
                }
            }
            if (($definitions['share_buttons']['script'] ?? null) === '/assets/plugins/prime-builder/js/share_buttons.js') {
                unset($definitions['share_buttons']['script']);
            }
            Assets::register($assets, $types, $definitions);
        });
        // A theme can render a declared zone without an entry or a Builder
        // widget. Its styles and widget assets must therefore be available at
        // the head, including for copies inserted after the initial page render.
        $context->hooks()->on('site.assets', static function (mixed $assets, mixed $page = null) use ($context, $zoneLayouts, $globals, $registry, &$hasMap): void {
            if (!$assets instanceof ThemeAssetRegistry || !$page instanceof PageContext) {
                return;
            }
            $seen = [];
            foreach ($assets->resolvedStyles() as $asset) {
                $seen[explode('?', $asset['url'], 2)[0]] = true;
            }
            $compiler = new Compiler((int) Configuration::get($context->settings(), 'breakpoint_tablet', 1024), (int) Configuration::get($context->settings(), 'breakpoint_mobile', 767));
            $snapshots = new Snapshots($context->app()->storagePath('prime-builder/styles'));
            $types = [];
            foreach ($zoneLayouts->all() as $layout) {
                $layout = $globals->expand($layout);
                $types += Assets::collectTypes(is_array($layout['elements'] ?? null) ? $layout['elements'] : []);
                $css = $compiler->compile($layout, legacy: false);
                if ($css === '') {
                    continue;
                }
                $hash = substr(hash('sha256', $css), 0, 16);
                $url = '/prime-builder/theme/' . $hash . '.css';
                if (isset($seen[$url])) {
                    continue;
                }
                $snapshots->remember('theme-' . $hash . '.css', $css);
                $assets->style('prime-builder-zone-' . $hash, $url, ['prime-builder-kit']);
                $seen[$url] = true;
            }
            $hasMap = $hasMap || isset($types['map']);
            Assets::register($assets, $types, $registry->all());
        });
        // Popups are independent models. Register only matching, revalidated
        // layouts; the foot hook below emits closed dialogs without inline code.
        $context->hooks()->on('site.assets', static function (mixed $assets, mixed $page = null) use ($context, $popups, $registry, &$hasMap): void {
            if (!$assets instanceof ThemeAssetRegistry || !$page instanceof PageContext) {
                return;
            }
            $matches = $popups->matching($page);
            if ($matches === []) {
                return;
            }
            $assets->style('prime-builder-popup', $context->url('css/popup.css'), ['prime-builder-base']);
            $assets->script('prime-builder-popup', $context->url('js/popup.js'));
            $compiler = new Compiler((int) Configuration::get($context->settings(), 'breakpoint_tablet', 1024), (int) Configuration::get($context->settings(), 'breakpoint_mobile', 767));
            $snapshots = new Snapshots($context->app()->storagePath('prime-builder/styles'));
            $seen = [];
            foreach ($assets->resolvedStyles() as $asset) {
                $seen[explode('?', $asset['url'], 2)[0]] = true;
            }
            $types = [];
            foreach ($matches as $match) {
                $layout = $match['layout'];
                $css = $compiler->compile($layout, legacy: false);
                if ($css !== '') {
                    $file = substr(hash('sha256', $css), 0, 16) . '.css';
                    $url = '/prime-builder/theme/' . $file;
                    if (!isset($seen[$url])) {
                        $snapshots->remember('theme-' . $file, $css);
                        $assets->style('prime-builder-popup-' . (string) $match['model']['id'], $url, ['prime-builder-kit']);
                        $seen[$url] = true;
                    }
                }
                $types += Assets::collectTypes(is_array($layout['elements'] ?? null) ? $layout['elements'] : []);
            }
            $hasMap = $hasMap || isset($types['map']);
            $definitions = $registry->all();
            foreach (['posts', 'current_content', 'theme_zone', 'site_title', 'site_logo', 'entry_title', 'entry_excerpt', 'entry_featured_image', 'entry_info', 'entry_navigation', 'entry_author', 'breadcrumbs', 'search_form'] as $type) {
                if (($definitions[$type]['script'] ?? null) === '/assets/plugins/prime-builder/js/content.js') {
                    unset($definitions[$type]['script']);
                }
            }
            if (($definitions['share_buttons']['script'] ?? null) === '/assets/plugins/prime-builder/js/share_buttons.js') {
                unset($definitions['share_buttons']['script']);
            }
            Assets::register($assets, $types, $definitions);
        });
        $context->hooks()->filter('site.foot', static function (mixed $previous, mixed $page) use ($context, $popups, $registry, $content, $blocks, $originals, $htmlContent, $mediaReferences, $pageRequest): mixed {
            if (!is_string($previous) || !$page instanceof PageContext) {
                return $previous;
            }
            $matches = $popups->matching($page);
            if ($matches === []) {
                return $previous;
            }
            $renderer = new Renderer($registry, $context->app()->get(HtmlSanitizer::class), $context->logger(), $content, null, false, $blocks, $originals, $htmlContent, false, $page, $mediaReferences, allowThemeHtml: true, pageQuery: $pageRequest->query($page), dynamicPlugin: $context, request: $pageRequest->request($page));
            $html = '';
            foreach ($matches as $match) {
                $html .= $popups->render($match, $renderer);
            }
            return $previous . $html;
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
        $context->hooks()->on('settings.updated', static function (mixed $name) use ($context, $partials, $popups, $globals, $theme, $themeModels): void {
            if (is_string($name) && str_starts_with($name, 'plugin.prime-builder.')) {
                $partials->invalidate();
                $popups->invalidate();
                $globals->invalidate();
                $theme->invalidate();
                $themeModels->invalidate();
                if (Models::maintainingIndex()) {
                    // Models atomically updates its directory and purges after commit.
                    return;
                }
                if (str_starts_with($name, $context->settings()->prefix() . Models::PREFIX)) {
                    // Legacy writers using PluginSettings still emit this event.
                    // Remove the old directory first so a failed rebuild fails closed.
                    $context->settings()->delete(Models::INDEX);
                    (new Models($context->settings(), $context->app()->get(\Prime\Settings\Settings::class), $context))->rebuildIndex();
                }
                $context->app()->get(\Prime\Cache\PageCache::class)->purgeAll();
            }
        });
        $context->hooks()->filter('system.checks', static function (mixed $checks) use ($context): mixed {
            if (!is_array($checks)) {
                return $checks;
            }
            $checks[] = ['id' => 'prime-builder.status', 'group' => 'plugins', 'label' => t('plugin::prime-builder.system.label'), 'status' => 'ok', 'value' => t('plugin::prime-builder.system.active')];
            $assetsPresent = is_file($context->path('assets/js/editor.js')) && is_file($context->path('assets/css/editor.css'))
                && is_file($context->path('assets/css/public.css')) && is_file($context->path('assets/js/popup.js')) && is_file($context->path('assets/css/popup.css'));
            $checks[] = ['id' => 'prime-builder.assets', 'group' => 'plugins', 'label' => t('plugin::prime-builder.system.assets'), 'status' => $assetsPresent ? 'ok' : 'error', 'value' => t($assetsPresent ? 'plugin::prime-builder.system.present' : 'plugin::prime-builder.system.missing')];
            $tablet = (int) Configuration::get($context->settings(), 'breakpoint_tablet', 1024);
            $mobile = (int) Configuration::get($context->settings(), 'breakpoint_mobile', 767);
            $breakpointsValid = $tablet >= 600 && $tablet <= 2000 && $mobile >= 320 && $mobile < $tablet;
            $checks[] = ['id' => 'prime-builder.breakpoints', 'group' => 'plugins', 'label' => t('plugin::prime-builder.system.breakpoints'), 'status' => $breakpointsValid ? 'ok' : 'warning', 'value' => t($breakpointsValid ? 'plugin::prime-builder.system.valid' : 'plugin::prime-builder.system.adjusted')];
            try {
                $db = $context->db();
                $files = '`' . $db->prefixed(Submissions::FILE_TABLE) . '`';
                $pending = (int) $db->scalar('SELECT COUNT(*) FROM ' . $files . ' WHERE `storage_key` IS NULL AND `migration_error` IS NULL');
                $invalid = (int) $db->scalar('SELECT COUNT(*) FROM ' . $files . ' WHERE `migration_error` IS NOT NULL');
                $garbage = (int) $db->table(Submissions::FILE_GC_TABLE)->count();
                $healthy = $pending === 0 && $invalid === 0 && $garbage === 0;
                $value = $healthy ? t('plugin::prime-builder.system.form_files_ok') : t('plugin::prime-builder.system.form_files_pending', ['pending' => $pending, 'invalid' => $invalid, 'garbage' => $garbage]);
                $checks[] = ['id' => 'prime-builder.form-files', 'group' => 'plugins', 'label' => t('plugin::prime-builder.system.form_files'), 'status' => $healthy ? 'ok' : 'warning', 'value' => $value];
            } catch (\Throwable) {
                $checks[] = ['id' => 'prime-builder.form-files', 'group' => 'plugins', 'label' => t('plugin::prime-builder.system.form_files'), 'status' => 'warning', 'value' => t('plugin::prime-builder.system.form_files_unavailable')];
            }
            if (version_compare($context->app()->version(), '1.0.2', '<')) {
                $checks[] = ['id' => 'prime-builder.legacy-auth', 'group' => 'plugins', 'label' => t('plugin::prime-builder.system.legacy_auth'), 'status' => 'warning', 'value' => t('plugin::prime-builder.system.legacy_auth_warning')];
                $checks[] = ['id' => 'prime-builder.legacy-loader', 'group' => 'plugins', 'label' => t('plugin::prime-builder.system.legacy_loader'), 'status' => 'warning', 'value' => t('plugin::prime-builder.system.legacy_loader_warning')];
            }
            return $checks;
        });
    }

    public function activate(PluginContext $context): void
    {
        $context->notices()->add(id: 'welcome', type: Notice::TYPE_SUCCESS, title: 'plugin::prime-builder.notice.title', message: 'plugin::prime-builder.notice.message', capability: 'prime_builder.edit', dismissible: true);
        $context->app()->get(\Prime\Cache\CacheManager::class)->clearPages();
    }

    public function deactivate(PluginContext $context): void
    {
        $context->notices()->remove('welcome');
        // Once the hooks are removed, a page cached before the bypass could
        // otherwise resurrect markup or asset URLs belonging to this plugin.
        $context->app()->get(\Prime\Cache\CacheManager::class)->clearPages();
    }

    public function uninstall(PluginContext $context): void
    {
        // An installed but never activated plugin, or an inactive 0.1.0/0.1.1
        // installation, has no notifications table. In that case no such jobs
        // were created by this version; the CMS still rolls back older tables.
        // Otherwise wait for in-flight delivery and cancel reserved jobs before
        // the CMS drops the tables. Pause public traffic/workers during uninstall.
        $storage = PrivateFiles::forApp($context->app());
        if ($context->db()->schema()->hasTable(Submissions::NOTIFICATION_TABLE)) {
            (new Submissions($context->db(), $storage))->deleteAll();
        }
        $context->notices()->clearAll();
        (new Snapshots($context->app()->storagePath('prime-builder/styles')))->clear();
        // A hook failure is logged by the CMS, which may still remove tables.
        // Operators must stop traffic/workers and verify disk cleanup afterwards.
        $storage->clear();
    }
}
