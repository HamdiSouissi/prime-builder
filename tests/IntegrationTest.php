<?php

declare(strict_types=1);

namespace PrimeBuilder\Tests;

use PHPUnit\Framework\TestCase;
use Prime\Content\ContentType;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\EntryQuery;
use Prime\Content\EntryRepository;
use Prime\Content\Taxonomy;
use Prime\Content\TaxonomyRegistry;
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
use Prime\Site\PermalinkSettings;
use Prime\Site\UrlGenerator;
use Prime\Site\UrlResolver;
use Prime\Site\View\EntryView;
use Prime\Themes\ThemeAssetRegistry;
use PrimeBuilder\Styles\Compiler;
use PrimeBuilder\Styles\Kit;
use PrimeBuilder\Styles\Scope;
use PrimeBuilder\Tests\Support\DatabaseFixture;
use PrimeBuilder\Widgets\Content;

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

    public function testResponsiveVisibilityPersistsInEntryAndThemeStylesheetsWithoutScripts(): void
    {
        $app = $this->activated('admin');
        $app->get(Settings::class)->set('plugin.prime-builder.configuration', ['breakpoint_tablet' => 1000, 'breakpoint_mobile' => 600]);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'responsive', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [[
            'id' => 'section', 'type' => 'container', 'settings' => ['layout' => 'grid', 'columns' => 2],
            'styles' => ['desktop' => ['visibility' => 'hide'], 'tablet' => ['visibility' => 'show'], 'mobile' => ['visibility' => 'hide']],
            'children' => [['id' => 'action', 'type' => 'button', 'settings' => ['text' => 'Action visible en tablette', 'url' => '/'], 'styles' => [], 'children' => []]],
        ]];
        $saved = $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token);
        self::assertSame(200, $saved->status(), $saved->body());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('Action visible en tablette', $entry->contentHtml);
        $layout = $entry->decodedContent()['blocks'][0]['data'];
        self::assertSame('show', $layout['elements'][0]['styles']['tablet']['visibility']);
        $css = (new Compiler(1000, 600))->compile($layout);
        $url = '/prime-builder/css/1-' . substr(hash('sha256', $css), 0, 16) . '.css';
        $stylesheet = $this->send($app, 'GET', $url);
        self::assertSame(200, $stylesheet->status());
        self::assertSame($css, $stylesheet->body());
        $guard = ':where([data-pb-owner="' . Scope::forLayout($layout) . '"],:not([data-pb-owner])):not(:where(.pb-theme_zone .pb-element:not([data-pb-owner])))';
        self::assertStringContainsString('@media not all and (max-width:1000px){.pb-element[data-pb-id="section"]' . $guard . '{display:none!important}}', $css);
        self::assertStringContainsString('@media(max-width:600px){.pb-element[data-pb-id="section"]' . $guard . '{display:none!important}}', $css);
        self::assertStringNotContainsString('@media(max-width:1000px){@media not all', $css);
        $assets = new ThemeAssetRegistry();
        $hooks = $app->get(Hooks::class);
        $page = new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry);
        $hooks->emit('site.assets', $assets, $page);
        self::assertStringContainsString('/assets/plugins/prime-builder/css/widgets/button.css', $assets->renderStyles());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/', $assets->renderScripts());

        $invalid = $document;
        $invalid['blocks'][0]['data']['elements'][0]['styles']['tablet']['visibility'] = 'show;}body{display:none';
        self::assertSame(422, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $invalid], $token)->status());
        self::assertSame($css, $this->send($app, 'GET', $url)->body(), 'Existing fingerprinted styles remain immutable');

        $template = $this->send($app, 'POST', '/api/prime-builder/v1/templates', ['name' => 'Visibilité', 'kind' => 'section', 'data' => $document['blocks'][0]['data']], $token);
        self::assertSame(201, $template->status(), $template->body());
        $templateId = json_decode($template->body(), true)['data']['id'];
        $fields = ['_csrf_token' => $app->get(Csrf::class)->token(), 'header' => $templateId, 'footer' => '', 'exclude' => ''];
        self::assertSame(302, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/theme', body: $fields))->status());
        $header = $hooks->apply('site.partial', null, 'header', $page, []);
        self::assertIsString($header);
        self::assertSame(1, preg_match('/data-pb-id="([a-zA-Z0-9_-]+)"/', $header, $idMatches));
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, $page);
        self::assertSame(1, preg_match('~/prime-builder/theme/([a-f0-9]{16}\\.css)~', $assets->renderStyles(), $matches));
        $themeStylesheet = $this->send($app, 'GET', '/prime-builder/theme/' . ($matches[1] ?? ''));
        self::assertSame(200, $themeStylesheet->status());
        self::assertSame(1, preg_match('/data-pb-owner="([a-f0-9]{24})"/', $header, $ownerMatches));
        self::assertStringContainsString('.pb-element[data-pb-id="' . ($idMatches[1] ?? '') . '"]:where([data-pb-owner="' . ($ownerMatches[1] ?? '') . '"]){display:none!important}', $themeStylesheet->body());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/', $assets->renderScripts());
    }

    public function testResponsivePositionPersistsInEntryAndThemeStylesheetsWithoutScripts(): void
    {
        $app = $this->activated('admin');
        $app->get(Settings::class)->set('plugin.prime-builder.configuration', ['breakpoint_tablet' => 1000, 'breakpoint_mobile' => 600]);
        $config = $this->editorConfig($app, 1);
        self::assertSame('Décalage haut', $config['ui']['offset_top']);
        self::assertSame('Automatique', $config['ui']['auto']);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'responsive position', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [[
            'id' => 'positioned', 'type' => 'button', 'settings' => ['text' => 'Action positionnée', 'url' => '/'],
            'styles' => [
                'desktop' => ['position' => 'relative', 'offset_top' => -12, 'offset_left' => '10%', 'z_index' => -3],
                'tablet' => ['position' => 'sticky', 'offset_top' => 0, 'offset_left' => 'auto', 'z_index' => 'auto'],
                'mobile' => ['position' => 'static', 'offset_top' => 'auto', 'z_index' => 2],
            ],
            'children' => [],
        ]];
        $saved = $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token);
        self::assertSame(200, $saved->status(), $saved->body());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('Action positionnée', $entry->contentHtml);
        self::assertStringNotContainsString('style=', $entry->contentHtml);
        $layout = $entry->decodedContent()['blocks'][0]['data'];
        self::assertSame($document['blocks'][0]['data']['elements'][0]['styles'], $layout['elements'][0]['styles']);
        $css = (new Compiler(1000, 600))->compile($layout);
        $url = '/prime-builder/css/1-' . substr(hash('sha256', $css), 0, 16) . '.css';
        $stylesheet = $this->send($app, 'GET', $url);
        self::assertSame(200, $stylesheet->status());
        self::assertSame($css, $stylesheet->body());
        self::assertStringContainsString('position:relative;top:-12px;left:10%;z-index:-3;', $css);
        self::assertStringContainsString('position:sticky;top:0px;left:auto;z-index:auto;', $css);
        self::assertStringContainsString('position:static;top:auto;z-index:2;', $css);
        $hooks = $app->get(Hooks::class);
        $page = new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry);
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, $page);
        self::assertStringContainsString($url, $assets->renderStyles());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/', $assets->renderScripts());

        foreach ([['position', 'fixed;}body{display:none'], ['offset_top', '1px;display:none'], ['z_index', 1001]] as [$key, $bad]) {
            $invalid = $document;
            $invalid['blocks'][0]['data']['elements'][0]['styles']['desktop'][$key] = $bad;
            self::assertSame(422, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $invalid], $token)->status());
            self::assertSame(422, $this->send($app, 'PATCH', '/api/v1/content/post/1', ['content' => $invalid], $token)->status());
        }
        self::assertSame($css, $this->send($app, 'GET', $url)->body());
        self::assertSame($entry->content, $app->get(EntryRepository::class)->find(1)?->content);

        $template = $this->send($app, 'POST', '/api/prime-builder/v1/templates', ['name' => 'Position', 'kind' => 'section', 'data' => $layout], $token);
        self::assertSame(201, $template->status(), $template->body());
        $templateId = json_decode($template->body(), true)['data']['id'];
        $fields = ['_csrf_token' => $app->get(Csrf::class)->token(), 'header' => $templateId, 'footer' => '', 'exclude' => ''];
        self::assertSame(302, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/theme', body: $fields))->status());
        $header = $hooks->apply('site.partial', null, 'header', $page, []);
        self::assertIsString($header);
        self::assertSame(1, preg_match('/data-pb-id="([a-zA-Z0-9_-]+)"/', $header, $idMatches));
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, $page);
        self::assertSame(1, preg_match('~/prime-builder/theme/([a-f0-9]{16}\\.css)~', $assets->renderStyles(), $matches));
        $themeCss = $this->send($app, 'GET', '/prime-builder/theme/' . ($matches[1] ?? ''));
        self::assertSame(200, $themeCss->status());
        self::assertSame(1, preg_match('/data-pb-owner="([a-f0-9]{24})"/', $header, $ownerMatches));
        self::assertStringContainsString('.pb-element[data-pb-id="' . ($idMatches[1] ?? '') . '"]:where([data-pb-owner="' . ($ownerMatches[1] ?? '') . '"]){position:relative;top:-12px;left:10%;z-index:-3;}', $themeCss->body());
        self::assertStringContainsString('position:static;top:auto;z-index:2;', $themeCss->body());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/', $assets->renderScripts());
    }

    public function testResponsiveSpacingSidesPersistThroughBothApisAndThemeCopies(): void
    {
        $app = $this->activated('admin');
        $app->get(Settings::class)->set('plugin.prime-builder.configuration', ['breakpoint_tablet' => 1000, 'breakpoint_mobile' => 600]);
        $config = $this->editorConfig($app, 1);
        self::assertSame('Marge intérieure par côté', $config['ui']['padding_sides']);
        self::assertSame('Valeur générale pour', $config['ui']['value_all_sides']);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'spacing', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [[
            'id' => 'spaced', 'type' => 'button', 'settings' => ['text' => 'Espacements', 'url' => '/'],
            'styles' => [
                'desktop' => ['padding_top' => 12, 'margin_left' => 'auto', 'padding' => 20, 'margin' => 8],
                'tablet' => ['padding' => 0, 'margin_right' => -4],
                'mobile' => ['padding_bottom' => '1.5rem'],
            ],
            'children' => [],
        ]];
        $saved = $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token);
        self::assertSame(200, $saved->status(), $saved->body());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        $layout = $entry->decodedContent()['blocks'][0]['data'];
        self::assertSame($document['blocks'][0]['data']['elements'][0]['styles'], $layout['elements'][0]['styles']);
        self::assertStringContainsString('Espacements', $entry->contentHtml);
        self::assertStringNotContainsString('style=', $entry->contentHtml);
        $css = (new Compiler(1000, 600))->compile($layout);
        $url = '/prime-builder/css/1-' . substr(hash('sha256', $css), 0, 16) . '.css';
        self::assertSame($css, $this->send($app, 'GET', $url)->body());
        self::assertStringContainsString('padding:20px;margin:8px;padding-top:12px;margin-left:auto;', $css);
        self::assertStringContainsString('padding:0px;margin-right:-4px;', $css);
        self::assertStringContainsString('padding-bottom:1.5rem;', $css);
        $hooks = $app->get(Hooks::class);
        $page = new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry);
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, $page);
        self::assertStringContainsString($url, $assets->renderStyles());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/', $assets->renderScripts());
        foreach ([['padding_top', '-1px'], ['padding_left', 'auto'], ['margin_top', -2001], ['margin_right', 'auto;display:none']] as [$key, $bad]) {
            $invalid = $document;
            $invalid['blocks'][0]['data']['elements'][0]['styles']['desktop'][$key] = $bad;
            self::assertSame(422, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $invalid], $token)->status());
            self::assertSame(422, $this->send($app, 'PATCH', '/api/v1/content/post/1', ['content' => $invalid], $token)->status());
        }
        self::assertSame($css, $this->send($app, 'GET', $url)->body());
        self::assertSame($entry->content, $app->get(EntryRepository::class)->find(1)?->content);
        $document['blocks'][0]['data']['elements'][0]['styles']['mobile']['margin_bottom'] = '-2rem';
        self::assertSame(200, $this->send($app, 'PATCH', '/api/v1/content/post/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        $layout = $entry->decodedContent()['blocks'][0]['data'];
        self::assertSame('-2rem', $layout['elements'][0]['styles']['mobile']['margin_bottom']);
        $template = $this->send($app, 'POST', '/api/prime-builder/v1/templates', ['name' => 'Espacements', 'kind' => 'section', 'data' => $layout], $token);
        self::assertSame(201, $template->status(), $template->body());
        $templateId = json_decode($template->body(), true)['data']['id'];
        $fields = ['_csrf_token' => $app->get(Csrf::class)->token(), 'header' => $templateId, 'footer' => '', 'exclude' => ''];
        self::assertSame(302, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/theme', body: $fields))->status());
        $page = new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry);
        $header = $hooks->apply('site.partial', null, 'header', $page, []);
        self::assertIsString($header);
        self::assertSame(1, preg_match('/data-pb-id="([a-zA-Z0-9_-]+)"/', $header, $idMatches));
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, $page);
        self::assertSame(1, preg_match('~/prime-builder/theme/([a-f0-9]{16}\\.css)~', $assets->renderStyles(), $matches));
        $themeCss = $this->send($app, 'GET', '/prime-builder/theme/' . ($matches[1] ?? ''));
        self::assertSame(200, $themeCss->status());
        self::assertSame(1, preg_match('/data-pb-owner="([a-f0-9]{24})"/', $header, $ownerMatches));
        self::assertStringContainsString('.pb-element[data-pb-id="' . ($idMatches[1] ?? '') . '"]:where([data-pb-owner="' . ($ownerMatches[1] ?? '') . '"]){padding:20px;margin:8px;padding-top:12px;margin-left:auto;}', $themeCss->body());
        self::assertStringContainsString('padding-bottom:1.5rem;margin-bottom:-2rem;', $themeCss->body());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/', $assets->renderScripts());
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

    public function testImageCarouselAssetsLoadOnlyWhenTheWidgetIsPresent(): void
    {
        $app = $this->activated(null);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'carousel', 'type' => 'image_carousel', 'settings' => ['images' => '/uploads/a.jpg|Paysage'], 'styles' => [], 'children' => []],
        ];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('data-pb-carousel-label="Carrousel d’images"', $entry->contentHtml);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/image_carousel.css'));
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/image_carousel.js'));
        $other = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($other);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/image_carousel.css', $assets->renderStyles());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/image_carousel.js', $assets->renderScripts());
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

    public function testCallToActionAndPricingStylesLoadOnlyWhenTheirWidgetsArePresent(): void
    {
        $app = $this->activated(null);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'cta', 'type' => 'call_to_action', 'settings' => ['title' => 'Ensemble', 'url' => '/contact', 'button_text' => 'Contacter'], 'styles' => [], 'children' => []],
            ['id' => 'price1', 'type' => 'price_table', 'settings' => ['title' => 'Offre', 'price' => '29 €'], 'styles' => [], 'children' => []],
            ['id' => 'price2', 'type' => 'price_table', 'settings' => ['title' => 'Autre offre', 'price' => '49 €'], 'styles' => [], 'children' => []],
            ['id' => 'list', 'type' => 'price_list', 'settings' => ['title' => 'Tarifs', 'items' => 'Service | 25 € | Description'], 'styles' => [], 'children' => []],
        ];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('href="/contact">Contacter</a>', $entry->contentHtml);
        self::assertStringContainsString('<dt>Service</dt><dd><strong>25 €</strong><span>Description</span></dd>', $entry->contentHtml);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        foreach (['call_to_action', 'price_table', 'price_list'] as $type) {
            self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/' . $type . '.css'));
            self::assertStringNotContainsString('/assets/plugins/prime-builder/js/' . $type . '.js', $assets->renderScripts());
        }
        $other = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($other);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        foreach (['call_to_action', 'price_table', 'price_list'] as $type) {
            self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/' . $type . '.css', $assets->renderStyles());
        }
    }

    public function testTestimonialCarouselPersistsAndSharesItsScriptWithImageCarousels(): void
    {
        $app = $this->activated('admin');
        $config = $this->editorConfig($app, 1);
        self::assertSame('Carrousel de témoignages', $config['widgets']['testimonial_carousel']['label']);
        self::assertSame('testimonial_items', $config['widgets']['testimonial_carousel']['fields']['items']['type']);
        self::assertSame('Une ligne : texte | auteur | fonction | URL du portrait (auteur, fonction et portrait facultatifs)', $config['widgets']['testimonial_carousel']['fields']['items']['label']);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'outer', 'type' => 'container', 'settings' => [], 'styles' => [], 'children' => [
                ['id' => 'testimonials', 'type' => 'testimonial_carousel', 'settings' => ['label' => 'Retours clients', 'items' => "Excellent | Camille | Designer\nTrès bien | Alex"], 'styles' => [], 'children' => []],
            ]],
            ['id' => 'testimonials2', 'type' => 'testimonial_carousel', 'settings' => ['items' => 'Autre témoignage'], 'styles' => [], 'children' => []],
            ['id' => 'images', 'type' => 'image_carousel', 'settings' => ['images' => '/uploads/a.jpg|Paysage'], 'styles' => [], 'children' => []],
        ];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('data-pb-carousel-label="Retours clients"', $entry->contentHtml);
        self::assertStringContainsString('<blockquote><p>Excellent</p></blockquote>', $entry->contentHtml);
        self::assertStringContainsString('<cite>Camille</cite><span>Designer</span>', $entry->contentHtml);
        self::assertStringContainsString('<blockquote><p>Très bien</p></blockquote>', $entry->contentHtml);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        foreach (['testimonial_carousel', 'image_carousel'] as $type) {
            self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/' . $type . '.css'));
        }
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/image_carousel.js'));
        $other = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($other);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        foreach (['testimonial_carousel', 'image_carousel'] as $type) {
            self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/' . $type . '.css', $assets->renderStyles());
        }
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/image_carousel.js', $assets->renderScripts());
    }

    public function testMediaCarouselPersistsAndLoadsItsAssetsOnlyWhereNeeded(): void
    {
        $app = $this->activated('admin');
        $config = $this->editorConfig($app, 1);
        self::assertSame('Carrousel de médias', $config['widgets']['media_carousel']['label']);
        self::assertSame('media_carousel_items', $config['widgets']['media_carousel']['fields']['items']['type']);
        self::assertSame('fr', $config['widgetDefaults']['media_carousel']['caption_language']);
        self::assertStringContainsString('image | /assets/plugins/prime-builder/images/landscape.svg | Paysage abstrait', $config['widgetDefaults']['media_carousel']['items']);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'outer', 'type' => 'container', 'settings' => [], 'styles' => [], 'children' => [
                ['id' => 'media', 'type' => 'media_carousel', 'settings' => ['label' => 'Notre travail', 'items' => "image | /uploads/a.jpg | Paysage | Légende\nvideo | /uploads/demo.webm | Démonstration | /uploads/demo.vtt | Transcription", 'caption_language' => 'fr'], 'styles' => [], 'children' => []],
            ]],
            ['id' => 'testimonials', 'type' => 'testimonial_carousel', 'settings' => ['items' => 'Excellent | Camille'], 'styles' => [], 'children' => []],
            ['id' => 'images', 'type' => 'image_carousel', 'settings' => ['images' => '/uploads/a.jpg|Paysage'], 'styles' => [], 'children' => []],
        ];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('data-pb-carousel-label="Notre travail"', $entry->contentHtml);
        self::assertStringContainsString('src="/uploads/a.jpg" alt="Paysage"', $entry->contentHtml);
        self::assertStringContainsString('<video controls preload="none" aria-label="Démonstration">', $entry->contentHtml);
        self::assertStringContainsString('src="/uploads/demo.vtt" srclang="fr"', $entry->contentHtml);
        self::assertStringContainsString('<p>Transcription</p>', $entry->contentHtml);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        foreach (['media_carousel', 'testimonial_carousel', 'image_carousel'] as $type) {
            self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/' . $type . '.css'));
        }
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/image_carousel.js'));
        $other = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($other);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/media_carousel.css', $assets->renderStyles());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/image_carousel.js', $assets->renderScripts());
    }

    public function testPortfolioPersistsAndLoadsItsAssetsOnlyWhereNeeded(): void
    {
        $app = $this->activated('admin');
        $config = $this->editorConfig($app, 1);
        self::assertSame('Portfolio', $config['widgets']['portfolio']['label']);
        self::assertSame('portfolio_items', $config['widgets']['portfolio']['fields']['items']['type']);
        self::assertStringContainsString('Une identité vivante', $config['widgetDefaults']['portfolio']['items']);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'outer', 'type' => 'container', 'settings' => [], 'styles' => [], 'children' => [
                ['id' => 'projects', 'type' => 'portfolio', 'settings' => ['columns' => 2, 'level' => 2, 'items' => "/uploads/a.jpg | Paysage | Projet A | Identité | Notre travail | /suite\n/uploads/b.jpg | Forêt | Projet B | Sites"], 'styles' => [], 'children' => []],
            ]],
            ['id' => 'projects2', 'type' => 'portfolio', 'settings' => ['items' => '/uploads/a.jpg | Paysage | Autre projet | Identité'], 'styles' => [], 'children' => []],
        ];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('data-pb-category="Identité"', $entry->contentHtml);
        self::assertStringContainsString('<h2><a href="/suite">Projet A</a></h2>', $entry->contentHtml);
        self::assertStringContainsString('<h2>Projet B</h2>', $entry->contentHtml);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/portfolio.css'));
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/portfolio.js'));
        $other = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($other);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/portfolio.css', $assets->renderStyles());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/portfolio.js', $assets->renderScripts());
    }

    public function testSlideshowPersistsAndSharesCarouselAssets(): void
    {
        $app = $this->activated('admin');
        $config = $this->editorConfig($app, 1);
        self::assertSame('Diaporama', $config['widgets']['slideshow']['label']);
        self::assertSame('slideshow_items', $config['widgets']['slideshow']['fields']['items']['type']);
        self::assertStringContainsString('Idées à partager', $config['widgetDefaults']['slideshow']['items']);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'outer', 'type' => 'container', 'settings' => [], 'styles' => [], 'children' => [
                ['id' => 'slides', 'type' => 'slideshow', 'settings' => ['label' => 'Nos projets', 'level' => 2, 'items' => "/uploads/a.jpg | Paysage | Bienvenue | Notre projet | Découvrir | /suite\n/uploads/b.jpg | Forêt | Ensemble"], 'styles' => [], 'children' => []],
            ]],
            ['id' => 'media', 'type' => 'media_carousel', 'settings' => ['items' => 'image | /uploads/a.jpg | Paysage'], 'styles' => [], 'children' => []],
            ['id' => 'testimonials', 'type' => 'testimonial_carousel', 'settings' => ['items' => 'Excellent | Camille'], 'styles' => [], 'children' => []],
            ['id' => 'images', 'type' => 'image_carousel', 'settings' => ['images' => '/uploads/a.jpg|Paysage'], 'styles' => [], 'children' => []],
        ];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('data-pb-carousel-label="Nos projets"', $entry->contentHtml);
        self::assertStringContainsString('<h2>Bienvenue</h2><p>Notre projet</p><a href="/suite">Découvrir</a>', $entry->contentHtml);
        self::assertStringContainsString('<h2>Ensemble</h2>', $entry->contentHtml);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        foreach (['slideshow', 'media_carousel', 'testimonial_carousel', 'image_carousel'] as $type) {
            self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/' . $type . '.css'));
        }
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/image_carousel.js'));
        $other = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($other);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/slideshow.css', $assets->renderStyles());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/image_carousel.js', $assets->renderScripts());
    }

    public function testAnimatedHeadingAndCodePersistAndLoadOnlyTheirStyles(): void
    {
        $app = $this->activated('admin');
        $config = $this->editorConfig($app, 1);
        self::assertSame('Titre animé', $config['widgets']['animated_heading']['label']);
        self::assertSame('Texte mis en valeur', $config['widgets']['animated_heading']['fields']['highlight']['label']);
        self::assertSame('Code coloré', $config['widgets']['code']['label']);
        self::assertSame('code', $config['widgets']['code']['fields']['source']['type']);
        self::assertSame('Code source', $config['widgets']['code']['fields']['source']['label']);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $source = "  const value = \"<img src=x onerror=alert(1)>\";\n// café\n";
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'heading1', 'type' => 'animated_heading', 'settings' => ['before' => 'Créer', 'highlight' => 'ensemble', 'after' => 'demain', 'level' => 2, 'effect' => 'rise'], 'styles' => [], 'children' => []],
            ['id' => 'container', 'type' => 'container', 'settings' => [], 'styles' => [], 'children' => [
                ['id' => 'code1', 'type' => 'code', 'settings' => ['language' => 'javascript', 'source' => $source, 'label' => 'Exemple sûr'], 'styles' => [], 'children' => []],
            ]],
            ['id' => 'heading2', 'type' => 'animated_heading', 'settings' => ['highlight' => 'Suite'], 'styles' => [], 'children' => []],
            ['id' => 'code2', 'type' => 'code', 'settings' => ['language' => 'html', 'source' => '<strong>Texte</strong>'], 'styles' => [], 'children' => []],
        ];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        $saved = json_decode($entry->content, true);
        self::assertSame($source, $saved['blocks'][0]['data']['elements'][1]['children'][0]['settings']['source']);
        self::assertStringContainsString('pb-animated-highlight--rise">ensemble</span> demain', $entry->contentHtml);
        self::assertStringContainsString('<span class="pb-code-token--keyword">const</span>', $entry->contentHtml);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $entry->contentHtml);
        self::assertStringNotContainsString('<img src=x', $entry->contentHtml);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        foreach (['animated_heading', 'code'] as $type) {
            self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/' . $type . '.css'));
            self::assertStringNotContainsString('/assets/plugins/prime-builder/js/' . $type . '.js', $assets->renderScripts());
        }
        $other = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($other);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        foreach (['animated_heading', 'code'] as $type) {
            self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/' . $type . '.css', $assets->renderStyles());
        }
    }

    public function testReviewAndFlipBoxPersistAndLoadOnlyTheirRequiredAssets(): void
    {
        $app = $this->activated('admin');
        $config = $this->editorConfig($app, 1);
        self::assertSame('Avis', $config['widgets']['review']['label']);
        self::assertSame('Note (0 à 5 étoiles)', $config['widgets']['review']['fields']['rating']['label']);
        self::assertSame('Boîte retournable', $config['widgets']['flip_box']['label']);
        self::assertSame('Titre du recto', $config['widgets']['flip_box']['fields']['front_title']['label']);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'review1', 'type' => 'review', 'settings' => ['title' => 'Avis', 'rating' => 4, 'text' => '<p>Très bien</p>', 'author' => 'Camille'], 'styles' => [], 'children' => []],
            ['id' => 'review2', 'type' => 'review', 'settings' => ['rating' => 5], 'styles' => [], 'children' => []],
            ['id' => 'container', 'type' => 'container', 'settings' => [], 'styles' => [], 'children' => [
                ['id' => 'flip1', 'type' => 'flip_box', 'settings' => ['front_title' => 'Offre', 'back_title' => 'Détails', 'url' => '/offre', 'button_text' => 'Découvrir'], 'styles' => [], 'children' => []],
            ]],
            ['id' => 'flip2', 'type' => 'flip_box', 'settings' => [], 'styles' => [], 'children' => []],
        ];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('aria-label="4 sur 5 étoiles"', $entry->contentHtml);
        self::assertStringContainsString('data-pb-flip-front><h2>Offre</h2>', $entry->contentHtml);
        self::assertStringContainsString('data-pb-flip-back><h2>Détails</h2>', $entry->contentHtml);
        self::assertStringContainsString('href="/offre">Découvrir</a>', $entry->contentHtml);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        foreach (['review', 'flip_box'] as $type) {
            self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/' . $type . '.css'));
        }
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/flip_box.js'));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/review.js', $assets->renderScripts());
        $other = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($other);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        foreach (['review', 'flip_box'] as $type) {
            self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/' . $type . '.css', $assets->renderStyles());
        }
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/flip_box.js', $assets->renderScripts());
    }

    public function testCountdownPersistsUtcDatesAndLoadsDeduplicatedAssetsOnlyWhereNeeded(): void
    {
        $app = $this->activated('admin');
        $config = $this->editorConfig($app, 1);
        self::assertSame('Compte à rebours', $config['widgets']['countdown']['label']);
        self::assertSame('Échéance (UTC)', $config['widgets']['countdown']['fields']['deadline']['label']);
        self::assertSame('datetime_utc', $config['widgets']['countdown']['fields']['deadline']['type']);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'outer', 'type' => 'container', 'settings' => [], 'styles' => [], 'children' => [
                ['id' => 'first', 'type' => 'countdown', 'settings' => ['title' => 'Prochain événement', 'deadline' => '2028-02-29T13:05', 'finished_text' => 'Ouvert'], 'styles' => [], 'children' => []],
            ]],
            ['id' => 'second', 'type' => 'countdown', 'settings' => ['deadline' => '2000-01-01T00:00'], 'styles' => [], 'children' => []],
        ];
        $invalid = $document;
        $invalid['blocks'][0]['data']['elements'][0]['children'][0]['settings']['deadline'] = '2027-02-29T13:05';
        self::assertSame(422, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $invalid], $token)->status());
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('<time datetime="2028-02-29T13:05:00Z">2028-02-29 13:05 UTC</time>', $entry->contentHtml);
        self::assertStringContainsString('data-pb-finished="Ouvert"', $entry->contentHtml);
        self::assertSame(2, substr_count($entry->contentHtml, 'data-pb-countdown '));
        self::assertStringNotContainsString('data-pb-live=', $entry->contentHtml);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/countdown.css'));
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/countdown.js'));
        $other = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($other);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/countdown.css', $assets->renderStyles());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/countdown.js', $assets->renderScripts());
    }

    public function testNavigationUsesCoreMenusAndNeverPersistsPrivateTargets(): void
    {
        $app = $this->activated('admin');
        $pdo = $this->db($app)->pdo();
        $idColumn = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $pdo->exec('CREATE TABLE pc_menus (id ' . $idColumn . ', name VARCHAR(150) NOT NULL, slug VARCHAR(120) NOT NULL UNIQUE, location VARCHAR(80) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $pdo->exec('CREATE TABLE pc_menu_items (id ' . $idColumn . ", menu_id INTEGER NOT NULL, parent_id INTEGER NOT NULL DEFAULT 0, position INTEGER NOT NULL DEFAULT 0, type VARCHAR(20) NOT NULL DEFAULT 'url', target_id INTEGER NULL, url VARCHAR(500) NULL, label VARCHAR(255) NOT NULL DEFAULT '', target VARCHAR(16) NOT NULL DEFAULT '_self', css_class VARCHAR(255) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)");
        $menus = $app->get(\Prime\Site\Menu\MenuRepository::class);
        $menuId = $menus->create('Notre menu');
        $menus->replaceItems($menuId, [
            ['type' => 'entry', 'target_id' => 1, 'label' => 'Article actuel', 'children' => [
                ['type' => 'url', 'url' => '/services', 'label' => 'Services', 'children' => [
                    ['type' => 'url', 'url' => '/details', 'label' => 'Détails'],
                ]],
            ]],
            ['type' => 'entry', 'target_id' => 2, 'label' => 'Brouillon confidentiel'],
            ['type' => 'url', 'url' => 'https://example.test/', 'label' => 'Externe', 'target' => '_blank'],
        ]);
        $config = $this->editorConfig($app, 1);
        self::assertSame('Menu de navigation', $config['widgets']['navigation']['label']);
        self::assertSame('enum', $config['widgets']['navigation']['fields']['menu_id']['type']);
        self::assertSame('Notre menu', $config['widgets']['navigation']['fields']['menu_id']['option_labels'][(string) $menuId]);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'menu', 'type' => 'navigation', 'settings' => ['menu_id' => (string) $menuId, 'label' => 'Explorer'], 'styles' => [], 'children' => []],
        ];
        $preview = $this->send($app, 'POST', '/api/prime-builder/v1/documents/1/render', ['data' => $document['blocks'][0]['data']], $token);
        self::assertSame(200, $preview->status(), $preview->body());
        $previewHtml = json_decode($preview->body(), true)['data']['html'];
        self::assertStringContainsString('>Services</a>', $previewHtml);
        self::assertStringNotContainsString('Brouillon confidentiel', $previewHtml);
        $saved = $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token);
        self::assertSame(200, $saved->status(), $saved->body());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('data-pb-live="/api/prime-builder/v1/documents/1/live"', $entry->contentHtml);
        self::assertStringContainsString('href="/"', $entry->contentHtml);
        self::assertStringNotContainsString('Article actuel', $entry->contentHtml);
        self::assertStringNotContainsString('Brouillon confidentiel', $entry->contentHtml);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/navigation.css'));
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/content.js'));
        $other = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($other);
        $otherAssets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $otherAssets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/navigation.css', $otherAssets->renderStyles());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/content.js', $otherAssets->renderScripts());
        $live = $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live');
        self::assertSame(200, $live->status(), $live->body());
        self::assertStringContainsString('no-store', (string) $live->header('Cache-Control'));
        $html = json_decode($live->body(), true)['data']['widgets']['menu'];
        self::assertStringContainsString('aria-label="Explorer"', $html);
        self::assertStringContainsString('href="/blog/article-publie" class="pb-nav__link" aria-current="page"', $html);
        self::assertStringContainsString('<ul class="pb-nav__submenu">', $html);
        self::assertStringContainsString('>Détails</a>', $html);
        self::assertStringContainsString('target="_blank" rel="noopener noreferrer"', $html);
        self::assertStringNotContainsString('site-nav__', $html);
        self::assertStringNotContainsString('Brouillon confidentiel', $html);
        $menus->replaceItems($menuId, [['type' => 'url', 'url' => '/nouveau', 'label' => 'Nouveau lien']]);
        $live = $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live');
        $updated = json_decode($live->body(), true)['data']['widgets']['menu'];
        self::assertStringContainsString('Nouveau lien', $updated);
        self::assertStringNotContainsString('Article actuel', $updated);
        $persisted = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($persisted);
        self::assertStringNotContainsString('Nouveau lien', $persisted->contentHtml);
        $menus->delete($menuId);
        $live = $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live');
        self::assertStringContainsString('href="/"', json_decode($live->body(), true)['data']['widgets']['menu']);
    }

    public function testNavigationInThemePartsUsesPublicTargetsAndRealPageContext(): void
    {
        $app = $this->activated('admin');
        $pdo = $this->db($app)->pdo();
        $idColumn = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $pdo->exec('CREATE TABLE pc_menus (id ' . $idColumn . ', name VARCHAR(150) NOT NULL, slug VARCHAR(120) NOT NULL UNIQUE, location VARCHAR(80) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $pdo->exec('CREATE TABLE pc_menu_items (id ' . $idColumn . ", menu_id INTEGER NOT NULL, parent_id INTEGER NOT NULL DEFAULT 0, position INTEGER NOT NULL DEFAULT 0, type VARCHAR(20) NOT NULL DEFAULT 'url', target_id INTEGER NULL, url VARCHAR(500) NULL, label VARCHAR(255) NOT NULL DEFAULT '', target VARCHAR(16) NOT NULL DEFAULT '_self', css_class VARCHAR(255) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)");
        $parent = $this->insertGridEntry($pdo, 'page', 'draft', 'Parent privé', 'parent-secret', 1);
        $child = $this->insertGridEntry($pdo, 'page', 'published', 'Enfant', 'enfant-secret', 1, $parent);
        $publicParent = $this->insertGridEntry($pdo, 'page', 'published', 'Parent public', 'parent-public', 1);
        $publicChild = $this->insertGridEntry($pdo, 'page', 'published', 'Page publique', 'page-publique', 1, $publicParent);
        $cycle = $this->insertGridEntry($pdo, 'page', 'published', 'Page cyclique', 'cycle-secret', 1);
        $pdo->exec('UPDATE pc_entries SET parent_id = ' . $cycle . ' WHERE id = ' . $cycle);
        $future = $this->insertGridEntry($pdo, 'page', 'published', 'Page future', 'future-secret', 1, 0, '2100-01-01 00:00:00');
        $futureChild = $this->insertGridEntry($pdo, 'page', 'published', 'Enfant futur', 'enfant-futur', 1, $future);
        $orphan = $this->insertGridEntry($pdo, 'page', 'published', 'Page orpheline', 'orpheline', 1, 999999);
        $deep = 0;
        for ($depth = 0; $depth < 13; $depth++) {
            $deep = $this->insertGridEntry($pdo, 'page', 'published', 'Profondeur ' . $depth, 'niveau-' . $depth, 1, $deep);
        }
        $types = $app->get(ContentTypeRegistry::class);
        $types->register(new ContentType(name: 'internal', labels: ['singular' => 'Privé'], public: false));
        $privateEntry = $this->insertGridEntry($pdo, 'internal', 'published', 'Type privé', 'type-secret', 1);
        $taxonomies = $app->get(TaxonomyRegistry::class);
        $taxonomies->register(new Taxonomy(name: 'secret', labels: ['singular' => 'Privé'], public: false));
        $pdo->exec("INSERT INTO pc_terms (id, taxonomy, name, slug, parent_id, menu_order, `count`) VALUES (11, 'category', 'Arts', 'arts', 0, 0, 0), (12, 'secret', 'Interne', 'terme-secret', 0, 0, 0)");
        $permalinks = $app->get(PermalinkSettings::class);
        $permalinks->setOverride('reading.front_mode', 'page');
        $permalinks->setOverride('reading.posts_page_id', $parent);
        $menus = $app->get(\Prime\Site\Menu\MenuRepository::class);
        $menuId = $menus->create('Menu du thème');
        $menus->replaceItems($menuId, [
            ['type' => 'url', 'url' => '/', 'label' => 'Accueil'],
            ['type' => 'entry', 'target_id' => $publicChild, 'label' => 'Page publique', 'children' => [
                ['type' => 'url', 'url' => '/services', 'label' => 'Services'],
            ]],
            ['type' => 'entry', 'target_id' => $child, 'label' => 'Enfant inaccessible', 'children' => [
                ['type' => 'url', 'url' => '/orphelin', 'label' => 'Sous-menu inaccessible'],
            ]],
            ['type' => 'entry', 'target_id' => $cycle, 'label' => 'Cycle inaccessible'],
            ['type' => 'entry', 'target_id' => $future, 'label' => 'Publication future'],
            ['type' => 'entry', 'target_id' => $futureChild, 'label' => 'Parent futur'],
            ['type' => 'entry', 'target_id' => $orphan, 'label' => 'Parent manquant'],
            ['type' => 'entry', 'target_id' => $deep, 'label' => 'Chemin trop profond'],
            ['type' => 'entry', 'target_id' => $privateEntry, 'label' => 'Type inaccessible'],
            ['type' => 'term', 'target_id' => 11, 'label' => 'Arts'],
            ['type' => 'term', 'target_id' => 12, 'label' => 'Taxonomie privée'],
            ['type' => 'archive', 'url' => 'post', 'label' => 'Blog'],
            ['type' => 'archive', 'url' => 'internal', 'label' => 'Archive privée'],
            ['type' => 'url', 'url' => 'https://example.test/', 'label' => 'Externe', 'target' => '_blank'],
        ]);
        $hooks = $app->get(Hooks::class);
        $hooks->filter('site.menu_items', static function (mixed $items) use ($child): mixed {
            if (is_array($items)) {
                $items[] = ['label' => 'Ajout du filtre', 'url' => '/filtre', 'children' => []];
                $items[] = ['type' => 'entry', 'target_id' => $child, 'label' => 'Ajout privé', 'url' => '/faux', 'children' => []];
            }
            return $items;
        }, 100);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'theme menu', ['*'])['token'];
        $layout = ['version' => 1, 'elements' => [[
            'id' => 'outer', 'type' => 'container', 'settings' => [], 'styles' => [], 'children' => [[
                'id' => 'menu', 'type' => 'navigation', 'settings' => ['menu_id' => (string) $menuId, 'label' => 'Explorer'], 'styles' => [], 'children' => [],
            ]],
        ]]];
        $created = $this->send($app, 'POST', '/api/prime-builder/v1/templates', ['name' => 'Menu thème', 'kind' => 'section', 'data' => $layout], $token);
        self::assertSame(201, $created->status(), $created->body());
        $template = json_decode($created->body(), true)['data']['id'];
        $fields = ['_csrf_token' => $app->get(Csrf::class)->token(), 'header' => $template, 'footer' => $template, 'exclude' => ''];
        self::assertSame(302, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/theme', body: $fields))->status());
        $page = new PageContext(type: PageContext::TYPE_ENTRY, path: '/parent-public/page-publique');
        $header = $hooks->apply('site.partial', null, 'header', $page, []);
        $footer = $hooks->apply('site.partial', null, 'footer', $page, []);
        self::assertIsString($header);
        self::assertIsString($footer);
        foreach ([$header, $footer] as $html) {
            self::assertStringContainsString('aria-label="Explorer"', $html);
            self::assertStringContainsString('data-pb-nav-breakpoint="767"', $html);
            self::assertStringContainsString('href="/parent-public/page-publique" class="pb-nav__link" aria-current="page"', $html);
            self::assertStringContainsString('<ul class="pb-nav__submenu">', $html);
            self::assertStringContainsString('>Services</a>', $html);
            self::assertStringContainsString('href="/categorie/arts"', $html);
            self::assertStringContainsString('href="/blog"', $html);
            self::assertStringContainsString('>Ajout du filtre</a>', $html);
            self::assertStringContainsString('target="_blank" rel="noopener noreferrer"', $html);
            self::assertStringNotContainsString('data-pb-live=', $html);
            foreach (['parent-secret', 'Enfant inaccessible', 'Sous-menu inaccessible', 'Ajout privé', 'cycle-secret', 'Cycle inaccessible', 'Publication future', 'Parent futur', 'Parent manquant', 'Chemin trop profond', 'Type inaccessible', 'Taxonomie privée', 'Archive privée'] as $secret) {
                self::assertStringNotContainsString($secret, $html);
            }
        }
        preg_match_all('/data-pb-id="([a-zA-Z0-9_-]+)"/', $header . $footer, $ids);
        self::assertSame(count($ids[1]), count(array_unique($ids[1])));
        $archive = $hooks->apply('site.partial', null, 'footer', new PageContext(type: PageContext::TYPE_BLOG, path: '/blog'), []);
        self::assertIsString($archive);
        self::assertStringContainsString('href="/blog" class="pb-nav__link" aria-current="page"', $archive);
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, $page);
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/navigation.css'));
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/content.js'));
        self::assertSame(1, preg_match('~/prime-builder/theme/([a-f0-9]{16}\\.css)~', $assets->renderStyles(), $defaultStylesheetMatches));
        $defaultStylesheet = $this->send($app, 'GET', '/prime-builder/theme/' . ($defaultStylesheetMatches[1] ?? ''));
        self::assertSame(200, $defaultStylesheet->status());
        self::assertStringContainsString('@media(max-width:767px)', $defaultStylesheet->body());

        // A settings change updates both the server markup and the fingerprinted
        // stylesheet, with the same bounds as all other responsive controls.
        $app->get(Settings::class)->set('plugin.prime-builder.configuration', ['breakpoint_tablet' => 1000, 'breakpoint_mobile' => 900]);
        $responsive = $hooks->apply('site.partial', null, 'header', $page, []);
        self::assertIsString($responsive);
        self::assertStringContainsString('data-pb-nav-breakpoint="900"', $responsive);
        $responsiveAssets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $responsiveAssets, $page);
        self::assertSame(1, preg_match('~/prime-builder/theme/([a-f0-9]{16}\\.css)~', $responsiveAssets->renderStyles(), $stylesheetMatches));
        self::assertSame(false, ($defaultStylesheetMatches[1] ?? '') === ($stylesheetMatches[1] ?? ''));
        $responsiveCss = $this->send($app, 'GET', '/prime-builder/theme/' . ($stylesheetMatches[1] ?? ''));
        self::assertSame(200, $responsiveCss->status());
        self::assertStringContainsString('@media(max-width:900px)', $responsiveCss->body());
        self::assertSame($defaultStylesheet->body(), $this->send($app, 'GET', '/prime-builder/theme/' . ($defaultStylesheetMatches[1] ?? ''))->body());
        self::assertStringContainsString(' > .pb-nav > .pb-nav__list{display:block;margin-top:.5rem}', $responsiveCss->body());
        $app->get(Settings::class)->set('plugin.prime-builder.configuration', ['breakpoint_tablet' => 600, 'breakpoint_mobile' => 9999]);
        $bounded = $hooks->apply('site.partial', null, 'header', $page, []);
        self::assertIsString($bounded);
        self::assertStringContainsString('data-pb-nav-breakpoint="599"', $bounded);
        $app->get(Settings::class)->set('plugin.prime-builder.configuration', ['breakpoint_tablet' => 1000, 'breakpoint_mobile' => 900]);

        $cache = $app->get(\Prime\Cache\PageCache::class);
        $cache->setEnabled(true);
        self::assertSame(false, $cache->isCacheableRequest(Request::create('GET', '/parent-public/page-publique')));

        // The live endpoint for an entry uses the same typed-target checks and
        // still persists only the conservative home-link fallback.
        $document = $this->document();
        $document['blocks'][0]['data'] = $layout;
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $saved = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($saved);
        self::assertStringNotContainsString('Enfant inaccessible', $saved->contentHtml);
        $live = $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live');
        self::assertSame(200, $live->status(), $live->body());
        $liveMenu = json_decode($live->body(), true)['data']['widgets']['menu'];
        self::assertStringContainsString('data-pb-nav-breakpoint="900"', $liveMenu);
        self::assertStringNotContainsString('parent-secret', $liveMenu);
        self::assertStringNotContainsString('Ajout privé', $liveMenu);

        // If a dependency fails while checking the items, the hook must fail
        // closed rather than returning the unfiltered core list. It is removed
        // afterwards so other renders and core menu filters remain usable.
        $brokenTaxonomies = static function (): never {
            throw new \RuntimeException('Taxonomy lookup failed');
        };
        $hooks->filter('content.taxonomies', $brokenTaxonomies, 1000);
        $provider = $app->get(Content::class);
        $fallback = $provider->navigation(['menu_id' => (string) $menuId, 'label' => 'Explorer'], null, true);
        self::assertStringContainsString('href="/"', $fallback);
        self::assertStringNotContainsString('Page publique', $fallback);
        self::assertStringNotContainsString('parent-secret', $fallback);
        $hooks->off('content.taxonomies', $brokenTaxonomies);
        $resumed = $provider->navigation(['menu_id' => (string) $menuId, 'label' => 'Explorer'], null, true);
        self::assertStringContainsString('Page publique', $resumed);
        self::assertStringContainsString('Ajout du filtre', $resumed);
        self::assertStringNotContainsString('Ajout privé', $resumed);

        // Menu targets and parent paths are read afresh even within one request.
        $pdo->exec("UPDATE pc_entries SET status = 'published', slug = 'parent-ouvert' WHERE id = " . $parent);
        $updated = $hooks->apply('site.partial', null, 'header', $page, []);
        self::assertIsString($updated);
        self::assertStringContainsString('href="/parent-ouvert/enfant-secret"', $updated);
        self::assertStringContainsString('href="/parent-ouvert" class="pb-nav__link">Blog</a>', $updated);
        self::assertStringNotContainsString('parent-secret', $updated);
        $pdo->exec("UPDATE pc_entries SET status = 'draft' WHERE id = " . $parent);
        $updated = $hooks->apply('site.partial', null, 'header', $page, []);
        self::assertIsString($updated);
        self::assertStringNotContainsString('Enfant inaccessible', $updated);
        self::assertStringNotContainsString('parent-ouvert', $updated);
        $menus->replaceItems($menuId, [['type' => 'url', 'url' => '/nouveau', 'label' => 'Nouveau lien']]);
        $updated = $hooks->apply('site.partial', null, 'footer', $page, []);
        self::assertIsString($updated);
        self::assertStringContainsString('>Nouveau lien</a>', $updated);
        self::assertStringNotContainsString('Page publique', $updated);
        $menus->delete($menuId);
        $missing = $hooks->apply('site.partial', null, 'header', PageContext::notFound('/absent'), []);
        self::assertIsString($missing);
        self::assertStringContainsString('href="/"', $missing);
        $app->get(Settings::class)->set('plugin.prime-builder.theme_partials', []);
        self::assertSame(true, $cache->isCacheableRequest(Request::create('GET', '/parent-public/page-publique')));
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, $page);
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/navigation.css', $assets->renderStyles());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/content.js', $assets->renderScripts());
    }

    public function testBreadcrumbsRefreshFromCoreContextWithoutPersistingPrivatePaths(): void
    {
        $app = $this->activated(null);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'trail', 'type' => 'breadcrumbs', 'settings' => ['label' => 'Vous êtes ici'], 'styles' => [], 'children' => []],
        ];
        $preview = $this->send($app, 'POST', '/api/prime-builder/v1/documents/1/render', ['data' => $document['blocks'][0]['data']], $token);
        self::assertSame(200, $preview->status(), $preview->body());
        $previewHtml = json_decode($preview->body(), true)['data']['html'];
        self::assertStringContainsString('aria-label="Vous êtes ici"', $previewHtml);
        self::assertStringContainsString('aria-current="page">Article publié</span>', $previewHtml);
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('href="/"', $entry->contentHtml);
        self::assertStringNotContainsString('Article publié', $entry->contentHtml);
        self::assertStringNotContainsString('aria-current="page"', $entry->contentHtml);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/breadcrumbs.css'));
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/content.js'));
        $other = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($other);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/breadcrumbs.css', $assets->renderStyles());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/content.js', $assets->renderScripts());
        $pdo = $this->db($app)->pdo();
        $pdo->exec("UPDATE pc_entries SET title = 'Titre & actuel' WHERE id = 1");
        $live = $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live');
        self::assertSame(200, $live->status(), $live->body());
        self::assertStringContainsString('no-store', (string) $live->header('Cache-Control'));
        $html = json_decode($live->body(), true)['data']['widgets']['trail'];
        self::assertStringContainsString('aria-current="page">Titre &amp; actuel</span>', $html);
        self::assertStringNotContainsString('Article publié', $html);
        // The core URL generator alone would include these unpublished ancestor slugs.
        $parent = $this->insertGridEntry($pdo, 'page', 'draft', 'Parent privé', 'parent-secret', 1);
        $postsPage = $this->insertGridEntry($pdo, 'page', 'published', 'Articles', 'blog-secret', 1, $parent);
        $permalinks = $app->get(PermalinkSettings::class);
        $permalinks->setOverride('reading.front_mode', 'page');
        $permalinks->setOverride('reading.posts_page_id', $postsPage);
        $live = $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live');
        $html = json_decode($live->body(), true)['data']['widgets']['trail'];
        self::assertStringContainsString('href="/blog">Blog</a>', $html);
        self::assertStringNotContainsString('parent-secret', $html);
        self::assertStringNotContainsString('blog-secret', $html);
        $pdo->exec('UPDATE pc_entries SET status = \'published\' WHERE id = ' . $parent);
        $live = $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live');
        $html = json_decode($live->body(), true)['data']['widgets']['trail'];
        self::assertStringContainsString('href="/parent-secret/blog-secret">Blog</a>', $html);
        $pdo->exec('UPDATE pc_entries SET slug = \'blog-renomme\' WHERE id = ' . $postsPage);
        $live = $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live');
        $html = json_decode($live->body(), true)['data']['widgets']['trail'];
        self::assertStringContainsString('href="/parent-secret/blog-renomme">Blog</a>', $html);
        self::assertStringNotContainsString('blog-secret', $html);
        $pdo->exec('UPDATE pc_entries SET status = \'draft\' WHERE id = ' . $postsPage);
        $live = $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live');
        $html = json_decode($live->body(), true)['data']['widgets']['trail'];
        self::assertStringContainsString('href="/blog">Blog</a>', $html);
        self::assertStringNotContainsString('blog-secret', $html);
        $front = $this->insertGridEntry($pdo, 'page', 'published', 'Page d’accueil', 'accueil', 1);
        $permalinks->setOverride('reading.front_page_id', $front);
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/' . $front, ['content' => $document], $token)->status());
        $live = $this->send($app, 'GET', '/api/prime-builder/v1/documents/' . $front . '/live');
        self::assertSame(200, $live->status(), $live->body());
        self::assertSame('', json_decode($live->body(), true)['data']['widgets']['trail']);
        $pdo->exec("UPDATE pc_entries SET status = 'draft' WHERE id = 1");
        self::assertSame(404, $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live')->status());
    }

    public function testBreadcrumbsInThemePartsUseResolvedPublicContextsAndSafeAncestors(): void
    {
        $app = $this->activated('admin');
        $pdo = $this->db($app)->pdo();
        $root = $this->insertGridEntry($pdo, 'page', 'published', 'Racine & idées', 'racine', 1);
        $parent = $this->insertGridEntry($pdo, 'page', 'published', 'Parent <utile>', 'parent', 1, $root);
        $child = $this->insertGridEntry($pdo, 'page', 'published', 'Enfant', 'enfant', 1, $parent);
        $pdo->exec("INSERT INTO pc_terms (id, taxonomy, name, slug, parent_id, menu_order, `count`) VALUES (11, 'category', 'Arts & idées', 'arts', 0, 0, 0)");
        $app->get(ContentTypeRegistry::class)->register(new ContentType(name: 'book', labels: ['singular' => 'Livre', 'plural' => 'Livres'], rewriteBase: 'livres'));
        $token = $this->tokens($app)->issue($this->user($app, 1), 'breadcrumbs in parts', ['*'])['token'];
        $layout = ['version' => 1, 'elements' => [[
            'id' => 'outer', 'type' => 'container', 'settings' => [], 'styles' => [], 'children' => [
                ['id' => 'trail', 'type' => 'breadcrumbs', 'settings' => ['label' => 'Votre parcours'], 'styles' => [], 'children' => []],
            ],
        ]]];
        $created = $this->send($app, 'POST', '/api/prime-builder/v1/templates', ['name' => 'Fil du thème', 'kind' => 'section', 'data' => $layout], $token);
        self::assertSame(201, $created->status(), $created->body());
        $templateId = json_decode($created->body(), true)['data']['id'];
        $fields = ['_csrf_token' => $app->get(Csrf::class)->token(), 'header' => $templateId, 'footer' => $templateId, 'exclude' => ''];
        self::assertSame(302, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/theme', body: $fields))->status());
        $hooks = $app->get(Hooks::class);
        $resolver = $app->get(UrlResolver::class);
        $page = $resolver->resolve(Request::create('GET', '/racine/parent/enfant'));
        self::assertSame(PageContext::TYPE_ENTRY, $page->type);
        foreach (['header', 'footer'] as $slot) {
            $html = $hooks->apply('site.partial', null, $slot, $page, []);
            self::assertIsString($html);
            self::assertStringContainsString('aria-label="Votre parcours"', $html);
            self::assertStringContainsString('<a href="/">Accueil</a>', $html);
            self::assertStringContainsString('<a href="/racine">Racine &amp; idées</a>', $html);
            self::assertStringContainsString('<a href="/racine/parent">Parent &lt;utile&gt;</a>', $html);
            self::assertStringContainsString('<span aria-current="page">Enfant</span>', $html);
            self::assertStringNotContainsString('data-pb-live=', $html);
            self::assertStringNotContainsString('href="/racine/parent/enfant"', $html);
        }
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, $page);
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/breadcrumbs.css'));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/content.js', $assets->renderScripts());
        $cache = $app->get(\Prime\Cache\PageCache::class);
        $cache->setEnabled(true);
        self::assertSame(false, $cache->isCacheableRequest(Request::create('GET', '/racine/parent/enfant')));

        foreach ([
            ['/blog', PageContext::TYPE_BLOG, 'Blog'],
            ['/categorie/arts', PageContext::TYPE_TERM, 'Arts &amp; idées'],
            ['/auteur/admin', PageContext::TYPE_AUTHOR, 'Admin Prime'],
            ['/recherche', PageContext::TYPE_SEARCH, 'Recherche'],
            ['/livres', PageContext::TYPE_ARCHIVE, 'Livres'],
            ['/blog/article-publie', PageContext::TYPE_ENTRY, 'Article publié'],
        ] as [$path, $type, $label]) {
            $context = $resolver->resolve(Request::create('GET', $path));
            self::assertSame($type, $context->type, $path);
            $html = $hooks->apply('site.partial', null, 'footer', $context, []);
            self::assertIsString($html);
            self::assertStringContainsString('<span aria-current="page">' . $label . '</span>', $html);
        }
        $home = $resolver->resolve(Request::create('GET', '/'));
        $homeHtml = $hooks->apply('site.partial', null, 'footer', $home, []);
        self::assertIsString($homeHtml);
        self::assertStringNotContainsString('pb-breadcrumb"', $homeHtml);

        // The same provider and URL generator are reused: changes must not retain
        // cached ancestor titles or slugs within the request.
        $pdo->exec("UPDATE pc_entries SET title = 'Parent nouveau', slug = 'parent-nouveau' WHERE id = " . $parent);
        $entries = $app->get(EntryRepository::class);
        $childEntry = $entries->find($child);
        self::assertNotNull($childEntry);
        $changed = new PageContext(type: PageContext::TYPE_ENTRY, path: '/racine/parent-nouveau/enfant', entry: $childEntry);
        $html = $hooks->apply('site.partial', null, 'header', $changed, []);
        self::assertIsString($html);
        self::assertStringContainsString('<a href="/racine/parent-nouveau">Parent nouveau</a>', $html);
        self::assertStringNotContainsString('Parent &lt;utile&gt;', $html);
        self::assertStringNotContainsString('href="/racine/parent"', $html);

        // Also test the public live endpoint for a child that is individually
        // published but no longer reachable because an ancestor is private.
        $document = $this->document();
        $document['blocks'][0]['data'] = $layout;
        $document['blocks'][0]['data']['elements'][0]['children'][] = [
            'id' => 'title', 'type' => 'entry_title', 'settings' => [], 'styles' => [], 'children' => [],
        ];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/' . $child, ['content' => $document], $token)->status());
        $savedChild = $entries->find($child);
        self::assertNotNull($savedChild);
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $savedChild));
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/breadcrumbs.css'));
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/content.js'));
        $live = $this->send($app, 'GET', '/api/prime-builder/v1/documents/' . $child . '/live');
        self::assertSame(200, $live->status(), $live->body());
        self::assertStringContainsString('Parent nouveau', json_decode($live->body(), true)['data']['widgets']['trail']);
        self::assertStringContainsString('Enfant', json_decode($live->body(), true)['data']['widgets']['title']);
        $pdo->exec("UPDATE pc_entries SET status = 'draft' WHERE id = " . $parent);
        $app->get(\Prime\Auth\Auth::class)->logout();
        $live = $this->send($app, 'GET', '/api/prime-builder/v1/documents/' . $child . '/live');
        self::assertSame(404, $live->status());
        self::assertStringNotContainsString('Enfant', $live->body());
        $unprivileged = $this->tokens($app)->issue($this->user($app, 2), 'unprivileged breadcrumbs', ['*'])['token'];
        self::assertSame(404, $this->send($app, 'GET', '/api/prime-builder/v1/documents/' . $child . '/live', token: $unprivileged)->status());
        $live = $this->send($app, 'GET', '/api/prime-builder/v1/documents/' . $child . '/live', token: $token);
        self::assertSame(200, $live->status());
        self::assertSame('', json_decode($live->body(), true)['data']['widgets']['trail']);
        self::assertStringContainsString('Enfant', json_decode($live->body(), true)['data']['widgets']['title']);
        $provider = $app->get(Content::class);
        $sanitizer = $app->get(\Prime\Security\HtmlSanitizer::class);
        self::assertSame('', $provider->breadcrumbsInPage([], $changed, $sanitizer));
        self::assertSame('', $provider->breadcrumbsInPage([], new PageContext(type: PageContext::TYPE_ENTRY, entry: $childEntry, httpStatus: 404), $sanitizer));
        self::assertSame('', $provider->breadcrumbsInPage([], PageContext::redirect('/x', '/y'), $sanitizer));
        $draft = $entries->find(2);
        self::assertNotNull($draft);
        self::assertSame('', $provider->breadcrumbsInPage([], new PageContext(type: PageContext::TYPE_ENTRY, entry: $draft), $sanitizer));
        $orphanId = $this->insertGridEntry($pdo, 'page', 'published', 'Orphelin secret', 'orphelin', 1, 999999);
        $orphan = $entries->find($orphanId);
        self::assertNotNull($orphan);
        self::assertSame('', $provider->breadcrumbsInPage([], new PageContext(type: PageContext::TYPE_ENTRY, entry: $orphan), $sanitizer));
        $pdo->exec('UPDATE pc_entries SET parent_id = ' . $child . ', status = \'published\' WHERE id = ' . $parent);
        self::assertSame('', $provider->breadcrumbsInPage([], $changed, $sanitizer));
        $futureId = $this->insertGridEntry($pdo, 'page', 'published', 'Parent futur', 'parent-futur', 1, published: '2999-01-01 00:00:00');
        $futureChildId = $this->insertGridEntry($pdo, 'page', 'published', 'Enfant futur', 'enfant-futur', 1, $futureId);
        $futureChild = $entries->find($futureChildId);
        self::assertNotNull($futureChild);
        self::assertSame('', $provider->breadcrumbsInPage([], new PageContext(type: PageContext::TYPE_ENTRY, entry: $futureChild), $sanitizer));
        $wrongParentId = $this->insertGridEntry($pdo, 'post', 'published', 'Parent de mauvais type', 'parent-post', 1);
        $wrongChildId = $this->insertGridEntry($pdo, 'page', 'published', 'Enfant du post', 'enfant-post', 1, $wrongParentId);
        $wrongChild = $entries->find($wrongChildId);
        self::assertNotNull($wrongChild);
        self::assertSame('', $provider->breadcrumbsInPage([], new PageContext(type: PageContext::TYPE_ENTRY, entry: $wrongChild), $sanitizer));
        $deepParent = 0;
        for ($i = 0; $i < 12; $i++) {
            $deepParent = $this->insertGridEntry($pdo, 'page', 'published', 'Profondeur ' . $i, 'profondeur-' . $i, 1, $deepParent);
        }
        $withinLimit = $entries->find($deepParent);
        self::assertNotNull($withinLimit);
        $longTrail = $provider->breadcrumbsInPage([], new PageContext(type: PageContext::TYPE_ENTRY, entry: $withinLimit), $sanitizer);
        self::assertStringContainsString('Profondeur 0</a>', $longTrail);
        self::assertStringContainsString('aria-current="page">Profondeur 11</span>', $longTrail);
        $deepChildId = $this->insertGridEntry($pdo, 'page', 'published', 'Trop profond', 'trop-profond', 1, $deepParent);
        $deepChild = $entries->find($deepChildId);
        self::assertNotNull($deepChild);
        self::assertSame('', $provider->breadcrumbsInPage([], new PageContext(type: PageContext::TYPE_ENTRY, entry: $deepChild), $sanitizer));
        $settings = $app->get(Settings::class);
        $settings->set('plugin.prime-builder.theme_partials', []);
        self::assertSame(true, $cache->isCacheableRequest(Request::create('GET', '/racine/parent/enfant')));
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, $home);
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/breadcrumbs.css', $assets->renderStyles());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/content.js', $assets->renderScripts());
    }

    public function testSiteIdentityUsesCoreSettingsMediaAndRefreshesSavedDocuments(): void
    {
        $app = $this->activated('admin');
        $this->db($app)->pdo()->exec('CREATE TABLE pc_media_usage (entry_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $this->db($app)->pdo()->exec('CREATE TABLE pc_media_zone_usage (zone_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $config = $this->editorConfig($app, 1);
        self::assertSame('Titre du site', $config['widgets']['site_title']['label']);
        self::assertSame('Logo du site', $config['widgets']['site_logo']['label']);
        self::assertSame('Lien vers l’accueil', $config['widgets']['site_logo']['fields']['link_home']['label']);
        $settings = $app->get(Settings::class);
        $settings->set('site.name', 'Atelier & <Co>');
        $media = $app->get(\Prime\Media\MediaRepository::class)->create([
            'disk_path' => 'identity/logo.png', 'filename' => 'logo.png', 'original_name' => 'logo.png',
            'extension' => 'png', 'mime_type' => 'image/png', 'kind' => 'image', 'width' => 1200, 'height' => 400,
            'variants' => ['medium' => ['path' => 'identity/logo-medium.png', 'width' => 300, 'height' => 100]],
        ]);
        $settings->set('site.logo_id', $media->id);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'title', 'type' => 'site_title', 'settings' => ['tag' => 'h3', 'link_home' => 'show'], 'styles' => [], 'children' => []],
            ['id' => 'outer', 'type' => 'container', 'settings' => [], 'styles' => [], 'children' => [
                ['id' => 'logo', 'type' => 'site_logo', 'settings' => ['link_home' => 'hide'], 'styles' => [], 'children' => []],
            ]],
        ];
        $preview = $this->send($app, 'POST', '/api/prime-builder/v1/documents/1/render', ['data' => $document['blocks'][0]['data']], $token);
        self::assertSame(200, $preview->status(), $preview->body());
        $html = json_decode($preview->body(), true)['data']['html'];
        self::assertStringContainsString('<h3 class="pb-site-title__text"><a href="/">Atelier &amp; &lt;Co&gt;</a></h3>', $html);
        self::assertStringContainsString('src="/uploads/identity/logo-medium.png"', $html);
        self::assertStringContainsString('alt="Atelier &amp; &lt;Co&gt;"', $html);
        self::assertStringContainsString('width="300" height="100" loading="lazy"', $html);
        self::assertStringContainsString('pb-site-logo__unlinked', $html);
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('data-pb-live="/api/prime-builder/v1/documents/1/live"', $entry->contentHtml);
        self::assertStringContainsString('Atelier &amp; &lt;Co&gt;', $entry->contentHtml);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        foreach (['site_title', 'site_logo'] as $type) {
            self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/' . $type . '.css'));
        }
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/content.js'));
        $other = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($other);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        foreach (['site_title', 'site_logo'] as $type) {
            self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/' . $type . '.css', $assets->renderStyles());
        }
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/content.js', $assets->renderScripts());
        $settings->set('site.name', 'Nouvel atelier');
        $live = $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live');
        self::assertSame(200, $live->status(), $live->body());
        self::assertStringContainsString('no-store', (string) $live->header('Cache-Control'));
        $widgets = json_decode($live->body(), true)['data']['widgets'];
        self::assertStringContainsString('>Nouvel atelier</a></h3>', $widgets['title']);
        self::assertStringContainsString('alt="Nouvel atelier"', $widgets['logo']);
        self::assertStringNotContainsString('Atelier &amp;', $widgets['logo']);
        $settings->set('site.logo_id', null);
        $widgets = json_decode($this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live')->body(), true)['data']['widgets'];
        self::assertStringContainsString('<span class="pb-site-logo__fallback">Nouvel atelier</span>', $widgets['logo']);
        self::assertStringNotContainsString('<img', $widgets['logo']);
        $documentMedia = $app->get(\Prime\Media\MediaRepository::class)->create([
            'disk_path' => 'identity/not-image.pdf', 'filename' => 'not-image.pdf', 'original_name' => 'not-image.pdf',
            'extension' => 'pdf', 'mime_type' => 'application/pdf', 'kind' => 'document',
        ]);
        $settings->set('site.logo_id', $documentMedia->id);
        $widgets = json_decode($this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live')->body(), true)['data']['widgets'];
        self::assertStringContainsString('pb-site-logo__fallback', $widgets['logo']);
        self::assertStringNotContainsString('not-image.pdf', $widgets['logo']);
        $saved = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($saved);
        self::assertStringContainsString('Atelier &amp; &lt;Co&gt;', $saved->contentHtml);
        self::assertStringNotContainsString('Nouvel atelier', $saved->contentHtml);
    }

    public function testSiteIdentityHonorsInheritedThemeOptionsAndPreviewOverrides(): void
    {
        $app = $this->activated('admin');
        $this->db($app)->pdo()->exec('CREATE TABLE pc_media_usage (entry_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $this->db($app)->pdo()->exec('CREATE TABLE pc_media_zone_usage (zone_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $repository = $app->get(\Prime\Media\MediaRepository::class);
        $global = $repository->create([
            'disk_path' => 'global.png', 'filename' => 'global.png', 'original_name' => 'global.png',
            'extension' => 'png', 'mime_type' => 'image/png', 'kind' => 'image', 'width' => 100, 'height' => 50,
        ]);
        $themeLogo = $repository->create([
            'disk_path' => 'theme.png', 'filename' => 'theme.png', 'original_name' => 'theme.png',
            'extension' => 'png', 'mime_type' => 'image/png', 'kind' => 'image', 'width' => 100, 'height' => 50,
        ]);
        $root = sys_get_temp_dir() . '/pb_identity_themes_' . bin2hex(random_bytes(6));
        try {
            foreach (['pb-identity-parent' => 'PbIdentityParent', 'pb-identity-child' => 'PbIdentityChild'] as $slug => $namespace) {
                mkdir($root . '/themes/' . $slug . '/src', 0755, true);
                $manifest = ['slug' => $slug, 'name' => $slug, 'version' => '1.0.0', 'namespace' => $namespace . '\\', 'main' => $namespace . '\\Theme'];
                if ($slug === 'pb-identity-parent') {
                    $manifest['options'] = [
                        'sections' => ['identity' => ['label' => 'Identity']],
                        'fields' => [
                            'site_name' => ['section' => 'identity', 'type' => 'text', 'label' => 'Name', 'default' => 'Parent'],
                            'site_logo_id' => ['section' => 'identity', 'type' => 'media', 'label' => 'Logo'],
                        ],
                    ];
                } else {
                    $manifest['parent'] = 'pb-identity-parent';
                    $manifest['options'] = [
                        'sections' => ['identity' => ['label' => 'Identity']],
                        'fields' => ['site_name' => ['section' => 'identity', 'type' => 'text', 'label' => 'Name', 'default' => 'Child']],
                    ];
                }
                file_put_contents($root . '/themes/' . $slug . '/theme.json', (string) json_encode($manifest));
                file_put_contents($root . '/themes/' . $slug . '/src/Theme.php', '<?php namespace ' . $namespace . '; final class Theme extends \\Prime\\Themes\\AbstractTheme {}');
            }
            $settings = $app->get(Settings::class);
            $settings->set('site.name', 'Nom global');
            $settings->set('site.logo_id', $global->id);
            $settings->set('theme.active', 'pb-identity-child');
            $settings->set('theme_options.pb-identity-child', ['site_name' => 'Nom du thème', 'site_logo_id' => $themeLogo->id]);
            $publisher = new \Prime\Themes\ThemeAssetPublisher($root . '/public');
            $options = $app->get(\Prime\Themes\ThemeOptions::class);
            $manager = new \Prime\Themes\ThemeManager(
                app: $app,
                themesPath: $root . '/themes',
                validator: $app->get(\Prime\Themes\ThemeManifestValidator::class),
                publisher: $publisher,
                assets: $app->get(ThemeAssetRegistry::class),
                options: $options,
                hooks: $app->get(Hooks::class),
                settings: $settings,
            );
            $app->container()->instance(\Prime\Themes\ThemeManager::class, $manager);
            self::assertSame('pb-identity-child', $manager->active()->slug);
            self::assertSame('pb-identity-parent', $manager->activeParent()?->slug);
            $content = $app->get(Content::class);
            self::assertStringContainsString('Nom du thème', $content->siteTitle([]));
            self::assertStringContainsString('src="/uploads/theme.png"', $content->siteLogo([]));
            self::assertStringContainsString('alt="Nom du thème"', $content->siteLogo([]));
            $settings->set('theme_options.pb-identity-child', ['site_name' => '', 'site_logo_id' => 0]);
            self::assertStringContainsString('Nom global', $content->siteTitle([]));
            self::assertStringContainsString('src="/uploads/global.png"', $content->siteLogo([]));
            // A fresh identity resolver observes the core's in-request customizer overrides.
            $options->setPreviewOverrides(['site_name' => 'Aperçu du thème', 'site_logo_id' => $themeLogo->id]);
            $preview = new \PrimeBuilder\Widgets\SiteIdentity($app);
            self::assertStringContainsString('Aperçu du thème', $preview->title([]));
            self::assertStringContainsString('src="/uploads/theme.png"', $preview->logo([]));
            self::assertStringContainsString('alt="Aperçu du thème"', $preview->logo([]));
            $options->setPreviewOverrides(null);
        } finally {
            \Prime\Themes\ThemeAssetPublisher::removeDirectory($root);
        }
    }

    public function testSiteIdentityInThemePartialsRendersWithoutScriptAndPurgesMediaCache(): void
    {
        $app = $this->activated('admin');
        $this->db($app)->pdo()->exec('CREATE TABLE pc_media_usage (entry_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $this->db($app)->pdo()->exec('CREATE TABLE pc_media_zone_usage (zone_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $settings = $app->get(Settings::class);
        $settings->set('site.name', 'Identité initiale');
        $repository = $app->get(\Prime\Media\MediaRepository::class);
        $media = $repository->create([
            'disk_path' => 'brand.png', 'filename' => 'brand.png', 'original_name' => 'brand.png',
            'extension' => 'png', 'mime_type' => 'image/png', 'kind' => 'image', 'width' => 120, 'height' => 40,
        ]);
        $settings->set('site.logo_id', $media->id);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'templates', ['*'])['token'];
        $layout = ['version' => 1, 'elements' => [
            ['id' => 'outer', 'type' => 'container', 'settings' => [], 'styles' => [], 'children' => [
                ['id' => 'logo', 'type' => 'site_logo', 'settings' => [], 'styles' => [], 'children' => []],
                ['id' => 'title', 'type' => 'site_title', 'settings' => ['tag' => 'p'], 'styles' => [], 'children' => []],
            ]],
        ]];
        $template = $this->send($app, 'POST', '/api/prime-builder/v1/templates', ['name' => 'Identité', 'kind' => 'section', 'data' => $layout], $token);
        self::assertSame(201, $template->status(), $template->body());
        $id = json_decode($template->body(), true)['data']['id'];
        $fields = ['_csrf_token' => $app->get(Csrf::class)->token(), 'header' => $id, 'footer' => '', 'exclude' => ''];
        self::assertSame(302, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/theme', body: $fields))->status());
        $hooks = $app->get(Hooks::class);
        // Archives have no entry, and still receive the server-rendered site identity.
        $page = new PageContext(type: PageContext::TYPE_ARCHIVE, path: '/blog');
        $header = $hooks->apply('site.partial', null, 'header', $page, []);
        self::assertIsString($header);
        self::assertStringContainsString('src="/uploads/brand.png"', $header);
        self::assertStringContainsString('alt="Identité initiale"', $header);
        self::assertStringContainsString('>Identité initiale</a></p>', $header);
        self::assertStringNotContainsString('data-pb-live=', $header);
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, $page);
        foreach (['site_title', 'site_logo'] as $type) {
            self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/' . $type . '.css'));
        }
        $cache = $app->get(\Prime\Cache\PageCache::class);
        $cache->setEnabled(true);
        $request = Request::create('GET', '/une-autre-page');
        $cache->store($request, Response::html('page en cache'));
        self::assertNotNull($cache->lookup($request));
        // The core cache subscriber purges whole pages on ordinary settings changes.
        $settings->set('site.name', 'Identité mise à jour');
        self::assertSame(null, $cache->lookup($request));
        $header = $hooks->apply('site.partial', null, 'header', $page, []);
        self::assertIsString($header);
        self::assertStringContainsString('alt="Identité mise à jour"', $header);
        self::assertStringContainsString('>Identité mise à jour</a></p>', $header);
        $cache->store($request, Response::html('page en cache'));
        self::assertNotNull($cache->lookup($request));
        // Model a changed path, then use the real UploadService metadata event.
        $media = $repository->update($media->id, ['disk_path' => 'brand-new.png']);
        self::assertNotNull($media);
        $uploader = $app->get(\Prime\Media\UploadService::class);
        $updated = $uploader->updateMetadata($media, ['title' => 'Média modifié']);
        self::assertSame('Média modifié', $updated->title);
        self::assertSame(null, $cache->lookup($request));
        $header = $hooks->apply('site.partial', null, 'header', $page, []);
        self::assertIsString($header);
        self::assertStringContainsString('src="/uploads/brand-new.png"', $header);
        $cache->store($request, Response::html('page en cache'));
        self::assertNotNull($cache->lookup($request));
        $uploader->delete($updated);
        self::assertSame(null, $cache->lookup($request));
        $header = $hooks->apply('site.partial', null, 'header', $page, []);
        self::assertIsString($header);
        self::assertStringContainsString('pb-site-logo__fallback">Identité mise à jour</span>', $header);
        self::assertStringNotContainsString('brand-new.png', $header);
    }

    public function testArchiveWidgetsUseTheResolvedPageAndConditionalAssetsWithoutPersistingResults(): void
    {
        $app = $this->activated('admin');
        $pdo = $this->db($app)->pdo();
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            // The SQLite-derived isolated fixture does not copy indexes; core search
            // uses this real CMS full-text index on MySQL for longer queries.
            $pdo->exec('CREATE FULLTEXT INDEX pb_test_content_search ON pc_entries (title, content_text)');
        }
        $this->insertGridEntry($pdo, 'post', 'published', 'Ancien public', 'ancien-public', 1);
        $this->insertGridEntry($pdo, 'post', 'published', 'Futur confidentiel', 'futur-confidentiel', 1, published: '2999-01-01 00:00:00');
        $pdo->exec("INSERT INTO pc_terms (id, taxonomy, name, slug, parent_id, menu_order, `count`) VALUES (11, 'category', 'Arts & idées', 'arts', 0, 0, 1)");
        $pdo->exec('INSERT INTO pc_entry_terms (entry_id, term_id, position) VALUES (1, 11, 0)');
        $settings = $app->get(Settings::class);
        $settings->set('reading.posts_per_page', 1);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'archive widgets', ['*'])['token'];
        $layout = ['version' => 1, 'elements' => [
            ['id' => 'archive-title', 'type' => 'archive_title', 'settings' => ['tag' => 'h2'], 'styles' => [], 'children' => []],
            ['id' => 'archive-list', 'type' => 'archive_list', 'settings' => ['layout' => 'cards', 'columns' => 2, 'show_excerpt' => 'show', 'pagination' => 'show'], 'styles' => [], 'children' => []],
        ]];
        $preview = $this->send($app, 'POST', '/api/prime-builder/v1/documents/1/render', ['data' => $layout], $token);
        self::assertSame(200, $preview->status(), $preview->body());
        self::assertStringContainsString('Ce widget affiche l’archive courante', $preview->body());
        $document = $this->document();
        $document['blocks'][0]['data'] = $layout;
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringNotContainsString('Article publié', $entry->contentHtml);
        self::assertStringNotContainsString('Ce widget affiche', $entry->contentHtml);
        self::assertStringNotContainsString('data-pb-live=', $entry->contentHtml);
        $template = $this->send($app, 'POST', '/api/prime-builder/v1/templates', ['name' => 'Archives', 'kind' => 'section', 'data' => $layout], $token);
        self::assertSame(201, $template->status(), $template->body());
        $templateId = json_decode($template->body(), true)['data']['id'];
        $fields = ['_csrf_token' => $app->get(Csrf::class)->token(), 'header' => '', 'footer' => $templateId, 'exclude' => ''];
        self::assertSame(302, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/theme', body: $fields))->status());
        $hooks = $app->get(Hooks::class);
        $resolver = $app->get(UrlResolver::class);
        $home = $resolver->resolve(Request::create('GET', '/'));
        self::assertSame(PageContext::TYPE_FRONT, $home->type);
        $footer = $hooks->apply('site.partial', null, 'footer', $home, []);
        self::assertIsString($footer);
        self::assertStringContainsString('<h2 class="pb-archive-title__text">Articles récents</h2>', $footer);
        self::assertStringContainsString('href="/blog/article-publie">Article publié</a>', $footer);
        self::assertStringContainsString('href="/page/2" rel="next"', $footer);
        self::assertStringContainsString('aria-current="page"', $footer);
        self::assertStringNotContainsString('Brouillon', $footer);
        self::assertStringNotContainsString('Futur confidentiel', $footer);
        self::assertStringNotContainsString('data-pb-live=', $footer);
        $second = $resolver->resolve(Request::create('GET', '/page/2'));
        $footer = $hooks->apply('site.partial', null, 'footer', $second, []);
        self::assertIsString($footer);
        self::assertStringContainsString('Ancien public', $footer);
        self::assertStringContainsString('href="/" rel="prev"', $footer);
        $category = $resolver->resolve(Request::create('GET', '/categorie/arts'));
        self::assertSame(PageContext::TYPE_TERM, $category->type);
        $footer = $hooks->apply('site.partial', null, 'footer', $category, []);
        self::assertIsString($footer);
        self::assertStringContainsString('Arts &amp; idées</h2>', $footer);
        self::assertStringContainsString('Article publié', $footer);
        $author = $resolver->resolve(Request::create('GET', '/auteur/admin'));
        self::assertSame(PageContext::TYPE_AUTHOR, $author->type);
        $footer = $hooks->apply('site.partial', null, 'footer', $author, []);
        self::assertIsString($footer);
        self::assertStringContainsString('Admin Prime</h2>', $footer);
        $search = $resolver->resolve(Request::create('GET', '/recherche', query: ['q' => 'art & <idée>']));
        $footer = $hooks->apply('site.partial', null, 'footer', $search, []);
        self::assertIsString($footer);
        self::assertStringContainsString('art &amp; &lt;idée&gt;', $footer);
        $single = $resolver->resolve(Request::create('GET', '/blog/article-publie'));
        $footer = $hooks->apply('site.partial', null, 'footer', $single, []);
        self::assertIsString($footer);
        self::assertStringNotContainsString('pb-archive-title__text', $footer);
        self::assertStringNotContainsString('pb-archive-list__items', $footer);
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, $home);
        foreach (['archive_title', 'archive_list'] as $type) {
            self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/' . $type . '.css'));
        }
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/content.js', $assets->renderScripts());
        $cache = $app->get(\Prime\Cache\PageCache::class);
        $cache->setEnabled(true);
        self::assertSame(false, $cache->isCacheableRequest(Request::create('GET', '/')));
        $settings->set('plugin.prime-builder.theme_partials', []);
        self::assertSame(true, $cache->isCacheableRequest(Request::create('GET', '/')));
        $other = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($other);
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        foreach (['archive_title', 'archive_list'] as $type) {
            self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/' . $type . '.css', $assets->renderStyles());
        }
    }

    public function testArchiveListChecksPrivateEntriesAndBatchesPageAncestors(): void
    {
        $app = $this->activated(null);
        $db = $this->db($app);
        $pdo = $db->pdo();
        $ids = [];
        for ($i = 1; $i <= 12; $i++) {
            $parent = $this->insertGridEntry($pdo, 'page', 'published', 'Parent ' . $i, 'archive-parent-' . $i, 1);
            $ids[] = $this->insertGridEntry($pdo, 'page', 'published', 'Enfant ' . $i, 'archive-enfant-' . $i, 1, $parent);
        }
        $hidden = $this->insertGridEntry($pdo, 'page', 'draft', 'Parent secret', 'archive-secret', 1);
        $ids[] = $this->insertGridEntry($pdo, 'page', 'published', 'Enfant secret', 'archive-enfant-secret', 1, $hidden);
        $ids[] = $this->insertGridEntry($pdo, 'page', 'published', 'Orphelin secret', 'archive-orphelin', 1, 999999);
        $cycle = $this->insertGridEntry($pdo, 'page', 'published', 'Cycle secret', 'archive-cycle', 1);
        $pdo->exec('UPDATE pc_entries SET parent_id = ' . $cycle . ' WHERE id = ' . $cycle);
        $ids[] = $cycle;
        $ids[] = $this->insertGridEntry($pdo, 'post', 'draft', 'Brouillon secret', 'archive-brouillon', 1);
        $ids[] = $this->insertGridEntry($pdo, 'post', 'published', 'Futur secret', 'archive-futur', 1, published: '2999-01-01 00:00:00');
        $raw = $app->get(EntryRepository::class)->findManyById($ids);
        $items = array_values($raw);
        $one = new PageContext(type: PageContext::TYPE_SEARCH, path: '/recherche', entries: [$items[0]]);
        $all = new PageContext(type: PageContext::TYPE_SEARCH, path: '/recherche', entries: $items);
        $db->enableQueryLog();
        $provider = $this->gridProvider($app);
        $db->clearQueryLog();
        $single = $provider->archiveWidget('archive_list', [], $one);
        $oneQueries = count($db->queryLog());
        self::assertSame(1, $oneQueries);
        self::assertSame(1, substr_count($single, '<article>'));
        $provider = $this->gridProvider($app);
        $db->clearQueryLog();
        $many = $provider->archiveWidget('archive_list', [], $all);
        self::assertSame($oneQueries, count($db->queryLog()), 'Ancestors are fetched in batches, not one query per result');
        self::assertSame(12, substr_count($many, '<article>'));
        self::assertStringContainsString('href="/archive-parent-12/archive-enfant-12"', $many);
        self::assertStringNotContainsString('secret', strtolower($many));
        self::assertStringNotContainsString('archive-brouillon', $many);
        self::assertStringNotContainsString('archive-futur', $many);
        $db->enableQueryLog(false);
    }

    public function testEntryWidgetsInThemePartialsUsePublicContextWithoutJavascriptOrStaleProfiles(): void
    {
        $app = $this->activated('admin');
        $pdo = $this->db($app)->pdo();
        $pdo->exec('CREATE TABLE pc_media_usage (entry_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $pdo->exec('CREATE TABLE pc_media_zone_usage (zone_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $pdo->exec("UPDATE pc_entries SET published_at = '2024-06-01 10:00:00' WHERE id = 1");
        $pdo->exec("UPDATE pc_users SET display_name = 'Camille & Co', username = 'camille', bio = 'Bio <publique>' WHERE id = 1");
        $previous = $this->insertGridEntry($pdo, 'post', 'published', 'Avant public', 'avant-public', 1, published: '2024-05-01 10:00:00');
        $this->insertGridEntry($pdo, 'post', 'published', 'Après public', 'apres-public', 1, published: '2024-07-01 10:00:00');
        $this->insertGridEntry($pdo, 'post', 'draft', 'Voisin privé', 'voisin-prive', 1, published: '2024-05-15 10:00:00');
        $pdo->exec("INSERT INTO pc_terms (id, taxonomy, name, slug, parent_id, menu_order, `count`) VALUES (11, 'category', 'Nature & idées', 'nature', 0, 0, 1)");
        $pdo->exec('INSERT INTO pc_entry_terms (entry_id, term_id, position) VALUES (1, 11, 0)');
        $media = $app->get(\Prime\Media\MediaRepository::class)->create([
            'disk_path' => 'entry/theme.png', 'filename' => 'theme.png', 'original_name' => 'theme.png',
            'extension' => 'png', 'mime_type' => 'image/png', 'kind' => 'image', 'width' => 600, 'height' => 300, 'alt' => 'Photo de l’article',
        ]);
        $entries = $app->get(EntryRepository::class);
        $entries->setMeta(1, ['_featured_media_id' => $media->id]);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'theme context', ['*'])['token'];
        $types = ['entry_title', 'entry_excerpt', 'entry_featured_image', 'entry_info', 'entry_navigation', 'entry_author'];
        $children = [];
        foreach ($types as $type) {
            $children[] = ['id' => $type, 'type' => $type, 'settings' => [], 'styles' => [], 'children' => []];
        }
        $layout = ['version' => 1, 'elements' => [[
            'id' => 'outer', 'type' => 'container', 'settings' => [], 'styles' => [], 'children' => $children,
        ]]];
        $template = $this->send($app, 'POST', '/api/prime-builder/v1/templates', ['name' => 'Contenu courant', 'kind' => 'section', 'data' => $layout], $token);
        self::assertSame(201, $template->status(), $template->body());
        $templateId = json_decode($template->body(), true)['data']['id'];
        $fields = ['_csrf_token' => $app->get(Csrf::class)->token(), 'header' => $templateId, 'footer' => '', 'exclude' => ''];
        self::assertSame(302, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/theme', body: $fields))->status());
        $entry = $entries->find(1);
        self::assertNotNull($entry);
        $page = new PageContext(type: PageContext::TYPE_ENTRY, path: '/blog/article-publie', entry: $entry);
        $hooks = $app->get(Hooks::class);
        $header = $hooks->apply('site.partial', null, 'header', $page, []);
        self::assertIsString($header);
        self::assertStringContainsString('>Article publié</h2>', $header);
        self::assertStringContainsString('>Résumé</p>', $header);
        self::assertStringContainsString('alt="Photo de l’article"', $header);
        self::assertStringContainsString('>Nature &amp; idées</a>', $header);
        self::assertStringContainsString('href="/blog/avant-public" rel="prev"', $header);
        self::assertStringContainsString('href="/blog/apres-public" rel="next"', $header);
        self::assertStringContainsString('href="/auteur/camille">Camille &amp; Co</a>', $header);
        self::assertStringContainsString('Bio &lt;publique&gt;', $header);
        self::assertStringNotContainsString('Voisin privé', $header);
        self::assertStringNotContainsString('data-pb-live=', $header);
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, $page);
        foreach ($types as $type) {
            self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/' . $type . '.css'));
        }
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/content.js', $assets->renderScripts());
        // An entry layout still needs its live script even when a matching widget
        // is rendered server-side in the partial.
        $entryWithLayout = \Prime\Content\Entry::fromRow([
            'id' => 1, 'type' => 'post', 'status' => 'published', 'title' => 'Article publié',
            'published_at' => '2024-06-01 10:00:00',
            'content' => json_encode(['version' => 1, 'blocks' => [['id' => 'layout', 'type' => 'prime-builder/layout', 'data' => $layout]]]),
        ]);
        $entryAssets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $entryAssets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entryWithLayout));
        self::assertSame(1, substr_count($entryAssets->renderScripts(), '/assets/plugins/prime-builder/js/content.js'));
        $cache = $app->get(\Prime\Cache\PageCache::class);
        $cache->setEnabled(true);
        self::assertSame(false, $cache->isCacheableRequest(Request::create('GET', '/blog/article-publie')));

        // The context is guarded independently of the source of the PageContext.
        $draft = $entries->find(2);
        self::assertNotNull($draft);
        $invalidPages = [
            new PageContext(type: PageContext::TYPE_ENTRY, entry: $draft),
            new PageContext(type: PageContext::TYPE_SEARCH, entry: $entry),
            new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry, httpStatus: 404),
            PageContext::redirect('/redirection', '/destination'),
            new PageContext(type: PageContext::TYPE_ENTRY, entry: \Prime\Content\Entry::fromRow(['id' => 99, 'type' => 'post', 'status' => 'published', 'title' => 'Futur privé', 'published_at' => '2999-01-01 00:00:00'])),
        ];
        $parent = $this->insertGridEntry($pdo, 'page', 'draft', 'Parent privé', 'parent-prive', 1);
        $child = $this->insertGridEntry($pdo, 'page', 'published', 'Enfant inaccessible', 'enfant-inaccessible', 1, $parent);
        $unreachable = $entries->find($child);
        self::assertNotNull($unreachable);
        $invalidPages[] = new PageContext(type: PageContext::TYPE_ENTRY, entry: $unreachable);
        $cycle = $this->insertGridEntry($pdo, 'page', 'published', 'Page cyclique', 'page-cyclique', 1);
        $pdo->exec('UPDATE pc_entries SET parent_id = ' . $cycle . ' WHERE id = ' . $cycle);
        $cyclic = $entries->find($cycle);
        self::assertNotNull($cyclic);
        $invalidPages[] = new PageContext(type: PageContext::TYPE_ENTRY, entry: $cyclic);
        $futureParent = $this->insertGridEntry($pdo, 'page', 'published', 'Parent futur', 'parent-futur', 1, published: '2999-01-01 00:00:00');
        $futureChild = $this->insertGridEntry($pdo, 'page', 'published', 'Page sous parent futur', 'page-sous-parent-futur', 1, $futureParent);
        $unpublishedParent = $entries->find($futureChild);
        self::assertNotNull($unpublishedParent);
        $invalidPages[] = new PageContext(type: PageContext::TYPE_ENTRY, entry: $unpublishedParent);
        $app->get(ContentTypeRegistry::class)->register(new ContentType(name: 'vault', labels: ['singular' => 'Privé'], public: false));
        $invalidPages[] = new PageContext(type: PageContext::TYPE_ENTRY, entry: \Prime\Content\Entry::fromRow(['id' => 100, 'type' => 'vault', 'status' => 'published', 'title' => 'Type privé']));
        foreach ($invalidPages as $invalid) {
            $rendered = $hooks->apply('site.partial', null, 'header', $invalid, []);
            self::assertIsString($rendered);
            self::assertStringNotContainsString('pb-entry-title__text', $rendered);
            self::assertStringNotContainsString('Brouillon admin', $rendered);
            self::assertStringNotContainsString('Enfant inaccessible', $rendered);
            self::assertStringNotContainsString('Page cyclique', $rendered);
            self::assertStringNotContainsString('Page sous parent futur', $rendered);
            self::assertStringNotContainsString('Futur privé', $rendered);
            self::assertStringNotContainsString('Type privé', $rendered);
        }

        $pdo->exec("UPDATE pc_entries SET status = 'draft', title = 'Retiré privé' WHERE id = " . $previous);
        $pdo->exec("UPDATE pc_users SET status = 'disabled', bio = 'Bio devenue privée' WHERE id = 1");
        $app->get(TaxonomyRegistry::class)->register(new Taxonomy(name: 'category', labels: ['singular' => 'Catégorie'], contentTypes: ['post'], public: false));
        $fresh = $entries->find(1);
        self::assertNotNull($fresh);
        $updated = $hooks->apply('site.partial', null, 'header', new PageContext(type: PageContext::TYPE_ENTRY, entry: $fresh), []);
        self::assertIsString($updated);
        self::assertStringNotContainsString('Avant public', $updated);
        self::assertStringNotContainsString('Retiré privé', $updated);
        self::assertStringNotContainsString('Bio devenue privée', $updated);
        self::assertStringNotContainsString('Camille &amp; Co', $updated);
        self::assertStringNotContainsString('pb-entry-author__card', $updated);
        self::assertStringNotContainsString('Nature &amp; idées', $updated);
        self::assertStringContainsString('Après public', $updated);
        $settings = $app->get(Settings::class);
        $stored = json_encode($settings->get('plugin.prime-builder.theme_partials'));
        self::assertIsString($stored);
        self::assertStringNotContainsString('Camille', $stored);
        self::assertStringNotContainsString('Avant public', $stored);
        $settings->set('plugin.prime-builder.theme_partials', []);
        self::assertSame(true, $cache->isCacheableRequest(Request::create('GET', '/blog/article-publie')));
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, $page);
        foreach ($types as $type) {
            self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/' . $type . '.css', $assets->renderStyles());
        }
    }

    public function testEntryContentCopiesThePublicEntryWithoutRecursionOrDuplicateIds(): void
    {
        $app = $this->activated('admin');
        $pdo = $this->db($app)->pdo();
        $pdo->exec('CREATE TABLE pc_media_usage (entry_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $pdo->exec('CREATE TABLE pc_media_zone_usage (zone_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $entries = $app->get(EntryRepository::class);
        $hooks = $app->get(Hooks::class);
        $initialEntry = $entries->find(1);
        self::assertNotNull($initialEntry);
        $assetsWithoutWidget = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assetsWithoutWidget, new PageContext(type: PageContext::TYPE_ENTRY, entry: $initialEntry));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/entry_content.css', $assetsWithoutWidget->renderStyles());
        $token = $this->tokens($app)->issue($this->user($app, 1), 'entry content', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'heading', 'type' => 'heading', 'settings' => ['text' => 'Texte publié'], 'styles' => [], 'children' => []],
            ['id' => 'tabs', 'type' => 'tabs', 'settings' => ['label' => 'Choix', 'title_1' => 'Premier', 'text_1' => '<p>Un</p>', 'title_2' => 'Second', 'text_2' => '<p>Deux</p>'], 'styles' => [], 'children' => []],
            ['id' => 'self', 'type' => 'entry_content', 'settings' => [], 'styles' => [], 'children' => []],
        ];
        $saved = $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token);
        self::assertSame(200, $saved->status(), $saved->body());
        $entry = $entries->find(1);
        self::assertNotNull($entry);
        self::assertSame(1, substr_count($entry->contentHtml, 'Texte publié'));
        self::assertStringNotContainsString('data-pb-content-copy', $entry->contentHtml);
        $layout = ['version' => 1, 'elements' => [
            ['id' => 'first', 'type' => 'entry_content', 'settings' => [], 'styles' => [], 'children' => []],
            ['id' => 'second', 'type' => 'entry_content', 'settings' => [], 'styles' => [], 'children' => []],
        ]];
        $template = $this->send($app, 'POST', '/api/prime-builder/v1/templates', ['name' => 'Corps du contenu', 'kind' => 'section', 'data' => $layout], $token);
        self::assertSame(201, $template->status(), $template->body());
        $templateId = json_decode($template->body(), true)['data']['id'];
        $fields = ['_csrf_token' => $app->get(Csrf::class)->token(), 'header' => $templateId, 'footer' => $templateId, 'exclude' => ''];
        self::assertSame(302, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/theme', body: $fields))->status());
        $page = new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry);
        $header = $hooks->apply('site.partial', null, 'header', $page, []);
        $footer = $hooks->apply('site.partial', null, 'footer', $page, []);
        self::assertIsString($header);
        self::assertIsString($footer);
        self::assertSame(2, substr_count($header, 'Texte publié'));
        self::assertSame(2, substr_count($footer, 'Texte publié'));
        self::assertSame(2, substr_count($header, 'data-pb-content-copy='));
        self::assertSame(2, substr_count($footer, 'data-pb-content-copy='));
        self::assertStringNotContainsString('pb-entry-content__preview', $header);
        preg_match_all('/\sid="([^"]+)"/', $header . $entry->contentHtml . $footer, $matches);
        self::assertNotEmpty($matches[1]);
        self::assertSame(count($matches[1]), count(array_unique($matches[1])));
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, $page);
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/entry_content.css'));
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/tabs.js'));
        $cache = $app->get(\Prime\Cache\PageCache::class);
        $cache->setEnabled(true);
        self::assertSame(false, $cache->isCacheableRequest(Request::create('GET', '/blog/article-publie')));

        $provider = $app->get(Content::class);
        $draft = $entries->find(2);
        self::assertNotNull($draft);
        self::assertSame('', $provider->entryContentInPage(new PageContext(type: PageContext::TYPE_ENTRY, entry: $draft)));
        self::assertSame('', $provider->entryContentInPage(new PageContext(type: PageContext::TYPE_SEARCH, entry: $entry)));
        self::assertSame('', $provider->entryContentInPage(new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry, httpStatus: 404)));
        $parent = $this->insertGridEntry($pdo, 'page', 'draft', 'Parent privé', 'parent-contenu-prive', 1);
        $child = $this->insertGridEntry($pdo, 'page', 'published', 'Enfant', 'enfant-contenu-prive', 1, $parent);
        $unreachable = $entries->find($child);
        self::assertNotNull($unreachable);
        self::assertSame('', $provider->entryContentInPage(new PageContext(type: PageContext::TYPE_ENTRY, entry: $unreachable)));

        // Registered CMS dynamic blocks are evaluated by EntryView::content().
        // A block that re-enters this same provider cannot recurse indefinitely.
        $recursivePage = null;
        $hooks->filter('blocks.dynamic', static function (mixed $blocks) use ($provider, &$recursivePage): mixed {
            if (is_array($blocks)) {
                $blocks['recursion_probe'] = [
                    'render' => static fn (array $attrs): string => $provider->entryContentInPage($recursivePage) . '<p id="dynamic">Dynamique public</p>',
                ];
            }
            return $blocks;
        });
        $app->get(\Prime\Site\Zone\DynamicBlockRegistry::class)->resetCache();
        $dynamicDocument = ['version' => 1, 'blocks' => [
            ['id' => 'one', 'type' => 'dynamic', 'data' => ['name' => 'recursion_probe', 'attrs' => []]],
            ['id' => 'two', 'type' => 'dynamic', 'data' => ['name' => 'search_form', 'attrs' => []]],
        ]];
        $stmt = $pdo->prepare('UPDATE pc_entries SET content = ?, content_html = ? WHERE id = 1');
        $stmt->execute([json_encode($dynamicDocument), '<p>Repli périmé</p>']);
        $dynamicEntry = $entries->find(1);
        self::assertNotNull($dynamicEntry);
        $recursivePage = new PageContext(type: PageContext::TYPE_ENTRY, entry: $dynamicEntry);
        $dynamic = $provider->entryContentInPage($recursivePage);
        self::assertSame(1, substr_count($dynamic, 'Dynamique public'));
        self::assertStringNotContainsString('Repli périmé', $dynamic);
        self::assertStringContainsString('<form', $dynamic);
        self::assertStringContainsString('name="q"', $dynamic);
        self::assertStringNotContainsString('id="dynamic"', $dynamic);
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, $recursivePage);
        self::assertStringContainsString('/assets/plugins/prime-builder/css/widgets/entry_content.css', $assets->renderStyles());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/tabs.js', $assets->renderScripts());
        $stored = json_encode($app->get(Settings::class)->get('plugin.prime-builder.theme_partials'));
        self::assertIsString($stored);
        self::assertStringNotContainsString('Dynamique public', $stored);
        self::assertStringNotContainsString('Texte publié', $stored);
    }

    public function testEntryWidgetsUseCoreMetadataRefreshAndLoadConditionalAssets(): void
    {
        $app = $this->activated(null);
        $pdo = $this->db($app)->pdo();
        $pdo->exec('CREATE TABLE pc_media_usage (entry_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $pdo->exec('CREATE TABLE pc_media_zone_usage (zone_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $pdo->exec("INSERT INTO pc_terms (id, taxonomy, name, slug, parent_id, menu_order, `count`) VALUES (11, 'category', 'Nature & idées', 'nature', 0, 0, 1)");
        $pdo->exec('INSERT INTO pc_entry_terms (entry_id, term_id, position) VALUES (1, 11, 0)');
        $media = $app->get(\Prime\Media\MediaRepository::class)->create([
            'disk_path' => 'entry/photo.png', 'filename' => 'photo.png', 'original_name' => 'photo.png',
            'extension' => 'png', 'mime_type' => 'image/png', 'kind' => 'image', 'width' => 1200, 'height' => 600, 'alt' => 'Paysage initial',
            'variants' => ['medium' => ['path' => 'entry/photo-medium.png', 'width' => 300, 'height' => 150]],
        ]);
        $entries = $app->get(EntryRepository::class);
        $entries->setMeta(1, ['_featured_media_id' => $media->id]);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'entry widgets', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'title', 'type' => 'entry_title', 'settings' => ['tag' => 'h3', 'link' => 'show'], 'styles' => [], 'children' => []],
            ['id' => 'excerpt', 'type' => 'entry_excerpt', 'settings' => ['length' => 180], 'styles' => [], 'children' => []],
            ['id' => 'photo', 'type' => 'entry_featured_image', 'settings' => ['size' => 'medium'], 'styles' => [], 'children' => []],
            ['id' => 'info', 'type' => 'entry_info', 'settings' => [], 'styles' => [], 'children' => []],
        ];
        $preview = $this->send($app, 'POST', '/api/prime-builder/v1/documents/1/render', ['data' => $document['blocks'][0]['data']], $token);
        self::assertSame(200, $preview->status(), $preview->body());
        $html = json_decode($preview->body(), true)['data']['html'];
        self::assertStringContainsString('<h3 class="pb-entry-title__text"><a href="/blog/article-publie">Article publié</a></h3>', $html);
        self::assertStringContainsString('pb-entry-excerpt__text">Résumé</p>', $html);
        self::assertStringContainsString('src="/uploads/entry/photo-medium.png"', $html);
        self::assertStringContainsString('alt="Paysage initial"', $html);
        self::assertStringContainsString('>Admin Prime</a>', $html);
        self::assertStringContainsString('>Nature &amp; idées</a>', $html);
        self::assertStringContainsString('<time datetime=', $html);
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $entries->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('data-pb-live="/api/prime-builder/v1/documents/1/live"', $entry->contentHtml);
        self::assertStringContainsString('Article publié', $entry->contentHtml);
        // Taxonomy visibility and the author's account can change after saving.
        self::assertStringNotContainsString('Nature &amp; idées', $entry->contentHtml);
        self::assertStringNotContainsString('Admin Prime', $entry->contentHtml);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        foreach (['entry_title', 'entry_excerpt', 'entry_featured_image', 'entry_info'] as $type) {
            self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/' . $type . '.css'));
        }
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/content.js'));
        $other = $entries->find(2);
        self::assertNotNull($other);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        foreach (['entry_title', 'entry_excerpt', 'entry_featured_image', 'entry_info'] as $type) {
            self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/' . $type . '.css', $assets->renderStyles());
        }
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/content.js', $assets->renderScripts());
        $content = $app->get(Content::class);
        self::assertStringContainsString('alt="Paysage initial"', $content->entryWidget('entry_featured_image', [], $entry, true));
        $app->get(\Prime\Media\UploadService::class)->updateMetadata($media, ['alt' => 'Paysage modifié']);
        // The same Entry instance must not retain a stale hydrated media object.
        self::assertStringContainsString('alt="Paysage modifié"', $content->entryWidget('entry_featured_image', [], $entry, true));
        $pdo->exec("UPDATE pc_entries SET title = 'Nouveau titre', excerpt = 'Nouvel extrait' WHERE id = 1");
        $live = $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live');
        self::assertSame(200, $live->status(), $live->body());
        self::assertStringContainsString('no-store', (string) $live->header('Cache-Control'));
        $widgets = json_decode($live->body(), true)['data']['widgets'];
        self::assertStringContainsString('>Nouveau titre</a></h3>', $widgets['title']);
        self::assertStringContainsString('>Nouvel extrait</p>', $widgets['excerpt']);
        self::assertStringContainsString('alt="Paysage modifié"', $widgets['photo']);
        self::assertStringContainsString('>Nature &amp; idées</a>', $widgets['info']);
        $saved = $entries->find(1);
        self::assertNotNull($saved);
        self::assertStringContainsString('Article publié', $saved->contentHtml);
        self::assertStringNotContainsString('Nouveau titre', $saved->contentHtml);
        $app->get(TaxonomyRegistry::class)->register(new Taxonomy(name: 'category', labels: ['singular' => 'Catégorie'], contentTypes: ['post'], public: false));
        $private = $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live');
        self::assertSame(200, $private->status());
        $privateWidgets = json_decode($private->body(), true)['data']['widgets'];
        self::assertStringNotContainsString('Nature', $privateWidgets['info']);
        self::assertStringNotContainsString('/category/', $privateWidgets['info']);
        $pdo->exec("UPDATE pc_entries SET status = 'draft' WHERE id = 1");
        self::assertSame(404, $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live')->status());
    }

    public function testEntryNavigationAndAuthorRefreshWithoutPersistingOtherProfilesOrEntries(): void
    {
        $app = $this->activated(null);
        $pdo = $this->db($app)->pdo();
        $pdo->exec("UPDATE pc_entries SET published_at = '2024-06-01 10:00:00' WHERE id = 1");
        $pdo->exec("UPDATE pc_users SET display_name = 'Camille & Co', username = 'camille', bio = 'Bio <privée> & sûre' WHERE id = 1");
        $insert = $pdo->prepare('INSERT INTO pc_entries (id, type, status, title, slug, excerpt, content, content_html, content_text, author_id, parent_id, published_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            [4, 'published', 'Ancien & public', 'ancien', '2024-05-01 10:00:00'],
            [5, 'published', 'Nouveau public', 'nouveau', '2024-07-01 10:00:00'],
            [6, 'draft', 'Titre secret', 'secret', '2024-05-15 10:00:00'],
            [7, 'published', 'Titre futur', 'futur', '2999-01-01 00:00:00'],
        ] as [$id, $status, $title, $slug, $date]) {
            $insert->execute([$id, 'post', $status, $title, $slug, '', '{"blocks":[]}', '', '', 1, 0, $date, $date, $date]);
        }
        $token = $this->tokens($app)->issue($this->user($app, 1), 'entry context', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'adjacent', 'type' => 'entry_navigation', 'settings' => ['label' => 'À lire', 'previous' => 'show', 'next' => 'show'], 'styles' => [], 'children' => []],
            ['id' => 'author', 'type' => 'entry_author', 'settings' => ['avatar' => 'show', 'bio' => 'show', 'link' => 'show'], 'styles' => [], 'children' => []],
        ];
        $preview = $this->send($app, 'POST', '/api/prime-builder/v1/documents/1/render', ['data' => $document['blocks'][0]['data']], $token);
        self::assertSame(200, $preview->status(), $preview->body());
        $html = json_decode($preview->body(), true)['data']['html'];
        self::assertStringContainsString('href="/blog/ancien" rel="prev"', $html);
        self::assertStringContainsString('Ancien &amp; public', $html);
        self::assertStringContainsString('href="/blog/nouveau" rel="next"', $html);
        self::assertStringContainsString('href="/auteur/camille">Camille &amp; Co</a>', $html);
        self::assertStringContainsString('Bio &lt;privée&gt; &amp; sûre', $html);
        self::assertStringNotContainsString('Titre secret', $html);
        self::assertStringNotContainsString('Titre futur', $html);
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        $owner = Scope::forLayout($entry->decodedContent()['blocks'][0]['data']);
        self::assertStringContainsString('class="pb-element pb-entry_navigation" data-pb-owner="' . $owner . '" data-pb-id="adjacent"></div>', $entry->contentHtml);
        self::assertStringContainsString('class="pb-element pb-entry_author" data-pb-owner="' . $owner . '" data-pb-id="author"></div>', $entry->contentHtml);
        self::assertStringNotContainsString('Ancien', $entry->contentHtml);
        self::assertStringNotContainsString('Camille', $entry->contentHtml);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        foreach (['entry_navigation', 'entry_author'] as $type) {
            self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/' . $type . '.css'));
        }
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/content.js'));
        $other = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($other);
        $unused = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $unused, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        foreach (['entry_navigation', 'entry_author'] as $type) {
            self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/' . $type . '.css', $unused->renderStyles());
        }
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/content.js', $unused->renderScripts());
        $live = $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live');
        self::assertSame(200, $live->status(), $live->body());
        self::assertStringContainsString('no-store', (string) $live->header('Cache-Control'));
        $widgets = json_decode($live->body(), true)['data']['widgets'];
        self::assertStringContainsString('Ancien &amp; public', $widgets['adjacent']);
        self::assertStringContainsString('Camille &amp; Co', $widgets['author']);

        // A neighbour and an author may be withdrawn without saving this entry.
        $pdo->exec("UPDATE pc_entries SET status = 'draft', title = 'Nouveau secret' WHERE id = 4");
        $pdo->exec("UPDATE pc_users SET status = 'disabled', bio = 'Biographie devenue privée' WHERE id = 1");
        $live = $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live');
        self::assertSame(200, $live->status());
        $widgets = json_decode($live->body(), true)['data']['widgets'];
        self::assertStringNotContainsString('Nouveau secret', $widgets['adjacent']);
        self::assertStringNotContainsString('Ancien', $widgets['adjacent']);
        self::assertStringContainsString('Nouveau public', $widgets['adjacent']);
        self::assertSame('', $widgets['author']);
        $stored = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($stored);
        self::assertStringNotContainsString('Nouveau secret', $stored->contentHtml);
        self::assertStringNotContainsString('Biographie devenue privée', $stored->contentHtml);

        // The CMS resolves an explicit username before another user's generated slug.
        // Do not link the latter's name to the wrong active author's archive.
        $pdo->exec("UPDATE pc_users SET status = 'active', username = NULL, display_name = 'Camille', bio = '' WHERE id = 1");
        $pdo->exec("UPDATE pc_users SET username = 'camille' WHERE id = 2");
        $collision = $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live');
        self::assertSame(200, $collision->status());
        $author = json_decode($collision->body(), true)['data']['widgets']['author'];
        self::assertStringContainsString('Camille', $author);
        self::assertStringNotContainsString('href=', $author);
        $current = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($current);
        $info = $app->get(Content::class)->entryWidget('entry_info', ['date' => 'hide', 'categories' => 'hide'], $current, true);
        self::assertStringContainsString('Camille', $info);
        self::assertStringNotContainsString('href=', $info);
        self::assertSame('', $app->get(Content::class)->current(['source' => 'author'], $current));
        self::assertStringContainsString('Camille', $app->get(Content::class)->current(['source' => 'author'], $current, true));
        $pdo->exec("UPDATE pc_users SET status = 'disabled' WHERE id = 1");
        self::assertSame('', $app->get(Content::class)->current(['source' => 'author'], $current, true));
        self::assertSame('', $app->get(Content::class)->entryWidget('entry_info', ['date' => 'hide', 'categories' => 'hide'], $current, true));
    }

    public function testEntryNavigationSkipsPublishedPagesWithPrivateOrCyclicAncestors(): void
    {
        $app = $this->activated(null);
        $pdo = $this->db($app)->pdo();
        $insert = $pdo->prepare('INSERT INTO pc_entries (id, type, status, title, slug, excerpt, content, content_html, content_text, author_id, parent_id, published_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            [10, 'published', 'Page courante', 'courante', 0, '2024-06-01 10:00:00'],
            [11, 'published', 'Enfant inaccessible', 'enfant', 12, '2024-05-15 10:00:00'],
            [12, 'draft', 'Parent privé', 'parent-secret', 0, '2024-05-01 10:00:00'],
            [13, 'published', 'Page précédente', 'precedente', 0, '2024-04-01 10:00:00'],
            [14, 'published', 'Cycle inaccessible', 'cycle', 14, '2024-07-01 10:00:00'],
            [15, 'published', 'Page suivante', 'suivante', 0, '2024-08-01 10:00:00'],
        ] as [$id, $status, $title, $slug, $parent, $date]) {
            $insert->execute([$id, 'page', $status, $title, $slug, '', '{"blocks":[]}', '', '', 1, $parent, $date, $date, $date]);
        }
        $entry = $app->get(EntryRepository::class)->find(10);
        self::assertNotNull($entry);
        $content = $app->get(Content::class);
        $html = $content->entryWidget('entry_navigation', [], $entry, true);
        self::assertStringContainsString('href="/precedente" rel="prev"', $html);
        self::assertStringContainsString('href="/suivante" rel="next"', $html);
        self::assertStringNotContainsString('inaccessible', $html);
        self::assertStringNotContainsString('parent-secret', $html);
        self::assertSame('', $content->entryWidget('entry_navigation', [], $entry, false));
        self::assertSame('', $content->entryWidget('entry_author', [], $entry, false));
    }

    public function testEntryWidgetsDoNotPersistDraftMetadataDuringPublication(): void
    {
        $app = $this->activated(null);
        $pdo = $this->db($app)->pdo();
        $pdo->exec('CREATE TABLE pc_media_usage (entry_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $pdo->exec('CREATE TABLE pc_media_zone_usage (zone_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $media = $app->get(\Prime\Media\MediaRepository::class);
        $secret = $media->create(['disk_path' => 'secret.png', 'filename' => 'secret.png', 'original_name' => 'secret.png', 'extension' => 'png', 'mime_type' => 'image/png', 'kind' => 'image', 'alt' => 'Image privée']);
        $public = $media->create(['disk_path' => 'public.png', 'filename' => 'public.png', 'original_name' => 'public.png', 'extension' => 'png', 'mime_type' => 'image/png', 'kind' => 'image', 'alt' => 'Image publique']);
        $entries = $app->get(EntryRepository::class);
        $entries->setMeta(2, ['_featured_media_id' => $secret->id]);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'entry publish', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'title', 'type' => 'entry_title', 'settings' => ['link' => 'show'], 'styles' => [], 'children' => []],
            ['id' => 'excerpt', 'type' => 'entry_excerpt', 'settings' => [], 'styles' => [], 'children' => []],
            ['id' => 'image', 'type' => 'entry_featured_image', 'settings' => [], 'styles' => [], 'children' => []],
            ['id' => 'info', 'type' => 'entry_info', 'settings' => [], 'styles' => [], 'children' => []],
            ['id' => 'legacy_title', 'type' => 'current_content', 'settings' => ['source' => 'title'], 'styles' => [], 'children' => []],
            ['id' => 'legacy_excerpt', 'type' => 'current_content', 'settings' => ['source' => 'excerpt'], 'styles' => [], 'children' => []],
        ];
        $initial = $this->send($app, 'PATCH', '/api/v1/content/post/2', [
            'status' => 'published', 'title' => 'Titre public', 'excerpt' => 'Résumé public', 'featured_media_id' => $public->id, 'content' => $document,
        ], $token);
        self::assertSame(200, $initial->status(), $initial->body());
        $entry = $entries->find(2);
        self::assertNotNull($entry);
        self::assertStringNotContainsString('Brouillon admin', $entry->contentHtml);
        self::assertStringNotContainsString('Secret', $entry->contentHtml);
        self::assertStringNotContainsString('secret.png', $entry->contentHtml);
        self::assertStringNotContainsString('Image privée', $entry->contentHtml);
        $live = $this->send($app, 'GET', '/api/prime-builder/v1/documents/2/live');
        self::assertSame(200, $live->status());
        $widgets = json_decode($live->body(), true)['data']['widgets'];
        self::assertStringContainsString('Titre public', $widgets['title']);
        self::assertStringContainsString('Résumé public', $widgets['excerpt']);
        self::assertStringContainsString('Titre public', $widgets['legacy_title']);
        self::assertStringContainsString('Résumé public', $widgets['legacy_excerpt']);
        self::assertStringContainsString('public.png', $widgets['image']);
        self::assertStringNotContainsString('secret.png', $widgets['image']);

        $draft = $this->send($app, 'PATCH', '/api/v1/content/post/2', ['status' => 'draft', 'title' => 'Titre privé', 'excerpt' => 'Résumé privé', 'featured_media_id' => $secret->id], $token);
        self::assertSame(200, $draft->status(), $draft->body());
        self::assertSame(404, $this->send($app, 'GET', '/api/prime-builder/v1/documents/2/live')->status());
        $preview = $this->send($app, 'POST', '/api/prime-builder/v1/documents/2/render', ['data' => $document['blocks'][0]['data']], $token);
        self::assertSame(200, $preview->status());
        self::assertStringContainsString('Titre privé', $preview->body());
        self::assertStringContainsString('Résumé privé', $preview->body());
        $republished = $this->send($app, 'PATCH', '/api/v1/content/post/2', ['status' => 'published', 'title' => 'Titre republié', 'excerpt' => 'Résumé republié', 'featured_media_id' => $public->id], $token);
        self::assertSame(200, $republished->status(), $republished->body());
        $entry = $entries->find(2);
        self::assertNotNull($entry);
        self::assertStringNotContainsString('Titre privé', $entry->contentHtml);
        self::assertStringNotContainsString('Résumé privé', $entry->contentHtml);
        self::assertStringNotContainsString('secret.png', $entry->contentHtml);
        $live = $this->send($app, 'GET', '/api/prime-builder/v1/documents/2/live');
        self::assertSame(200, $live->status());
        $widgets = json_decode($live->body(), true)['data']['widgets'];
        self::assertStringContainsString('Titre republié', $widgets['title']);
        self::assertStringContainsString('Résumé republié', $widgets['excerpt']);
        self::assertStringContainsString('Titre republié', $widgets['legacy_title']);
        self::assertStringContainsString('Résumé republié', $widgets['legacy_excerpt']);
        self::assertStringContainsString('public.png', $widgets['image']);
        $future = \Prime\Content\Entry::fromRow(['id' => 10, 'type' => 'post', 'status' => 'published', 'title' => 'À venir', 'published_at' => '2999-01-01 00:00:00']);
        $provider = $app->get(Content::class);
        self::assertSame('', $provider->entryWidget('entry_title', [], $future, false));
        self::assertStringContainsString('À venir', $provider->entryWidget('entry_title', [], $future, true));
        self::assertSame('', $provider->current(['source' => 'title'], $future));
        self::assertStringContainsString('À venir', $provider->current(['source' => 'title'], $future, true));
        $app->get(ContentTypeRegistry::class)->register(new ContentType(name: 'vault', labels: ['singular' => 'Privé'], public: false));
        $privateType = \Prime\Content\Entry::fromRow(['id' => 11, 'type' => 'vault', 'status' => 'published', 'title' => 'Type privé']);
        self::assertSame('', $provider->entryWidget('entry_title', [], $privateType, false));
        self::assertSame('', $provider->current(['source' => 'title'], $privateType));
    }

    public function testEntryExcerptFallsBackToBuilderTextWithoutRecursionAcrossSaves(): void
    {
        $app = $this->activated(null);
        $this->db($app)->pdo()->exec('UPDATE pc_entries SET excerpt = NULL WHERE id = 1');
        $token = $this->tokens($app)->issue($this->user($app, 1), 'entry excerpt', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'text', 'type' => 'text', 'settings' => ['text' => '<p>Texte du jardin</p>'], 'styles' => [], 'children' => []],
            ['id' => 'excerpt', 'type' => 'entry_excerpt', 'settings' => [], 'styles' => [], 'children' => []],
        ];
        for ($i = 0; $i < 3; $i++) {
            $response = $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token);
            self::assertSame(200, $response->status(), $response->body());
            $entry = $app->get(EntryRepository::class)->find(1);
            self::assertNotNull($entry);
            // Core calls the Builder's text extractor without an Entry, so the
            // excerpt widget cannot feed its own result into content_text.
            self::assertSame('Texte du jardin', $entry->contentText);
            $live = $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live');
            self::assertSame(200, $live->status());
            self::assertSame('<p class="pb-entry-excerpt__text">Texte du jardin</p>', json_decode($live->body(), true)['data']['widgets']['excerpt']);
        }
    }

    public function testShareButtonsPersistPublicLinksAndLoadOnlyTheirAssets(): void
    {
        $app = $this->activated('admin');
        $config = $this->editorConfig($app, 1);
        self::assertSame('Boutons de partage', $config['widgets']['share_buttons']['label']);
        self::assertSame('Afficher LinkedIn', $config['widgets']['share_buttons']['fields']['linkedin']['label']);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'outer', 'type' => 'container', 'settings' => [], 'styles' => [], 'children' => [
                ['id' => 'share1', 'type' => 'share_buttons', 'settings' => ['label' => 'Faire connaître', 'message' => 'Bonjour & merci', 'linkedin' => 'hide'], 'styles' => [], 'children' => []],
            ]],
            ['id' => 'share2', 'type' => 'share_buttons', 'settings' => [], 'styles' => [], 'children' => []],
        ];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        $url = $app->get(UrlGenerator::class)->absolute($app->get(UrlGenerator::class)->entryUrl($entry));
        self::assertStringContainsString('aria-label="Faire connaître"', $entry->contentHtml);
        self::assertStringContainsString('https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($url), $entry->contentHtml);
        self::assertStringContainsString('subject=Bonjour%20%26%20merci', $entry->contentHtml);
        self::assertSame(1, substr_count($entry->contentHtml, 'https://www.linkedin.com/sharing/share-offsite/'));
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/share_buttons.css'));
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/share_buttons.js'));
        $other = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($other);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/share_buttons.css', $assets->renderStyles());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/share_buttons.js', $assets->renderScripts());
    }

    public function testShareButtonsInThemePartsUsePublicCanonicalContextWithoutClientRewrite(): void
    {
        $app = $this->activated('admin');
        $hooks = $app->get(Hooks::class);
        $cache = $app->get(\Prime\Cache\PageCache::class);
        $cache->setEnabled(true);
        $request = Request::create('GET', '/autre-page');
        self::assertSame(true, $cache->isCacheableRequest($request));
        $token = $this->tokens($app)->issue($this->user($app, 1), 'theme share', ['*'])['token'];
        $layout = ['version' => 1, 'elements' => [[
            'id' => 'outer', 'type' => 'container', 'settings' => [], 'styles' => [], 'children' => [
                ['id' => 'share1', 'type' => 'share_buttons', 'settings' => ['label' => 'Partager le thème', 'message' => 'Bonjour & merci', 'linkedin' => 'hide'], 'styles' => [], 'children' => []],
            ],
        ]]];
        $created = $this->send($app, 'POST', '/api/prime-builder/v1/templates', ['name' => 'Partage du thème', 'kind' => 'section', 'data' => $layout], $token);
        self::assertSame(201, $created->status(), $created->body());
        $template = json_decode($created->body(), true)['data']['id'];
        $fields = ['_csrf_token' => $app->get(Csrf::class)->token(), 'header' => $template, 'footer' => $template, 'exclude' => ''];
        self::assertSame(302, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/theme', body: $fields))->status());
        self::assertSame(false, $cache->isCacheableRequest($request));

        // Archive/search contexts have no entry. Their paths are resolved by the
        // CMS, but query strings and fragments must not be shared.
        $page = new PageContext(type: PageContext::TYPE_SEARCH, path: '/recherche?secret=token#fragment', searchQuery: 'secret');
        $expected = rawurlencode($app->get(UrlGenerator::class)->absolute('/recherche'));
        $header = $hooks->apply('site.partial', null, 'header', $page, []);
        $footer = $hooks->apply('site.partial', null, 'footer', $page, []);
        self::assertIsString($header);
        self::assertIsString($footer);
        foreach ([$header, $footer] as $html) {
            self::assertStringContainsString('aria-label="Partager le thème"', $html);
            self::assertStringContainsString('facebook.com/sharer/sharer.php?u=' . $expected, $html);
            self::assertStringContainsString('subject=Bonjour%20%26%20merci', $html);
            self::assertStringNotContainsString('linkedin.com', $html);
            self::assertStringNotContainsString('secret', $html);
            self::assertStringNotContainsString('fragment', $html);
            self::assertStringNotContainsString('data-pb-share ', $html);
            self::assertStringNotContainsString('data-pb-live=', $html);
        }
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, $page);
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/share_buttons.css'));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/share_buttons.js', $assets->renderScripts());

        $service = $app->get(\Prime\Content\EntryService::class);
        $repository = $app->get(EntryRepository::class);
        $service->update(1, ['seo' => ['canonical' => 'https://canonical.example/original?private_token=secret#fragment']], $this->user($app, 1));
        $entry = $repository->find(1);
        self::assertNotNull($entry);
        $context = new PageContext(type: PageContext::TYPE_ENTRY, path: '/blog/article-publie', entry: $entry);
        $rendered = $hooks->apply('site.partial', null, 'header', $context, []);
        self::assertIsString($rendered);
        self::assertStringContainsString('u=https%3A%2F%2Fcanonical.example%2Foriginal', $rendered);
        self::assertStringNotContainsString('secret', $rendered);
        self::assertStringNotContainsString('fragment', $rendered);
        $service->update(1, ['seo' => ['canonical' => 'https://canonical.example/updated']], $this->user($app, 1));
        $updated = $repository->find(1);
        self::assertNotNull($updated);
        $changed = $hooks->apply('site.partial', null, 'footer', new PageContext(type: PageContext::TYPE_ENTRY, entry: $updated), []);
        self::assertIsString($changed);
        self::assertStringContainsString('u=https%3A%2F%2Fcanonical.example%2Fupdated', $changed);
        self::assertStringNotContainsString('original', $changed);
        $service->update(1, ['seo' => ['canonical' => 'https://user:password@canonical.example/hidden']], $this->user($app, 1));
        $unsafe = $repository->find(1);
        self::assertNotNull($unsafe);
        $unsafeOutput = $hooks->apply('site.partial', null, 'header', new PageContext(type: PageContext::TYPE_ENTRY, entry: $unsafe), []);
        self::assertIsString($unsafeOutput);
        self::assertStringNotContainsString('<a ', $unsafeOutput);
        self::assertStringNotContainsString('password', $unsafeOutput);

        // No sharing from previews, drafts, errors, plugin contexts, or children
        // beneath a private ancestor, even if a caller constructs such a context.
        $draft = $repository->find(2);
        self::assertNotNull($draft);
        $pdo = $this->db($app)->pdo();
        $parentId = $this->insertGridEntry($pdo, 'page', 'draft', 'Parent caché', 'parent-cache', 1);
        $childId = $this->insertGridEntry($pdo, 'page', 'published', 'Enfant', 'enfant', 1, $parentId);
        $child = $repository->find($childId);
        self::assertNotNull($child);
        $contexts = [
            $context->withPreview(),
            $context->withPreview(false, true),
            new PageContext(type: PageContext::TYPE_ENTRY, entry: $draft),
            new PageContext(type: PageContext::TYPE_ENTRY, entry: $child),
            PageContext::notFound('/introuvable'),
            PageContext::plugin('/plugin-prive', 'Privé', ''),
        ];
        foreach ($contexts as $invalid) {
            $html = $hooks->apply('site.partial', null, 'header', $invalid, []);
            self::assertIsString($html);
            self::assertStringNotContainsString('<a ', $html);
            self::assertStringNotContainsString('parent-cache', $html);
            self::assertStringContainsString('pb-share-message', $html);
        }

        // A saved-entry share still loads its own refresh script, but the theme
        // markup lacks the opt-in attribute and must not be rewritten by it.
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [[
            'id' => 'share1', 'type' => 'share_buttons', 'settings' => [], 'styles' => [], 'children' => [],
        ]];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $repository->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('data-pb-share data-pb-share-message=', $entry->contentHtml);
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/share_buttons.css'));
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/share_buttons.js'));
        $app->get(Settings::class)->set('plugin.prime-builder.theme_partials', []);
        self::assertSame(true, $cache->isCacheableRequest($request));
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, $page);
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/share_buttons.css', $assets->renderStyles());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/share_buttons.js', $assets->renderScripts());
    }

    public function testTableOfContentsPersistsItsTargetsAndOnlyLoadsItsStyles(): void
    {
        $app = $this->activated('admin');
        $config = $this->editorConfig($app, 1);
        self::assertSame('Sommaire automatique', $config['widgets']['table_of_contents']['label']);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'toc', 'type' => 'table_of_contents', 'settings' => ['label' => 'Plan de la page'], 'styles' => [], 'children' => []],
            ['id' => 'first', 'type' => 'heading', 'settings' => ['text' => 'Introduction', 'level' => 2], 'styles' => [], 'children' => []],
            ['id' => 'second', 'type' => 'heading', 'settings' => ['text' => 'Détails', 'level' => 3], 'styles' => [], 'children' => []],
        ];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('aria-label="Plan de la page"', $entry->contentHtml);
        self::assertStringContainsString('<a href="#pb-toc-first">Introduction</a><ol><li><a href="#pb-toc-second">Détails</a>', $entry->contentHtml);
        self::assertStringContainsString('id="pb-toc-first" tabindex="-1"', $entry->contentHtml);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/table_of_contents.css'));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/table_of_contents.js', $assets->renderScripts());
        $other = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($other);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/table_of_contents.css', $assets->renderStyles());
    }

    public function testSearchFormPersistsUsableHtmlAndRefreshesTheCoreSearchPath(): void
    {
        $app = $this->activated('admin');
        $config = $this->editorConfig($app, 1);
        self::assertSame('Formulaire de recherche', $config['widgets']['search_form']['label']);
        self::assertSame('Libellé du champ de recherche', $config['widgets']['search_form']['fields']['search_label']['label']);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'search1', 'type' => 'search_form', 'settings' => ['search_label' => 'Votre recherche', 'placeholder' => 'Sujet', 'button_text' => 'Trouver'], 'styles' => [], 'children' => []],
            ['id' => 'search2', 'type' => 'search_form', 'settings' => [], 'styles' => [], 'children' => []],
        ];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertSame(2, substr_count($entry->contentHtml, 'action="/recherche"'));
        self::assertStringContainsString('<label for="pb-search-search1">Votre recherche</label>', $entry->contentHtml);
        self::assertStringContainsString('id="pb-search-search2" name="q"', $entry->contentHtml);
        self::assertStringContainsString('>Rechercher sur le site</label>', $entry->contentHtml);
        self::assertStringContainsString('data-pb-live="/api/prime-builder/v1/documents/1/live"', $entry->contentHtml);

        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/search_form.css'));
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/content.js'));
        $other = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($other);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/search_form.css', $assets->renderStyles());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/content.js', $assets->renderScripts());

        $app->get(\Prime\Site\PermalinkSettings::class)->setOverride('permalinks.search_base', 'trouver-ici');
        $live = $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live');
        self::assertSame(200, $live->status(), $live->body());
        self::assertStringContainsString('no-store', (string) $live->header('Cache-Control'));
        $widgets = json_decode($live->body(), true)['data']['widgets'];
        self::assertStringContainsString('action="/trouver-ici"', $widgets['search1']);
        self::assertStringContainsString('action="/trouver-ici"', $widgets['search2']);
        $persisted = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($persisted);
        self::assertStringContainsString('action="/recherche"', $persisted->contentHtml);
    }

    public function testSearchFormsInThemePartsUseCurrentUrlAndKeepIndependentIdsAndAssets(): void
    {
        $app = $this->activated('admin');
        $token = $this->tokens($app)->issue($this->user($app, 1), 'theme search', ['*'])['token'];
        $layout = ['version' => 1, 'elements' => [[
            'id' => 'outer', 'type' => 'container', 'settings' => [], 'styles' => [], 'children' => [
                ['id' => 'search1', 'type' => 'search_form', 'settings' => ['search_label' => 'Chercher & trouver', 'placeholder' => 'Un sujet', 'button_text' => 'Trouver'], 'styles' => [], 'children' => []],
                ['id' => 'search2', 'type' => 'search_form', 'settings' => [], 'styles' => [], 'children' => []],
            ],
        ]]];
        $created = $this->send($app, 'POST', '/api/prime-builder/v1/templates', ['name' => 'Recherche du thème', 'kind' => 'section', 'data' => $layout], $token);
        self::assertSame(201, $created->status(), $created->body());
        $template = json_decode($created->body(), true)['data']['id'];
        $fields = ['_csrf_token' => $app->get(Csrf::class)->token(), 'header' => $template, 'footer' => $template, 'exclude' => ''];
        self::assertSame(302, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/theme', body: $fields))->status());
        $hooks = $app->get(Hooks::class);
        // A real search or a 404 has no entry but should still have usable forms.
        $page = new PageContext(type: PageContext::TYPE_SEARCH, path: '/recherche', searchQuery: 'public');
        $header = $hooks->apply('site.partial', null, 'header', $page, []);
        $footer = $hooks->apply('site.partial', null, 'footer', $page, []);
        self::assertIsString($header);
        self::assertIsString($footer);
        foreach ([$header, $footer] as $html) {
            self::assertSame(2, substr_count($html, 'action="/recherche"'));
            self::assertStringContainsString('role="search" aria-label="Chercher &amp; trouver"', $html);
            self::assertStringContainsString('placeholder="Un sujet"', $html);
            self::assertStringContainsString('name="q"', $html);
            self::assertStringNotContainsString('data-pb-live=', $html);
        }
        $missing = $hooks->apply('site.partial', null, 'footer', PageContext::notFound('/introuvable'), []);
        self::assertIsString($missing);
        self::assertStringContainsString('action="/recherche"', $missing);
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, $page);
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/search_form.css'));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/content.js', $assets->renderScripts());

        // Core settings changes purge cached pages, so the server-rendered form
        // follows a changed search base without disabling the page cache.
        $cache = $app->get(\Prime\Cache\PageCache::class);
        $cache->setEnabled(true);
        $request = Request::create('GET', '/autre-page');
        self::assertSame(true, $cache->isCacheableRequest($request));
        $cache->store($request, Response::html('ancien formulaire'));
        self::assertNotNull($cache->lookup($request));
        $settings = $app->get(Settings::class);
        $settings->set('permalinks.search_base', 'trouver-ici');
        self::assertSame(null, $cache->lookup($request));
        $updated = $hooks->apply('site.partial', null, 'header', $page, []);
        self::assertIsString($updated);
        self::assertSame(2, substr_count($updated, 'action="/trouver-ici"'));
        $cache->store($request, Response::html('formulaire actualisé'));
        self::assertNotNull($cache->lookup($request));
        self::assertSame(true, $settings->delete('permalinks.search_base'));
        self::assertSame(null, $cache->lookup($request));
        $reset = $hooks->apply('site.partial', null, 'header', $page, []);
        self::assertIsString($reset);
        self::assertSame(2, substr_count($reset, 'action="/recherche"'));

        // The original page and both theme slots may reuse widget IDs. Each form
        // must still have a distinct input ID and matching label.
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [[
            'id' => 'search1', 'type' => 'search_form', 'settings' => [], 'styles' => [], 'children' => [],
        ]];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        $markup = $header . $entry->contentHtml . $footer;
        preg_match_all('/id="(pb-search-[a-zA-Z0-9_-]+)"/', $markup, $ids);
        self::assertSame(5, count($ids[1]));
        self::assertSame(5, count(array_unique($ids[1])));
        foreach ($ids[1] as $id) {
            self::assertSame(1, substr_count($markup, '<label for="' . $id . '">'));
        }
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/search_form.css'));
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/content.js'));
        $settings->set('plugin.prime-builder.theme_partials', []);
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, $page);
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/search_form.css', $assets->renderStyles());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/content.js', $assets->renderScripts());
    }

    public function testDeclaredZoneLayoutsHaveIndependentDeduplicatedStylesAndRecoverableSnapshots(): void
    {
        $app = $this->activated('admin');
        $pdo = $this->db($app)->pdo();
        $pdo->exec('CREATE TABLE pc_media_zone_usage (zone_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $zones = $app->get(\Prime\Site\Zone\ZoneRepository::class);
        $first = ['version' => 1, 'elements' => [[
            'id' => 'shared', 'type' => 'heading', 'settings' => ['text' => 'Zone'],
            'styles' => ['desktop' => ['color' => '#178041']], 'children' => [],
        ]]];
        $second = $first;
        $second['elements'][0]['styles']['desktop']['color'] = '#124499';
        $zoneDocument = ['version' => 1, 'blocks' => [
            ['id' => 'first', 'type' => 'prime-builder/layout', 'data' => $first],
            ['id' => 'copy', 'type' => 'prime-builder/layout', 'data' => $first],
            ['id' => 'second', 'type' => 'prime-builder/layout', 'data' => $second],
        ]];
        $zones->save('sidebar', $zoneDocument);
        $unregistered = $first;
        $unregistered['elements'][0]['styles']['desktop']['color'] = '#998877';
        $zones->save('not_declared', ['version' => 1, 'blocks' => [['id' => 'other', 'type' => 'prime-builder/layout', 'data' => $unregistered]]]);
        $stored = $zones->document('sidebar');
        $first = $stored['blocks'][0]['data'];
        $second = $stored['blocks'][2]['data'];
        $firstCss = (new Compiler())->compile($first, legacy: false);
        $secondCss = (new Compiler())->compile($second, legacy: false);
        $firstOwner = Scope::forLayout($first);
        $secondOwner = Scope::forLayout($second);
        self::assertNotSame($firstOwner, $secondOwner);
        self::assertStringContainsString(':where([data-pb-owner="' . $firstOwner . '"])', $firstCss);
        self::assertStringNotContainsString(':not([data-pb-owner])', $firstCss);
        $rendered = $zones->renderLive('sidebar', $app->get(\Prime\Site\Zone\DynamicBlockRegistry::class));
        self::assertSame(2, substr_count($rendered, 'data-pb-owner="' . $firstOwner . '" data-pb-id="shared"'));
        self::assertSame(1, substr_count($rendered, 'data-pb-owner="' . $secondOwner . '" data-pb-id="shared"'));
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_BLOG));
        $firstFile = substr(hash('sha256', $firstCss), 0, 16) . '.css';
        $secondFile = substr(hash('sha256', $secondCss), 0, 16) . '.css';
        self::assertSame(1, substr_count($assets->renderStyles(), '/prime-builder/theme/' . $firstFile));
        self::assertSame(1, substr_count($assets->renderStyles(), '/prime-builder/theme/' . $secondFile));
        $otherCss = (new Compiler())->compile($unregistered, legacy: false);
        self::assertStringNotContainsString('/prime-builder/theme/' . substr(hash('sha256', $otherCss), 0, 16) . '.css', $assets->renderStyles());
        $response = $this->send($app, 'GET', '/prime-builder/theme/' . $firstFile);
        self::assertSame(200, $response->status());
        self::assertSame($firstCss, $response->body());
        self::assertStringContainsString('immutable', (string) $response->header('Cache-Control'));
        $path = $app->storagePath('prime-builder/styles/theme-' . $firstFile);
        self::assertFileExists($path);
        self::assertTrue(unlink($path));
        $regenerated = $this->send($app, 'GET', '/prime-builder/theme/' . $firstFile);
        self::assertSame(200, $regenerated->status());
        self::assertSame($firstCss, $regenerated->body());
        self::assertSame(404, $this->send($app, 'GET', '/prime-builder/theme/0000000000000000.css')->status());
        $zoneDocument['blocks'][0]['data']['elements'][0]['styles']['desktop']['color'] = '#338855';
        $zones->save('sidebar', $zoneDocument);
        self::assertSame($firstCss, $this->send($app, 'GET', '/prime-builder/theme/' . $firstFile)->body(), 'Advertised CSS remains immutable after a zone edit');
    }

    public function testThemeZoneUsesDeclaredCoreZonesAndRefreshesWithoutPersistingTheirContents(): void
    {
        $app = $this->activated('admin');
        $pdo = $this->db($app)->pdo();
        $pdo->exec('CREATE TABLE pc_media_zone_usage (zone_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $zones = $app->get(\Prime\Site\Zone\ZoneRepository::class);
        $zoneDocument = ['version' => 1, 'blocks' => [
            ['id' => 'greeting', 'type' => 'paragraph', 'data' => ['text' => 'Bonjour depuis la zone']],
            ['id' => 'search', 'type' => 'dynamic', 'data' => ['name' => 'search_form', 'attrs' => []]],
        ]];
        $zones->save('sidebar', $zoneDocument);
        $config = $this->editorConfig($app, 1);
        self::assertSame('enum', $config['widgets']['theme_zone']['fields']['zone']['type']);
        self::assertSame(true, in_array('sidebar', $config['widgets']['theme_zone']['fields']['zone']['values'], true));
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'zone', 'type' => 'theme_zone', 'settings' => ['zone' => 'sidebar'], 'styles' => [], 'children' => []],
            ['id' => 'undeclared', 'type' => 'theme_zone', 'settings' => ['zone' => 'not_declared'], 'styles' => [], 'children' => []],
        ];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('La zone du thème est disponible avec JavaScript', $entry->contentHtml);
        self::assertStringNotContainsString('Bonjour depuis la zone', $entry->contentHtml);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/content.js'));
        $live = $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live');
        self::assertSame(200, $live->status(), $live->body());
        self::assertStringContainsString('no-store', (string) $live->header('Cache-Control'));
        $widgets = json_decode($live->body(), true)['data']['widgets'];
        self::assertStringContainsString('Bonjour depuis la zone', $widgets['zone']);
        self::assertStringContainsString('role="search"', $widgets['zone']);
        self::assertSame('', $widgets['undeclared']);
        $zoneDocument['blocks'][0]['data']['text'] = 'Zone mise à jour';
        $zones->save('sidebar', $zoneDocument);
        $updated = $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live');
        self::assertSame(200, $updated->status());
        self::assertStringContainsString('Zone mise à jour', json_decode($updated->body(), true)['data']['widgets']['zone']);
        self::assertStringNotContainsString('Bonjour depuis la zone', $updated->body());
        $other = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($other);
        $assets = new ThemeAssetRegistry();
        $app->get(Hooks::class)->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/content.js', $assets->renderScripts());
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/2', ['content' => $document], $token)->status());
        $app->get(\Prime\Auth\Auth::class)->logout();
        self::assertSame(404, $this->send($app, 'GET', '/api/prime-builder/v1/documents/2/live')->status());
        self::assertSame(200, $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live')->status());
    }

    public function testMapAssetsAndCspAreScopedToThePageAndRespectFrameDenials(): void
    {
        $app = $this->activated(null);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'map', 'type' => 'map', 'settings' => ['latitude' => '48.79', 'longitude' => '2.45'], 'styles' => [], 'children' => []],
        ];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringNotContainsString('<iframe', $entry->contentHtml);
        $hooks = $app->get(Hooks::class);
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry));
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/map.css'));
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/map.js'));
        $csp = new \Prime\Themes\PublicCsp($hooks);
        self::assertStringContainsString('frame-src https://www.youtube-nocookie.com https://player.vimeo.com https://www.openstreetmap.org', $csp->headerValue());
        self::assertSame(['frame-src' => "'none'"], $hooks->apply('site.csp', ['frame-src' => "'none'"]));
        self::assertSame(['default-src' => "'self'", 'frame-src' => "'self' https://www.openstreetmap.org"], $hooks->apply('site.csp', ['default-src' => "'self'"]));
        $other = $app->get(EntryRepository::class)->find(2);
        self::assertNotNull($other);
        $assets = new ThemeAssetRegistry();
        $hooks->emit('site.assets', $assets, new PageContext(type: PageContext::TYPE_ENTRY, entry: $other));
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/map.css', $assets->renderStyles());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/map.js', $assets->renderScripts());
        self::assertStringNotContainsString('https://www.openstreetmap.org', $csp->headerValue());
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
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/posts.css', $styles);
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
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/posts.css'));
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

    public function testPostsGridFiltersPublicEntriesAndPaginatesIndependently(): void
    {
        $app = $this->activated(null);
        $pdo = $this->db($app)->pdo();
        $pdo->exec("INSERT INTO pc_terms (id, taxonomy, name, slug) VALUES (31, 'category', 'Un', 'un'), (32, 'category', 'Deux', 'deux')");
        $first = $this->insertGridEntry($pdo, 'post', 'published', 'A & une', 'a-une', 2);
        $second = $this->insertGridEntry($pdo, 'post', 'published', 'B <img src=x>', 'b-deux', 2);
        $third = $this->insertGridEntry($pdo, 'post', 'published', 'C trois', 'c-trois', 2);
        $otherAuthor = $this->insertGridEntry($pdo, 'post', 'published', 'Autre auteur', 'autre-auteur', 1);
        $otherTerm = $this->insertGridEntry($pdo, 'post', 'published', 'Autre terme', 'autre-terme', 2);
        $draft = $this->insertGridEntry($pdo, 'post', 'draft', 'Brouillon secret', 'brouillon-secret', 2);
        $future = $this->insertGridEntry($pdo, 'post', 'published', 'Futur secret', 'futur-secret', 2, published: '2099-01-01 00:00:00');
        $insertTerm = $pdo->prepare('INSERT INTO pc_entry_terms (entry_id, term_id) VALUES (?, ?)');
        foreach ([$first, $second, $third, $otherAuthor, $draft, $future] as $entryId) {
            $insertTerm->execute([$entryId, 31]);
        }
        $insertTerm->execute([$otherTerm, 32]);
        $pdo->exec("UPDATE pc_entries SET excerpt = '<em>Extrait</em> & texte' WHERE id = " . $first);
        $types = $app->get(ContentTypeRegistry::class);
        $types->register(new ContentType(name: 'book', labels: ['singular' => 'Livre'], public: true, rewriteBase: 'livres'));
        $types->register(new ContentType(name: 'vault', labels: ['singular' => 'Privé'], public: false));
        $this->insertGridEntry($pdo, 'book', 'published', 'Livre public', 'livre-public', 2);
        $this->insertGridEntry($pdo, 'vault', 'published', 'Type privé', 'type-prive', 2);
        $app->get(TaxonomyRegistry::class)->register(new Taxonomy(name: 'secret_tax', labels: ['singular' => 'Secret'], contentTypes: ['post'], public: false));

        $provider = $app->get(Content::class);
        $settings = [
            'content_type' => 'post', 'taxonomy' => 'category', 'term_ids' => '31', 'author_id' => 2,
            'order_by' => 'title', 'order_direction' => 'asc', 'limit' => 2, 'layout' => 'cards', 'columns' => 4, 'show_excerpt' => 'show',
        ];
        $html = $provider->posts($settings, true);
        self::assertStringContainsString('pb-post-list--cards pb-post-columns-4', $html);
        self::assertStringContainsString('A &amp; une', $html);
        self::assertStringContainsString('B &lt;img src=x&gt;', $html);
        self::assertStringContainsString('Extrait &amp; texte', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('C trois', $html);
        self::assertStringNotContainsString('Autre auteur', $html);
        self::assertStringNotContainsString('Autre terme', $html);
        self::assertStringNotContainsString('secret', $html);
        self::assertLessThan(strpos($html, 'B &lt;img'), strpos($html, 'A &amp; une'));
        self::assertStringContainsString('Autre terme', $provider->posts([...$settings, 'term_ids' => '31,32', 'limit' => 12], true));
        self::assertStringContainsString('C trois', $provider->posts([...$settings, 'order_direction' => 'desc', 'limit' => 1, 'layout' => 'list'], true));
        self::assertStringContainsString('href="/livres/livre-public"', $provider->posts(['content_type' => 'book'], true));
        self::assertStringContainsString('href="/livres"', $provider->posts(['content_type' => 'book'], false));
        self::assertSame('', $provider->posts(['content_type' => 'vault'], true));
        self::assertSame('', $provider->posts([...$settings, 'taxonomy' => 'secret_tax'], true));
        self::assertSame('', $provider->posts([...$settings, 'taxonomy' => 'tag', 'term_ids' => '31; DROP TABLE pc_entries'], true));

        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $paginated = [...$settings, 'pagination' => 'show'];
        $document['blocks'][0]['data']['elements'] = [
            ['id' => 'one', 'type' => 'posts', 'settings' => $paginated, 'styles' => [], 'children' => []],
            ['id' => 'two', 'type' => 'posts', 'settings' => $paginated, 'styles' => [], 'children' => []],
        ];
        $saved = $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token);
        self::assertSame(200, $saved->status(), $saved->body());
        $response = $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live?pb_page_one=2&pb_page_two=bogus');
        self::assertSame(200, $response->status(), $response->body());
        $widgets = json_decode($response->body(), true)['data']['widgets'];
        self::assertStringContainsString('C trois', $widgets['one']);
        self::assertStringContainsString('Page 2 sur 2', $widgets['one']);
        self::assertStringContainsString('aria-current="page"', $widgets['one']);
        self::assertStringContainsString('data-pb-post-page="1"', $widgets['one']);
        self::assertStringContainsString('A &amp; une', $widgets['two']);
        self::assertStringContainsString('Page 1 sur 2', $widgets['two']);
        self::assertStringNotContainsString('C trois', $widgets['two']);
        $invalid = $this->send($app, 'GET', '/api/prime-builder/v1/documents/1/live?pb_page_one%5B%5D=2&pb_page_two=1001');
        $widgets = json_decode($invalid->body(), true)['data']['widgets'];
        self::assertStringContainsString('Page 1 sur 2', $widgets['one']);
        self::assertStringContainsString('Page 1 sur 2', $widgets['two']);
    }

    public function testPostsGridCountsQueriesForOneAndTwelvePostsAndSharesIdenticalWidgets(): void
    {
        $app = $this->activated(null);
        $db = $this->db($app);
        for ($i = 1; $i <= 12; $i++) {
            $this->insertGridEntry($db->pdo(), 'post', 'published', sprintf('Article %02d', $i), sprintf('grid-post-%02d', $i), 2);
        }
        $makeProvider = fn (): Content => $this->gridProvider($app);
        $settings = ['content_type' => 'post', 'author_id' => 2, 'order_by' => 'title', 'order_direction' => 'asc', 'pagination' => 'show'];
        $db->enableQueryLog();
        $provider = $makeProvider();
        $db->clearQueryLog();
        $one = $provider->posts([...$settings, 'limit' => 1], true, 'first');
        $oneQueries = count($db->queryLog());
        self::assertSame(1, substr_count($one, '<article>'));
        self::assertSame(2, $oneQueries, 'One count and one bounded SELECT for one item');
        $provider = $makeProvider();
        $db->clearQueryLog();
        $twelve = $provider->posts([...$settings, 'limit' => 12], true, 'first');
        $twelveQueries = count($db->queryLog());
        self::assertSame(12, substr_count($twelve, '<article>'));
        self::assertSame($oneQueries, $twelveQueries, 'Twelve items must not add a query per result');
        $again = $provider->posts([...$settings, 'limit' => 12], true, 'other');
        self::assertSame($twelveQueries, count($db->queryLog()), 'Identical widgets share their query');
        self::assertSame(12, substr_count($again, '<article>'));
        $provider = $makeProvider();
        $db->clearQueryLog();
        $provider->posts(['author_id' => 2, 'limit' => 1], true, 'one');
        $provider->posts(['author_id' => 2, 'limit' => 12], true, 'two');
        self::assertSame(1, count($db->queryLog()), 'Non-paginated widgets share a bounded SELECT');
        $db->enableQueryLog(false);
    }

    public function testPostsGridBatchesHierarchicalUrlsAndRejectsUnreachableAncestors(): void
    {
        $app = $this->activated(null);
        $db = $this->db($app);
        $pdo = $db->pdo();
        for ($i = 1; $i <= 12; $i++) {
            $grand = $this->insertGridEntry($pdo, 'page', 'published', sprintf('Racine %02d', $i), sprintf('grand-%02d', $i), 1);
            $parent = $this->insertGridEntry($pdo, 'page', 'published', sprintf('Parent %02d', $i), sprintf('parent-%02d', $i), 1, $grand);
            $this->insertGridEntry($pdo, 'page', 'published', sprintf('Enfant %02d', $i), sprintf('enfant-%02d', $i), 2, $parent);
        }
        $private = $this->insertGridEntry($pdo, 'page', 'draft', 'Parent privé', 'parent-prive', 1);
        $this->insertGridEntry($pdo, 'page', 'published', 'Enfant inaccessible', 'enfant-inaccessible', 3, $private);
        $this->insertGridEntry($pdo, 'page', 'published', 'Orphelin', 'orphelin', 3, 999999);
        $cycle = $this->insertGridEntry($pdo, 'page', 'published', 'Cycle', 'cycle', 3);
        $pdo->exec('UPDATE pc_entries SET parent_id = ' . $cycle . ' WHERE id = ' . $cycle);
        $future = $this->insertGridEntry($pdo, 'page', 'published', 'Parent futur', 'parent-futur', 1, published: '2099-01-01 00:00:00');
        $this->insertGridEntry($pdo, 'page', 'published', 'Enfant futur', 'enfant-futur', 3, $future);
        $ancestor = 0;
        $segments = [];
        for ($i = 1; $i <= 12; $i++) {
            $slug = sprintf('long-%02d', $i);
            $segments[] = $slug;
            $ancestor = $this->insertGridEntry($pdo, 'page', 'published', $slug, $slug, 1, $ancestor);
            if ($i === 11) {
                $this->insertGridEntry($pdo, 'page', 'published', 'Limite accessible', 'limite', 4, $ancestor);
            }
        }
        $this->insertGridEntry($pdo, 'page', 'published', 'Trop profond', 'trop-profond', 3, $ancestor);
        $settings = ['content_type' => 'page', 'author_id' => 2, 'order_by' => 'title', 'order_direction' => 'asc', 'pagination' => 'show'];
        $db->enableQueryLog();
        $provider = $this->gridProvider($app);
        $db->clearQueryLog();
        $one = $provider->posts([...$settings, 'limit' => 1], true, 'one');
        $oneQueries = count($db->queryLog());
        self::assertSame(1, substr_count($one, '<article>'));
        self::assertSame(4, $oneQueries, 'Count, entries, and two batches of ancestors');
        self::assertStringContainsString('href="/grand-01/parent-01/enfant-01"', $one);
        $provider = $this->gridProvider($app);
        $db->clearQueryLog();
        $twelve = $provider->posts([...$settings, 'limit' => 12], true, 'one');
        self::assertSame(12, substr_count($twelve, '<article>'));
        self::assertSame($oneQueries, count($db->queryLog()), 'Hierarchy adds one query per level, not per page');
        self::assertStringContainsString('href="/grand-12/parent-12/enfant-12"', $twelve);
        $provider->posts([...$settings, 'limit' => 12], true, 'two');
        self::assertSame($oneQueries, count($db->queryLog()), 'Repeated widget also shares ancestor batches');
        $db->enableQueryLog(false);
        $context = $app->get(UrlResolver::class)->resolve(Request::create('GET', '/grand-12/parent-12/enfant-12'));
        self::assertSame(PageContext::TYPE_ENTRY, $context->type);
        self::assertSame('Enfant 12', $context->entry?->title);
        $unsafe = $provider->posts(['content_type' => 'page', 'author_id' => 3, 'limit' => 12], true);
        self::assertStringNotContainsString('Enfant inaccessible', $unsafe);
        self::assertStringNotContainsString('parent-prive', $unsafe);
        self::assertStringNotContainsString('Orphelin', $unsafe);
        self::assertStringNotContainsString('Cycle', $unsafe);
        self::assertStringNotContainsString('Enfant futur', $unsafe);
        self::assertStringNotContainsString('Trop profond', $unsafe);
        $boundary = $provider->posts(['content_type' => 'page', 'author_id' => 4], true);
        $path = '/' . implode('/', array_slice($segments, 0, 11)) . '/limite';
        self::assertStringContainsString('href="' . $path . '"', $boundary);
        self::assertSame('Limite accessible', $app->get(UrlResolver::class)->resolve(Request::create('GET', $path))->entry?->title);
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
            ['id' => 'map', 'type' => 'map', 'settings' => ['latitude' => '48.79', 'longitude' => '2.45'], 'styles' => [], 'children' => []],
            ['id' => 'countdown', 'type' => 'countdown', 'settings' => ['deadline' => '2028-02-29T13:05'], 'styles' => [], 'children' => []],
            ['id' => 'flip', 'type' => 'flip_box', 'settings' => ['front_title' => 'Recto', 'back_title' => 'Verso'], 'styles' => [], 'children' => []],
            ['id' => 'animated', 'type' => 'animated_heading', 'settings' => ['before' => 'Créer', 'highlight' => 'ensemble'], 'styles' => [], 'children' => []],
            ['id' => 'sample', 'type' => 'code', 'settings' => ['language' => 'html', 'source' => '<p>Bonjour</p>'], 'styles' => [], 'children' => []],
            ['id' => 'testimonials', 'type' => 'testimonial_carousel', 'settings' => ['items' => "Excellent | Camille\nTrès bien | Alex"], 'styles' => [], 'children' => []],
            ['id' => 'images', 'type' => 'image_carousel', 'settings' => ['images' => '/uploads/a.jpg|Image'], 'styles' => [], 'children' => []],
            ['id' => 'media', 'type' => 'media_carousel', 'settings' => ['items' => 'image | /uploads/a.jpg | Image'], 'styles' => [], 'children' => []],
            ['id' => 'slides', 'type' => 'slideshow', 'settings' => ['items' => '/uploads/a.jpg | Image | Présentation'], 'styles' => [], 'children' => []],
            ['id' => 'portfolio', 'type' => 'portfolio', 'settings' => ['items' => '/uploads/a.jpg | Image | Projet | Identité'], 'styles' => [], 'children' => []],
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
        self::assertStringContainsString('datetime="2028-02-29T13:05:00Z"', $header);
        self::assertStringContainsString('data-pb-flip-front><h2>Recto</h2>', $header);
        self::assertStringContainsString('pb-animated-highlight--underline">ensemble</span>', $header);
        self::assertStringContainsString('&lt;p&gt;', $header);
        self::assertStringContainsString('<blockquote><p>Excellent</p></blockquote>', $header);
        self::assertStringContainsString('data-pb-category="Identité"', $header);
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
        self::assertStringContainsString('/assets/plugins/prime-builder/js/map.js', $assets->renderScripts());
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/countdown.js'));
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/countdown.css'));
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/flip_box.js'));
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/flip_box.css'));
        foreach (['animated_heading', 'code', 'testimonial_carousel', 'image_carousel', 'media_carousel', 'slideshow', 'portfolio'] as $type) {
            self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/' . $type . '.css'));
        }
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/image_carousel.js'));
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/portfolio.js'));
        self::assertStringContainsString('https://www.openstreetmap.org', (new \Prime\Themes\PublicCsp($hooks))->headerValue());
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
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/countdown.css', $assets->renderStyles());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/countdown.js', $assets->renderScripts());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/flip_box.css', $assets->renderStyles());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/flip_box.js', $assets->renderScripts());
        foreach (['animated_heading', 'code', 'testimonial_carousel', 'image_carousel', 'media_carousel', 'slideshow', 'portfolio'] as $type) {
            self::assertStringNotContainsString('/assets/plugins/prime-builder/css/widgets/' . $type . '.css', $assets->renderStyles());
        }
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/image_carousel.js', $assets->renderScripts());
        self::assertStringNotContainsString('/assets/plugins/prime-builder/js/portfolio.js', $assets->renderScripts());
        self::assertStringNotContainsString('https://www.openstreetmap.org', (new \Prime\Themes\PublicCsp($hooks))->headerValue());
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
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/countdown.js'));
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/countdown.css'));
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/flip_box.js'));
        self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/flip_box.css'));
        foreach (['animated_heading', 'code', 'testimonial_carousel', 'image_carousel', 'media_carousel', 'slideshow', 'portfolio'] as $type) {
            self::assertSame(1, substr_count($assets->renderStyles(), '/assets/plugins/prime-builder/css/widgets/' . $type . '.css'));
        }
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/image_carousel.js'));
        self::assertSame(1, substr_count($assets->renderScripts(), '/assets/plugins/prime-builder/js/portfolio.js'));
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
        foreach (['theme_zone', 'current_content'] as $type) {
            $unsupported = ['version' => 1, 'elements' => [['id' => 'outer', 'type' => 'container', 'settings' => [], 'styles' => [], 'children' => [
                ['id' => 'dynamic', 'type' => $type, 'settings' => [], 'styles' => [], 'children' => []],
            ]]]];
            $template = $this->send($app, 'POST', '/api/prime-builder/v1/templates', ['name' => $type, 'kind' => 'section', 'data' => $unsupported], $admin);
            self::assertSame(201, $template->status(), $template->body());
            $fields['header'] = json_decode($template->body(), true)['data']['id'];
            self::assertSame(422, $app->get(Kernel::class)->handle(Request::create('POST', '/admin/prime-builder/theme', body: $fields))->status());
            self::assertSame($header, $hooks->apply('site.partial', null, 'header', $page, []));
        }
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

    public function testConvertedOriginalsKeepCoreMediaReferencesUntilRemoved(): void
    {
        $app = $this->activated('admin');
        $pdo = $this->db($app)->pdo();
        $pdo->exec('CREATE TABLE pc_media_usage (entry_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $pdo->exec('CREATE TABLE pc_media_zone_usage (zone_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $media = $app->get(\Prime\Media\MediaRepository::class);
        $first = $media->create([
            'disk_path' => 'original/first.jpg', 'filename' => 'first.jpg', 'original_name' => 'first.jpg',
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'kind' => 'image', 'width' => 800, 'height' => 600, 'alt' => 'Première image',
        ]);
        $second = $media->create([
            'disk_path' => 'original/second.jpg', 'filename' => 'second.jpg', 'original_name' => 'second.jpg',
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'kind' => 'image', 'width' => 800, 'height' => 600, 'alt' => 'Deuxième image',
        ]);
        $unrelated = $media->create([
            'disk_path' => 'original/unrelated.jpg', 'filename' => 'unrelated.jpg', 'original_name' => 'unrelated.jpg',
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'kind' => 'image', 'alt' => 'Autre image',
        ]);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'original media', ['*'])['token'];
        $content = ['version' => 1, 'blocks' => [
            ['id' => 'photo', 'type' => 'image', 'data' => ['mediaId' => $first->id, 'alt' => 'Première image']],
            ['id' => 'text', 'type' => 'paragraph', 'data' => ['text' => 'Entre les images']],
            ['id' => 'gallery', 'type' => 'gallery', 'data' => ['items' => [['mediaId' => $first->id], ['mediaId' => $second->id]]]],
        ]];
        $saved = $this->send($app, 'PATCH', '/api/v1/content/post/1', ['content' => $content], $token);
        self::assertSame(200, $saved->status(), $saved->body());
        $usage = $app->get(\Prime\Media\MediaUsageRepository::class);
        self::assertCount(1, $usage->findByMedia($first->id));
        self::assertCount(1, $usage->findByMedia($second->id));
        self::assertSame(409, $this->send($app, 'DELETE', '/api/v1/media/' . $first->id, token: $token)->status());
        self::assertSame(409, $this->send($app, 'DELETE', '/api/v1/media/' . $second->id, token: $token)->status());
        $before = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($before);
        $beforeHtml = $before->contentHtml;
        $config = $this->editorConfig($app, 1);
        self::assertSame(false, $config['conversionFailed']);
        $converted = $config['document'];
        $children = $converted['blocks'][0]['data']['elements'][0]['children'];
        self::assertSame(['original_content', 'prime_block', 'original_content'], array_column($children, 'type'));
        // Client-supplied index entries must not be trusted; the server rebuilds it.
        $converted['blocks'][0]['data']['items'] = [['mediaId' => $unrelated->id]];
        $saved = $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $converted, 'source_hash' => $config['sourceHash']], $token);
        self::assertSame(200, $saved->status(), $saved->body());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString('/uploads/original/first.jpg', $entry->contentHtml);
        self::assertStringContainsString('/uploads/original/second.jpg', $entry->contentHtml);
        self::assertStringContainsString('Entre les images', $entry->contentHtml);
        self::assertStringContainsString('pc-block--gallery', $beforeHtml);
        self::assertSame([['mediaId' => $first->id], ['mediaId' => $second->id]], $entry->decodedContent()['blocks'][0]['data']['items']);
        self::assertCount(1, $usage->findByMedia($first->id));
        self::assertCount(1, $usage->findByMedia($second->id));
        self::assertSame([], $usage->findByMedia($unrelated->id));
        self::assertSame(409, $this->send($app, 'DELETE', '/api/v1/media/' . $first->id, token: $token)->status());
        self::assertSame(409, $this->send($app, 'DELETE', '/api/v1/media/' . $second->id, token: $token)->status());

        // Native widget URLs and authenticated original references are merged, not replaced.
        $mixed = $entry->decodedContent();
        $mixed['blocks'][0]['data']['elements'][0]['children'][] = ['id' => 'native', 'type' => 'image', 'settings' => ['url' => $unrelated->url(), 'alt' => 'Autre image'], 'styles' => [], 'children' => []];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $mixed], $token)->status());
        $mixedEntry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($mixedEntry);
        self::assertSame([['mediaId' => $first->id], ['mediaId' => $second->id], ['mediaId' => $unrelated->id]], $mixedEntry->decodedContent()['blocks'][0]['data']['items']);
        self::assertCount(1, $usage->findByMedia($unrelated->id));

        // Removing the gallery still keeps the first image's usage, but frees the second.
        $layout = $entry->decodedContent();
        array_pop($layout['blocks'][0]['data']['elements'][0]['children']);
        self::assertSame(200, $this->send($app, 'PATCH', '/api/v1/content/post/1', ['content' => $layout], $token)->status());
        self::assertCount(1, $usage->findByMedia($first->id));
        self::assertSame([], $usage->findByMedia($second->id));
        self::assertSame([], $usage->findByMedia($unrelated->id));
        self::assertSame(409, $this->send($app, 'DELETE', '/api/v1/media/' . $first->id, token: $token)->status());
        self::assertSame(204, $this->send($app, 'DELETE', '/api/v1/media/' . $second->id, token: $token)->status());
        // Finally removing the image releases its reference as well.
        $layout['blocks'][0]['data']['elements'][0]['children'] = [];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $layout], $token)->status());
        self::assertSame([], $usage->findByMedia($first->id));
        self::assertSame(204, $this->send($app, 'DELETE', '/api/v1/media/' . $first->id, token: $token)->status());
    }

    public function testGroupedOriginalKeepsMediaReferencesAcrossRevisionsAndDeactivation(): void
    {
        $app = $this->activated('admin');
        $pdo = $this->db($app)->pdo();
        $pdo->exec('CREATE TABLE pc_media_usage (entry_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $pdo->exec('CREATE TABLE pc_media_zone_usage (zone_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $media = $app->get(\Prime\Media\MediaRepository::class);
        $first = $media->create([
            'disk_path' => 'group/first.jpg', 'filename' => 'first.jpg', 'original_name' => 'first.jpg',
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'kind' => 'image', 'alt' => 'Image existante',
        ]);
        $second = $media->create([
            'disk_path' => 'group/second.jpg', 'filename' => 'second.jpg', 'original_name' => 'second.jpg',
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'kind' => 'image', 'alt' => 'Image du bloc indisponible',
        ]);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'grouped media', ['*'])['token'];
        $content = ['version' => 1, 'blocks' => [
            ['id' => 'photo', 'type' => 'image', 'data' => ['mediaId' => $first->id, 'alt' => 'Image existante']],
        ]];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/v1/content/post/1', ['content' => $content], $token)->status());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        $old = $entry->decodedContent();
        // Simulate two renderers that were removed after core had indexed their saved data.
        $old['blocks'][] = ['id' => 'missing_one', 'type' => 'missing/one', 'data' => ['mediaId' => $second->id]];
        $old['blocks'][] = ['id' => 'missing_two', 'type' => 'missing/two', 'data' => ['media_id' => $first->id]];
        $oldHtml = $entry->contentHtml . '<div>Bloc historique <img src="/uploads/group/second.jpg" alt="Image historique"></div><p>Autre bloc</p>';
        $statement = $pdo->prepare('UPDATE pc_entries SET content = ?, content_html = ? WHERE id = 1');
        $statement->execute([(string) json_encode($old), $oldHtml]);
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        $usage = $app->get(\Prime\Media\MediaUsageRepository::class);
        $usage->syncFromEntry($entry);
        self::assertCount(1, $usage->findByMedia($first->id));
        self::assertCount(1, $usage->findByMedia($second->id));
        $config = $this->editorConfig($app, 1);
        self::assertSame(false, $config['conversionFailed']);
        $layout = $config['document'];
        $node = $layout['blocks'][0]['data']['elements'][0];
        self::assertSame('original_group', $node['type']);
        $originals = \PrimeBuilder\Widgets\OriginalContent::fromAppKey($app->config()->get('app.key'));
        self::assertNotNull($originals);
        self::assertSame([$first->id, $second->id], $originals->open($node['settings']['payload'])['media_ids'] ?? null);
        $saved = $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $layout, 'source_hash' => $config['sourceHash']], $token);
        self::assertSame(200, $saved->status(), $saved->body());
        $entry = $app->get(EntryRepository::class)->find(1);
        self::assertNotNull($entry);
        self::assertStringContainsString($oldHtml, $entry->contentHtml);
        self::assertSame([['mediaId' => $first->id], ['mediaId' => $second->id]], $entry->decodedContent()['blocks'][0]['data']['items']);
        self::assertCount(1, $usage->findByMedia($first->id));
        self::assertCount(1, $usage->findByMedia($second->id));
        self::assertSame(409, $this->send($app, 'DELETE', '/api/v1/media/' . $second->id, token: $token)->status());
        $revisions = $app->get(\Prime\Content\RevisionRepository::class)->forEntry(1);
        self::assertNotEmpty($revisions);
        $revisionId = $revisions[0]->id;

        $invalid = $layout;
        $invalid['blocks'][0]['data']['elements'][0]['settings']['payload'] .= 'x';
        self::assertSame(422, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $invalid], $token)->status());
        self::assertSame(422, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/2', ['content' => $layout], $token)->status());
        self::assertCount(1, $usage->findByMedia($second->id));
        $layout['blocks'][0]['data']['elements'] = [];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $layout], $token)->status());
        self::assertSame([], $usage->findByMedia($first->id));
        self::assertSame([], $usage->findByMedia($second->id));
        $restored = $app->get(\Prime\Content\EntryService::class)->restoreRevision(1, $revisionId, $this->user($app, 1));
        self::assertStringContainsString($oldHtml, $restored->contentHtml);
        self::assertCount(1, $usage->findByMedia($first->id));
        self::assertCount(1, $usage->findByMedia($second->id));
        $app->get(PluginManager::class)->deactivate('prime-builder');
        self::assertCount(1, $usage->findByMedia($second->id));
        self::assertSame(409, $this->send($app, 'DELETE', '/api/v1/media/' . $second->id, token: $token)->status());
    }

    public function testBuilderTracksCanonicalMediaUrlsAcrossWidgetsAndCoreSaves(): void
    {
        $app = $this->activated('admin');
        $pdo = $this->db($app)->pdo();
        $pdo->exec('CREATE TABLE pc_media_usage (entry_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $pdo->exec('CREATE TABLE pc_media_zone_usage (zone_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $media = $app->get(\Prime\Media\MediaRepository::class);
        $image = $media->create([
            'disk_path' => 'references/image.jpg', 'filename' => 'image.jpg', 'original_name' => 'image.jpg',
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'kind' => 'image', 'alt' => 'Image test',
        ]);
        $video = $media->create([
            'disk_path' => 'references/video.mp4', 'filename' => 'video.mp4', 'original_name' => 'video.mp4',
            'extension' => 'mp4', 'mime_type' => 'video/mp4', 'kind' => 'video',
        ]);
        $captions = $media->create([
            'disk_path' => 'references/captions.vtt', 'filename' => 'captions.vtt', 'original_name' => 'captions.vtt',
            'extension' => 'vtt', 'mime_type' => 'text/vtt', 'kind' => 'other',
        ]);
        $other = $media->create([
            'disk_path' => 'references/unrelated.jpg', 'filename' => 'unrelated.jpg', 'original_name' => 'unrelated.jpg',
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'kind' => 'image', 'alt' => 'Autre image',
        ]);
        $imageUrl = $image->url();
        $videoUrl = $video->url();
        $captionUrl = $captions->url();
        $cases = [
            'image' => [['url' => $imageUrl . '?version=2#image', 'alt' => 'Image'], [$image->id]],
            'image_box' => [['image_url' => $imageUrl, 'title' => 'Image'], [$image->id]],
            'card' => [['image_url' => $imageUrl, 'title' => 'Carte'], [$image->id]],
            'testimonial' => [['image_url' => $imageUrl, 'author' => 'Auteur'], [$image->id]],
            'review' => [['image_url' => $imageUrl, 'author' => 'Auteur'], [$image->id]],
            'call_to_action' => [['image_url' => $imageUrl, 'title' => 'Action'], [$image->id]],
            'gallery' => [['images' => $imageUrl . '|Image'], [$image->id]],
            'image_carousel' => [['images' => $imageUrl . '|Image'], [$image->id]],
            'slideshow' => [['items' => $imageUrl . '|Image|Titre'], [$image->id]],
            'portfolio' => [['items' => $imageUrl . '|Image|Projet|Art'], [$image->id]],
            'testimonial_carousel' => [['items' => 'Citation|Auteur|Fonction|' . $imageUrl], [$image->id]],
            'video' => [['url' => $videoUrl, 'video_title' => 'Vidéo', 'captions' => $captionUrl], [$video->id, $captions->id]],
            'audio' => [['url' => $videoUrl], [$video->id]],
            'media_carousel' => [['items' => 'image|' . $imageUrl . '|Image' . "\n" . 'video|' . $videoUrl . '|Vidéo|' . $captionUrl], [$image->id, $video->id, $captions->id]],
            'button' => [['url' => $videoUrl, 'text' => 'Télécharger'], [$video->id]],
            'text' => [['text' => '<p><a href="' . $imageUrl . '">Télécharger</a></p>'], [$image->id]],
        ];
        $token = $this->tokens($app)->issue($this->user($app, 1), 'widget media', ['*'])['token'];
        $usage = $app->get(\Prime\Media\MediaUsageRepository::class);
        foreach ($cases as $type => [$settings, $expected]) {
            $document = $this->document();
            $document['blocks'][0]['data']['elements'] = [['id' => 'widget', 'type' => $type, 'settings' => $settings, 'styles' => [], 'children' => []]];
            $document['blocks'][0]['data']['items'] = [['mediaId' => $other->id]];
            $response = $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token);
            self::assertSame(200, $response->status(), $type . ': ' . $response->body());
            $entry = $app->get(EntryRepository::class)->find(1);
            self::assertNotNull($entry);
            $items = $entry->decodedContent()['blocks'][0]['data']['items'] ?? [];
            $actual = array_column($items, 'mediaId');
            sort($actual);
            sort($expected);
            self::assertSame($expected, $actual, $type);
            self::assertSame([], $usage->findByMedia($other->id), $type . ': client index must be ignored');
        }
        // A matching relative URL protects the file; changing it through the core API releases it.
        $document['blocks'][0]['data']['elements'] = [['id' => 'widget', 'type' => 'image', 'settings' => ['url' => $imageUrl, 'alt' => 'Image'], 'styles' => [], 'children' => []]];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        self::assertCount(1, $usage->findByMedia($image->id));
        self::assertSame(409, $this->send($app, 'DELETE', '/api/v1/media/' . $image->id, token: $token)->status());
        $revisions = $app->get(\Prime\Content\RevisionRepository::class)->forEntry(1);
        self::assertNotEmpty($revisions);
        $imageRevision = $revisions[0]->id;
        $document['blocks'][0]['data']['elements'][0]['settings']['url'] = 'https://external.test' . $imageUrl;
        self::assertSame(200, $this->send($app, 'PATCH', '/api/v1/content/post/1', ['content' => $document], $token)->status());
        self::assertSame([], $usage->findByMedia($image->id));
        $app->get(\Prime\Content\EntryService::class)->restoreRevision(1, $imageRevision, $this->user($app, 1));
        self::assertCount(1, $usage->findByMedia($image->id));
        self::assertSame(409, $this->send($app, 'DELETE', '/api/v1/media/' . $image->id, token: $token)->status());
        self::assertSame(200, $this->send($app, 'PATCH', '/api/v1/content/post/1', ['content' => $document], $token)->status());
        self::assertSame([], $usage->findByMedia($image->id));
        self::assertSame(204, $this->send($app, 'DELETE', '/api/v1/media/' . $image->id, token: $token)->status());
        $document['blocks'][0]['data']['elements'][0] = ['id' => 'widget', 'type' => 'video', 'settings' => ['url' => $videoUrl, 'video_title' => 'Vidéo'], 'styles' => [], 'children' => []];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        $app->get(PluginManager::class)->deactivate('prime-builder');
        self::assertCount(1, $usage->findByMedia($video->id));
        self::assertSame(409, $this->send($app, 'DELETE', '/api/v1/media/' . $video->id, token: $token)->status());
    }

    public function testMediaReferenceResolutionIsBoundedAndRejectsForeignOrigins(): void
    {
        $app = $this->activated('admin');
        $db = $this->db($app);
        $db->pdo()->exec('CREATE TABLE pc_media_usage (entry_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $db->pdo()->exec('CREATE TABLE pc_media_zone_usage (zone_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $repository = $app->get(\Prime\Media\MediaRepository::class);
        $first = $repository->create([
            'disk_path' => 'folder/One photo.jpg', 'filename' => 'One photo.jpg', 'original_name' => 'One photo.jpg',
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'kind' => 'image',
        ]);
        $other = $repository->create([
            'disk_path' => 'folder/unused.jpg', 'filename' => 'unused.jpg', 'original_name' => 'unused.jpg',
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'kind' => 'image',
        ]);
        $bridge = $repository->create([
            'disk_path' => 'folder/bridge.jpg', 'filename' => 'bridge.jpg', 'original_name' => 'bridge.jpg',
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'kind' => 'image',
        ]);
        $references = new \PrimeBuilder\Document\MediaReferences($db, '/library', 'https://site.test/');
        $registry = new \PrimeBuilder\Widgets\Registry();
        $validator = new \PrimeBuilder\Document\Validator($registry, $app->get(\Prime\Security\HtmlSanitizer::class), $app->get(BlockRegistry::class), mediaReferences: $references);
        $node = static fn (string $id, string $url): array => ['id' => $id, 'type' => 'image', 'settings' => ['url' => $url], 'styles' => [], 'children' => []];
        $relative = '/library/folder/One%20photo.jpg?cache=2#image';
        $data = ['version' => 1, 'elements' => [
            ['id' => 'parent', 'type' => 'container', 'settings' => [], 'styles' => [], 'children' => [
                $node('relative', $relative),
                $node('absolute', 'https://SITE.TEST:443/library/folder/One%20photo.jpg'),
                $node('foreign', 'https://evil.test/library/folder/unused.jpg'),
                $node('wrong_port', 'https://site.test:444/library/folder/unused.jpg'),
                $node('wrong_scheme', 'http://site.test/library/folder/unused.jpg'),
                $node('userinfo', 'https://user@site.test/library/folder/unused.jpg'),
                $node('wrong_prefix', '/library-extra/folder/unused.jpg'),
                $node('traversal', '/library/folder/../folder/unused.jpg'),
                $node('wrong_case', '/library/folder/one%20photo.jpg'),
                ['id' => 'text', 'type' => 'text', 'settings' => ['text' => '<p><a href="/library/folder/One%20photo.jpg?a=1&amp;b=2">Lien</a></p>'], 'styles' => [], 'children' => []],
                ['id' => 'bridge', 'type' => 'prime_block', 'settings' => ['block_type' => 'paragraph', 'block_data' => '{"text":"<a href=\"/library/folder/bridge.jpg\">Fichier</a>"}'], 'styles' => [], 'children' => []],
                ['id' => 'code', 'type' => 'code', 'settings' => ['source' => '<a href="/library/folder/unused.jpg">code</a>'], 'styles' => [], 'children' => []],
            ]],
        ], 'items' => [['mediaId' => $other->id]]];
        $db->enableQueryLog();
        $db->clearQueryLog();
        $validated = $validator->validate($data);
        self::assertSame([], $validated['errors']);
        $ids = array_column($validated['data']['items'], 'mediaId');
        sort($ids);
        self::assertSame([$first->id, $bridge->id], $ids);
        self::assertCount(1, $db->queryLog(), 'A page of references is resolved in one query');
        $db->clearQueryLog();
        $wrongCase = $validator->validate(['version' => 1, 'elements' => [$node('case', '/library/folder/one%20photo.jpg')]]);
        self::assertSame([], $wrongCase['errors']);
        self::assertArrayNotHasKey('items', $wrongCase['data'], 'A case-insensitive SQL match must not count as the actual file');
        self::assertCount(1, $db->queryLog());
        $db->clearQueryLog();
        $external = $validator->validate(['version' => 1, 'elements' => [$node('external', 'https://evil.test/library/folder/unused.jpg')]]);
        self::assertSame([], $external['errors']);
        self::assertArrayNotHasKey('items', $external['data']);
        self::assertCount(0, $db->queryLog(), 'Unrelated origins never query the media table');

        $many = [];
        for ($i = 0; $i < 401; $i++) {
            $many[] = $node('image' . $i, '/library/missing-' . $i . '.jpg');
        }
        $many[] = $node('real', $relative);
        $db->clearQueryLog();
        $validated = $validator->validate(['version' => 1, 'elements' => $many]);
        self::assertSame([], $validated['errors']);
        self::assertSame([['mediaId' => $first->id]], $validated['data']['items']);
        self::assertCount(3, $db->queryLog(), '402 distinct paths use batches of 200, not one query per widget');
        $db->enableQueryLog(false);
    }

    public function testBuilderTracksCoreImageVariantsWithoutTrustingGuessedPaths(): void
    {
        $app = $this->activated('admin');
        $db = $this->db($app);
        $db->pdo()->exec('CREATE TABLE pc_media_usage (entry_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $db->pdo()->exec('CREATE TABLE pc_media_zone_usage (zone_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $media = $app->get(\Prime\Media\MediaRepository::class);
        $png = $media->create([
            'disk_path' => 'variantes/Lac azur.png', 'filename' => 'Lac azur.png', 'original_name' => 'Lac azur.png',
            'extension' => 'png', 'mime_type' => 'image/png', 'kind' => 'image',
            'variants' => [
                'large' => ['path' => 'variantes/Lac azur-800x600.png', 'webp' => 'variantes/Lac azur-800x600.webp'],
                '_original_webp' => ['path' => 'variantes/Lac azur.webp'],
            ],
        ]);
        // Core-generated WebP paths may overlap across original extensions.
        $jpg = $media->create([
            'disk_path' => 'variantes/Lac azur.jpg', 'filename' => 'Lac azur.jpg', 'original_name' => 'Lac azur.jpg',
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'kind' => 'image',
            'variants' => [
                'large' => ['path' => 'variantes/Lac azur-800x600.jpg', 'webp' => 'variantes/Lac azur-800x600.webp'],
                '_original_webp' => ['path' => 'variantes/Lac azur.webp'],
            ],
        ]);
        $unrelated = $media->create([
            'disk_path' => 'variantes/Lac azur.jpeg', 'filename' => 'Lac azur.jpeg', 'original_name' => 'Lac azur.jpeg',
            'extension' => 'jpeg', 'mime_type' => 'image/jpeg', 'kind' => 'image',
            'variants' => ['small' => ['path' => 'variantes/Lac azur-640x480.jpeg', 'webp' => 'variantes/Lac azur-640x480.webp']],
        ]);
        // A requested path may be both an original and another item's variant.
        $samePath = $media->create([
            'disk_path' => 'variantes/Lac azur-800x600.png', 'filename' => 'Lac azur-800x600.png', 'original_name' => 'Lac azur-800x600.png',
            'extension' => 'png', 'mime_type' => 'image/png', 'kind' => 'image',
        ]);
        $cases = [
            ['/uploads/variantes/Lac%20azur-800x600.png?version=1#image', [$png->id, $samePath->id]],
            ['/uploads/variantes/Lac%20azur-800x600.webp', [$png->id, $jpg->id]],
            ['/uploads/variantes/Lac%20azur.webp', [$png->id, $jpg->id]],
            ['/uploads/variantes/Lac%20azur-640x480.webp', [$unrelated->id]],
            ['/uploads/variantes/Lac%20azur-999x999.png', []],
            ['/uploads/variantes/lac%20azur-800x600.png', []],
            ['https://foreign.test/uploads/variantes/Lac%20azur-800x600.webp', []],
        ];
        foreach (['gif', 'webp', 'avif'] as $extension) {
            $base = 'variantes/paysage-' . $extension;
            $extra = $media->create([
                'disk_path' => $base . '.' . $extension, 'filename' => 'paysage-' . $extension . '.' . $extension,
                'original_name' => 'paysage-' . $extension . '.' . $extension, 'extension' => $extension,
                'mime_type' => 'image/' . $extension, 'kind' => 'image',
                'variants' => ['small' => ['path' => $base . '-20x30.' . $extension, 'webp' => $base . '-20x30.webp']],
            ]);
            $cases[] = ['/uploads/' . $base . '-20x30.' . $extension, [$extra->id]];
            $cases[] = ['/uploads/' . $base . '-20x30.webp', [$extra->id]];
        }
        $token = $this->tokens($app)->issue($this->user($app, 1), 'variant media', ['*'])['token'];
        $usage = $app->get(\Prime\Media\MediaUsageRepository::class);
        $document = $this->document();
        $node = static fn (string $url): array => ['id' => 'image', 'type' => 'image', 'settings' => ['url' => $url, 'alt' => 'Lac'], 'styles' => [], 'children' => []];
        foreach ($cases as [$url, $expected]) {
            $document['blocks'][0]['data']['elements'] = [$node($url)];
            // A client-supplied index must not manufacture an unrelated usage.
            $document['blocks'][0]['data']['items'] = [['mediaId' => $unrelated->id]];
            $response = $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token);
            self::assertSame(200, $response->status(), $response->body());
            $entry = $app->get(EntryRepository::class)->find(1);
            self::assertNotNull($entry);
            $actual = array_column($entry->decodedContent()['blocks'][0]['data']['items'] ?? [], 'mediaId');
            sort($actual);
            sort($expected);
            self::assertSame($expected, $actual, $url);
        }
        $document['blocks'][0]['data']['elements'] = [$node('/uploads/variantes/Lac%20azur-800x600.webp')];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/v1/content/post/1', ['content' => $document], $token)->status());
        self::assertCount(1, $usage->findByMedia($png->id));
        self::assertCount(1, $usage->findByMedia($jpg->id));
        self::assertSame([], $usage->findByMedia($unrelated->id));
        self::assertSame(409, $this->send($app, 'DELETE', '/api/v1/media/' . $png->id, token: $token)->status());
        self::assertSame(409, $this->send($app, 'DELETE', '/api/v1/media/' . $jpg->id, token: $token)->status());
        $document['blocks'][0]['data']['elements'] = [];
        self::assertSame(200, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        self::assertSame(204, $this->send($app, 'DELETE', '/api/v1/media/' . $png->id, token: $token)->status());
        self::assertSame(204, $this->send($app, 'DELETE', '/api/v1/media/' . $jpg->id, token: $token)->status());

        // Candidate lookups are batched rather than one query per image.
        $references = new \PrimeBuilder\Document\MediaReferences($db, '/uploads', 'http://localhost');
        $validator = new \PrimeBuilder\Document\Validator(new \PrimeBuilder\Widgets\Registry(), $app->get(\Prime\Security\HtmlSanitizer::class), $app->get(BlockRegistry::class), mediaReferences: $references);
        $nodes = [];
        for ($i = 0; $i < 201; $i++) {
            $element = $node('/uploads/missing-' . $i . '-20x30.jpg');
            $element['id'] = 'image' . $i;
            $nodes[] = $element;
        }
        $db->enableQueryLog();
        $db->clearQueryLog();
        $result = $validator->validate(['version' => 1, 'elements' => $nodes]);
        self::assertSame([], $result['errors']);
        self::assertArrayNotHasKey('items', $result['data']);
        self::assertCount(3, $db->queryLog(), '201 variant paths and 201 possible originals use batches of 200');
        $db->enableQueryLog(false);
    }

    public function testBuilderMediaInsideCoreZoneUsesTheCoreZoneUsageIndex(): void
    {
        $app = $this->activated('admin');
        $pdo = $this->db($app)->pdo();
        $pdo->exec('CREATE TABLE pc_media_usage (entry_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $pdo->exec('CREATE TABLE pc_media_zone_usage (zone_id INTEGER, media_id INTEGER, context VARCHAR(100))');
        $image = $app->get(\Prime\Media\MediaRepository::class)->create([
            'disk_path' => 'zone/image.jpg', 'filename' => 'image.jpg', 'original_name' => 'image.jpg',
            'extension' => 'jpg', 'mime_type' => 'image/jpeg', 'kind' => 'image', 'alt' => 'Zone',
        ]);
        $document = $this->document();
        $document['blocks'][0]['data']['elements'] = [['id' => 'zoneimage', 'type' => 'image', 'settings' => ['url' => $image->url(), 'alt' => 'Zone'], 'styles' => [], 'children' => []]];
        $zones = $app->get(\Prime\Site\Zone\ZoneRepository::class);
        $zones->save('sidebar', $document);
        $usage = $app->get(\Prime\Media\MediaUsageRepository::class)->findByMedia($image->id);
        self::assertCount(1, $usage);
        self::assertSame('zone', $usage[0]['type']);
        self::assertSame('zone_block', $usage[0]['context']);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'zone media', ['*'])['token'];
        self::assertSame(409, $this->send($app, 'DELETE', '/api/v1/media/' . $image->id, token: $token)->status());
        $document['blocks'][0]['data']['elements'] = [];
        $zones->save('sidebar', $document);
        self::assertSame([], $app->get(\Prime\Media\MediaUsageRepository::class)->findByMedia($image->id));
        self::assertSame(204, $this->send($app, 'DELETE', '/api/v1/media/' . $image->id, token: $token)->status());
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

    private function insertGridEntry(\PDO $pdo, string $type, string $status, string $title, string $slug, int $author, int $parent = 0, string $published = '2020-01-01 00:00:00'): int
    {
        $statement = $pdo->prepare('INSERT INTO pc_entries (type, status, title, slug, excerpt, content, content_html, content_text, author_id, parent_id, menu_order, published_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$type, $status, $title, $slug, '', '{"blocks":[]}', '', '', $author, $parent, 0, $published, '2020-01-01 00:00:00', '2020-01-01 00:00:00']);
        return (int) $pdo->lastInsertId();
    }

    private function gridProvider(Application $app): Content
    {
        $types = $app->get(ContentTypeRegistry::class);
        $entries = $app->get(EntryRepository::class);
        // A fresh URL generator per comparison prevents a warm ancestor cache from masking N+1.
        $urls = new UrlGenerator(new PermalinkSettings(), $types, $entries);
        return new Content($app->get(EntryQuery::class), $types, $urls, taxonomies: $app->get(TaxonomyRegistry::class), entries: $entries);
    }

    private function activated(?string $as): Application
    {
        $app = $this->createApp($as);
        if (getenv('PB_TEST_MYSQL') === '1') {
            self::assertSame('mysql', $this->db($app)->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        }
        $pdo = $this->db($app)->pdo();
        $pdo->exec('CREATE TABLE pc_plugins (slug VARCHAR(64) PRIMARY KEY, version VARCHAR(64), status VARCHAR(20), manifest_hash VARCHAR(64), last_error TEXT, errored_at DATETIME, installed_at DATETIME, activated_at DATETIME, created_at DATETIME, updated_at DATETIME)');
        $idColumn = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $pdo->exec('CREATE TABLE pc_zones (id ' . $idColumn . ', theme VARCHAR(80), slug VARCHAR(80), content LONGTEXT, content_html LONGTEXT, created_at DATETIME, updated_at DATETIME)');
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
