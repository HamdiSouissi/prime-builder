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
use Prime\Tests\Support\ApiTestFixture;
use PrimeBuilder\Styles\Compiler;

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

    public function testInvalidTreeAndPrivateCssAreNotExposed(): void
    {
        $app = $this->activated(null);
        $token = $this->tokens($app)->issue($this->user($app, 1), 'builder', ['*'])['token'];
        $document = $this->document();
        $document['blocks'][0]['data']['elements'][0]['settings']['url'] = 'javascript:alert(1)';
        self::assertSame(422, $this->send($app, 'PATCH', '/api/prime-builder/v1/documents/1', ['content' => $document], $token)->status());
        self::assertSame(404, $this->send($app, 'GET', '/prime-builder/css/2-0000000000000000.css')->status());
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
