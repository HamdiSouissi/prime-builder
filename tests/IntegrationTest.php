<?php

declare(strict_types=1);

namespace PrimeBuilder\Tests;

use PHPUnit\Framework\TestCase;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\EntryRepository;
use Prime\Editor\BlockRegistry;
use Prime\Editor\EditorRegistry;
use Prime\Hooks\Hooks;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Http\Session;
use Prime\Kernel\Application;
use Prime\Plugins\AssetPublisher;
use Prime\Plugins\PluginManager;
use Prime\Security\Csrf;
use Prime\Settings\Settings;
use Prime\Site\PageContext;
use Prime\Site\UrlGenerator;
use Prime\Site\View\EntryView;
use Prime\Tests\Support\ApiTestFixture;
use Prime\Themes\ThemeAssetRegistry;
use PrimeBuilder\Styles\Compiler;
use PrimeBuilder\Styles\Kit;

final class IntegrationTest extends TestCase
{
    use ApiTestFixture;

    private string $assets = '';

    protected function tearDown(): void
    {
        if ($this->assets !== '') {
            AssetPublisher::deleteDirectory($this->assets);
        }
    }

    public function testActivationRegistersEditorBlockAndPageFilter(): void
    {
        $app = $this->activated('admin');
        self::assertNotNull($app->get(BlockRegistry::class)->get('prime-builder/layout'));
        $editor = $app->get(EditorRegistry::class)->get('prime-builder');
        self::assertNotNull($editor);
        $page = $app->get(ContentTypeRegistry::class)->get('page');
        self::assertNotNull($page);
        self::assertSame('prime-builder', $app->get(Hooks::class)->apply('content.editor', 'blocks', $page));
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        $html = ($editor->render)(['entry' => $entry, 'type' => $page, 'document' => $entry->decodedContent(), 'contentJson' => $entry->content]);
        self::assertStringContainsString('name="content_json" data-prime-custom-editor-input hidden', $html);
        self::assertStringContainsString('/admin/prime-builder/edit/1', $html);
        self::assertSame(200, $this->send($app, 'GET', '/admin/prime-builder/edit/1')->status());
    }

    public function testDashboardAndSystemChecksReportRealState(): void
    {
        $app = $this->activated('admin');
        $response = $this->send($app, 'GET', '/admin/prime-builder');
        self::assertSame(200, $response->status(), $response->body());
        self::assertStringContainsString('pb-start-title', $response->body());
        self::assertStringContainsString('/admin/content/page', $response->body());
        self::assertStringContainsString('no-store', (string) $response->header('Cache-Control'));
        $checks = $app->get(Hooks::class)->apply('system.checks', []);
        self::assertSame('ok', $checks[1]['status']);
        self::assertSame('ok', $checks[2]['status']);
        $app->get(Settings::class)->set('plugin.prime-builder.breakpoint_tablet', 600);
        $app->get(Settings::class)->set('plugin.prime-builder.breakpoint_mobile', 767);
        $checks = $app->get(Hooks::class)->apply('system.checks', []);
        self::assertSame('warning', $checks[2]['status']);
    }

    public function testApiPersistsRenderedHtmlAndHashedCss(): void
    {
        $app = $this->activated(null);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $response = $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $this->document()], $token);
        self::assertSame(200, $response->status(), $response->body());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('<h2>Bonjour</h2>', $entry->contentHtml);
        $layout = $entry->decodedContent()['blocks'][0]['data'];
        $css = (new Compiler())->compile($layout);
        $url = '/prime-builder/css/1-' . substr(hash('sha256', $css), 0, 16) . '.css';
        $stylesheet = $this->send($app, 'GET', $url);
        self::assertSame(200, $stylesheet->status());
        self::assertStringContainsString('color:#c9441f', $stylesheet->body());
        self::assertStringContainsString('immutable', (string) $stylesheet->header('Cache-Control'));
        self::assertSame(404, $this->send($app, 'GET', '/prime-builder/css/1-0000000000000000.css')->status());
    }

    public function testInteractiveScriptIsLoadedOnlyForPagesWithTabs(): void
    {
        $app = $this->activated(null);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'][] = ['id' => 'tabs', 'type' => 'tabs', 'settings' => ['title_1' => 'Un', 'title_2' => 'Deux'], 'styles' => [], 'children' => []];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        self::assertStringContainsString('/assets/plugins/prime-builder/js/tabs.js', $assets->renderScripts());

        $assets = new ThemeAssetRegistry();
        $other = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($other);
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/tabs.js', $assets->renderScripts());
    }

    public function testLiveContentHasSafeFallbackAndNeverListsDraftPosts(): void
    {
        $app = $this->activated(null);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'recent', 'type' => 'posts', 'settings' => ['limit' => 3, 'show_excerpt' => 'show'], 'styles' => [], 'children' => []],
            ['id' => 'again', 'type' => 'posts', 'settings' => ['limit' => 1, 'show_excerpt' => 'hide'], 'styles' => [], 'children' => []],
            ['id' => 'current', 'type' => 'current_content', 'settings' => ['source' => 'title'], 'styles' => [], 'children' => []],
        ];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('Voir les articles récents', $entry->contentHtml);
        self::assertStringNotContainsString('Résumé', $entry->contentHtml);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/content.js'));
        $live = $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live');
        self::assertSame(200, $live->status(), $live->body());
        self::assertStringContainsString('no-store', (string) $live->header('Cache-Control'));
        $widgets = json_decode($live->body(), true)['data']['widgets'];
        self::assertStringContainsString('Article publié', $widgets['recent']);
        self::assertStringContainsString('Résumé', $widgets['recent']);
        self::assertStringNotContainsString('Brouillon admin', $widgets['recent']);
        self::assertStringNotContainsString('Brouillon contributeur', $widgets['recent']);
        self::assertStringContainsString('Article publié', $widgets['current']);
        self::assertStringNotContainsString('Résumé', $widgets['again']);

        // Current values are refreshed after the main entry changes.
        $app->get(\Prime\Content\EntryService::class)->update(1, ['title' => 'Titre actualisé'], $this->user($app, 1));
        $updated = json_decode($this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live')->body(), true)['data']['widgets'];
        self::assertStringContainsString('Titre actualisé', $updated['current']);
        self::assertStringContainsString('Titre actualisé', $updated['recent']);

        // A draft layout cannot be fetched without the same per-entry edit checks.
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/2', ['content' => $document], $token)->status());
        self::assertSame(404, $this->send($app, 'GET', '/api/prime-builder/v1/documents/2/live')->status());
        self::assertSame(200, $this->send($app, 'GET', '/api/prime-builder/v1/documents/2/live', token: $token)->status());
    }

    public function testRecentPostsUseOneBoundedSharedQueryAndEscapeTitles(): void
    {
        $app = $this->activated(null);
        $provider = $app->get(\PrimeBuilder\Widgets\Content::class);
        $pdo = $this->db($app)->pdo();
        $pdo->exec("UPDATE pc_entries SET title = '<img src=x onerror=alert(1)>' WHERE id = 1");
        $first = $provider->posts(['limit' => 12], true);
        self::assertStringContainsString('&lt;img', $first);
        self::assertStringNotContainsString('<img', $first);
        $pdo->exec("UPDATE pc_entries SET title = 'Nouvelle valeur' WHERE id = 1");
        self::assertSame($first, $provider->posts(['limit' => 12], true));
        $provider->invalidate();
        self::assertStringContainsString('Nouvelle valeur', $provider->posts(['limit' => 12], true));
    }

    public function testHistoricalStylesRemainImmutableAndStillRespectVisibility(): void
    {
        $app = $this->activated(null);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $oldCss = (new Compiler())->compile($document['blocks'][0]['data']);
        $oldUrl = '/prime-builder/css/1-' . substr(hash('sha256', $oldCss), 0, 16) . '.css';
        $oldResponse = $this->send($app, 'GET', $oldUrl);
        self::assertSame(200, $oldResponse->status());
        self::assertStringContainsString('immutable', (string) $oldResponse->header('Cache-Control'));
        $document['blocks'][0]['data']['elements'][0]['styles']['desktop']['color'] = '#345678';
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $newCss = (new Compiler())->compile($document['blocks'][0]['data']);
        $newUrl = '/prime-builder/css/1-' . substr(hash('sha256', $newCss), 0, 16) . '.css';
        self::assertSame($oldCss, $this->send($app, 'GET', $oldUrl)->body());
        self::assertSame($newCss, $this->send($app, 'GET', $newUrl)->body());
        $this->db($app)->pdo()->exec("UPDATE pc_entries SET status = 'draft' WHERE id = 1");
        self::assertSame(404, $this->send($app, 'GET', $oldUrl)->status());
        self::assertSame(404, $this->send($app, 'GET', $newUrl)->status());
        $this->appLogin($app);
        $private = $this->send($app, 'GET', $newUrl);
        self::assertSame(200, $private->status());
        self::assertStringContainsString('no-store', (string) $private->header('Cache-Control'));
    }

    public function testHistoricalKitAndOwnedPruneTask(): void
    {
        $app = $this->activated(null);
        $original = Kit::compile([]);
        $url = '/prime-builder/kit/' . substr(hash('sha256', $original), 0, 16) . '.css';
        self::assertSame($original, $this->send($app, 'GET', $url)->body());
        $app->get(Settings::class)->set('plugin.prime-builder.kit_primary', '#123456');
        self::assertSame($original, $this->send($app, 'GET', $url)->body());
        $schedule = $app->get(Hooks::class)->apply('scheduler.register', new \Prime\Scheduler\Schedule());
        self::assertSame(true, $schedule->has('prime-builder.prune-styles'));
        self::assertSame('prime-builder', $schedule->get('prime-builder.prune-styles')->owner);
    }

    public function testPrimeBlockPersistsCoreHtmlWithoutAllowingPrivilegedBlocks(): void
    {
        $app = $this->activated(null);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'][0] = [
            'id' => 'bridge', 'type' => 'prime_block',
            'settings' => ['block_type' => 'paragraph', 'block_data' => '{"text":"Texte <strong>Prime</strong>"}'],
            'styles' => [], 'children' => [],
        ];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('pc-block--paragraph', $entry->contentHtml);
        self::assertStringContainsString('Texte <strong>Prime</strong>', $entry->contentHtml);
        $document['blocks'][0]['data']['elements'][0]['settings'] = ['block_type' => 'html', 'block_data' => '{"html":"<script>alert(1)</script>"}'];
        self::assertSame(422, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
    }

    public function testGlobalKitIsFingerprintedAndSafe(): void
    {
        $app = $this->activated(null);
        $kitCss = Kit::compile([]);
        $url = '/prime-builder/kit/' . substr(hash('sha256', $kitCss), 0, 16) . '.css';
        $response = $this->send($app, 'GET', $url);
        self::assertSame(200, $response->status());
        self::assertStringContainsString('immutable', (string) $response->header('Cache-Control'));
        self::assertSame($kitCss, $response->body());
        self::assertSame(404, $this->send($app, 'GET', '/prime-builder/kit/0000000000000000.css')->status());
    }

    public function testOwnerChecksAndSessionCsrf(): void
    {
        $app = $this->activated(null);
        $this->db($app)->pdo()->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'prime_builder.edit')");
        $token = $this->tokens($app)->issue($this->user($app, 2), 'builder', ['*'])['token'];
        self::assertSame(404, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/2', ['content' => $this->document()], $token)->status());
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $this->document()], $token)->status());

        $this->appLogin($app);
        self::assertSame(403, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/2', ['content' => $this->document()])->status());
        $csrf = (new Csrf($app->get(Session::class)))->token();
        $request = Request::create('PATCH', '/api/prime-builder/v1/documents/2', headers: ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Prime-CSRF' => $csrf], rawBody: (string) json_encode(['content' => $this->document()]));
        self::assertSame(200, $app->get(Kernel::class)->handle($request)->status());
    }

    public function testSettingsRequirePluginCapabilityAndCannotBeChangedThroughCoreApi(): void
    {
        $app = $this->activated('contributor');
        $db = $this->db($app)->pdo();
        $csrf = $app->get(Csrf::class)->token();
        $fields = [
            '_csrf_token' => $csrf, 'pages' => '1', 'posts' => '1', 'other_types' => 'portfolio, product',
            'breakpoint_tablet' => '1100', 'breakpoint_mobile' => '700',
            'kit_primary' => '#123456', 'kit_text' => '#abcdef', 'kit_font_size' => '18',
        ];
        self::assertSame(403, $this->send($app, 'GET', '/admin/prime-builder/settings')->status());
        self::assertSame(403, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/settings', body: $fields))->status());

        // The general CMS settings permission alone must not expose or modify plugin fields.
        $db->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'settings.manage')");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        $app->get(\Prime\Auth\Auth::class)->login($this->user($app, 2));
        self::assertSame(403, $this->send($app, 'GET', '/admin/prime-builder/settings')->status());
        $general = $this->send($app, 'GET', '/admin/settings/general');
        self::assertSame(200, $general->status());
        self::assertStringNotContainsString('settings-section-prime-builder', $general->body());
        $token = $this->tokens($app)->issue($this->user($app, 2), 'settings', ['*'])['token'];
        self::assertSame(422, $this->send($app, 'PATCH', '/api/v1/settings', ['plugin.prime-builder.kit_primary' => '#badbad'], $token)->status());
        self::assertSame(false, $app->get(Settings::class)->has('plugin.prime-builder.kit_primary'));

        // Plugin settings can be managed without the broader CMS settings permission.
        $db->exec("DELETE FROM pc_role_capabilities WHERE role_id = 2 AND capability = 'settings.manage'");
        $db->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'prime_builder.settings')");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        $app->get(\Prime\Auth\Auth::class)->login($this->user($app, 2));
        $fields['_csrf_token'] = $app->get(Csrf::class)->token();
        self::assertSame(200, $this->send($app, 'GET', '/admin/prime-builder/settings')->status());
        self::assertSame(403, $this->send($app, 'GET', '/admin/settings/general')->status());
        $withoutCsrf = $fields;
        unset($withoutCsrf['_csrf_token']);
        self::assertSame(419, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/settings', body: $withoutCsrf))->status());
        $invalid = $fields;
        $invalid['breakpoint_mobile'] = '1500';
        $invalid['kit_primary'] = '</style><script>alert(1)</script>';
        $response = $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/settings', body: $invalid));
        self::assertSame(422, $response->status(), $response->body());
        self::assertStringNotContainsString('</style><script>alert(1)</script>', $response->body());
        self::assertSame(false, $app->get(Settings::class)->has('plugin.prime-builder.posts'));
        $response = $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/settings', body: $fields));
        self::assertSame(302, $response->status(), $response->body());
        self::assertSame(true, $app->get(Settings::class)->get('plugin.prime-builder.posts'));
        self::assertSame(1100, $app->get(Settings::class)->get('plugin.prime-builder.breakpoint_tablet'));
        self::assertSame('#123456', $app->get(Settings::class)->get('plugin.prime-builder.kit_primary'));
        self::assertSame('portfolio, product', $app->get(Settings::class)->get('plugin.prime-builder.other_types'));
    }

    public function testEditorAndTemplateAccessHaveSeparateCapabilities(): void
    {
        $app = $this->activated('contributor');
        $db = $this->db($app)->pdo();
        self::assertSame(403, $this->send($app, 'GET', '/admin/prime-builder/edit/3')->status());
        $token = $this->tokens($app)->issue($this->user($app, 2), 'editor', ['*'])['token'];
        self::assertSame(403, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $this->document()], $token)->status());
        self::assertSame(403, $this->send($app, 'POST', '/api/prime-builder/v1/documents/3/render', ['data' => ['version' => 1, 'elements' => []]], $token)->status());
        $db->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'prime_builder.edit')");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        $app->get(\Prime\Auth\Auth::class)->login($this->user($app, 2));
        $config = $this->editorConfig($app, 3);
        self::assertSame(false, $config['canTemplates']);
        self::assertSame([], $config['library']);
        $screen = $this->send($app, 'GET', '/admin/prime-builder/edit/3');
        self::assertStringContainsString('data-pb-panel="library" aria-pressed="false" hidden', $screen->body());

        $db->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'prime_builder.templates')");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        $app->get(\Prime\Auth\Auth::class)->login($this->user($app, 2));
        $config = $this->editorConfig($app, 3);
        self::assertSame(true, $config['canTemplates']);
        self::assertSame(9, count($config['library']));
        $screen = $this->send($app, 'GET', '/admin/prime-builder/edit/3');
        self::assertStringContainsString('data-pb-panel="library" aria-pressed="false">', $screen->body());
    }

    public function testDeactivationKeepsReadableHtmlAndUninstallCleansSettingsAndCapabilities(): void
    {
        $app = $this->activated(null);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $this->document()], $token)->status());
        $settings = $app->get(Settings::class);
        $settings->set('plugin.prime-builder.kit_primary', '#123456');
        $pdo = $this->db($app)->pdo();
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'prime_builder.edit')");
        $kitUrl = '/prime-builder/kit/' . substr(hash('sha256', Kit::compile(['primary' => '#123456'])), 0, 16) . '.css';
        self::assertSame(200, $this->send($app, 'GET', $kitUrl)->status());
        $snapshotDirectory = $app->storagePath('prime-builder/styles');
        self::assertSame(true, is_dir($snapshotDirectory));
        $manager = $app->get(PluginManager::class);
        $manager->deactivate('prime-builder');
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        // Use a fresh core-only registry, as on a new request after deactivation.
        $coreRenderer = new \Prime\Editor\Renderer(new BlockRegistry(), new \Prime\Security\HtmlSanitizer());
        $zones = new \Prime\Site\Zone\ZoneRepository($this->db($app), $coreRenderer);
        $view = new EntryView($entry, $app->get(UrlGenerator::class), zones: $zones, dynamicBlocks: $app->get(\Prime\Site\Zone\DynamicBlockRegistry::class));
        self::assertStringContainsString('<h2>Bonjour</h2>', $view->content());
        $manager->uninstall('prime-builder', deleteFiles: false);
        self::assertSame(false, $settings->has('plugin.prime-builder.kit_primary'));
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM pc_role_capabilities WHERE capability LIKE 'prime!_builder.%' ESCAPE '!'")->fetchColumn());
        self::assertSame(false, is_dir($this->assets . '/prime-builder'));
        self::assertSame(false, is_dir($snapshotDirectory));
        $saved = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($saved);
        self::assertStringContainsString('<h2>Bonjour</h2>', $saved->contentHtml);
    }

    public function testInvalidTreeAndPrivateCssAreNotExposed(): void
    {
        $app = $this->activated(null);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'][0]['settings']['url'] = 'javascript:alert(1)';
        self::assertSame(422, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        self::assertSame(404, $this->send($app, 'GET', '/prime-builder/css/2-0000000000000000.css')->status());
    }

    public function testOpeningAndSavingLegacyBlocksPreservesTheirContentsAndProtectedHtml(): void
    {
        $app = $this->activated('admin');
        $service = $app->get(\Prime\Content\EntryService::class);
        $legacy = ['version' => 1, 'blocks' => [
            ['id' => 'first', 'type' => 'paragraph', 'data' => ['text' => 'Premier contenu']],
            ['id' => 'middle', 'type' => 'html', 'data' => ['html' => '<p data-legacy="original">Contenu original conservé</p>']],
            ['id' => 'last', 'type' => 'heading', 'data' => ['level' => 2, 'text' => 'Troisième contenu']],
        ]];
        $service->update(1, ['content' => $legacy], $this->user($app, 1));
        $repository = $app->get(EntryRepository::class);
        $before = $repository->find(1);
        self::assertNotNull($before);
        $beforeDocument = $before->content;
        $beforeHtml = $before->contentHtml;
        $config = $this->editorConfig($app, 1);
        self::assertSame(false, $config['conversionFailed']);
        self::assertSame(true, $config['converted']);
        self::assertSame($beforeDocument, $repository->find(1)?->content);
        self::assertSame($beforeHtml, $repository->find(1)?->contentHtml);
        $converted = $config['document'];
        $children = $converted['blocks'][0]['data']['elements'][0]['children'];
        self::assertSame(['prime_block', 'original_content', 'prime_block'], array_column($children, 'type'));
        self::assertStringContainsString('id="troisieme-contenu"', $beforeHtml);
        self::assertSame('troisieme-contenu', $children[2]['settings']['block_anchor']);
        $protected = $children[1]['settings']['payload'];
        self::assertStringNotContainsString('Contenu original', $protected);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $preview = $this->send($app, 'POST', '/api/prime-builder/v1/documents/1/render', ['data' => $converted['blocks'][0]['data']], $token);
        self::assertSame(200, $preview->status(), $preview->body());
        $previewHtml = json_decode($preview->body(), true)['data']['html'];
        self::assertStringContainsString('<p data-legacy="original">Contenu original conservé</p>', $previewHtml);
        $saved = $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $converted, 'source_hash' => $config['sourceHash']], $token);
        self::assertSame(200, $saved->status(), $saved->body());
        $after = $repository->find(1);
        self::assertNotNull($after);
        self::assertStringContainsString('Premier contenu', $after->contentHtml);
        self::assertStringContainsString('<p data-legacy="original">Contenu original conservé</p>', $after->contentHtml);
        self::assertStringContainsString('Troisième contenu', $after->contentHtml);
        self::assertStringContainsString('id="troisieme-contenu"', $after->contentHtml);
        self::assertSame(true, strpos($after->contentHtml, 'Premier contenu') < strpos($after->contentHtml, 'Contenu original conservé'));
        self::assertSame(true, strpos($after->contentHtml, 'Contenu original conservé') < strpos($after->contentHtml, 'Troisième contenu'));

        // A token cannot be altered, moved to another entry, or minted by the CMS encrypter.
        $tampered = $converted;
        $tampered['blocks'][0]['data']['elements'][0]['children'][1]['settings']['payload'] .= 'x';
        self::assertSame(422, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $tampered], $token)->status());
        self::assertSame(422, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/2', ['content' => $converted], $token)->status());
        // The generic CMS writer also cannot render another entry's authenticated fragment.
        $service->update(2, ['content' => $converted], $this->user($app, 1));
        self::assertStringNotContainsString('Contenu original conservé', $repository->find(2)?->contentHtml ?? '');
        $forged = $converted;
        $forged['blocks'][0]['data']['elements'][0]['children'][1]['settings']['payload'] = $app->get(\Prime\Security\Encrypter::class)->encrypt((string) json_encode(['entry' => 1, 'type' => 'html', 'html' => '<script>alert(1)</script>']));
        self::assertSame(422, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $forged], $token)->status());
        $styled = $converted;
        $styled['blocks'][0]['data']['elements'][0]['children'][1]['styles'] = ['desktop' => ['color' => '#000000']];
        self::assertSame(422, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $styled], $token)->status());
    }

    public function testConversionRetainsHeadingAnchorsAndProtectsChangedNormalizedOutput(): void
    {
        $app = $this->activated('admin');
        $repository = $app->get(EntryRepository::class);
        $entry = $repository->find(1);
        self::assertNotNull($entry);
        $legacy = ['version' => 1, 'blocks' => [
            ['id' => 'first', 'type' => 'heading', 'data' => ['level' => 2, 'text' => 'Répétition', 'anchor' => 'repete']],
            ['id' => 'second', 'type' => 'heading', 'data' => ['level' => 2, 'text' => 'Répétition', 'anchor' => 'repete']],
            ['id' => 'link', 'type' => 'paragraph', 'data' => ['text' => '<a href="#repete-2">Vers le second</a>']],
            // Legacy raw list data can render differently after core normalization.
            ['id' => 'list', 'type' => 'list', 'data' => ['style' => 'unordered', 'items' => ['Ancien format']]],
        ]];
        $renderer = new \Prime\Editor\Renderer($app->get(BlockRegistry::class), $app->get(\Prime\Security\HtmlSanitizer::class));
        $html = $renderer->renderHtml($legacy, $entry);
        self::assertStringContainsString('id="repete-2"', $html);
        self::assertStringContainsString('<ul class="pc-block pc-block--list"></ul>', $html);
        $statement = $this->db($app)->pdo()->prepare('UPDATE pc_entries SET content = ?, content_html = ? WHERE id = 1');
        $statement->execute([(string) json_encode($legacy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $html]);

        $config = $this->editorConfig($app, 1);
        self::assertSame(false, $config['conversionFailed']);
        $children = $config['document']['blocks'][0]['data']['elements'][0]['children'];
        self::assertSame(['prime_block', 'prime_block', 'prime_block', 'original_content'], array_column($children, 'type'));
        self::assertSame('repete', $children[0]['settings']['block_anchor']);
        self::assertSame('repete-2', $children[1]['settings']['block_anchor']);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $saved = $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $config['document'], 'source_hash' => $config['sourceHash']], $token);
        self::assertSame(200, $saved->status(), $saved->body());
        $after = $repository->find(1);
        self::assertNotNull($after);
        foreach (explode("\n", $html) as $fragment) {
            self::assertStringContainsString($fragment, $after->contentHtml);
        }
    }

    public function testUnknownBlockBoundariesFailClosedAndStaleDocumentIsRejected(): void
    {
        $app = $this->activated('admin');
        $pdo = $this->db($app)->pdo();
        $content = '{"version":1,"blocks":[{"id":"a","type":"missing/block","data":{}},{"id":"b","type":"paragraph","data":{"text":"Deux"}}]}';
        $html = '<div>Ancien bloc</div>\n<p>Deux</p>';
        $stmt = $pdo->prepare('UPDATE pc_entries SET content = ?, content_html = ? WHERE id = 1');
        $stmt->execute([$content, $html]);
        $config = $this->editorConfig($app, 1);
        self::assertSame(true, $config['conversionFailed']);
        self::assertSame($content, $app->get(EntryRepository::class)->find(1)?->content);
        self::assertSame($html, $app->get(EntryRepository::class)->find(1)?->contentHtml);

        // A lone unavailable block has an unambiguous saved HTML fragment.
        $single = '{"version":1,"blocks":[{"id":"a","type":"missing/block","data":{}}]}';
        $stmt->execute([$single, '<div>Ancien bloc</div>']);
        $config = $this->editorConfig($app, 1);
        self::assertSame(false, $config['conversionFailed']);
        self::assertSame('original_content', $config['document']['blocks'][0]['data']['elements'][0]['children'][0]['type']);
        $stmt->execute([$single, '<div>Changé ailleurs</div>']);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        self::assertSame(409, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $config['document'], 'source_hash' => $config['sourceHash']], $token)->status());
        self::assertSame('<div>Changé ailleurs</div>', $app->get(EntryRepository::class)->find(1)?->contentHtml);
    }

    /** @return array<string, mixed> */
    private function editorConfig(Application $app, int $id): array
    {
        $response = $this->send($app, 'GET', '/admin/prime-builder/edit/' . $id);
        self::assertSame(200, $response->status(), $response->body());
        self::assertSame(1, preg_match('/<textarea hidden data-pb-config>(.*?)<\/textarea>/s', $response->body(), $matches));
        $decoded = json_decode(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        self::assertSame(true, is_array($decoded));
        return $decoded;
    }

    private function appLogin(Application $app): void
    {
        $app->get(\Prime\Auth\Auth::class)->login($this->user($app, 1));
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        return ['version' => 1, 'blocks' => [['id' => 'pb_layout', 'type' => 'prime-builder/layout', 'data' => ['version' => 1, 'elements' => [[
            'id' => 'one', 'type' => 'heading', 'settings' => ['text' => 'Bonjour', 'level' => 2],
            'styles' => ['desktop' => ['color' => '#c9441f']], 'children' => [],
        ]]]]]];
    }

    private function activated(?string $as): Application
    {
        $app = $this->createApp($as);
        $this->db($app)->pdo()->exec('CREATE TABLE pc_plugins (slug VARCHAR(64) PRIMARY KEY, version VARCHAR(64), status VARCHAR(20), manifest_hash VARCHAR(64), last_error TEXT, errored_at DATETIME, installed_at DATETIME, activated_at DATETIME, created_at DATETIME, updated_at DATETIME)');
        $this->assets = sys_get_temp_dir() . '/prime_builder_assets_' . bin2hex(random_bytes(4));
        mkdir($this->assets, 0755, true);
        $app->container()->instance(AssetPublisher::class, new AssetPublisher($this->assets, '/assets/plugins'));
        $app->get(PluginManager::class)->activate('prime-builder');
        return $app;
    }

    /** @param array<string, mixed>|null $body */
    private function send(Application $app, string $method, string $uri, ?array $body = null, ?string $token = null): Response
    {
        $headers = ['Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        return $app->get(Kernel::class)->handle(Request::create($method, $uri, headers: $headers, rawBody: $body === null ? '' : (string) json_encode($body)));
    }
}
