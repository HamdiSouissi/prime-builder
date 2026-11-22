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
use Prime\Themes\ThemeAssetRegistry;
use PrimeBuilder\Styles\Compiler;
use PrimeBuilder\Styles\Kit;
use PrimeBuilder\Tests\Support\DatabaseFixture;

require_once __DIR__ . '/Support/DatabaseFixture.php';

final class IntegrationTest extends TestCase
{
    use DatabaseFixture;

    private string $assets = '';

    protected function tearDown(): void
    {
        if ($this->assets !== '') {
            AssetPublisher::deleteDirectory($this->assets);
        }
        $this->removeMysqlDatabases();
    }

    public function testActivationRegistersEditorBlockAndPageFilter(): void
    {
        $app = $this->activated('admin');
        // The fixture activates after CMS boot, so apply the menu filter explicitly.
        // A syntactically valid but missing sprite icon can otherwise disappear or
        // break every administration screen in debug mode on a fresh request.
        $menu = $app->get(Hooks::class)->apply('admin.menu', new \Prime\Admin\Menu\AdminMenu());
        self::assertInstanceOf(\Prime\Admin\Menu\AdminMenu::class, $menu);
        foreach (['plugin.prime-builder', 'plugin.prime-builder-theme', 'plugin.prime-builder-submissions'] as $id) {
            $item = $menu->get($id);
            self::assertNotNull($item);
            self::assertSame(true, \Prime\View\Icon::exists($item->icon));
        }
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
        $legacyStatus = null;
        if (is_array($checks)) {
            foreach ($checks as $check) {
                if (is_array($check) && ($check['id'] ?? '') === 'prime-builder.legacy-auth') {
                    $legacyStatus = $check['status'] ?? null;
                }
            }
        }
        self::assertSame(version_compare($app->version(), '1.0.2', '<') ? 'warning' : null, $legacyStatus);
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

    public function testPlainTextSeparatesRenderedElementsAndNormalizesWhitespace(): void
    {
        $app = $this->activated(null);
        $document = $this->document();
        $document['blocks'][0]['data']['elements'][0]['settings'] = ['text' => 'Titre', 'level' => 2];
        $document['blocks'][0]['data']['elements'][] = [
            'id' => 'paragraph', 'type' => 'text', 'settings' => ['text' => '<p>Paragraphe</p>'], 'styles' => [], 'children' => [],
        ];
        $definition = $app->get(BlockRegistry::class)->get('prime-builder/layout');
        self::assertNotNull($definition);
        self::assertSame('Titre Paragraphe', ($definition->textExtractor)($document['blocks'][0]['data']));

        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $response = $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token);
        self::assertSame(200, $response->status(), $response->body());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertSame('Titre Paragraphe', $entry->contentText);

        $document['blocks'][0]['data']['elements'][1]['settings']['text'] = '<p>Du <code>code</code> &amp; du texte<br>Suite</p>';
        self::assertSame('Titre Du code & du texte Suite', ($definition->textExtractor)($document['blocks'][0]['data']));
    }

    public function testStarRatingIsLocalizedInPersistedContent(): void
    {
        $app = $this->activated(null);
        $document = $this->document();
        $document['blocks'][0]['data']['elements'][0] = [
            'id' => 'rating', 'type' => 'star_rating', 'settings' => ['value' => 4], 'styles' => [], 'children' => [],
        ];
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $response = $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token);
        self::assertSame(200, $response->status(), $response->body());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('role="img" aria-label="4 sur 5 étoiles"', $entry->contentHtml);
        self::assertStringContainsString('aria-hidden="true">★★★★☆', $entry->contentHtml);
    }

    public function testPartialPreviewRendersOnlyTheRequestedSubtreeAndValidatesTheWholeDocument(): void
    {
        $app = $this->activated(null);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $data = ['version' => 1, 'elements' => [[
            'id' => 'parent', 'type' => 'container', 'settings' => [], 'styles' => [], 'children' => [
                ['id' => 'first', 'type' => 'heading', 'settings' => ['text' => 'Premier'], 'styles' => [], 'children' => []],
                ['id' => 'second', 'type' => 'heading', 'settings' => ['text' => 'Second'], 'styles' => [], 'children' => []],
            ],
        ]]];
        $full = $this->send($app, 'POST', '/api/prime-builder/v1/documents/1/render', ['data' => $data], $token);
        self::assertSame(200, $full->status(), $full->body());
        $fullResult = json_decode($full->body(), true)['data'];
        self::assertStringContainsString('Premier', $fullResult['html']);
        self::assertStringContainsString('Second', $fullResult['html']);
        self::assertSame(2, count($fullResult['layout']['elements'][0]['children']));

        $part = $this->send($app, 'POST', '/api/prime-builder/v1/documents/1/render', ['data' => $data, 'target' => 'first'], $token);
        self::assertSame(200, $part->status(), $part->body());
        $partResult = json_decode($part->body(), true)['data'];
        self::assertSame('first', $partResult['target']);
        self::assertStringContainsString('Premier', $partResult['html']);
        self::assertStringNotContainsString('Second', $partResult['html']);
        self::assertSame(false, isset($partResult['layout']));
        self::assertSame(422, $this->send($app, 'POST', '/api/prime-builder/v1/documents/1/render', ['data' => $data, 'target' => 'missing'], $token)->status());
        self::assertSame(422, $this->send($app, 'POST', '/api/prime-builder/v1/documents/1/render', ['data' => $data, 'target' => ['first']], $token)->status());

        // An invalid sibling must not evade validation just because it is not rendered.
        $data['elements'][0]['children'][1]['styles'] = ['desktop' => ['color' => 'url(javascript:alert(1))']];
        self::assertSame(422, $this->send($app, 'POST', '/api/prime-builder/v1/documents/1/render', ['data' => $data, 'target' => 'first'], $token)->status());
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
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/tabs.css'));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/form.css', $assets->renderStyles());

        $assets = new ThemeAssetRegistry();
        $other = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($other);
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/tabs.js', $assets->renderScripts());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/tabs.css', $assets->renderStyles());
    }

    public function testGalleryAssetsAreConditionalAndFallbackLabelsAreLocalized(): void
    {
        $app = $this->activated(null);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'gallery', 'type' => 'gallery', 'settings' => ['images' => '/uploads/a.jpg|Couronne'], 'styles' => [], 'children' => []],
        ];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('aria-label="Agrandir l’image 1 : Couronne"', $entry->contentHtml);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/gallery.css'));
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/gallery.js'));
        $other = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($other);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/gallery.css', $assets->renderStyles());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/gallery.js', $assets->renderScripts());
    }

    public function testVideoAssetsArePresentOnlyOnPagesWithVideo(): void
    {
        $app = $this->activated(null);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'video', 'type' => 'video', 'settings' => ['url' => 'https://vimeo.com/12345678'], 'styles' => [], 'children' => []],
        ];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('data-pb-provider="vimeo"', $entry->contentHtml);
        self::assertStringNotContainsString('<iframe', $entry->contentHtml);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/video.css'));
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/video.js'));
        $other = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($other);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/video.css', $assets->renderStyles());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/video.js', $assets->renderScripts());
    }

    public function testWidgetStylesAreConditionalDeduplicatedAndCollectedFromNestedElements(): void
    {
        $app = $this->activated(null);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $children = [];
        foreach (['icon', 'icon_list', 'image_box', 'testimonial', 'toggle', 'social', 'star_rating'] as $type) {
            $children[] = ['id' => $type, 'type' => $type, 'settings' => [], 'styles' => [], 'children' => []];
        }
        $children[] = ['id' => 'second_box', 'type' => 'image_box', 'settings' => [], 'styles' => [], 'children' => []];
        $document['blocks'][0]['data']['elements'] = [['id' => 'wrap', 'type' => 'container', 'settings' => [], 'styles' => [], 'children' => $children]];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        $styles = $assets->renderStyles();
        self::assertSame(1, substr_count($styles, '/assets/plugins/prime-builder/css/widgets/container.css'));
        self::assertSame(1, substr_count($styles, '/assets/plugins/prime-builder/css/widgets/image_box.css'));
        self::assertSame(1, substr_count($styles, '/assets/plugins/prime-builder/css/widgets/toggle.css'));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/tabs.css', $styles);
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/form.css', $styles);
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/', $assets->renderScripts());
        $documentPosition = strpos($styles, 'href="/prime-builder/css/');
        $widgetPosition = strpos($styles, '/assets/plugins/prime-builder/css/widgets/image_box.css');
        self::assertNotFalse($documentPosition);
        self::assertNotFalse($widgetPosition);
        self::assertLessThan($documentPosition, $widgetPosition);
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
        self::assertSame($original, $this->send($app, 'GET', $url . '?after=1')->body());
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
        self::assertSame(403, $this->send($app, 'POST', '/api/prime-builder/v1/documents/2/render', ['data' => ['version' => 1, 'elements' => []]])->status());
        $csrf = (new Csrf($app->get(Session::class)))->token();
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Prime-CSRF' => $csrf];
        $request = Request::create('PATCH', '/api/prime-builder/v1/documents/2', headers: $headers, rawBody: (string) json_encode(['content' => $this->document()]));
        self::assertSame(200, $app->get(Kernel::class)->handle($request)->status());
        $request = Request::create('POST', '/api/prime-builder/v1/documents/2/render', headers: $headers, rawBody: (string) json_encode(['data' => ['version' => 1, 'elements' => []]]));
        self::assertSame(200, $app->get(Kernel::class)->handle($request)->status());
    }

    public function testFilteredHtmlRequiresCapabilityAndRemainsSafeAcrossCoreAndBuilderApis(): void
    {
        $app = $this->activated(null);
        $pdo = $this->db($app)->pdo();
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'prime_builder.edit'), (2, 'prime_builder.templates')");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        $editorToken = $this->tokens($app)->issue($this->user($app, 2), 'editor', ['*'])['token'];
        $layout = ['version' => 1, 'elements' => [[
            'id' => 'box', 'type' => 'container', 'settings' => [], 'styles' => [], 'children' => [[
                'id' => 'snippet', 'type' => 'html', 'settings' => ['markup' => '<p>HTML <strong>autorisé</strong><script>alert(1)</script><a href="javascript:alert(1)" onclick="alert(2)">lien</a></p>'], 'styles' => [], 'children' => [],
            ]],
        ]]];
        $document = ['version' => 1, 'blocks' => [['id' => 'pb_layout', 'type' => 'prime-builder/layout', 'data' => $layout]]];
        $template = ['name' => 'HTML', 'kind' => 'section', 'data' => $layout];
        $original = $app->get(EntryRepository::class)->find(3);
        self::assertNotNull($original);
        self::assertSame(403, $this->send($app, 'POST', '/api/prime-builder/v1/documents/3/render', ['data' => $layout], $editorToken)->status());
        self::assertSame(403, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $document], $editorToken)->status());
        self::assertSame(403, $this->send($app, 'POST', '/api/prime-builder/v1/documents/3/import', ['data' => $layout], $editorToken)->status());
        self::assertSame(403, $this->send($app, 'POST', '/api/prime-builder/v1/templates', $template, $editorToken)->status());
        // The core block validator cannot authorize a new unsigned widget either.
        self::assertSame(422, $this->send($app, 'PATCH', '/api/v1/content/post/3', ['content' => $document], $editorToken)->status());
        self::assertSame($original->content, $app->get(EntryRepository::class)->find(3)?->content);

        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'prime_builder.unfiltered_html')");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        $htmlToken = $this->tokens($app)->issue($this->user($app, 2), 'html author', ['*'])['token'];
        // An account's permission must not broaden a deliberately scoped token.
        $scoped = $this->tokens($app)->issue($this->user($app, 2), 'no html', ['prime_builder.edit', 'prime_builder.templates', 'content.edit_own'])['token'];
        self::assertSame(403, $this->send($app, 'POST', '/api/prime-builder/v1/documents/3/render', ['data' => $layout], $scoped)->status());
        self::assertSame(403, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $document], $scoped)->status());
        self::assertSame(403, $this->send($app, 'POST', '/api/prime-builder/v1/templates', $template, $scoped)->status());
        $preview = $this->send($app, 'POST', '/api/prime-builder/v1/documents/3/render', ['data' => $layout], $htmlToken);
        self::assertSame(200, $preview->status(), $preview->body());
        self::assertStringContainsString('<strong>autorisé</strong>', $preview->body());
        self::assertStringNotContainsString('<script', $preview->body());
        self::assertStringNotContainsString('onclick', $preview->body());
        self::assertStringNotContainsString('javascript:', $preview->body());
        $saved = $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $document], $htmlToken);
        self::assertSame(200, $saved->status(), $saved->body());
        $entry = $app->get(EntryRepository::class)->find(3);
        self::assertNotNull($entry);
        self::assertStringContainsString('<strong>autorisé</strong>', $entry->contentHtml);
        self::assertStringNotContainsString('<script', $entry->contentHtml);
        $stored = $entry->decodedContent();
        $settings = $stored['blocks'][0]['data']['elements'][0]['children'][0]['settings'];
        self::assertSame(3, $settings['signed_entry']);
        self::assertSame(64, strlen($settings['signature']));
        $savedTemplate = $this->send($app, 'POST', '/api/prime-builder/v1/templates', ['name' => 'HTML', 'kind' => 'section', 'data' => $stored['blocks'][0]['data']], $htmlToken);
        self::assertSame(201, $savedTemplate->status(), $savedTemplate->body());
        $templateSettings = json_decode($savedTemplate->body(), true)['data']['elements'][0]['children'][0]['settings'];
        self::assertSame(false, isset($templateSettings['signature']));
        self::assertSame(false, isset($templateSettings['signed_entry']));
        self::assertSame(200, $this->send($app, 'POST', '/api/prime-builder/v1/documents/3/import', ['data' => $layout], $htmlToken)->status());
        $admin = $this->tokens($app)->issue($this->user($app, 1), 'administrator', ['*'])['token'];
        $coreCopy = $this->send($app, 'PATCH', '/api/v1/content/post/2', ['content' => $stored], $admin);
        self::assertSame(200, $coreCopy->status(), $coreCopy->body());
        $copyEntry = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($copyEntry);
        self::assertStringContainsString('<strong>autorisé</strong>', $copyEntry->contentHtml);
        $crossEntry = $copyEntry->decodedContent();
        self::assertSame(2, $crossEntry['blocks'][0]['data']['elements'][0]['children'][0]['settings']['signed_entry']);
        $adminDuplicate = $app->get(\Prime\Content\EntryService::class)->duplicate(3, $this->user($app, 1));
        self::assertStringContainsString('<strong>autorisé</strong>', $adminDuplicate->contentHtml);
        self::assertSame('text', $adminDuplicate->decodedContent()['blocks'][0]['data']['elements'][0]['children'][0]['type']);

        // Revocation permits unchanged HTML to survive while denying edits or copies.
        $pdo->exec("DELETE FROM pc_role_capabilities WHERE role_id = 2 AND capability = 'prime_builder.unfiltered_html'");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        $unchanged = $stored;
        $unchanged['blocks'][0]['data']['elements'][] = ['id' => 'heading', 'type' => 'heading', 'settings' => ['text' => 'Autre contenu'], 'styles' => [], 'children' => []];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $unchanged], $htmlToken)->status());
        $changed = $unchanged;
        $changed['blocks'][0]['data']['elements'][0]['children'][0]['settings']['markup'] = '<p>Modification interdite</p>';
        self::assertSame(403, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $changed], $htmlToken)->status());
        self::assertSame(403, $this->send($app, 'POST', '/api/prime-builder/v1/documents/3/render', ['data' => $changed['blocks'][0]['data']], $htmlToken)->status());
        self::assertSame(422, $this->send($app, 'PATCH', '/api/v1/content/post/3', ['content' => $changed], $htmlToken)->status());
        $copy = $unchanged;
        $copy['blocks'][0]['data']['elements'][0]['children'][] = $copy['blocks'][0]['data']['elements'][0]['children'][0];
        $copy['blocks'][0]['data']['elements'][0]['children'][1]['id'] = 'copied';
        self::assertSame(403, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $copy], $htmlToken)->status());
        $beforeCrossEntry = $app->get(EntryRepository::class)->find(3);
        self::assertNotNull($beforeCrossEntry);
        self::assertSame(200, $this->send($app, 'PATCH', '/api/v1/content/post/3', ['content' => $crossEntry], $htmlToken)->status());
        self::assertSame($beforeCrossEntry->content, $app->get(EntryRepository::class)->find(3)?->content);
        $after = $app->get(EntryRepository::class)->find(3);
        self::assertNotNull($after);
        self::assertStringContainsString('<strong>autorisé</strong>', $after->contentHtml);
        self::assertStringContainsString('Autre contenu', $after->contentHtml);
        self::assertStringNotContainsString('Modification interdite', $after->contentHtml);
        // Core duplication has no destination ID during validation; keep the filtered
        // content as ordinary rich text rather than producing an empty duplicate.
        $duplicate = $app->get(\Prime\Content\EntryService::class)->duplicate(3, $this->user($app, 2));
        self::assertStringContainsString('<strong>autorisé</strong>', $duplicate->contentHtml);
        self::assertStringContainsString('Autre contenu', $duplicate->contentHtml);
        self::assertSame('text', $duplicate->decodedContent()['blocks'][0]['data']['elements'][0]['children'][0]['type']);
        $app->get(\Prime\Auth\Auth::class)->login($this->user($app, 2));
        $editor = $this->send($app, 'GET', '/admin/prime-builder/edit/3');
        self::assertSame(200, $editor->status());
        self::assertSame(1, preg_match('~<textarea hidden data-pb-config>(.*?)</textarea>~s', $editor->body(), $matches));
        $config = json_decode(html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        self::assertSame(false, $config['canHtml']);
        self::assertSame(true, $config['widgets']['html']['hidden']);
        self::assertSame(true, $config['widgets']['html']['readOnly']);
        self::assertSame(false, isset($config['widgets']['html']['fields']['signature']));
    }

    public function testCoreSaveCannotReplayOldSignedHtmlAfterCapabilityRevocation(): void
    {
        $app = $this->activated(null);
        $pdo = $this->db($app)->pdo();
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'prime_builder.edit'), (2, 'prime_builder.unfiltered_html')");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        $token = $this->tokens($app)->issue($this->user($app, 2), 'html author', ['*'])['token'];
        $document = ['version' => 1, 'blocks' => [['id' => 'pb_layout', 'type' => 'prime-builder/layout', 'data' => ['version' => 1, 'elements' => [[
            'id' => 'snippet', 'type' => 'html', 'settings' => ['markup' => '<p>Première version</p>'], 'styles' => [], 'children' => [],
        ]]]]]];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $document], $token)->status());
        $first = $app->get(EntryRepository::class)->find(3)?->decodedContent();
        self::assertNotNull($first);
        $second = $first;
        $second['blocks'][0]['data']['elements'][0]['settings']['markup'] = '<p>Seconde version</p>';
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $second], $token)->status());
        $current = $app->get(EntryRepository::class)->find(3);
        self::assertNotNull($current);
        self::assertStringContainsString('Seconde version', $current->contentHtml);
        $pdo->exec("DELETE FROM pc_role_capabilities WHERE role_id = 2 AND capability = 'prime_builder.unfiltered_html'");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        // The core hook API does not support aborting a save: this request reports 200,
        // but the guarded content remains unchanged. Builder's own API returns 403.
        self::assertSame(200, $this->send($app, 'PATCH', '/api/v1/content/post/3', ['content' => $first, 'title' => 'Titre changé'], $token)->status());
        $after = $app->get(EntryRepository::class)->find(3);
        self::assertNotNull($after);
        self::assertSame($current->content, $after->content);
        self::assertSame($current->contentHtml, $after->contentHtml);
        self::assertSame('Titre changé', $after->title);
        self::assertStringNotContainsString('Première version', $after->contentHtml);
        self::assertSame(403, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $first], $token)->status());
        // Core autosave bypasses content.saving; restoring that revision still calls update.
        $service = $app->get(\Prime\Content\EntryService::class);
        $actor = $this->user($app, 2);
        $autosave = $service->autosave(3, ['content' => $first], $actor);
        self::assertStringContainsString('Première version', $autosave->content);
        $restored = $service->restoreRevision(3, $autosave->id, $actor);
        self::assertSame($current->content, $restored->content);
        self::assertStringContainsString('Seconde version', $restored->contentHtml);
    }

    public function testBearerTokenCannotRetainRevokedAccountPermissions(): void
    {
        $app = $this->activated(null);
        $pdo = $this->db($app)->pdo();
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'prime_builder.edit'), (2, 'content.publish')");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        $token = $this->tokens($app)->issue($this->user($app, 2), 'builder', ['*'])['token'];
        $restricted = $this->tokens($app)->issue($this->user($app, 2), 'restricted', ['prime_builder.edit'])['token'];
        self::assertSame(404, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $this->document()], $restricted)->status());
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $this->document()], $token)->status());

        // A token issued before a role change must not retain publication or ownership rights.
        $pdo->exec("DELETE FROM pc_role_capabilities WHERE role_id = 2 AND capability = 'content.publish'");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        self::assertSame(403, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $this->document(), 'status' => 'published'], $token)->status());
        self::assertSame('draft', $app->get(EntryRepository::class)->find(3)?->status);
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $this->document()], $token)->status());
        $pdo->exec("DELETE FROM pc_role_capabilities WHERE role_id = 2 AND capability = 'content.edit_own'");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        self::assertSame(404, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $this->document()], $token)->status());
        self::assertSame(404, $this->send($app, 'POST', '/api/prime-builder/v1/documents/3/render', ['data' => ['version' => 1, 'elements' => []]], $token)->status());
        self::assertSame(404, $this->send($app, 'GET', '/api/prime-builder/v1/documents/3/live', token: $token)->status());
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'content.edit_own')");
        $pdo->exec("DELETE FROM pc_role_capabilities WHERE role_id = 2 AND capability = 'prime_builder.edit'");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        self::assertSame(403, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $this->document()], $token)->status());
        self::assertSame(403, $this->send($app, 'POST', '/api/prime-builder/v1/documents/3/render', ['data' => ['version' => 1, 'elements' => []]], $token)->status());
        self::assertSame(404, $this->send($app, 'GET', '/api/prime-builder/v1/documents/3/live', token: $token)->status());
        $pdo->exec("UPDATE pc_users SET status = 'disabled' WHERE id = 2");
        self::assertSame(401, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $this->document()], $token)->status());

        // Prime 1.0.2 expands '*' on issuance and ignores a literal wildcard in storage;
        // the effective API user must never become an administrator from that value.
        $admin = $this->tokens($app)->issue($this->user($app, 1), 'wildcard regression', ['*']);
        $statement = $pdo->prepare('UPDATE pc_api_tokens SET abilities = ? WHERE id = ?');
        $statement->execute(['["*"]', $admin['id']]);
        self::assertSame(403, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/2', ['content' => $this->document()], $admin['token'])->status());
    }

    public function testRevokedBearerAbilitiesAreRemovedFromCoreAndPluginApis(): void
    {
        $app = $this->activated(null);
        $pdo = $this->db($app)->pdo();
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'prime_builder.edit'), (2, 'prime_builder.templates'), (2, 'content.publish')");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        $token = $this->tokens($app)->issue($this->user($app, 2), 'before revocation', ['*'])['token'];
        $before = $this->send($app, 'GET', '/api/v1/me', token: $token);
        self::assertSame(200, $before->status());
        $beforeAbilities = json_decode($before->body(), true)['data']['abilities'];
        self::assertSame(true, in_array('content.publish', $beforeAbilities, true));
        self::assertSame(true, in_array('prime_builder.templates', $beforeAbilities, true));
        $template = ['name' => 'Avant retrait', 'kind' => 'page', 'data' => $this->document()['blocks'][0]['data']];
        $created = $this->send($app, 'POST', '/api/prime-builder/v1/templates', $template, $token);
        self::assertSame(201, $created->status(), $created->body());
        $templateId = json_decode($created->body(), true)['data']['id'];

        $pdo->exec("DELETE FROM pc_role_capabilities WHERE role_id = 2 AND capability IN ('content.publish', 'prime_builder.templates')");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        // The plugin guards HTTP; direct calls to the 1.0.1 core service remain outside it.
        $after = $this->send($app, 'GET', '/api/v1/me', token: $token);
        self::assertSame(200, $after->status());
        $afterAbilities = json_decode($after->body(), true)['data']['abilities'];
        self::assertSame(false, in_array('content.publish', $afterAbilities, true));
        self::assertSame(false, in_array('prime_builder.templates', $afterAbilities, true));
        self::assertSame(true, in_array('content.edit_own', $afterAbilities, true));
        self::assertSame(403, $this->send($app, 'PATCH', '/api/v1/content/post/3', ['status' => 'published'], $token)->status());
        self::assertSame(403, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $this->document(), 'status' => 'published'], $token)->status());
        self::assertSame(403, $this->send($app, 'POST', '/api/prime-builder/v1/templates', $template, $token)->status());
        self::assertSame(403, $this->send($app, 'DELETE', '/api/prime-builder/v1/templates/' . $templateId, token: $token)->status());
        // Unrevoked abilities still work; this is an intersection, not a blanket token ban.
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $this->document()], $token)->status());
        self::assertSame(200, $this->send($app, 'PATCH', '/api/v1/content/post/3', ['title' => 'Titre conservé'], $token)->status());
        $pdo->exec("DELETE FROM pc_role_capabilities WHERE role_id = 2 AND capability = 'content.edit_own'");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        self::assertSame(403, $this->send($app, 'PATCH', '/api/v1/content/post/3', ['title' => 'Interdit'], $token)->status());
        self::assertSame(404, $this->send($app, 'GET', '/api/v1/content/post/3', token: $token)->status());
        self::assertSame(404, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $this->document()], $token)->status());

        // Even an administrator token with a literal stored '*' is not an administrator.
        $admin = $this->tokens($app)->issue($this->user($app, 1), 'literal wildcard', ['*']);
        $statement = $pdo->prepare('UPDATE pc_api_tokens SET abilities = ? WHERE id = ?');
        $statement->execute(['["*"]', $admin['id']]);
        $wildcard = $this->send($app, 'GET', '/api/v1/me', token: $admin['token']);
        self::assertSame(200, $wildcard->status());
        self::assertSame([], json_decode($wildcard->body(), true)['data']['abilities']);
        self::assertSame(403, $this->send($app, 'GET', '/api/v1/users', token: $admin['token'])->status());
    }

    public function testContactFormRendersAndOnlyStartsForVisiblePublishedWidgets(): void
    {
        $app = $this->activated(null);
        $this->publishContactForm($app);
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('action="/api/prime-builder/v1/forms/1/submit"', $entry->contentHtml);
        self::assertStringContainsString('data-pb-form', $entry->contentHtml);
        self::assertStringContainsString('name="name"', $entry->contentHtml);
        self::assertStringContainsString('name="email"', $entry->contentHtml);
        self::assertStringContainsString('name="message"', $entry->contentHtml);
        self::assertStringNotContainsString('_csrf_token', $entry->contentHtml);
        self::assertStringNotContainsString('name="token"', $entry->contentHtml);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/forms.js'));
        $other = $app->get(EntryRepository::class)->find(3);
        self::assertNotNull($other);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/forms.js', $assets->renderScripts());

        $start = $this->formRequest($app, 'GET', '/api/prime-builder/v1/forms/1/start?node=contact');
        self::assertSame(200, $start->status(), $start->body());
        self::assertStringContainsString('no-store', (string) $start->header('Cache-Control'));
        $token = json_decode($start->body(), true)['data']['token'];
        self::assertIsString($token);
        self::assertMatchesRegularExpression('/^[0-9]+\.[a-f0-9]{32}\.[a-f0-9]{64}$/D', $token);
        $again = $this->formRequest($app, 'GET', '/api/prime-builder/v1/forms/1/start?node=contact');
        self::assertSame(200, $again->status());
        self::assertSame(false, $token === json_decode($again->body(), true)['data']['token']);
        self::assertSame(404, $this->formRequest($app, 'GET', '/api/prime-builder/v1/forms/1/start?node=hidden_form')->status());
        self::assertSame(404, $this->formRequest($app, 'GET', '/api/prime-builder/v1/forms/1/start?node=nested_hidden')->status());
        self::assertSame(404, $this->formRequest($app, 'GET', '/api/prime-builder/v1/forms/1/start?node=unknown')->status());
        self::assertSame(404, $this->formRequest($app, 'GET', '/api/prime-builder/v1/forms/2/start?node=contact')->status());
        self::assertSame(404, $this->formRequest($app, 'GET', '/api/prime-builder/v1/forms/0/start?node=contact')->status());
        self::assertSame(404, $this->formRequest($app, 'GET', '/api/prime-builder/v1/forms/1/start?node[]=contact')->status());

        // Removing a form or unpublishing its entry invalidates even an already issued start.
        $this->db($app)->pdo()->exec("UPDATE pc_entries SET status = 'draft' WHERE id = 1");
        self::assertSame(404, $this->formRequest($app, 'GET', '/api/prime-builder/v1/forms/1/start?node=contact')->status());
        self::assertSame(404, $this->formRequest($app, 'POST', '/api/prime-builder/v1/forms/1/submit', [
            'node' => 'contact', 'name' => 'Nom', 'email' => 'a@example.test', 'message' => 'Bonjour', 'website' => '', 'token' => $token,
        ])->status());
    }

    public function testContactFormValidatesAndProtectsRealAnonymousSubmissions(): void
    {
        $app = $this->activated(null);
        $this->publishContactForm($app);
        $tokens = \PrimeBuilder\Forms\Tokens::fromAppKey($app->config()->get('app.key'));
        self::assertNotNull($tokens);
        $payload = static fn (string $ip, int $age = 4): array => [
            'node' => 'contact', 'name' => '<script>danger()</script>', 'email' => 'sender@example.test',
            'message' => "Bonjour <img src=x onerror=alert(1)>\nSuite", 'website' => '',
            'token' => $tokens->issue(1, 'contact', $ip, time() - $age),
        ];
        $count = fn (): int => $this->db($app)->table('prime_builder_submissions')->count();
        $trap = $payload('198.51.100.10');
        $trap['website'] = 'https://spam.example';
        self::assertSame(201, $this->formRequest($app, 'POST', '/api/prime-builder/v1/forms/1/submit', $trap, '198.51.100.10')->status());
        self::assertSame(0, $count());

        $early = $payload('198.51.100.11', 0);
        self::assertSame(422, $this->formRequest($app, 'POST', '/api/prime-builder/v1/forms/1/submit', $early, '198.51.100.11')->status());
        $expired = $payload('198.51.100.12', 1801);
        self::assertSame(422, $this->formRequest($app, 'POST', '/api/prime-builder/v1/forms/1/submit', $expired, '198.51.100.12')->status());
        $future = $payload('198.51.100.13', -30);
        self::assertSame(422, $this->formRequest($app, 'POST', '/api/prime-builder/v1/forms/1/submit', $future, '198.51.100.13')->status());
        $forged = $payload('198.51.100.14');
        $forged['token'] .= '0';
        self::assertSame(422, $this->formRequest($app, 'POST', '/api/prime-builder/v1/forms/1/submit', $forged, '198.51.100.14')->status());
        $wrongIp = $payload('198.51.100.15');
        self::assertSame(422, $this->formRequest($app, 'POST', '/api/prime-builder/v1/forms/1/submit', $wrongIp, '198.51.100.16')->status());
        $wrongNode = $payload('198.51.100.17');
        $wrongNode['token'] = $tokens->issue(1, 'other', '198.51.100.17', time() - 4);
        self::assertSame(422, $this->formRequest($app, 'POST', '/api/prime-builder/v1/forms/1/submit', $wrongNode, '198.51.100.17')->status());
        $wrongEntry = $payload('198.51.100.18');
        $wrongEntry['token'] = $tokens->issue(2, 'contact', '198.51.100.18', time() - 4);
        self::assertSame(422, $this->formRequest($app, 'POST', '/api/prime-builder/v1/forms/1/submit', $wrongEntry, '198.51.100.18')->status());
        $invalid = $payload('198.51.100.19');
        $invalid['email'] = 'not an email';
        self::assertSame(422, $this->formRequest($app, 'POST', '/api/prime-builder/v1/forms/1/submit', $invalid, '198.51.100.19')->status());
        $invalid = $payload('198.51.100.20');
        $invalid['message'] = str_repeat('x', 24001);
        self::assertSame(422, $this->formRequest($app, 'POST', '/api/prime-builder/v1/forms/1/submit', $invalid, '198.51.100.20')->status());
        $invalid = $payload('198.51.100.21');
        $invalid['extra'] = 'unexpected';
        self::assertSame(422, $this->formRequest($app, 'POST', '/api/prime-builder/v1/forms/1/submit', $invalid, '198.51.100.21')->status());
        $invalid = $payload('198.51.100.22');
        $invalid['name'] = ['not', 'a', 'string'];
        self::assertSame(422, $this->formRequest($app, 'POST', '/api/prime-builder/v1/forms/1/submit', $invalid, '198.51.100.22')->status());
        self::assertSame(0, $count());

        $valid = $payload('198.51.100.23');
        $response = $this->formRequest($app, 'POST', '/api/prime-builder/v1/forms/1/submit', $valid, '198.51.100.23');
        self::assertSame(201, $response->status(), $response->body());
        self::assertStringContainsString('no-store', (string) $response->header('Cache-Control'));
        self::assertSame(1, $count());
        self::assertSame(409, $this->formRequest($app, 'POST', '/api/prime-builder/v1/forms/1/submit', $valid, '198.51.100.23')->status());
        self::assertSame(1, $count());
        $stored = $this->db($app)->table('prime_builder_submissions')->first();
        self::assertNotNull($stored);
        self::assertSame('<script>danger()</script>', $stored['sender_name']);
        self::assertSame("Bonjour <img src=x onerror=alert(1)>\nSuite", $stored['message']);
        self::assertStringNotContainsString('198.51.100.23', (string) json_encode($stored));
        // Deleting the message does not allow its still-valid start token to be reused.
        self::assertSame(1, $this->db($app)->table('prime_builder_submissions')->where('id', '=', $stored['id'])->delete());
        self::assertSame(409, $this->formRequest($app, 'POST', '/api/prime-builder/v1/forms/1/submit', $valid, '198.51.100.23')->status());
        self::assertSame(0, $count());
    }

    public function testContactFormRateLimitsAndScheduledPurge(): void
    {
        $app = $this->activated(null);
        $this->publishContactForm($app);
        $ip = '203.0.113.25';
        $body = ['node' => 'contact', 'name' => 'Nom', 'email' => 'bad', 'message' => 'Bonjour', 'website' => '', 'token' => 'invalid'];
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(422, $this->formRequest($app, 'POST', '/api/prime-builder/v1/forms/1/submit', $body, $ip)->status());
        }
        $limited = $this->formRequest($app, 'POST', '/api/prime-builder/v1/forms/1/submit', $body, $ip);
        self::assertSame(429, $limited->status());
        self::assertSame(true, (int) $limited->header('Retry-After') > 0);
        self::assertSame(422, $this->formRequest($app, 'POST', '/api/prime-builder/v1/forms/1/submit', $body, '203.0.113.26')->status());
        self::assertSame(0, $this->db($app)->table('prime_builder_submissions')->count());
        $tokens = \PrimeBuilder\Forms\Tokens::fromAppKey($app->config()->get('app.key'));
        self::assertNotNull($tokens);
        $now = time();
        $limiter = new \PrimeBuilder\Forms\RateLimit($this->db($app), $tokens);
        $first = $limiter->allow('probe', '203.0.113.40', 2, 60, $now);
        $second = $limiter->allow('probe', '203.0.113.40', 2, 60, $now);
        $third = $limiter->allow('probe', '203.0.113.40', 2, 60, $now);
        self::assertSame([true, true, false], [$first, $second, $third]);
        self::assertSame(true, $limiter->allow('probe', '203.0.113.41', 2, 60, $now));
        self::assertSame(true, $limiter->allow('probe', '203.0.113.40', 2, 60, $now + 60));
        $repository = new \PrimeBuilder\Forms\Submissions($this->db($app));
        $values = ['name' => 'Nom', 'email' => 'a@example.test', 'message' => 'Message'];
        self::assertSame(true, $repository->create(1, 'contact', $values, hash('sha256', 'old'), $now - 31 * 86400));
        self::assertSame(true, $repository->create(1, 'contact', $values, hash('sha256', 'new'), $now));
        $this->db($app)->table('prime_builder_form_limits')->insert(['key_hash' => hash('sha256', 'expired'), 'hits' => 1, 'expires_at' => gmdate('Y-m-d H:i:s', $now - 60)]);
        $schedule = $app->get(Hooks::class)->apply('scheduler.register', new \Prime\Scheduler\Schedule());
        self::assertSame(true, $schedule->has('prime-builder.purge-submissions'));
        self::assertSame('prime-builder', $schedule->get('prime-builder.purge-submissions')->owner);
        $purge = $schedule->get('prime-builder.purge-submissions')->closure();
        self::assertNotNull($purge);
        self::assertGreaterThanOrEqual(2, $purge());
        self::assertSame(1, $this->db($app)->table('prime_builder_submissions')->count());
        self::assertSame(false, $this->db($app)->table('prime_builder_form_limits')->where('key_hash', '=', hash('sha256', 'expired'))->exists());
    }

    public function testSubmissionInboxRequiresItsOwnCapabilityAndCsrf(): void
    {
        $app = $this->activated(null);
        $this->publishContactForm($app);
        $tokens = \PrimeBuilder\Forms\Tokens::fromAppKey($app->config()->get('app.key'));
        self::assertNotNull($tokens);
        $ip = '198.51.100.99';
        $body = ['node' => 'contact', 'name' => '<script>danger()</script>', 'email' => 'sender@example.test', 'message' => '<img src=x onerror=alert(1)>', 'website' => '', 'token' => $tokens->issue(1, 'contact', $ip, time() - 4)];
        self::assertSame(201, $this->formRequest($app, 'POST', '/api/prime-builder/v1/forms/1/submit', $body, $ip)->status());
        $row = $this->db($app)->table('prime_builder_submissions')->first();
        self::assertNotNull($row);
        $id = (int) $row['id'];
        $app->get(\Prime\Auth\Auth::class)->login($this->user($app, 2));
        $csrf = $app->get(Csrf::class)->token();
        self::assertSame(403, $this->send($app, 'GET', '/admin/prime-builder/submissions')->status());
        self::assertSame(403, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/submissions/' . $id . '/delete', body: ['_csrf_token' => $csrf]))->status());
        self::assertSame(1, $this->db($app)->table('prime_builder_submissions')->count());
        $this->db($app)->pdo()->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'prime_builder.forms')");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        $app->get(\Prime\Auth\Auth::class)->login($this->user($app, 2));
        $csrf = $app->get(Csrf::class)->token();
        $inbox = $this->send($app, 'GET', '/admin/prime-builder/submissions');
        self::assertSame(200, $inbox->status(), $inbox->body());
        self::assertStringContainsString('no-store', (string) $inbox->header('Cache-Control'));
        self::assertStringContainsString('&lt;script&gt;danger()&lt;/script&gt;', $inbox->body());
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $inbox->body());
        self::assertStringNotContainsString('<script>danger()</script>', $inbox->body());
        self::assertSame(419, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/submissions/' . $id . '/delete'))->status());
        self::assertSame(404, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/submissions/99999/delete', body: ['_csrf_token' => $csrf]))->status());
        self::assertSame(302, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/submissions/' . $id . '/delete', body: ['_csrf_token' => $csrf]))->status());
        self::assertSame(0, $this->db($app)->table('prime_builder_submissions')->count());
        $this->db($app)->pdo()->exec("DELETE FROM pc_role_capabilities WHERE role_id = 2 AND capability = 'prime_builder.forms'");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        $app->get(\Prime\Auth\Auth::class)->login($this->user($app, 2));
        self::assertSame(403, $this->send($app, 'GET', '/admin/prime-builder/submissions')->status());
    }

    public function testThemePartialsUseRealTemplatesAndRequireTheirOwnCapability(): void
    {
        $app = $this->activated('contributor');
        $admin = $this->tokens($app)->issue($this->user($app, 1), 'templates', ['*'])['token'];
        $layout = ['version' => 1, 'elements' => [
            ['id' => 'title', 'type' => 'heading', 'settings' => ['text' => 'En-tête du constructeur', 'level' => 2], 'styles' => ['desktop' => ['color' => '#123456']], 'children' => []],
            ['id' => 'tabs', 'type' => 'tabs', 'settings' => ['title_1' => 'Un', 'text_1' => 'Contenu', 'title_2' => 'Deux', 'text_2' => 'Suite'], 'styles' => [], 'children' => []],
            ['id' => 'anchor', 'type' => 'anchor', 'settings' => ['anchor' => 'section'], 'styles' => [], 'children' => []],
            ['id' => 'link', 'type' => 'button', 'settings' => ['text' => 'Lien', 'url' => '#section'], 'styles' => [], 'children' => []],
        ]];
        $created = $this->send($app, 'POST', '/api/prime-builder/v1/templates', ['name' => 'En-tête partagé', 'kind' => 'section', 'data' => $layout], $admin);
        self::assertSame(201, $created->status(), $created->body());
        $id = json_decode($created->body(), true)['data']['id'];
        $csrf = $app->get(Csrf::class)->token();
        $fields = ['_csrf_token' => $csrf, 'header' => $id, 'footer' => '', 'exclude' => '3'];
        self::assertSame(403, $this->send($app, 'GET', '/admin/prime-builder/theme')->status());
        self::assertSame(403, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/theme', body: $fields))->status());
        self::assertSame(false, $app->get(Settings::class)->has('plugin.prime-builder.theme_partials'));
        $this->db($app)->pdo()->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'settings.manage')");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        $settingsToken = $this->tokens($app)->issue($this->user($app, 2), 'settings only', ['*'])['token'];
        self::assertSame(422, $this->send($app, 'PATCH', '/api/v1/settings', ['plugin.prime-builder.theme_partials' => ['header' => $layout]], $settingsToken)->status());
        self::assertSame(false, $app->get(Settings::class)->has('plugin.prime-builder.theme_partials'));

        $this->db($app)->pdo()->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'prime_builder.theme_builder')");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        $app->get(\Prime\Auth\Auth::class)->login($this->user($app, 2));
        $fields['_csrf_token'] = $app->get(Csrf::class)->token();
        self::assertSame(200, $this->send($app, 'GET', '/admin/prime-builder/theme')->status());
        $noCsrf = $fields;
        unset($noCsrf['_csrf_token']);
        self::assertSame(419, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/theme', body: $noCsrf))->status());
        $invalid = $fields;
        $invalid['exclude'] = '3, 0, ../../';
        self::assertSame(422, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/theme', body: $invalid))->status());
        self::assertSame(false, $app->get(Settings::class)->has('plugin.prime-builder.theme_partials'));
        /** @var \ArrayObject<int, bool> $purges */
        $purges = new \ArrayObject();
        $app->get(Hooks::class)->on('cache.purged', static function (mixed $event) use ($purges): void {
            if (is_array($event) && ($event['scope'] ?? null) === 'pages') {
                $purges[] = true;
            }
        });
        self::assertSame(302, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/theme', body: $fields))->status());
        self::assertNotEmpty($purges->getArrayCopy());
        $entry = $app->get(EntryRepository::class)->find(1);
        $excluded = $app->get(EntryRepository::class)->find(3);
        self::assertNotNull($entry);
        self::assertNotNull($excluded);
        $page = new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry);
        $excludedPage = new PageContext(type: PageContext::TYPE_ENTRY, entry: $excluded);
        $hooks = $app->get(Hooks::class);
        $header = $hooks->apply('site.partial', null, 'header', $page, []);
        self::assertIsString($header);
        self::assertStringContainsString('En-tête du constructeur', $header);
        self::assertStringContainsString('class="pb-theme-header"', $header);
        self::assertStringContainsString('href="#pbt-header-', $header);
        self::assertStringContainsString('id="pbt-header-', $header);
        self::assertStringNotContainsString('data-pb-id="title"', $header);
        self::assertSame(null, $hooks->apply('site.partial', null, 'header', $excludedPage, []));
        self::assertSame(null, $hooks->apply('site.partial', null, 'footer', $page, []));
        self::assertSame('Autre plugin', $hooks->apply('site.partial', 'Autre plugin', 'header', $page, []));
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, $page);
        self::assertStringContainsString('/assets/plugins/prime-builder/js/tabs.js', $assets->renderScripts());
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/tabs.css'));
        self::assertSame(1, preg_match('~/prime-builder/theme/([a-f0-9]{16}\.css)~', $assets->renderStyles(), $matches));
        $css = $this->send($app, 'GET', '/prime-builder/theme/' . ($matches[1] ?? ''));
        self::assertSame(200, $css->status());
        self::assertStringContainsString('color:#123456', $css->body());
        self::assertStringContainsString('pbt_header_', $css->body());
        self::assertSame(404, $this->send($app, 'GET', '/prime-builder/theme/0000000000000000.css')->status());
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, $excludedPage);
        self::assertStringNotContainsString('/prime-builder/theme/', $assets->renderStyles());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/tabs.css', $assets->renderStyles());
        $fields['footer'] = $id;
        self::assertSame(302, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/theme', body: $fields))->status());
        $footer = $hooks->apply('site.partial', null, 'footer', $page, ['after_save' => true]);
        self::assertIsString($footer);
        self::assertStringContainsString('pbt_footer_', $footer);
        self::assertStringNotContainsString('pbt_header_', $footer);
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, $page);
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/tabs.js'));
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/tabs.css'));
        // A library manager deleting a source cannot silently change the selected theme snapshot.
        self::assertSame(200, $this->send($app, 'DELETE', '/api/prime-builder/v1/templates/' . $id, token: $admin)->status());
        self::assertSame($header, $hooks->apply('site.partial', null, 'header', $page, []));
        self::assertSame($footer, $hooks->apply('site.partial', null, 'footer', $page, ['after_delete' => true]));
        $dynamic = ['version' => 1, 'elements' => [['id' => 'posts', 'type' => 'posts', 'settings' => ['limit' => 3], 'styles' => [], 'children' => []]]];
        $dynamicTemplate = $this->send($app, 'POST', '/api/prime-builder/v1/templates', ['name' => 'Articles', 'kind' => 'section', 'data' => $dynamic], $admin);
        self::assertSame(201, $dynamicTemplate->status(), $dynamicTemplate->body());
        $fields['header'] = json_decode($dynamicTemplate->body(), true)['data']['id'];
        self::assertSame(422, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/theme', body: $fields))->status());
        self::assertSame($header, $hooks->apply('site.partial', null, 'header', $page, []));
        $duplicates = ['version' => 1, 'elements' => [
            ['id' => 'a', 'type' => 'anchor', 'settings' => ['anchor' => 'repetition'], 'styles' => [], 'children' => []],
            ['id' => 'b', 'type' => 'anchor', 'settings' => ['anchor' => 'repetition'], 'styles' => [], 'children' => []],
        ]];
        $duplicateTemplate = $this->send($app, 'POST', '/api/prime-builder/v1/templates', ['name' => 'Ancres', 'kind' => 'section', 'data' => $duplicates], $admin);
        self::assertSame(201, $duplicateTemplate->status(), $duplicateTemplate->body());
        $fields['header'] = json_decode($duplicateTemplate->body(), true)['data']['id'];
        self::assertSame(422, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/theme', body: $fields))->status());
        self::assertSame($header, $hooks->apply('site.partial', null, 'header', $page, []));

        $html = ['version' => 1, 'elements' => [['id' => 'snippet', 'type' => 'html', 'settings' => ['markup' => '<p>HTML <strong>sûr</strong><script>alert(1)</script></p>'], 'styles' => [], 'children' => []]]];
        $createdHtml = $this->send($app, 'POST', '/api/prime-builder/v1/templates', ['name' => 'HTML', 'kind' => 'section', 'data' => $html], $admin);
        self::assertSame(201, $createdHtml->status(), $createdHtml->body());
        $fields['header'] = json_decode($createdHtml->body(), true)['data']['id'];
        self::assertSame(403, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/theme', body: $fields))->status());
        self::assertSame($header, $hooks->apply('site.partial', null, 'header', $page, []));
        $this->db($app)->pdo()->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'prime_builder.unfiltered_html')");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        $app->get(\Prime\Auth\Auth::class)->login($this->user($app, 2));
        $fields['_csrf_token'] = $app->get(Csrf::class)->token();
        self::assertSame(302, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/theme', body: $fields))->status());
        $newHeader = $hooks->apply('site.partial', null, 'header', $page, ['after_html_save' => true]);
        self::assertIsString($newHeader);
        self::assertStringContainsString('<strong>sûr</strong>', $newHeader);
        self::assertStringNotContainsString('<script', $newHeader);
        self::assertSame($css->body(), $this->send($app, 'GET', '/prime-builder/theme/' . ($matches[1] ?? ''))->body());
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
        self::assertSame(422, $this->send($app, 'PATCH', '/api/v1/settings', ['plugin.prime-builder.configuration' => ['kit_primary' => '#badbad']], $token)->status());
        self::assertSame(false, $app->get(Settings::class)->has('plugin.prime-builder.kit_primary'));
        self::assertSame(false, $app->get(Settings::class)->has('plugin.prime-builder.configuration'));

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
        self::assertSame(false, $app->get(Settings::class)->has('plugin.prime-builder.configuration'));
        $response = $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/settings', body: $fields));
        self::assertSame(302, $response->status(), $response->body());
        $bundle = $app->get(Settings::class)->get('plugin.prime-builder.configuration');
        self::assertSame(true, $bundle['posts']);
        self::assertSame(1100, $bundle['breakpoint_tablet']);
        self::assertSame('#123456', $bundle['kit_primary']);
        self::assertSame('portfolio, product', $bundle['other_types']);
        self::assertSame(403, $this->send($app, 'GET', '/admin/prime-builder/edit/3')->status());
        $screen = $this->send($app, 'GET', '/admin/prime-builder/settings');
        self::assertStringContainsString('value="#123456"', $screen->body());
    }

    public function testSettingsBundleIsAtomicAndReadsOlderIndividualKeys(): void
    {
        $app = $this->activated('admin');
        $settings = $app->get(Settings::class);
        $settings->set('plugin.prime-builder.kit_primary', '#336699');
        $settings->set('plugin.prime-builder.breakpoint_tablet', 1200);
        $legacy = $this->editorConfig($app, 1);
        self::assertSame('#336699', $legacy['kitColors']['primary']);
        self::assertSame(1200, $legacy['tabletBreakpoint']);
        /** @var \ArrayObject<int, list<mixed>> $events */
        $events = new \ArrayObject();
        $app->get(Hooks::class)->on('settings.updated', static function (string $name, mixed $old, mixed $new) use ($events): void {
            if (str_starts_with($name, 'plugin.prime-builder.')) {
                $events[] = [$name, $old, $new];
            }
        });
        $fields = [
            '_csrf_token' => $app->get(Csrf::class)->token(), 'pages' => '1', 'posts' => '1', 'other_types' => 'portfolio',
            'breakpoint_tablet' => '1100', 'breakpoint_mobile' => '700',
            'kit_primary' => '#123456', 'kit_text' => '#abcdef', 'kit_font_size' => '18',
        ];
        $pdo = $this->db($app)->pdo();
        $trigger = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? "CREATE TRIGGER reject_builder_settings BEFORE INSERT ON pc_settings FOR EACH ROW BEGIN IF NEW.name = 'plugin.prime-builder.configuration' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected failure'; END IF; END"
            : "CREATE TRIGGER reject_builder_settings BEFORE INSERT ON pc_settings WHEN NEW.name = 'plugin.prime-builder.configuration' BEGIN SELECT RAISE(ABORT, 'injected failure'); END";
        $pdo->exec($trigger);
        $failed = $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/settings', body: $fields));
        self::assertSame(500, $failed->status());
        self::assertSame(false, $settings->has('plugin.prime-builder.configuration'));
        self::assertSame('#336699', $settings->get('plugin.prime-builder.kit_primary'));
        self::assertSame([], $events->getArrayCopy());
        $pdo->exec('DROP TRIGGER reject_builder_settings');
        $saved = $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/settings', body: $fields));
        self::assertSame(302, $saved->status());
        $bundle = $settings->get('plugin.prime-builder.configuration');
        self::assertSame(1, count($events));
        self::assertSame(['plugin.prime-builder.configuration', null, $bundle], $events[0]);
        self::assertSame('#123456', $bundle['kit_primary']);
        self::assertSame(1100, $bundle['breakpoint_tablet']);
        $config = $this->editorConfig($app, 1);
        self::assertSame('#123456', $config['kitColors']['primary']);
        self::assertSame(1100, $config['tabletBreakpoint']);
        $page = $app->get(ContentTypeRegistry::class)->get('post');
        self::assertNotNull($page);
        self::assertSame('prime-builder', $app->get(Hooks::class)->apply('content.editor', 'blocks', $page));
        $pdo->exec($trigger);
        $fields['kit_primary'] = '#ffffff';
        $fields['breakpoint_tablet'] = '1300';
        $failed = $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/settings', body: $fields));
        self::assertSame(500, $failed->status());
        self::assertSame($bundle, $settings->get('plugin.prime-builder.configuration'));
        self::assertSame(1, count($events));
        $pdo->exec('DROP TRIGGER reject_builder_settings');
    }

    public function testEditorAndTemplateAccessHaveSeparateCapabilities(): void
    {
        $app = $this->activated('contributor');
        $db = $this->db($app)->pdo();
        self::assertSame(403, $this->send($app, 'GET', '/admin/prime-builder')->status());
        self::assertSame(403, $this->send($app, 'GET', '/admin/prime-builder/edit/3')->status());
        $token = $this->tokens($app)->issue($this->user($app, 2), 'editor', ['*'])['token'];
        self::assertSame(403, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $this->document()], $token)->status());
        self::assertSame(403, $this->send($app, 'POST', '/api/prime-builder/v1/documents/3/render', ['data' => ['version' => 1, 'elements' => []]], $token)->status());
        $db->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'prime_builder.edit')");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        $app->get(\Prime\Auth\Auth::class)->login($this->user($app, 2));
        self::assertSame(200, $this->send($app, 'GET', '/admin/prime-builder')->status());
        self::assertSame(404, $this->send($app, 'GET', '/admin/prime-builder/edit/2')->status());
        // Tokens retain the capabilities granted at issuance; issue a new one after the role change.
        self::assertSame(403, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $this->document()], $token)->status());
        $token = $this->tokens($app)->issue($this->user($app, 2), 'builder editor', ['*'])['token'];
        $otherBefore = $app->get(EntryRepository::class)->find(2)?->content;
        self::assertSame(404, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/2', ['content' => $this->document()], $token)->status());
        self::assertSame($otherBefore, $app->get(EntryRepository::class)->find(2)?->content);
        self::assertSame(404, $this->send($app, 'POST', '/api/prime-builder/v1/documents/2/render', ['data' => ['version' => 1, 'elements' => []]], $token)->status());
        self::assertSame(200, $this->send($app, 'POST', '/api/prime-builder/v1/documents/3/render', ['data' => ['version' => 1, 'elements' => []]], $token)->status());
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $this->document()], $token)->status());

        // Public routes return 404 for another user's draft, even when its layout is valid.
        $app->get(\Prime\Content\EntryService::class)->update(2, ['content' => $this->document()], $this->user($app, 1));
        $css = (new Compiler())->compile($this->document()['blocks'][0]['data']);
        $cssUrl = '/prime-builder/css/2-' . substr(hash('sha256', $css), 0, 16) . '.css';
        self::assertSame(404, $this->send($app, 'GET', '/api/prime-builder/v1/documents/2/live', token: $token)->status());
        self::assertSame(404, $this->send($app, 'GET', $cssUrl)->status());
        $adminToken = $this->tokens($app)->issue($this->user($app, 1), 'builder admin', ['*'])['token'];
        self::assertSame(200, $this->send($app, 'GET', '/api/prime-builder/v1/documents/2/live', token: $adminToken)->status());
        $this->appLogin($app);
        $privateCss = $this->send($app, 'GET', $cssUrl);
        self::assertSame(200, $privateCss->status());
        self::assertStringContainsString('no-store', (string) $privateCss->header('Cache-Control'));
        $app->get(\Prime\Auth\Auth::class)->login($this->user($app, 2));
        $config = $this->editorConfig($app, 3);
        self::assertSame(false, $config['canTemplates']);
        self::assertSame(9, count($config['library']));
        $screen = $this->send($app, 'GET', '/admin/prime-builder/edit/3');
        self::assertStringContainsString('data-pb-panel="library" aria-pressed="false">', $screen->body());
        self::assertStringNotContainsString('data-pb-context="save_template"', $screen->body());
        self::assertStringNotContainsString('data-pb-import', $screen->body());

        $db->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'prime_builder.templates')");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        $app->get(\Prime\Auth\Auth::class)->login($this->user($app, 2));
        $config = $this->editorConfig($app, 3);
        self::assertSame(true, $config['canTemplates']);
        self::assertSame(9, count($config['library']));
        $screen = $this->send($app, 'GET', '/admin/prime-builder/edit/3');
        self::assertStringContainsString('data-pb-panel="library" aria-pressed="false">', $screen->body());
        self::assertStringContainsString('data-pb-context="save_template"', $screen->body());
        self::assertStringContainsString('data-pb-import', $screen->body());
    }

    public function testSharedTemplatesAreValidatedPersistentAndProtectedByCapabilitiesAndCsrf(): void
    {
        $app = $this->activated(null);
        $pdo = $this->db($app)->pdo();
        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'prime_builder.edit')");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        $editor = $this->tokens($app)->issue($this->user($app, 2), 'editor', ['*'])['token'];
        $admin = $this->tokens($app)->issue($this->user($app, 1), 'admin', ['*'])['token'];
        $layout = ['version' => 1, 'elements' => [[
            'id' => 'parent', 'type' => 'container', 'settings' => [], 'styles' => [], 'children' => [[
                'id' => 'child', 'type' => 'text', 'settings' => ['text' => '<p>Bonjour <strong>modèle</strong><script>alert(1)</script></p>'], 'styles' => [], 'children' => [],
            ]],
        ]]];
        $payload = ['name' => '<b>Modèle partagé</b>', 'kind' => 'section', 'data' => $layout];
        self::assertSame(401, $this->send($app, 'POST', '/api/prime-builder/v1/templates', $payload)->status());
        self::assertSame(200, $this->send($app, 'GET', '/api/prime-builder/v1/templates', token: $editor)->status());
        self::assertSame(403, $this->send($app, 'POST', '/api/prime-builder/v1/templates', $payload, $editor)->status());
        self::assertSame(403, $this->send($app, 'DELETE', '/api/prime-builder/v1/templates/' . str_repeat('a', 32), token: $editor)->status());
        self::assertSame(403, $this->send($app, 'POST', '/api/prime-builder/v1/documents/3/import', ['data' => $layout], $editor)->status());

        $created = $this->send($app, 'POST', '/api/prime-builder/v1/templates', $payload, $admin);
        self::assertSame(201, $created->status(), $created->body());
        $item = json_decode($created->body(), true)['data'];
        self::assertSame('Modèle partagé', $item['name']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $item['id']);
        self::assertStringNotContainsString('<script', $item['elements'][0]['children'][0]['settings']['text']);
        self::assertStringContainsString('<strong>modèle</strong>', $item['elements'][0]['children'][0]['settings']['text']);
        self::assertStringContainsString('no-store', (string) $created->header('Cache-Control'));
        $key = 'plugin.prime-builder.saved_template.' . $item['id'];
        $statement = $pdo->prepare('SELECT autoload FROM pc_settings WHERE name = ?');
        $statement->execute([$key]);
        self::assertSame(0, (int) $statement->fetchColumn());
        $app->get(Settings::class)->flushCache();
        $listing = $this->send($app, 'GET', '/api/prime-builder/v1/templates', token: $editor);
        $items = json_decode($listing->body(), true)['data'];
        self::assertSame(10, count($items));
        self::assertSame($item, $items[9]);
        // Inserting an available snapshot requires edit, not template management.
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = $item['elements'];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/3', ['content' => $document], $editor)->status());
        $inserted = $app->get(EntryRepository::class)->find(3);
        self::assertNotNull($inserted);
        self::assertStringContainsString('<strong>modèle</strong>', $inserted->contentHtml);

        $invalid = $payload;
        $invalid['data']['elements'][0]['children'][0]['type'] = 'unknown_widget';
        self::assertSame(422, $this->send($app, 'POST', '/api/prime-builder/v1/templates', $invalid, $admin)->status());
        $invalid = $payload;
        $invalid['name'] = str_repeat('a', 101);
        self::assertSame(422, $this->send($app, 'POST', '/api/prime-builder/v1/templates', $invalid, $admin)->status());
        $originals = \PrimeBuilder\Widgets\OriginalContent::fromAppKey($app->config()->get('app.key'));
        self::assertNotNull($originals);
        $protected = $originals->protect(3, 'legacy', '<p>Secret lié à une entrée</p>');
        self::assertNotNull($protected);
        $invalid = $payload;
        $invalid['data']['elements'][0]['children'][0] = ['id' => 'legacy', 'type' => 'original_content', 'settings' => ['payload' => $protected], 'styles' => [], 'children' => []];
        self::assertSame(422, $this->send($app, 'POST', '/api/prime-builder/v1/templates', $invalid, $admin)->status());

        $pdo->exec("INSERT INTO pc_role_capabilities (role_id, capability) VALUES (2, 'prime_builder.templates')");
        $app->get(\Prime\Auth\RoleRepository::class)->forget();
        $manager = $this->tokens($app)->issue($this->user($app, 2), 'template manager', ['*'])['token'];
        self::assertSame(403, $this->send($app, 'POST', '/api/prime-builder/v1/templates', $payload, $editor)->status());
        self::assertSame(200, $this->send($app, 'POST', '/api/prime-builder/v1/documents/3/import', ['data' => $layout], $manager)->status());
        self::assertSame(404, $this->send($app, 'POST', '/api/prime-builder/v1/documents/2/import', ['data' => $layout], $manager)->status());
        self::assertSame(200, $this->send($app, 'POST', '/api/prime-builder/v1/documents/3/import', ['data' => $invalid['data']], $manager)->status());
        $invalid['data']['elements'][0]['children'][0]['settings']['payload'] = $originals->protect(2, 'legacy', '<p>Autre entrée</p>');
        self::assertSame(422, $this->send($app, 'POST', '/api/prime-builder/v1/documents/3/import', ['data' => $invalid['data']], $manager)->status());
        self::assertSame(404, $this->send($app, 'DELETE', '/api/prime-builder/v1/templates/not-a-template', token: $manager)->status());

        $this->appLogin($app);
        self::assertSame(403, $this->send($app, 'POST', '/api/prime-builder/v1/templates', $payload)->status());
        self::assertSame(403, $this->send($app, 'DELETE', '/api/prime-builder/v1/templates/' . $item['id'])->status());
        $csrf = (new Csrf($app->get(Session::class)))->token();
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-Prime-CSRF' => $csrf];
        $request = Request::create('POST', '/api/prime-builder/v1/templates', headers: $headers, rawBody: (string) json_encode($payload));
        self::assertSame(201, $app->get(Kernel::class)->handle($request)->status());
        self::assertSame(200, $this->send($app, 'DELETE', '/api/prime-builder/v1/templates/' . $item['id'], token: $manager)->status());
        self::assertSame(false, $app->get(Settings::class)->has($key));
        self::assertSame(404, $this->send($app, 'DELETE', '/api/prime-builder/v1/templates/' . $item['id'], token: $manager)->status());
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
        $schema = $this->db($app)->schema();
        self::assertSame(true, $schema->hasTable('prime_builder_submissions'));
        self::assertSame(true, $schema->hasTable('prime_builder_form_tokens'));
        self::assertSame(true, $schema->hasTable('prime_builder_form_limits'));
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
        self::assertSame(false, $schema->hasTable('prime_builder_submissions'));
        self::assertSame(false, $schema->hasTable('prime_builder_form_tokens'));
        self::assertSame(false, $schema->hasTable('prime_builder_form_limits'));
        self::assertSame(false, $settings->has('plugin.prime-builder.kit_primary'));
        $capabilities = $pdo->query("SELECT COUNT(*) FROM pc_role_capabilities WHERE capability LIKE 'prime!_builder.%' ESCAPE '!'");
        if ($capabilities === false) {
            throw new \RuntimeException('Could not query remaining capabilities');
        }
        self::assertSame(0, (int) $capabilities->fetchColumn());
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
        $other = $repository->find(2);
        self::assertNotNull($other);
        self::assertStringNotContainsString('Contenu original conservé', $other->contentHtml);
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

    public function testUnavailableMiddleBlockKeepsSavedHtmlAndEditableNeighbours(): void
    {
        $app = $this->activated('admin');
        $legacy = ['version' => 1, 'blocks' => [
            ['id' => 'first', 'type' => 'paragraph', 'data' => ['text' => 'Premier contenu']],
            ['id' => 'missing', 'type' => 'html', 'data' => ['html' => "<p data-legacy=\"missing\">Bloc absent\n<strong>conservé</strong></p>"]],
            ['id' => 'last', 'type' => 'heading', 'data' => ['level' => 2, 'text' => 'Troisième contenu']],
        ]];
        $app->get(\Prime\Content\EntryService::class)->update(1, ['content' => $legacy], $this->user($app, 1));
        $repository = $app->get(EntryRepository::class);
        $before = $repository->find(1);
        self::assertNotNull($before);
        $beforeHtml = $before->contentHtml;
        // Simulate an extension becoming unavailable after its output was saved.
        $legacy['blocks'][1]['type'] = 'missing/block';
        $statement = $this->db($app)->pdo()->prepare('UPDATE pc_entries SET content = ? WHERE id = 1');
        $statement->execute([(string) json_encode($legacy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

        $config = $this->editorConfig($app, 1);
        self::assertSame(false, $config['conversionFailed']);
        $converted = $config['document'];
        $children = $converted['blocks'][0]['data']['elements'][0]['children'];
        self::assertSame(['prime_block', 'original_content', 'prime_block'], array_column($children, 'type'));
        $originals = \PrimeBuilder\Widgets\OriginalContent::fromAppKey($app->config()->get('app.key'));
        self::assertNotNull($originals);
        $original = $originals->open($children[1]['settings']['payload']);
        self::assertNotNull($original);
        self::assertSame('missing/block', $original['type']);
        self::assertStringContainsString("Bloc absent\n<strong>conservé</strong>", $original['html']);
        self::assertStringContainsString($original['html'], $beforeHtml);
        self::assertSame($beforeHtml, $repository->find(1)?->contentHtml);

        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $preview = $this->send($app, 'POST', '/api/prime-builder/v1/documents/1/render', ['data' => $converted['blocks'][0]['data']], $token);
        self::assertSame(200, $preview->status(), $preview->body());
        self::assertStringContainsString('data-legacy=\\"missing\\"', $preview->body());
        $saved = $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $converted, 'source_hash' => $config['sourceHash']], $token);
        self::assertSame(200, $saved->status(), $saved->body());
        $after = $repository->find(1);
        self::assertNotNull($after);
        self::assertStringContainsString($original['html'], $after->contentHtml);
        self::assertStringContainsString('id="troisieme-contenu"', $after->contentHtml);
        self::assertSame(true, strpos($after->contentHtml, 'Premier contenu') < strpos($after->contentHtml, 'Bloc absent'));
        self::assertSame(true, strpos($after->contentHtml, 'Bloc absent') < strpos($after->contentHtml, 'Troisième contenu'));
    }

    public function testUnavailableBlockIsRecoveredAtEitherEdgeOrWhenItsOutputIsEmpty(): void
    {
        $app = $this->activated('admin');
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        $known = ['id' => 'known', 'type' => 'paragraph', 'data' => ['text' => 'Bloc connu']];
        $missing = ['id' => 'unknown', 'type' => 'missing/block', 'data' => []];
        $renderer = new \Prime\Editor\Renderer($app->get(BlockRegistry::class), $app->get(\Prime\Security\HtmlSanitizer::class));
        $knownHtml = $renderer->renderHtml(['version' => 1, 'blocks' => [$known]], $entry);
        $unavailableHtml = "<div>Inconnu\navec plusieurs lignes</div>";
        $cases = [
            [[$missing, $known], $unavailableHtml . "\n" . $knownHtml, 0, $unavailableHtml],
            [[$known, $missing], $knownHtml . "\n" . $unavailableHtml, 1, $unavailableHtml],
            [[$known, $missing], $knownHtml, 1, ''],
        ];
        $originals = \PrimeBuilder\Widgets\OriginalContent::fromAppKey($app->config()->get('app.key'));
        self::assertNotNull($originals);
        $statement = $this->db($app)->pdo()->prepare('UPDATE pc_entries SET content = ?, content_html = ? WHERE id = 1');
        foreach ($cases as [$blocks, $html, $index, $expected]) {
            $statement->execute([(string) json_encode(['version' => 1, 'blocks' => $blocks]), $html]);
            $config = $this->editorConfig($app, 1);
            self::assertSame(false, $config['conversionFailed']);
            $children = $config['document']['blocks'][0]['data']['elements'][0]['children'];
            self::assertSame('original_content', $children[$index]['type']);
            $original = $originals->open($children[$index]['settings']['payload']);
            self::assertNotNull($original);
            self::assertSame($expected, $original['html']);
        }

        // Multiple unavailable renderers have no recoverable individual boundaries.
        $groupedHtml = $unavailableHtml . "\n" . $knownHtml . "\n<div>Autre</div>";
        $statement->execute([(string) json_encode(['version' => 1, 'blocks' => [$missing, $known, ['id' => 'other', 'type' => 'missing/other', 'data' => []]]]), $groupedHtml]);
        $config = $this->editorConfig($app, 1);
        self::assertSame(false, $config['conversionFailed']);
        $elements = $config['document']['blocks'][0]['data']['elements'];
        self::assertSame(['original_group'], array_column($elements, 'type'));
        $original = $originals->open($elements[0]['settings']['payload']);
        self::assertNotNull($original);
        self::assertSame(true, $original['grouped']);
        self::assertSame($groupedHtml, $original['html']);
    }

    public function testUnknownBlockBoundariesAreGroupedAndStaleDocumentIsRejected(): void
    {
        $app = $this->activated('admin');
        $pdo = $this->db($app)->pdo();
        $content = '{"version":1,"blocks":[{"id":"a","type":"missing/block","data":{}},{"id":"b","type":"paragraph","data":{"text":"Deux"}}]}';
        $html = '<div>Ancien bloc</div>\n<p>Deux</p>';
        $stmt = $pdo->prepare('UPDATE pc_entries SET content = ?, content_html = ? WHERE id = 1');
        $stmt->execute([$content, $html]);
        $config = $this->editorConfig($app, 1);
        self::assertSame(false, $config['conversionFailed']);
        self::assertSame(true, $config['converted']);
        $elements = $config['document']['blocks'][0]['data']['elements'];
        self::assertSame(['original_group'], array_column($elements, 'type'));
        self::assertSame([], $elements[0]['children']);
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertSame($content, $entry->content);
        self::assertSame($html, $entry->contentHtml);
        $originals = \PrimeBuilder\Widgets\OriginalContent::fromAppKey($app->config()->get('app.key'));
        self::assertNotNull($originals);
        $original = $originals->open($elements[0]['settings']['payload']);
        self::assertNotNull($original);
        self::assertSame($html, $original['html']);
        self::assertSame(true, $original['grouped']);

        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $preview = $this->send($app, 'POST', '/api/prime-builder/v1/documents/1/render', ['data' => $config['document']['blocks'][0]['data']], $token);
        self::assertSame(200, $preview->status(), $preview->body());
        self::assertStringContainsString($html, json_decode($preview->body(), true)['data']['html']);
        $saved = $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $config['document'], 'source_hash' => $config['sourceHash']], $token);
        self::assertSame(200, $saved->status(), $saved->body());
        $savedEntry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($savedEntry);
        self::assertStringContainsString($html, $savedEntry->contentHtml);
        $view = new EntryView($savedEntry, $app->get(UrlGenerator::class));
        self::assertStringContainsString($html, $view->content());
        $reopened = $this->editorConfig($app, 1);
        self::assertSame(false, $reopened['conversionFailed']);
        self::assertSame('original_group', $reopened['document']['blocks'][0]['data']['elements'][0]['type']);
        self::assertSame($html, $originals->open($reopened['document']['blocks'][0]['data']['elements'][0]['settings']['payload'])['html'] ?? null);

        // Grouped data is entry-bound and authenticated; it cannot be relabelled or restyled.
        $altered = $config['document'];
        $altered['blocks'][0]['data']['elements'][0]['settings']['payload'] .= 'x';
        self::assertSame(422, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $altered], $token)->status());
        self::assertSame(422, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/2', ['content' => $config['document']], $token)->status());
        $altered = $config['document'];
        $altered['blocks'][0]['data']['elements'][0]['type'] = 'original_content';
        self::assertSame(422, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $altered], $token)->status());
        $altered = $config['document'];
        $altered['blocks'][0]['data']['elements'][0]['styles'] = ['desktop' => ['color' => '#000']];
        self::assertSame(422, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $altered], $token)->status());
        $altered = $config['document'];
        $altered['blocks'][0]['data']['elements'][0]['settings']['payload'] = $app->get(\Prime\Security\Encrypter::class)->encrypt((string) json_encode(['entry' => 1, 'type' => 'saved-document', 'html' => '<script>alert(1)</script>', 'grouped' => true]));
        self::assertSame(422, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $altered], $token)->status());

        // A lone unavailable block has an unambiguous saved HTML fragment.
        $single = '{"version":1,"blocks":[{"id":"a","type":"missing/block","data":{}}]}';
        $stmt->execute([$single, '<div>Ancien bloc</div>']);
        $config = $this->editorConfig($app, 1);
        self::assertSame(false, $config['conversionFailed']);
        self::assertSame('original_content', $config['document']['blocks'][0]['data']['elements'][0]['children'][0]['type']);
        $stmt->execute([$single, '<div>Changé ailleurs</div>']);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        self::assertSame(409, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $config['document'], 'source_hash' => $config['sourceHash']], $token)->status());
        $changed = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($changed);
        self::assertSame('<div>Changé ailleurs</div>', $changed->contentHtml);
    }

    private function publishContactForm(Application $app): void
    {
        $token = $this->tokens($app)->issue($this->user($app, 1), 'contact editor', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'contact', 'type' => 'form', 'settings' => ['title' => 'Nous contacter', 'button_text' => 'Envoyer'], 'styles' => [], 'children' => []],
            ['id' => 'hidden_form', 'type' => 'form', 'settings' => [], 'styles' => [], 'children' => [], 'hidden' => true],
            ['id' => 'hidden_container', 'type' => 'container', 'settings' => [], 'styles' => [], 'hidden' => true, 'children' => [
                ['id' => 'nested_hidden', 'type' => 'form', 'settings' => [], 'styles' => [], 'children' => []],
            ]],
        ];
        $published = $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token);
        self::assertSame(200, $published->status(), $published->body());
        $draft = $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/2', ['content' => $document], $token);
        self::assertSame(200, $draft->status(), $draft->body());
    }

    /** @param array<string, mixed>|null $body */
    private function formRequest(Application $app, string $method, string $uri, ?array $body = null, string $ip = '127.0.0.1'): Response
    {
        $headers = ['Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        return $app->get(Kernel::class)->handle(Request::create(
            $method,
            $uri,
            headers: $headers,
            server: ['REMOTE_ADDR' => $ip],
            rawBody: $body === null ? '' : (string) json_encode($body),
        ));
    }

    /** @return array<string, mixed> */
    private function editorConfig(Application $app, int $id): array
    {
        $response = $this->send($app, 'GET', '/admin/prime-builder/edit/' . $id);
        self::assertSame(200, $response->status(), $response->body());
        self::assertSame(1, preg_match('/<textarea hidden data-pb-config>(.*?)<\/textarea>/s', $response->body(), $matches));
        $decoded = json_decode(html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
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
        if (getenv('PB_TEST_MYSQL') === '1') {
            self::assertSame('mysql', $this->db($app)->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        }
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
