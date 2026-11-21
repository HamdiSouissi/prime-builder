<?php

declare(strict_types=1);

namespace PrimeBuilder\Tests;

use PHPUnit\Framework\TestCase;
use Prime\Hooks\Hooks;
use Prime\Security\HtmlSanitizer;
use PrimeBuilder\Document\Validator;
use PrimeBuilder\Styles\Compiler;
use PrimeBuilder\Widgets\Registry;
use PrimeBuilder\Widgets\Renderer;

require_once dirname(__DIR__) . '/src/Widgets/Registry.php';
require_once dirname(__DIR__) . '/src/Widgets/Renderer.php';
require_once dirname(__DIR__) . '/src/Document/Validator.php';
require_once dirname(__DIR__) . '/src/Styles/Compiler.php';

final class DocumentTest extends TestCase
{
    /** @return array<string, mixed> */
    private function element(string $type = 'heading', string $id = 'one'): array
    {
        return ['id' => $id, 'type' => $type, 'settings' => [], 'styles' => [], 'children' => []];
    }

    private function validator(?Registry $registry = null): Validator
    {
        return new Validator($registry ?? new Registry(), new HtmlSanitizer());
    }

    public function testValidTreeAndCss(): void
    {
        $heading = $this->element();
        $heading['settings'] = ['text' => 'Bonjour', 'level' => 2];
        $heading['styles'] = ['desktop' => ['color' => '#c9441f', 'padding' => 12], 'mobile' => ['font_size' => 20]];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$heading]]);
        self::assertSame([], $result['errors']);
        $html = (new Renderer(new Registry(), new HtmlSanitizer()))->render($result['data']);
        self::assertStringContainsString('<h2>Bonjour</h2>', $html);
        $css = (new Compiler())->compile($result['data']);
        self::assertStringContainsString('color:#c9441f', $css);
        self::assertStringContainsString('@media(max-width:767px)', $css);
    }

    public function testMaliciousUrlsAndCssAreRejected(): void
    {
        $button = $this->element('button');
        $button['settings'] = ['text' => 'Click', 'url' => 'javascript:alert(1)'];
        $button['styles'] = ['desktop' => ['color' => 'red;}</style><script>alert(1)</script>']];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$button]]);
        self::assertNotEmpty($result['errors']);
        self::assertStringNotContainsString('javascript:', (string) json_encode($result['data']));
        self::assertStringNotContainsString('<script>', (new Compiler())->compile($result['data']));
    }

    public function testRichTextIsSanitized(): void
    {
        $node = $this->element('text');
        $node['settings'] = ['text' => '<p>Bon <strong>texte</strong><script>alert(1)</script><img src=x onerror=alert(1)></p>'];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        $html = (new Renderer(new Registry(), new HtmlSanitizer()))->render($result['data']);
        self::assertStringContainsString('<strong>texte</strong>', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('onerror', $html);
    }

    public function testUnknownWidgetDuplicateIdAndInvalidChildrenAreRejected(): void
    {
        $a = $this->element();
        $a['children'] = [$this->element('text', 'two')];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$a, $this->element('unknown', 'three'), $this->element('text')]]);
        self::assertGreaterThanOrEqual(3, count($result['errors']));
    }

    public function testOversizedDocumentIsRejected(): void
    {
        $node = $this->element();
        $node['settings'] = ['text' => str_repeat('x', Validator::MAX_BYTES + 1)];
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
    }

    public function testAllBuiltInWidgetsRenderWithoutThrowing(): void
    {
        $registry = new Registry();
        $renderer = new Renderer($registry, new HtmlSanitizer());
        foreach (array_keys($registry->all()) as $type) {
            $node = $this->element($type, $type);
            $result = $this->validator($registry)->validate(['version' => 1, 'elements' => [$node]]);
            self::assertSame([], $result['errors'], $type);
            self::assertStringContainsString('data-pb-id="' . $type . '"', $renderer->render($result['data']));
        }
    }

    public function testCrashingExtensionDoesNotBreakOtherElements(): void
    {
        $hooks = new Hooks();
        $hooks->filter('prime_builder.widgets', static function (array $widgets): array {
            $widgets['broken'] = ['fields' => [], 'render' => static function (): string { throw new \RuntimeException('broken'); }];
            return $widgets;
        });
        $registry = new Registry($hooks);
        $html = (new Renderer($registry, new HtmlSanitizer()))->render(['elements' => [$this->element('broken'), $this->element('heading', 'other')]]);
        self::assertStringNotContainsString('data-pb-id="one"', $html);
        self::assertStringContainsString('data-pb-id="other"', $html);
    }
}
