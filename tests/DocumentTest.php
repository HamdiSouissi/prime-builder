<?php

declare(strict_types=1);

namespace PrimeBuilder\Tests;

use PHPUnit\Framework\TestCase;
use Prime\Hooks\Hooks;
use Prime\Security\HtmlSanitizer;
use PrimeBuilder\Document\Validator;
use PrimeBuilder\Styles\Compiler;
use PrimeBuilder\Styles\Kit;
use PrimeBuilder\Styles\Snapshots;
use PrimeBuilder\Widgets\OriginalContent;
use PrimeBuilder\Widgets\Registry;
use PrimeBuilder\Widgets\Renderer;

require_once dirname(__DIR__) . '/src/Widgets/OriginalContent.php';
require_once dirname(__DIR__) . '/src/Widgets/PrimeBlock.php';
require_once dirname(__DIR__) . '/src/Widgets/Registry.php';
require_once dirname(__DIR__) . '/src/Widgets/Renderer.php';
require_once dirname(__DIR__) . '/src/Document/Validator.php';
require_once dirname(__DIR__) . '/src/Styles/Values.php';
require_once dirname(__DIR__) . '/src/Styles/Compiler.php';
require_once dirname(__DIR__) . '/src/Styles/Kit.php';
require_once dirname(__DIR__) . '/src/Styles/Snapshots.php';

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

    public function testKitAndCustomBreakpointsAreSafe(): void
    {
        $css = Kit::compile(['primary' => '#123456;body{display:none}', 'text' => '#ABC', 'font_size' => 999]);
        self::assertStringContainsString('--pb-kit-primary:#c9441f', $css);
        self::assertStringContainsString('--pb-kit-text:#abc', $css);
        self::assertStringContainsString('--pb-kit-font-size:28px', $css);
        self::assertStringNotContainsString('body{', $css);
        $node = $this->element();
        $node['styles'] = ['tablet' => ['padding' => 10], 'mobile' => ['padding' => 5]];
        $compiled = (new Compiler(900, 500))->compile(['elements' => [$node]]);
        self::assertStringContainsString('@media(max-width:900px)', $compiled);
        self::assertStringContainsString('@media(max-width:500px)', $compiled);
    }

    public function testResponsiveContainerStylesAreAllowlistedAndCompiled(): void
    {
        $node = $this->element('container');
        $node['settings'] = ['layout' => 'grid', 'columns' => 3];
        $node['styles'] = [
            'desktop' => ['width' => '1200px', 'justify' => 'space-between'],
            'tablet' => ['grid_columns' => 2],
            'mobile' => ['layout_mode' => 'flex', 'flow' => 'column', 'wrap' => 'wrap', 'items_align' => 'stretch'],
        ];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        $css = (new Compiler())->compile($result['data']);
        self::assertStringContainsString('width:1200px;', $css);
        self::assertStringContainsString('justify-content:space-between;', $css);
        self::assertStringContainsString('grid-template-columns:repeat(2,minmax(0,1fr));', $css);
        self::assertStringContainsString('display:flex;flex-direction:column;flex-wrap:wrap;align-items:stretch;', $css);
        $node['styles']['mobile']['layout_mode'] = 'grid;}body{display:none';
        $node['styles']['desktop']['width'] = '2001px';
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        self::assertStringNotContainsString('body{', (new Compiler())->compile(['elements' => [$node]]));
    }

    public function testKitColorReferencesAndTypedLengthUnitsAreValidatedAndCompiled(): void
    {
        $node = $this->element();
        $node['styles'] = ['desktop' => ['color' => 'kit:primary', 'background' => 'kit:text', 'padding' => '1.25rem', 'margin' => '2.5em', 'width' => '85%', 'font_size' => 24]];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        $css = (new Compiler())->compile($result['data']);
        self::assertStringContainsString('color:var(--pb-kit-primary);', $css);
        self::assertStringContainsString('background-color:var(--pb-kit-text);', $css);
        self::assertStringContainsString('padding:1.25rem;', $css);
        self::assertStringContainsString('margin:2.5em;', $css);
        self::assertStringContainsString('width:85%;', $css);
        self::assertStringContainsString('font-size:24px;', $css);
        $node['styles']['desktop']['padding'] = '1px;display:none';
        $node['styles']['desktop']['color'] = 'var(--other)';
        $node['styles']['desktop']['width'] = '100.01%';
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        $badCss = (new Compiler())->compile(['elements' => [$node]]);
        self::assertStringNotContainsString('display:none', $badCss);
        self::assertStringNotContainsString('var(--other)', $badCss);
        self::assertStringNotContainsString('100.01%', $badCss);
    }

    public function testStyleSnapshotsAreAddressedByHashAndPrunedSafely(): void
    {
        $directory = sys_get_temp_dir() . '/pb_snapshots_' . bin2hex(random_bytes(6));
        $store = new Snapshots($directory);
        $css = '.pb-layout{color:#123456}';
        $name = '12-' . substr(hash('sha256', $css), 0, 16) . '.css';
        self::assertSame(true, $store->remember($name, $css));
        self::assertSame($css, $store->read($name));
        self::assertSame(false, $store->remember('../' . $name, $css));
        self::assertSame(false, $store->remember($name, 'different'));
        file_put_contents($directory . '/' . $name, 'tampered');
        self::assertSame(null, $store->read($name));
        file_put_contents($directory . '/' . $name, $css);
        touch($directory . '/' . $name, time() - 367 * 86400);
        self::assertSame(1, $store->prune());
        self::assertSame(null, $store->read($name));
        $store->clear();
        self::assertSame(false, is_dir($directory));
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
        $originals = OriginalContent::fromAppKey(str_repeat('k', 32));
        self::assertNotNull($originals);
        $entry = \Prime\Content\Entry::fromRow(['id' => 1]);
        $renderer = new Renderer($registry, new HtmlSanitizer(), entry: $entry, originals: $originals);
        $validator = new Validator($registry, new HtmlSanitizer(), originals: $originals, entryId: 1);
        foreach (array_keys($registry->all()) as $type) {
            $node = $this->element($type, $type);
            if ($type === 'original_content') {
                $node['settings'] = ['payload' => $originals->protect(1, 'legacy', '<p>Conservé</p>')];
            }
            $result = $validator->validate(['version' => 1, 'elements' => [$node]]);
            self::assertSame([], $result['errors'], $type);
            $html = $renderer->render($result['data']);
            self::assertStringContainsString('data-pb-id="' . $type . '"', $html);
            if ($type === 'original_content') {
                self::assertStringContainsString('<p>Conservé</p>', $html);
            }
        }
    }

    public function testNewWidgetsProduceSafeReadableFallbacks(): void
    {
        $list = $this->element('list', 'list');
        $list['settings'] = ['items' => "Premier\nSecond <script>alert(1)</script>", 'list_type' => 'ordered'];
        $accordion = $this->element('accordion', 'accordion');
        $accordion['settings'] = ['title' => 'Détails', 'text' => '<p>Contenu <strong>important</strong></p>'];
        $tabs = $this->element('tabs', 'tabs');
        $tabs['settings'] = ['title_1' => 'Premier', 'text_1' => '<p>Un</p>', 'title_2' => 'Second', 'text_2' => '<p>Deux</p>'];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$list, $accordion, $tabs]]);
        self::assertSame([], $result['errors']);
        $html = (new Renderer(new Registry(), new HtmlSanitizer()))->render($result['data']);
        self::assertStringContainsString('<ol><li>Premier</li>', $html);
        self::assertStringContainsString('<details><summary>Détails</summary>', $html);
        self::assertStringContainsString('<h3 data-pb-tab-heading>Premier</h3><p>Un</p>', $html);
        self::assertStringContainsString('<h3 data-pb-tab-heading>Second</h3><p>Deux</p>', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testMediaUrlsAndGalleryAreValidated(): void
    {
        $gallery = $this->element('gallery', 'gallery');
        $gallery['settings'] = ['images' => "/uploads/a.jpg|Vue <b>montagne</b>\n/uploads/b.jpg|"];
        $video = $this->element('video', 'video');
        $video['settings'] = ['url' => '/uploads/video.mp4', 'captions' => '/uploads/subtitles.vtt', 'caption_language' => 'en', 'transcript' => '<p>Transcription</p>'];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$gallery, $video]]);
        self::assertSame([], $result['errors']);
        $html = (new Renderer(new Registry(), new HtmlSanitizer()))->render($result['data']);
        self::assertStringContainsString('alt="Vue montagne"', $html);
        self::assertStringContainsString('<track kind="captions"', $html);
        self::assertStringContainsString('<p>Transcription</p>', $html);
        $gallery['settings']['images'] = 'javascript:alert(1)|Bad';
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$gallery]])['errors']);
        $gallery['settings']['images'] = implode("\n", array_fill(0, 21, '/uploads/a.jpg|a'));
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$gallery]])['errors']);
    }

    public function testPrimeBlockUsesCoreValidatorAndRejectsPrivilegedTypes(): void
    {
        $node = $this->element('prime_block', 'bridge');
        $node['settings'] = ['block_type' => 'paragraph', 'block_data' => '{"text":"Bonjour <strong>Prime</strong><script>alert(1)</script>"}'];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        $rendered = (new Renderer(new Registry(), new HtmlSanitizer()))->render($result['data']);
        self::assertStringContainsString('pc-block--paragraph', $rendered);
        self::assertStringContainsString('<strong>Prime</strong>', $rendered);
        self::assertStringNotContainsString('<script', $rendered);
        $node['settings'] = ['block_type' => 'html', 'block_data' => '{"html":"<script>alert(1)</script>"}'];
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        $node['settings'] = ['block_type' => 'paragraph', 'block_data' => '["not an object"]'];
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        $node['settings'] = ['block_type' => 'heading', 'block_data' => '{"text":"Titre","level":9}'];
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
    }

    public function testCrashingExtensionDoesNotBreakOtherElements(): void
    {
        $hooks = new Hooks();
        $hooks->filter('prime_builder.widgets', static function (array $widgets): array {
            $widgets['broken'] = ['fields' => [], 'render' => static function (): string {
                throw new \RuntimeException('broken');
            }];
            return $widgets;
        });
        $registry = new Registry($hooks);
        $html = (new Renderer($registry, new HtmlSanitizer()))->render(['elements' => [$this->element('broken'), $this->element('heading', 'other')]]);
        self::assertStringNotContainsString('data-pb-id="one"', $html);
        self::assertStringContainsString('data-pb-id="other"', $html);
    }
}
