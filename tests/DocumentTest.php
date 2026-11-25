<?php

declare(strict_types=1);

namespace PrimeBuilder\Tests;

use PHPUnit\Framework\TestCase;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\EntryQuery;
use Prime\Content\EntryRepository;
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use Prime\Security\HtmlSanitizer;
use Prime\Site\PermalinkSettings;
use Prime\Site\UrlGenerator;
use PrimeBuilder\Document\HtmlContent;
use PrimeBuilder\Document\Layout;
use PrimeBuilder\Document\Validator;
use PrimeBuilder\Styles\Compiler;
use PrimeBuilder\Styles\Kit;
use PrimeBuilder\Styles\Scope;
use PrimeBuilder\Styles\Snapshots;
use PrimeBuilder\Widgets\Archive;
use PrimeBuilder\Widgets\Assets;
use PrimeBuilder\Widgets\Breadcrumbs;
use PrimeBuilder\Widgets\Content;
use PrimeBuilder\Widgets\Countdown;
use PrimeBuilder\Widgets\CurrentEntry;
use PrimeBuilder\Widgets\OriginalContent;
use PrimeBuilder\Widgets\Registry;
use PrimeBuilder\Widgets\Renderer;
use PrimeBuilder\Widgets\ShareButtons;
use PrimeBuilder\Widgets\SiteIdentity;

require_once dirname(__DIR__) . '/src/Widgets/Archive.php';
require_once dirname(__DIR__) . '/src/Widgets/Assets.php';
require_once dirname(__DIR__) . '/src/Widgets/Breadcrumbs.php';
require_once dirname(__DIR__) . '/src/Widgets/Content.php';
require_once dirname(__DIR__) . '/src/Widgets/ContentFragment.php';
require_once dirname(__DIR__) . '/src/Widgets/CodeHighlighter.php';
require_once dirname(__DIR__) . '/src/Widgets/Countdown.php';
require_once dirname(__DIR__) . '/src/Widgets/CurrentEntry.php';
require_once dirname(__DIR__) . '/src/Widgets/Map.php';
require_once dirname(__DIR__) . '/src/Widgets/MediaCarousel.php';
require_once dirname(__DIR__) . '/src/Widgets/Navigation.php';
require_once dirname(__DIR__) . '/src/Widgets/OriginalContent.php';
require_once dirname(__DIR__) . '/src/Widgets/PrimeBlock.php';
require_once dirname(__DIR__) . '/src/Widgets/Registry.php';
require_once dirname(__DIR__) . '/src/Widgets/Renderer.php';
require_once dirname(__DIR__) . '/src/Widgets/ShareButtons.php';
require_once dirname(__DIR__) . '/src/Widgets/SiteIdentity.php';
require_once dirname(__DIR__) . '/src/Widgets/TableOfContents.php';
require_once dirname(__DIR__) . '/src/Widgets/Portfolio.php';
require_once dirname(__DIR__) . '/src/Widgets/Slideshow.php';
require_once dirname(__DIR__) . '/src/Widgets/TermIds.php';
require_once dirname(__DIR__) . '/src/Widgets/Testimonials.php';
require_once dirname(__DIR__) . '/src/Document/HtmlContent.php';
require_once dirname(__DIR__) . '/src/Document/Validator.php';
require_once dirname(__DIR__) . '/src/Document/Layout.php';
require_once dirname(__DIR__) . '/src/Document/Converter.php';
require_once dirname(__DIR__) . '/src/Styles/Values.php';
require_once dirname(__DIR__) . '/src/Styles/Scope.php';
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

    /** @param array<string, mixed> $layout */
    private function legacyGuard(array $layout): string
    {
        return ':where([data-pb-owner="' . Scope::forLayout($layout) . '"],:not([data-pb-owner])):not(:where(.pb-theme_zone .pb-element:not([data-pb-owner])))';
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

    public function testDocumentStylesExcludeBuilderElementsInsertedInsideThemeZones(): void
    {
        $heading = $this->element('heading', 'shared');
        $heading['styles'] = [
            'desktop' => ['color' => '#cc1122', 'padding_left' => 40],
            'tablet' => ['visibility' => 'hide'],
        ];
        $zone = $this->element('theme_zone', 'zone');
        $zone['settings'] = ['zone' => 'sidebar'];
        $zone['styles'] = ['desktop' => ['background' => '#eeeeee']];
        $validated = $this->validator()->validate(['version' => 1, 'elements' => [$heading, $zone]]);
        self::assertSame([], $validated['errors']);
        $layout = $validated['data'];
        $owner = Scope::forLayout($layout);
        self::assertMatchesRegularExpression('/^[a-f0-9]{24}$/D', $owner);
        $roundTrip = json_decode((string) json_encode($layout), true);
        self::assertIsArray($roundTrip);
        self::assertSame($owner, Scope::forLayout($roundTrip));
        $changedText = $layout;
        $changedText['elements'][0]['settings']['text'] = 'Autre texte';
        self::assertSame($owner, Scope::forLayout($changedText));
        $differentStyle = $layout;
        $differentStyle['elements'][0]['styles']['desktop']['color'] = '#33aa55';
        self::assertNotSame($owner, Scope::forLayout($differentStyle));
        $html = (new Renderer(new Registry(), new HtmlSanitizer()))->render($layout);
        self::assertStringContainsString('data-pb-owner="' . $owner . '" data-pb-id="shared"', $html);
        self::assertStringContainsString('data-pb-owner="' . $owner . '" data-pb-id="zone"', $html);
        self::assertStringNotContainsString('data-pb-document=', $html);
        $entry = \Prime\Content\Entry::fromRow(['id' => 42]);
        $entryHtml = (new Renderer(new Registry(), new HtmlSanitizer(), entry: $entry))->render($layout);
        self::assertStringContainsString('<div class="pb-layout" data-pb-document="42"', $entryHtml);
        $css = (new Compiler(1000, 600))->compile($layout);
        $guard = ':where([data-pb-owner="' . $owner . '"],:not([data-pb-owner])):not(:where(.pb-theme_zone .pb-element:not([data-pb-owner])))';
        $selector = '.pb-element[data-pb-id="shared"]' . $guard;
        self::assertStringContainsString($selector . '{color:#cc1122;padding-left:40px;}', $css);
        self::assertStringContainsString($selector . ' :where(h1,h2,h3,h4,h5,h6,p,a,span,li,blockquote,cite,summary):not(:where(' . $selector . ' .pb-layout *)){color:inherit;}', $css);
        self::assertStringContainsString($selector . '{display:none!important}', $css);
        self::assertStringContainsString('.pb-element[data-pb-id="zone"]' . $guard . '{background-color:#eeeeee;}', $css);
        $strict = (new Compiler(1000, 600))->compile($layout, legacy: false);
        self::assertStringContainsString('.pb-element[data-pb-id="shared"]:where([data-pb-owner="' . $owner . '"]){color:#cc1122;padding-left:40px;}', $strict);
        self::assertStringNotContainsString(':not([data-pb-owner])', $strict);
    }

    public function testNavigatorLabelsAndHiddenElementsAreValidatedAndRendered(): void
    {
        $container = $this->element('container', 'section');
        $container['label'] = '<b>Ma section</b>';
        $container['hidden'] = true;
        $container['children'] = [$this->element('heading', 'child')];
        $container['children'][0]['settings'] = ['text' => 'Contenu masqué'];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$container]]);
        self::assertSame([], $result['errors']);
        self::assertSame('Ma section', $result['data']['elements'][0]['label']);
        self::assertSame(true, $result['data']['elements'][0]['hidden']);
        $html = (new Renderer(new Registry(), new HtmlSanitizer()))->render($result['data']);
        self::assertStringContainsString('data-pb-id="section" hidden>', $html);
        self::assertStringContainsString('<h2>Contenu masqué</h2>', $html);
        self::assertStringNotContainsString('Ma section', $html);
        $container['hidden'] = 'true';
        $container['label'] = str_repeat('a', 321);
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$container]])['errors']);
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

    public function testResponsiveVisibilityInheritsAndDoesNotOverrideContainerDisplay(): void
    {
        $container = $this->element('container', 'section');
        $container['settings'] = ['layout' => 'flex', 'direction' => 'row'];
        $container['styles'] = [
            'desktop' => ['visibility' => 'hide'],
            'tablet' => ['layout_mode' => 'grid'],
            'mobile' => ['visibility' => 'show'],
        ];
        $button = $this->element('button', 'action');
        $button['settings'] = ['text' => 'Continuer', 'url' => '/'];
        $button['styles'] = ['tablet' => ['visibility' => 'hide'], 'mobile' => ['visibility' => 'show']];
        $heading = $this->element('heading', 'small');
        $heading['settings'] = ['text' => 'Petit écran'];
        $heading['styles'] = ['mobile' => ['visibility' => 'hide']];
        $container['children'] = [$button];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$container, $heading]]);
        self::assertSame([], $result['errors']);
        $html = (new Renderer(new Registry(), new HtmlSanitizer()))->render($result['data']);
        self::assertStringContainsString('Continuer', $html, 'Visibility is presentation, not access control');
        self::assertStringNotContainsString('data-pb-id="section" hidden', $html);
        $css = (new Compiler(1000, 600))->compile($result['data']);
        $guard = $this->legacyGuard($result['data']);
        self::assertStringContainsString('.pb-element[data-pb-id="section"]' . $guard . '{display:flex;flex-direction:row;}', $css);
        self::assertStringContainsString('.pb-element[data-pb-id="section"]' . $guard . '{display:grid;}', $css);
        self::assertStringContainsString('@media not all and (max-width:1000px){.pb-element[data-pb-id="section"]' . $guard . '{display:none!important}}', $css);
        self::assertStringContainsString('@media(max-width:1000px){@media not all and (max-width:600px){.pb-element[data-pb-id="section"]' . $guard . '{display:none!important}.pb-element[data-pb-id="action"]' . $guard . '{display:none!important}}}', $css);
        self::assertStringContainsString('@media(max-width:600px){.pb-element[data-pb-id="small"]' . $guard . '{display:none!important}}', $css);
        $container['styles']['desktop']['visibility'] = 'hide;}body{display:none';
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$container]])['errors']);
        self::assertStringNotContainsString('body{', (new Compiler())->compile(['elements' => [$container]]));
        $container['styles']['desktop']['visibility'] = true;
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$container]])['errors']);
    }

    public function testResponsivePositionOffsetsAndStackingAreBoundedAndCompiled(): void
    {
        $node = $this->element('button', 'positioned');
        $node['settings'] = ['text' => 'Lien', 'url' => '/'];
        $node['styles'] = [
            'desktop' => ['position' => 'relative', 'offset_top' => -12, 'offset_right' => '1.25rem', 'z_index' => -5],
            'tablet' => ['position' => 'sticky', 'offset_top' => 0, 'offset_left' => '-25%', 'z_index' => 'auto'],
            'mobile' => ['position' => 'static', 'offset_top' => 'auto', 'offset_bottom' => '-0.5em', 'z_index' => 1000],
        ];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        self::assertSame($node['styles'], $result['data']['elements'][0]['styles']);
        $css = (new Compiler(1000, 600))->compile($result['data']);
        $guard = $this->legacyGuard($result['data']);
        self::assertStringContainsString('.pb-element[data-pb-id="positioned"]' . $guard . '{position:relative;top:-12px;right:1.25rem;z-index:-5;}', $css);
        self::assertStringContainsString('@media(max-width:1000px){.pb-element[data-pb-id="positioned"]' . $guard . '{position:sticky;top:0px;left:-25%;z-index:auto;}}', $css);
        self::assertStringContainsString('@media(max-width:600px){.pb-element[data-pb-id="positioned"]' . $guard . '{position:static;top:auto;bottom:-0.5em;z-index:1000;}}', $css);
        foreach (['static', 'relative', 'absolute', 'fixed', 'sticky'] as $position) {
            $node['styles'] = ['desktop' => ['position' => $position, 'offset_left' => -2000, 'offset_right' => '2000vh', 'z_index' => -1000]];
            $valid = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
            self::assertSame([], $valid['errors']);
            self::assertStringContainsString('position:' . $position . ';left:-2000px;right:2000vh;z-index:-1000;', (new Compiler())->compile($valid['data']));
        }
        // The compiler independently rejects unvalidated input as well as the API validator.
        foreach ([
            ['position', 'absolute;}body{display:none'], ['position', true],
            ['offset_top', '0'], ['offset_right', 'calc(1px + 2px)'], ['offset_bottom', '-2000.01px'],
            ['offset_left', '1px;display:none'], ['offset_top', -2001], ['offset_bottom', 1.5],
            ['z_index', '10'], ['z_index', 'auto;display:none'], ['z_index', -1001], ['z_index', 1001], ['z_index', 0.5],
            // Existing padding and margin remain nonnegative.
            ['padding', -1], ['margin', '-1px'],
        ] as [$key, $value]) {
            $node['styles'] = ['desktop' => [$key => $value]];
            self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors'], (string) $key);
            self::assertSame('', (new Compiler())->compile(['elements' => [$node]]), (string) $key);
        }
    }

    public function testResponsiveSpacingSidesAreBoundedAndOverrideShorthandDeterministically(): void
    {
        $node = $this->element('button', 'spaced');
        $node['styles'] = [
            // Side values precede shorthands in the JSON on purpose: CSS output
            // must give a side value precedence within the same breakpoint.
            'desktop' => ['padding_top' => 12, 'margin_left' => 'auto', 'padding' => 20, 'margin' => 8, 'margin_right' => -4, 'padding_bottom' => '1.5rem'],
            'tablet' => ['padding' => 0, 'margin' => 4, 'margin_right' => 0],
            'mobile' => ['padding_left' => '2rem', 'margin_bottom' => '-1.25em'],
        ];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        self::assertSame($node['styles'], $result['data']['elements'][0]['styles']);
        $css = (new Compiler(1000, 600))->compile($result['data']);
        $guard = $this->legacyGuard($result['data']);
        self::assertStringContainsString('.pb-element[data-pb-id="spaced"]' . $guard . '{padding:20px;margin:8px;padding-top:12px;padding-bottom:1.5rem;margin-right:-4px;margin-left:auto;}', $css);
        self::assertStringContainsString('@media(max-width:1000px){.pb-element[data-pb-id="spaced"]' . $guard . '{padding:0px;margin:4px;margin-right:0px;}}', $css);
        self::assertStringContainsString('@media(max-width:600px){.pb-element[data-pb-id="spaced"]' . $guard . '{padding-left:2rem;margin-bottom:-1.25em;}}', $css);
        foreach ([
            ['padding_top', 0, '0px'], ['padding_right', '2000vh', '2000vh'], ['padding_bottom', '0.01rem', '0.01rem'], ['padding_left', 2000, '2000px'],
            ['margin_top', -2000, '-2000px'], ['margin_right', '-2000vw', '-2000vw'], ['margin_bottom', 'auto', 'auto'], ['margin_left', '0px', '0px'],
        ] as [$key, $value, $expected]) {
            $node['styles'] = ['desktop' => [$key => $value]];
            self::assertSame([], $this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors'], (string) $key);
            self::assertStringContainsString(str_replace('_', '-', (string) $key) . ':' . $expected . ';', (new Compiler())->compile(['elements' => [$node]]));
        }
        foreach ([
            ['padding_top', -1], ['padding_right', '-1px'], ['padding_bottom', 'auto'], ['padding_left', '2000.01px'],
            ['padding_top', '1px;display:none'], ['margin_top', -2001], ['margin_right', '2001rem'],
            ['margin_bottom', 'auto;display:none'], ['margin_left', 'calc(1px + 2px)'], ['margin_top', 1.5],
        ] as [$key, $bad]) {
            $node['styles'] = ['desktop' => [$key => $bad]];
            self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors'], (string) $key);
            self::assertSame('', (new Compiler())->compile(['elements' => [$node]]), (string) $key);
        }
    }

    public function testResponsiveBordersAreBoundedAndCompiledWithoutRawCss(): void
    {
        $node = $this->element('button', 'bordered');
        $node['settings'] = ['text' => 'Découvrir', 'url' => '/'];
        $node['styles'] = [
            'desktop' => ['border_style' => 'solid', 'border_width' => 4, 'border_color' => 'kit:primary'],
            'tablet' => ['border_width' => 8, 'border_style' => 'dashed', 'border_color' => '#123ABC'],
            'mobile' => ['border_style' => 'none', 'border_width' => 0],
        ];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        self::assertSame($node['styles'], $result['data']['elements'][0]['styles']);
        $css = (new Compiler(1000, 600))->compile($result['data']);
        $selector = '.pb-element[data-pb-id="bordered"]' . $this->legacyGuard($result['data']);
        self::assertStringContainsString($selector . '{border-style:solid;border-width:4px;border-color:var(--pb-kit-primary);}', $css);
        self::assertStringContainsString('@media(max-width:1000px){' . $selector . '{border-width:8px;border-style:dashed;border-color:#123abc;}}', $css);
        self::assertStringContainsString('@media(max-width:600px){' . $selector . '{border-style:none;border-width:0px;}}', $css);
        foreach (['none', 'solid', 'dashed', 'dotted', 'double'] as $style) {
            $node['styles'] = ['desktop' => ['border_style' => $style, 'border_width' => 100, 'border_color' => 'kit:text']];
            $valid = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
            self::assertSame([], $valid['errors']);
            self::assertStringContainsString('border-style:' . $style . ';border-width:100px;border-color:var(--pb-kit-text);', (new Compiler())->compile($valid['data']));
        }
        // Validation and compilation independently reject malformed or injected declarations.
        foreach ([
            ['border_style', 'solid;display:none'], ['border_style', 'hidden'], ['border_style', true],
            ['border_width', -1], ['border_width', 101], ['border_width', 1.5], ['border_width', '4px'], ['border_width', '1px;display:none'],
            ['border_color', '#123456;display:none'], ['border_color', 'url(javascript:evil)'], ['border_color', 'transparent'], ['border_color', null],
        ] as [$key, $bad]) {
            $node['styles'] = ['desktop' => [$key => $bad]];
            self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors'], (string) $key);
            self::assertSame('', (new Compiler())->compile(['elements' => [$node]]), (string) $key);
        }
        $node['styles'] = [];
        self::assertSame('', (new Compiler())->compile(['elements' => [$node]]));
    }

    public function testResponsiveShadowPresetsAreAllowlistedAndCompiled(): void
    {
        $node = $this->element('button', 'shadowed');
        $node['settings'] = ['text' => 'Découvrir', 'url' => '/'];
        $node['styles'] = [
            'desktop' => ['box_shadow' => 'soft'],
            'tablet' => ['box_shadow' => 'strong'],
            'mobile' => ['box_shadow' => 'none'],
        ];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        self::assertSame($node['styles'], $result['data']['elements'][0]['styles']);
        $selector = '.pb-element[data-pb-id="shadowed"]' . $this->legacyGuard($result['data']);
        $css = (new Compiler(1000, 600))->compile($result['data']);
        self::assertStringContainsString($selector . '{box-shadow:0 2px 8px rgba(0,0,0,.15);}', $css);
        self::assertStringContainsString('@media(max-width:1000px){' . $selector . '{box-shadow:0 12px 32px rgba(0,0,0,.28);}}', $css);
        self::assertStringContainsString('@media(max-width:600px){' . $selector . '{box-shadow:none;}}', $css);
        foreach (['none' => 'none', 'soft' => '0 2px 8px rgba(0,0,0,.15)', 'medium' => '0 6px 20px rgba(0,0,0,.2)', 'strong' => '0 12px 32px rgba(0,0,0,.28)'] as $preset => $expected) {
            $node['styles'] = ['desktop' => ['box_shadow' => $preset]];
            $valid = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
            self::assertSame([], $valid['errors']);
            self::assertStringContainsString('box-shadow:' . $expected . ';', (new Compiler())->compile($valid['data']));
        }
        // Client-supplied CSS and unknown presets are rejected even if the compiler is called directly.
        foreach (['soft;display:none', '0 0 8px red', 'inherit', '__proto__', 1, true, null, []] as $bad) {
            $node['styles'] = ['desktop' => ['box_shadow' => $bad]];
            self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
            self::assertSame('', (new Compiler())->compile(['elements' => [$node]]));
        }
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

    public function testRichTextKeepsBasicMarkupAndEnforcesItsUtf8ByteLimit(): void
    {
        $node = $this->element('text');
        $node['settings'] = ['text' => '<h3>Sous-titre</h3><ul><li>Élément</li></ul><table><tbody><tr><th>Nom</th><td>Valeur</td></tr></tbody></table><p><a href="/guide" target="_blank" onclick="evil()">Guide</a> <a href="jav&#x61;script:evil()">Danger</a><script>evil()</script></p>'];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        $normalized = $result['data']['elements'][0]['settings']['text'];
        self::assertIsString($normalized);
        self::assertStringContainsString('<h3>Sous-titre</h3>', $normalized);
        self::assertStringContainsString('<ul><li>Élément</li></ul>', $normalized);
        self::assertStringContainsString('<table><tbody><tr><th>Nom</th><td>Valeur</td></tr></tbody></table>', $normalized);
        self::assertStringContainsString('<a href="/guide" target="_blank" rel="noopener noreferrer">Guide</a>', $normalized);
        self::assertStringContainsString('Danger', $normalized);
        self::assertStringNotContainsString('onclick', $normalized);
        self::assertStringNotContainsString('javascript:', $normalized);
        self::assertStringNotContainsString('<script', $normalized);
        $rendered = (new Renderer(new Registry(), new HtmlSanitizer()))->render($result['data']);
        self::assertStringContainsString('<th>Nom</th><td>Valeur</td>', $rendered);
        self::assertStringNotContainsString('<script', $rendered);

        $node['settings'] = ['text' => str_repeat('é', 10000)];
        self::assertSame([], $this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        $node['settings'] = ['text' => str_repeat('é', 10001)];
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
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
            if (in_array($type, ['original_content', 'original_group'], true)) {
                $node['settings'] = ['payload' => $originals->protect(1, 'legacy', '<p>Conservé</p>', $type === 'original_group')];
            }
            $result = $validator->validate(['version' => 1, 'elements' => [$node]]);
            self::assertSame([], $result['errors'], $type);
            $html = $renderer->render($result['data']);
            self::assertStringContainsString('data-pb-id="' . $type . '"', $html);
            if (in_array($type, ['original_content', 'original_group'], true)) {
                self::assertStringContainsString('<p>Conservé</p>', $html);
            }
        }
    }

    public function testGroupedFallbackRespectsKeyAndDocumentSizeLimits(): void
    {
        $blocks = new \Prime\Editor\BlockRegistry();
        $sanitizer = new HtmlSanitizer();
        $registry = new Registry();
        $originals = OriginalContent::fromAppKey(str_repeat('k', 32));
        self::assertNotNull($originals);
        self::assertSame(null, OriginalContent::fromAppKey(''));
        $content = (string) json_encode(['version' => 1, 'blocks' => [
            ['id' => 'one', 'type' => 'missing/one', 'data' => []],
            ['id' => 'two', 'type' => 'missing/two', 'data' => []],
        ]]);
        $entry = \Prime\Content\Entry::fromRow(['id' => 1, 'content' => $content, 'content_html' => '<p>Un</p><p>Deux</p>']);
        $converter = new \PrimeBuilder\Document\Converter($blocks, $sanitizer, $registry, $originals);
        self::assertNotNull($converter->convert($entry));
        self::assertSame(null, (new \PrimeBuilder\Document\Converter($blocks, $sanitizer, $registry, null))->convert($entry));
        $tooLarge = \Prime\Content\Entry::fromRow(['id' => 1, 'content' => $content, 'content_html' => str_repeat('x', Validator::MAX_BYTES + 1)]);
        self::assertSame(null, $converter->convert($tooLarge));
        // Even when the raw HTML is under 1 MiB, its encrypted layout must fit the limit.
        $expandsPastLimit = \Prime\Content\Entry::fromRow(['id' => 1, 'content' => $content, 'content_html' => str_repeat('x', 800000)]);
        self::assertSame(null, $converter->convert($expandsPastLimit));
        $noBlocks = \Prime\Content\Entry::fromRow(['id' => 1, 'content_html' => "  \n"]);
        $converted = $converter->convert($noBlocks);
        self::assertNotNull($converted);
        $element = $converted['blocks'][0]['data']['elements'][0];
        self::assertSame('original_group', $element['type']);
        self::assertSame("  \n", $originals->open($element['settings']['payload'])['html'] ?? null);

        $validator = new Validator($registry, $sanitizer, $blocks, $originals, 1);
        $node = $this->element('original_group');
        $node['settings'] = ['payload' => $originals->protect(1, 'legacy', '<p>Seul</p>')];
        self::assertNotEmpty($validator->validate(['version' => 1, 'elements' => [$node]])['errors']);
    }

    public function testOriginalMediaIndexIsAuthenticatedDeduplicatedAndBackwardCompatible(): void
    {
        $encrypter = new \Prime\Security\Encrypter(str_repeat('k', 32));
        $originals = new OriginalContent($encrypter);
        $legacy = $encrypter->encrypt((string) json_encode(['entry' => 1, 'type' => 'image', 'html' => '<img src="/uploads/old.jpg">']));
        self::assertSame([], $originals->open($legacy)['media_ids'] ?? null);
        self::assertNull($originals->protect(1, 'image', '<p>x</p>', mediaIds: [0]));
        self::assertNull($originals->protect(1, 'image', '<p>x</p>', mediaIds: ['1']));
        self::assertNull($originals->protect(1, 'image', '<p>x</p>', mediaIds: [1 => 3]));
        foreach ([['1'], [0], [-1], [1.5], ['nested' => 1], '1'] as $bad) {
            $invalid = $encrypter->encrypt((string) json_encode(['entry' => 1, 'type' => 'image', 'html' => '<p>x</p>', 'media_ids' => $bad]));
            self::assertNull($originals->open($invalid));
        }
        $payload = $originals->protect(1, 'image', '<img src="/uploads/photo.jpg">', mediaIds: [7, 9, 7]);
        self::assertIsString($payload);
        self::assertSame([7, 9], $originals->open($payload)['media_ids'] ?? null);
        self::assertNull($originals->open($payload . 'x'));
        $node = $this->element('original_content');
        $node['settings'] = ['payload' => $payload];
        $validator = new Validator(new Registry(), new HtmlSanitizer(), originals: $originals, entryId: 1);
        $result = $validator->validate(['version' => 1, 'elements' => [$node], 'items' => [['mediaId' => 42]]]);
        self::assertSame([], $result['errors']);
        self::assertSame([['mediaId' => 7], ['mediaId' => 9]], $result['data']['items']);
        $node['settings']['payload'] = $legacy;
        $result = $validator->validate(['version' => 1, 'elements' => [$node], 'items' => [['mediaId' => 42]]]);
        self::assertSame([], $result['errors']);
        self::assertArrayNotHasKey('items', $result['data']);
        self::assertNotEmpty((new Validator(new Registry(), new HtmlSanitizer(), originals: $originals, entryId: 2))->validate(['version' => 1, 'elements' => [$node]])['errors']);
    }

    /** @param array<string, mixed> $settings */
    private function renderBasicWidget(string $type, array $settings): string
    {
        $node = $this->element($type, 'widget');
        $node['settings'] = $settings;
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        return (new Renderer(new Registry(), new HtmlSanitizer()))->render($result['data']);
    }

    public function testWidgetAssetUrlsRejectExternalAndTraversalPaths(): void
    {
        self::assertSame('/assets/plugins/prime-builder/css/widgets/toggle.css', Assets::safeUrl('/assets/plugins/prime-builder/css/widgets/toggle.css', 'css'));
        self::assertSame(null, Assets::safeUrl('https://example.test/style.css', 'css'));
        self::assertSame(null, Assets::safeUrl('/assets/plugins/prime-builder/../evil.css', 'css'));
        self::assertSame(null, Assets::safeUrl('/assets/plugins/prime-builder/js/tabs.js', 'css'));
        self::assertSame(null, Assets::safeUrl('//evil.test/widget.css', 'css'));
    }

    public function testBasicWidgetsAreCategorizedAndNeedNoPublicScript(): void
    {
        $definitions = (new Registry())->all();
        foreach (['icon', 'icon_list', 'image_box', 'testimonial', 'toggle', 'social', 'star_rating'] as $type) {
            self::assertSame('base', $definitions[$type]['category']);
            self::assertArrayNotHasKey('script', $definitions[$type]);
        }
    }

    public function testIconRendersAnAccessibleOrDecorativeSymbol(): void
    {
        $html = $this->renderBasicWidget('icon', ['icon' => 'heart', 'accessible_label' => 'Favori']);
        self::assertStringContainsString('<span role="img" aria-label="Favori">♥</span>', $html);
        self::assertStringContainsString('<span aria-hidden="true">★</span>', $this->renderBasicWidget('icon', ['icon' => 'star']));
        $node = $this->element('icon');
        $node['settings'] = ['icon' => '<script>'];
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
    }

    public function testIconListRendersEscapedItemsWithDecorativeIcons(): void
    {
        $html = $this->renderBasicWidget('icon_list', ['icon' => 'check', 'items' => "Premier\nDeuxième <script>alert(1)</script>"]);
        self::assertStringContainsString('<ul><li><span aria-hidden="true">✓</span> Premier</li>', $html);
        self::assertStringContainsString('Deuxième', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testImageBoxRendersSafeImageDimensionsContentAndLink(): void
    {
        $html = $this->renderBasicWidget('image_box', ['image_url' => '/uploads/image.jpg', 'alt' => 'Paysage', 'width' => 640, 'height' => 480, 'title' => 'Une boîte', 'text' => '<p>Texte <strong>utile</strong></p>', 'url' => '/suite', 'button_text' => 'Suite']);
        self::assertStringContainsString('src="/uploads/image.jpg" alt="Paysage" width="640" height="480" loading="lazy"', $html);
        self::assertStringContainsString('<h3>Une boîte</h3>', $html);
        self::assertStringContainsString('<strong>utile</strong>', $html);
        self::assertStringContainsString('href="/suite">Suite</a>', $html);
        $node = $this->element('image_box');
        $node['settings'] = ['image_url' => 'javascript:alert(1)'];
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
    }

    public function testTestimonialRendersQuotationAttributionAndOptionalPortrait(): void
    {
        $html = $this->renderBasicWidget('testimonial', ['text' => '<p>Très <strong>utile</strong>.</p>', 'author' => 'Camille', 'job_title' => 'Designer', 'image_url' => '/uploads/portrait.jpg', 'alt' => '', 'width' => 100, 'height' => 100]);
        self::assertStringContainsString('<figure><blockquote><p>Très <strong>utile</strong>.</p></blockquote>', $html);
        self::assertStringContainsString('alt="" width="100" height="100" loading="lazy"', $html);
        self::assertStringContainsString('<cite>Camille</cite> <span>Designer</span>', $html);
    }

    public function testTestimonialCarouselRendersSafeSlidesAndRejectsInvalidRows(): void
    {
        $node = $this->element('testimonial_carousel');
        $node['settings'] = [
            'label' => 'Retours & expériences',
            'items' => " Bon <strong>résultat</strong> | Camille & Alex | Designer | /uploads/portrait.jpg\n\nAutre retour | Sam",
        ];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        self::assertSame("Bon résultat | Camille & Alex | Designer | /uploads/portrait.jpg\nAutre retour | Sam", $result['data']['elements'][0]['settings']['items']);
        $html = (new Renderer(new Registry(), new HtmlSanitizer()))->render($result['data']);
        self::assertStringContainsString('data-pb-carousel-label="Retours &amp; expériences"', $html);
        self::assertStringContainsString('data-pb-carousel-next="' . Renderer::escape(t('plugin::prime-builder.ui.testimonials_next')) . '"', $html);
        self::assertStringContainsString('data-pb-carousel-position="' . Renderer::escape(t('plugin::prime-builder.ui.testimonials_position')) . '"', $html);
        self::assertSame(2, substr_count($html, 'data-pb-slide'));
        self::assertStringContainsString('<blockquote><p>Bon résultat</p></blockquote>', $html);
        self::assertStringContainsString('src="/uploads/portrait.jpg" alt="" width="96" height="96" loading="lazy"', $html);
        self::assertStringContainsString('<cite>Camille &amp; Alex</cite><span>Designer</span>', $html);
        self::assertStringContainsString('<cite>Sam</cite>', $html);
        self::assertStringNotContainsString(' hidden', $html);
        self::assertStringNotContainsString('<button', $html);
        self::assertStringNotContainsString('<strong>', $html);
        self::assertStringContainsString(Renderer::escape(t('plugin::prime-builder.ui.testimonials_empty')), $this->renderBasicWidget('testimonial_carousel', []));
        $node['settings'] = ['items' => implode("\n", array_fill(0, 20, str_repeat('x', 495) . '|a|b'))];
        $nearLimit = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $nearLimit['errors']);
        self::assertSame([], $this->validator()->validate($nearLimit['data'])['errors']);
        foreach ([
            'Texte | Nom | Fonction | javascript:alert(1)', 'Texte | Auteur | Fonction | /image.jpg | surplus',
            ' | Auteur', str_repeat('a', 1501), implode("\n", array_fill(0, 21, 'Témoignage')),
        ] as $bad) {
            $node['settings'] = ['items' => $bad];
            self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        }
        $definition = (new Registry())->all()['testimonial_carousel'];
        self::assertSame('pro', $definition['category']);
        self::assertSame('/assets/plugins/prime-builder/css/widgets/testimonial_carousel.css', $definition['style']);
        self::assertSame('/assets/plugins/prime-builder/js/image_carousel.js', $definition['script']);
        self::assertFileExists(dirname(__DIR__) . '/assets/css/widgets/testimonial_carousel.css');
    }

    public function testMediaCarouselValidatesAndRendersImagesAndNativeVideo(): void
    {
        $node = $this->element('media_carousel');
        $node['settings'] = [
            'label' => 'Images & vidéos', 'caption_language' => 'en',
            'items' => "image | /uploads/view.jpg | Paysage & ciel | <b>Vue</b>\nvideo | /uploads/demo.webm | Démo & exemple | /uploads/demo.vtt | Texte <em>complet</em>",
        ];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        self::assertSame("image | /uploads/view.jpg | Paysage & ciel | Vue\nvideo | /uploads/demo.webm | Démo & exemple | /uploads/demo.vtt | Texte complet", $result['data']['elements'][0]['settings']['items']);
        $html = (new Renderer(new Registry(), new HtmlSanitizer()))->render($result['data']);
        self::assertSame(2, substr_count($html, 'data-pb-slide'));
        self::assertStringContainsString('data-pb-carousel-label="Images &amp; vidéos"', $html);
        self::assertStringContainsString('src="/uploads/view.jpg" alt="Paysage &amp; ciel"', $html);
        self::assertStringContainsString('<figcaption>Vue</figcaption>', $html);
        self::assertStringContainsString('<video controls preload="none" aria-label="Démo &amp; exemple">', $html);
        self::assertStringContainsString('<source src="/uploads/demo.webm">', $html);
        self::assertStringContainsString('<track kind="captions" src="/uploads/demo.vtt" srclang="en"', $html);
        self::assertStringContainsString('<p>Texte complet</p>', $html);
        self::assertStringNotContainsString(' hidden', $html);
        self::assertStringNotContainsString('autoplay', $html);
        self::assertStringNotContainsString('<em>', $html);
        self::assertStringContainsString(Renderer::escape(t('plugin::prime-builder.ui.media_carousel_empty')), $this->renderBasicWidget('media_carousel', []));
        $node['settings'] = ['items' => implode("\n", array_fill(0, 20, 'image|/x|' . str_repeat('d', 300) . '|' . str_repeat('c', 438)))];
        $nearLimit = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $nearLimit['errors']);
        self::assertSame([], $this->validator()->validate($nearLimit['data'])['errors']);
        foreach ([
            'image | javascript:alert(1) | Danger', 'video | /movie.webm | | /captions.vtt',
            'video | /movie.webm | Démo | javascript:alert(1)', 'image | /x | alt | caption | surplus',
            'audio | /x | titre', implode("\n", array_fill(0, 21, 'image | /x | alt')),
        ] as $bad) {
            $node['settings'] = ['items' => $bad];
            self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        }
        $definition = (new Registry())->all()['media_carousel'];
        self::assertSame('pro', $definition['category']);
        self::assertSame('/assets/plugins/prime-builder/css/widgets/media_carousel.css', $definition['style']);
        self::assertSame('/assets/plugins/prime-builder/js/image_carousel.js', $definition['script']);
        self::assertFileExists(dirname(__DIR__) . '/assets/css/widgets/media_carousel.css');
    }

    public function testPortfolioValidatesCardsAndRendersEveryProjectWithoutScript(): void
    {
        $node = $this->element('portfolio');
        $node['settings'] = [
            'columns' => 2, 'level' => 3,
            'items' => "/uploads/a.jpg | Paysage & ciel | <b>Projet A</b> | Identité & création | Texte <em>riche</em> | /suite?x=1&y=2\n/uploads/b.jpg | | Projet B | Sites web",
        ];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        self::assertSame("/uploads/a.jpg | Paysage & ciel | Projet A | Identité & création | Texte riche | /suite?x=1&y=2\n/uploads/b.jpg |  | Projet B | Sites web", $result['data']['elements'][0]['settings']['items']);
        $html = (new Renderer(new Registry(), new HtmlSanitizer()))->render($result['data']);
        self::assertSame(2, substr_count($html, 'data-pb-portfolio-item'));
        self::assertStringContainsString('pb-portfolio-columns-2', $html);
        self::assertStringContainsString('data-pb-category="Identité &amp; création"', $html);
        self::assertStringContainsString('src="/uploads/a.jpg" alt="Paysage &amp; ciel" width="800" height="600" loading="lazy"', $html);
        self::assertStringContainsString('<h3><a href="/suite?x=1&amp;y=2">Projet A</a></h3>', $html);
        self::assertStringContainsString('<h3>Projet B</h3>', $html);
        self::assertStringContainsString('<p>Texte riche</p>', $html);
        self::assertStringNotContainsString('<em>', $html);
        self::assertStringNotContainsString(' hidden', $html);
        self::assertStringNotContainsString('<button', $html);
        self::assertStringContainsString(Renderer::escape(t('plugin::prime-builder.ui.portfolio_empty')), $this->renderBasicWidget('portfolio', []));
        $compact = implode("\n", array_fill(0, 20, '/i|' . str_repeat('a', 300) . '|' . str_repeat('t', 300) . '|Catégorie|' . str_repeat('d', 126)));
        $node['settings'] = ['items' => $compact];
        $nearLimit = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $nearLimit['errors']);
        self::assertSame($compact, $nearLimit['data']['elements'][0]['settings']['items']);
        self::assertSame([], $this->validator()->validate($nearLimit['data'])['errors']);
        $manyCategories = [];
        for ($i = 0; $i < 13; $i++) {
            $manyCategories[] = '/a.jpg | alt | Titre | catégorie ' . $i;
        }
        foreach ([
            'javascript:alert(1) | alt | Titre | Catégorie', '//evil.test/a.jpg | alt | Titre | Catégorie',
            '/a.jpg | alt | | Catégorie', '/a.jpg | alt | Titre |', '/a.jpg | alt | Titre | Catégorie | description | javascript:alert(1)',
            '/a.jpg | alt | Titre | Catégorie | description | /suite | surplus',
            implode("\n", array_fill(0, 25, '/a.jpg | alt | Titre | Catégorie')),
            implode("\n", $manyCategories),
        ] as $bad) {
            $node['settings'] = ['items' => $bad];
            self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        }
        $node['settings'] = ['columns' => 5, 'level' => 1];
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        $definition = (new Registry())->all()['portfolio'];
        self::assertSame('pro', $definition['category']);
        self::assertSame('/assets/plugins/prime-builder/css/widgets/portfolio.css', $definition['style']);
        self::assertSame('/assets/plugins/prime-builder/js/portfolio.js', $definition['script']);
        self::assertFileExists(dirname(__DIR__) . '/assets/css/widgets/portfolio.css');
    }

    public function testSlideshowValidatesSlidesAndRendersSafeContentWithoutScript(): void
    {
        $node = $this->element('slideshow');
        $node['settings'] = [
            'label' => 'Nos & projets', 'level' => 3,
            'items' => "/uploads/a.jpg | Paysage & ciel | <b>Bienvenue</b> | Texte <em>riche</em> | Voir | /suite?x=1&y=2\n/uploads/b.jpg | | Deuxième",
        ];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        self::assertSame("/uploads/a.jpg | Paysage & ciel | Bienvenue | Texte riche | Voir | /suite?x=1&y=2\n/uploads/b.jpg |  | Deuxième", $result['data']['elements'][0]['settings']['items']);
        $html = (new Renderer(new Registry(), new HtmlSanitizer()))->render($result['data']);
        self::assertSame(2, substr_count($html, 'data-pb-slide'));
        self::assertStringContainsString('data-pb-carousel-label="Nos &amp; projets"', $html);
        self::assertStringContainsString('src="/uploads/a.jpg" alt="Paysage &amp; ciel"', $html);
        self::assertStringContainsString('src="/uploads/b.jpg" alt=""', $html);
        self::assertStringContainsString('width="1600" height="900" loading="lazy"', $html);
        self::assertStringContainsString('<h3>Bienvenue</h3><p>Texte riche</p>', $html);
        self::assertStringContainsString('<a href="/suite?x=1&amp;y=2">Voir</a>', $html);
        self::assertStringNotContainsString('<em>', $html);
        self::assertStringNotContainsString(' hidden', $html);
        self::assertStringContainsString(Renderer::escape(t('plugin::prime-builder.ui.slideshow_empty')), $this->renderBasicWidget('slideshow', []));
        $compact = implode("\n", array_fill(0, 20, '/i|' . str_repeat('a', 300) . '|' . str_repeat('t', 300) . '|' . str_repeat('d', 139)));
        $node['settings'] = ['items' => $compact];
        $nearLimit = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $nearLimit['errors']);
        self::assertSame($compact, $nearLimit['data']['elements'][0]['settings']['items']);
        self::assertSame([], $this->validator()->validate($nearLimit['data'])['errors']);
        foreach ([
            'javascript:alert(1) | alt', '//evil.test/a.jpg | alt', '/a.jpg | alt | title | desc | Lire | javascript:alert(1)',
            '/a.jpg | alt | title | desc | Lire', '/a.jpg | alt | title | desc | | /suite', '/a.jpg | alt | t | d | l | /x | surplus',
            implode("\n", array_fill(0, 21, '/a.jpg | alt')),
        ] as $bad) {
            $node['settings'] = ['items' => $bad];
            self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        }
        $node['settings'] = ['level' => 1];
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        $definition = (new Registry())->all()['slideshow'];
        self::assertSame('pro', $definition['category']);
        self::assertSame('/assets/plugins/prime-builder/css/widgets/slideshow.css', $definition['style']);
        self::assertSame('/assets/plugins/prime-builder/js/image_carousel.js', $definition['script']);
        self::assertFileExists(dirname(__DIR__) . '/assets/css/widgets/slideshow.css');
    }

    public function testAnimatedHeadingHasOneReadableHeadingAndValidatedEffects(): void
    {
        $html = $this->renderBasicWidget('animated_heading', [
            'before' => 'Construisons', 'highlight' => 'ensemble & durablement', 'after' => 'demain', 'level' => 3, 'effect' => 'rise',
        ]);
        self::assertStringContainsString('<h3 class="pb-animated-heading">Construisons <span class="pb-animated-highlight pb-animated-highlight--rise">ensemble &amp; durablement</span> demain</h3>', $html);
        self::assertSame(1, substr_count($html, 'ensemble'));
        self::assertStringNotContainsString('aria-live', $html);
        self::assertStringNotContainsString('<h', $this->renderBasicWidget('animated_heading', []));
        $node = $this->element('animated_heading');
        foreach ([['effect' => 'rise" onload="alert(1)'], ['level' => 7], ['highlight' => str_repeat('a', 301)]] as $bad) {
            $node['settings'] = $bad;
            self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        }
    }

    public function testCodeIsPreservedEscapedAndHighlightedWithoutExecutingMarkup(): void
    {
        $source = "  const value = \"<script>alert('x')</script>\";\n// café & thé\n";
        $node = $this->element('code');
        $node['settings'] = ['label' => 'Exemple & test', 'language' => 'javascript', 'source' => $source];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        self::assertSame($source, $result['data']['elements'][0]['settings']['source']);
        $html = (new Renderer(new Registry(), new HtmlSanitizer()))->render($result['data']);
        self::assertStringContainsString('<pre class="pb-code-content" role="region" aria-label="Exemple &amp; test" tabindex="0"><code class="language-javascript">', $html);
        self::assertStringContainsString('<span class="pb-code-token--keyword">const</span>', $html);
        self::assertStringContainsString('<span class="pb-code-token--string">&quot;&lt;script&gt;', $html);
        self::assertStringContainsString('<span class="pb-code-token--comment">// café &amp; thé</span>', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertSame($source, html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        foreach (['text', 'html', 'css', 'javascript', 'json', 'php'] as $language) {
            $input = "<img src=x onerror=alert(1)> & \"texte\"\n";
            $rendered = $this->renderBasicWidget('code', ['language' => $language, 'source' => $input]);
            self::assertSame($input, html_entity_decode(strip_tags($rendered), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            self::assertStringNotContainsString('<img', $rendered);
        }
        $samples = [
            'html' => ['<h1>Bonjour</h1>', 'tag'],
            'css' => ['p { color: #fff; }', 'number'],
            'javascript' => ['const message = "Salut";', 'keyword'],
            'json' => ['{"ok":true}', 'string'],
            'php' => ['<?php $value = 3;', 'variable'],
        ];
        foreach ($samples as $language => [$input, $token]) {
            $highlighted = \PrimeBuilder\Widgets\CodeHighlighter::render($input, $language);
            self::assertStringContainsString('pb-code-token--' . $token, $highlighted);
            self::assertSame($input, html_entity_decode(strip_tags($highlighted), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        foreach (['javascript' => '"' . str_repeat('\\"', 9999), 'html' => str_repeat('<a', 10000)] as $language => $input) {
            $highlighted = \PrimeBuilder\Widgets\CodeHighlighter::render($input, $language);
            self::assertSame($input, html_entity_decode(strip_tags($highlighted), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $long = str_repeat('const ', 1500);
        $bounded = \PrimeBuilder\Widgets\CodeHighlighter::render($long, 'javascript');
        self::assertSame(1000, substr_count($bounded, 'pb-code-token--keyword'));
        self::assertSame($long, html_entity_decode(strip_tags($bounded), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        foreach ([['language' => 'javascript" onclick="alert(1)'], ['source' => "bad\0code"], ['source' => str_repeat('x', 20001)]] as $bad) {
            $node['settings'] = $bad;
            self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        }
        $definitions = (new Registry())->all();
        foreach (['animated_heading', 'code'] as $type) {
            self::assertSame('pro', $definitions[$type]['category']);
            self::assertSame('/assets/plugins/prime-builder/css/widgets/' . $type . '.css', $definitions[$type]['style']);
            self::assertFileExists(dirname(__DIR__) . '/assets/css/widgets/' . $type . '.css');
            self::assertArrayNotHasKey('script', $definitions[$type]);
        }
    }

    public function testReviewRendersAnAccessibleEditorialRatingAndSafeAttribution(): void
    {
        $html = $this->renderBasicWidget('review', [
            'title' => 'Une belle expérience', 'level' => 3, 'rating' => 4,
            'text' => '<p>Très <strong>utile</strong><script>alert(1)</script>.</p>',
            'author' => 'Camille & Alex', 'job_title' => 'Designer',
            'image_url' => '/uploads/portrait.jpg', 'alt' => '', 'width' => 100, 'height' => 100,
        ]);
        self::assertStringContainsString('<article class="pb-review-card"><h3>Une belle expérience</h3>', $html);
        self::assertStringContainsString('<span role="img" aria-label="', $html);
        self::assertStringContainsString('<span aria-hidden="true">★★★★☆</span>', $html);
        self::assertStringContainsString('<blockquote><p>Très <strong>utile</strong>.</p></blockquote>', $html);
        self::assertStringContainsString('src="/uploads/portrait.jpg" alt="" width="100" height="100" loading="lazy"', $html);
        self::assertStringContainsString('<cite>Camille &amp; Alex</cite><span>Designer</span>', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('itemprop=', $html);
        $node = $this->element('review');
        foreach ([['rating' => 6], ['rating' => -1], ['level' => 1], ['image_url' => 'javascript:alert(1)']] as $bad) {
            $node['settings'] = $bad;
            self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        }
    }

    public function testFlipBoxRendersBothSafeFacesAndAnOptionalLinkWithoutScript(): void
    {
        $html = $this->renderBasicWidget('flip_box', [
            'front_title' => 'Recto & aperçu', 'front_text' => '<p>En avant<script>alert(1)</script>.</p>',
            'back_title' => 'Verso', 'back_text' => '<p>Plus de détails.</p>', 'level' => 3,
            'url' => '/contact', 'button_text' => 'Nous joindre',
        ]);
        self::assertStringContainsString('data-pb-flip-context="Recto &amp; aperçu"', $html);
        self::assertStringContainsString('data-pb-flip-front><h3>Recto &amp; aperçu</h3><p>En avant.</p>', $html);
        self::assertStringContainsString('data-pb-flip-back><h3>Verso</h3><p>Plus de détails.</p>', $html);
        self::assertStringContainsString('href="/contact">Nous joindre</a>', $html);
        self::assertStringNotContainsString(' hidden', $html);
        self::assertStringNotContainsString('<button', $html);
        self::assertStringNotContainsString('<script', $html);
        $node = $this->element('flip_box');
        foreach ([['url' => 'javascript:alert(1)'], ['level' => 7], ['front_title' => str_repeat('x', 201)]] as $bad) {
            $node['settings'] = $bad;
            self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        }
        $definitions = (new Registry())->all();
        foreach (['review', 'flip_box'] as $type) {
            self::assertSame('pro', $definitions[$type]['category']);
            self::assertSame('/assets/plugins/prime-builder/css/widgets/' . $type . '.css', $definitions[$type]['style']);
            self::assertFileExists(dirname(__DIR__) . '/assets/css/widgets/' . $type . '.css');
        }
        self::assertArrayNotHasKey('script', $definitions['review']);
        self::assertSame('/assets/plugins/prime-builder/js/flip_box.js', $definitions['flip_box']['script']);
        self::assertFileExists(dirname(__DIR__) . '/assets/js/flip_box.js');
    }

    public function testCallToActionRendersSafeImageHeadingTextAndLink(): void
    {
        $html = $this->renderBasicWidget('call_to_action', [
            'title' => 'Construisons ensemble', 'level' => 3, 'text' => '<p>Une <strong>idée</strong><script>alert(1)</script></p>',
            'image_url' => '/uploads/atelier.jpg', 'alt' => 'Notre atelier', 'width' => 640, 'height' => 480,
            'url' => '/contact', 'button_text' => 'Nous contacter',
        ]);
        self::assertStringContainsString('<h3>Construisons ensemble</h3>', $html);
        self::assertStringContainsString('<strong>idée</strong>', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('src="/uploads/atelier.jpg" alt="Notre atelier" width="640" height="480" loading="lazy"', $html);
        self::assertStringContainsString('href="/contact">Nous contacter</a>', $html);
        $node = $this->element('call_to_action');
        $node['settings'] = ['url' => 'javascript:alert(1)', 'level' => 1];
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        self::assertStringNotContainsString('<a ', $this->renderBasicWidget('call_to_action', ['title' => 'Sans lien']));
    }

    public function testPriceTableRendersEscapedPriceBenefitsAndOptionalLink(): void
    {
        $html = $this->renderBasicWidget('price_table', [
            'title' => 'Offre & services', 'level' => 3, 'text' => '<p>Une <strong>offre</strong></p>',
            'price' => '29 €', 'period' => 'par mois', 'features' => "Premier avantage\nAssistance & suivi", 'url' => '/offre', 'button_text' => 'Choisir',
        ]);
        self::assertStringContainsString('<h3>Offre &amp; services</h3>', $html);
        self::assertStringContainsString('<strong>29 €</strong> <span>par mois</span>', $html);
        self::assertStringContainsString('<ul><li>Premier avantage</li><li>Assistance &amp; suivi</li></ul>', $html);
        self::assertStringContainsString('href="/offre">Choisir</a>', $html);
        self::assertStringNotContainsString('<a ', $this->renderBasicWidget('price_table', ['title' => 'Sans lien']));
        $node = $this->element('price_table');
        $node['settings'] = ['url' => 'javascript:alert(1)'];
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
    }

    public function testPriceListValidatesAndRendersTextOnlyRows(): void
    {
        $html = $this->renderBasicWidget('price_list', ['title' => 'Tarifs', 'level' => 2, 'items' => "Conseil | 25 € | Suivi & aide\nAtelier | Sur devis"]);
        self::assertStringContainsString('<h2>Tarifs</h2><dl>', $html);
        self::assertStringContainsString('<dt>Conseil</dt><dd><strong>25 €</strong><span>Suivi &amp; aide</span></dd>', $html);
        self::assertStringContainsString('<dt>Atelier</dt><dd><strong>Sur devis</strong></dd>', $html);
        $withBlankLines = $this->renderBasicWidget('price_list', ['items' => "Conseil | 25 €\n\n Atelier | Sur devis\n"]);
        self::assertStringContainsString('<dt>Conseil</dt>', $withBlankLines);
        self::assertStringContainsString('<dt>Atelier</dt>', $withBlankLines);
        $node = $this->element('price_list');
        foreach (['Sans prix', 'Nom | | Description', 'Nom | Prix | Description | Extra', implode("\n", array_fill(0, 31, 'Nom | Prix'))] as $bad) {
            $node['settings'] = ['items' => $bad];
            self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        }
        $safe = $this->renderBasicWidget('price_list', ['items' => '<img src=x onerror=alert(1)>Conseil | 25 € | <script>alert(1)</script>']);
        self::assertStringNotContainsString('<img', $safe);
        self::assertStringNotContainsString('<script', $safe);
        self::assertStringNotContainsString('onerror=', $safe);
    }

    public function testCountdownValidatesUtcDeadlinesAndRendersAnAccessibleStaticFallback(): void
    {
        $html = $this->renderBasicWidget('countdown', [
            'title' => 'Offre & suite', 'level' => 3, 'deadline' => '2028-02-29T13:05',
            'finished_text' => '<img src=x onerror=alert(1)>Terminé & disponible',
        ]);
        self::assertStringContainsString('<h3>Offre &amp; suite</h3>', $html);
        self::assertStringContainsString('<time datetime="2028-02-29T13:05:00Z">2028-02-29 13:05 UTC</time>', $html);
        self::assertStringContainsString('data-pb-deadline="2028-02-29T13:05:00Z"', $html);
        self::assertStringContainsString('data-pb-finished="Terminé &amp; disponible"', $html);
        self::assertStringContainsString('data-pb-countdown-values role="group"', $html);
        self::assertStringContainsString('" hidden>', $html);
        self::assertStringContainsString('role="status" data-pb-countdown-status', $html);
        self::assertStringNotContainsString('aria-live="assertive"', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('onerror=', $html);
        self::assertSame('0001-01-01T00:00:00Z', Countdown::canonical('0001-01-01T00:00'));
        self::assertSame('9999-12-31T23:59:00Z', Countdown::canonical('9999-12-31T23:59'));
        self::assertSame('2000-02-29T23:59:00Z', Countdown::canonical('2000-02-29T23:59'));
        foreach (['0000-01-01T00:00', '10000-01-01T00:00', '1900-02-29T00:00', '2027-02-29T00:00', '2028-02-30T00:00', '2028-01-01T24:00', '2028-01-01T12:60', '2028-01-01T12:00Z', '2028-01-01T12:00:00', '2028-01-01 12:00', ' 2028-01-01T12:00', 'not a date'] as $bad) {
            $node = $this->element('countdown');
            $node['settings'] = ['deadline' => $bad];
            self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors'], $bad);
            self::assertSame(null, Countdown::canonical($bad), $bad);
        }
        $node = $this->element('countdown');
        $node['settings'] = ['level' => 1];
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        self::assertStringNotContainsString('data-pb-countdown ', $this->renderBasicWidget('countdown', ['deadline' => '']));
        $node['settings'] = ['deadline' => '2028-02-30T00:00'];
        self::assertStringNotContainsString('<time', (new Renderer(new Registry(), new HtmlSanitizer()))->render(['elements' => [$node]]));
    }

    public function testCountdownDeclaresItsOwnConditionalAssets(): void
    {
        $definition = (new Registry())->all()['countdown'];
        self::assertSame('pro', $definition['category']);
        self::assertSame('datetime_utc', $definition['fields']['deadline']['type']);
        self::assertSame('/assets/plugins/prime-builder/css/widgets/countdown.css', $definition['style']);
        self::assertSame('/assets/plugins/prime-builder/js/countdown.js', $definition['script']);
        self::assertFileExists(dirname(__DIR__) . '/assets/css/widgets/countdown.css');
        self::assertFileExists(dirname(__DIR__) . '/assets/js/countdown.js');
    }

    public function testProPricingWidgetsHaveIndividualStylesAndNoPublicScript(): void
    {
        $definitions = (new Registry())->all();
        foreach (['call_to_action', 'price_table', 'price_list'] as $type) {
            self::assertSame('pro', $definitions[$type]['category']);
            self::assertSame('/assets/plugins/prime-builder/css/widgets/' . $type . '.css', $definitions[$type]['style']);
            self::assertArrayNotHasKey('script', $definitions[$type]);
        }
    }

    public function testToggleUsesNativeDetailsWithAnOptionalOpenInitialState(): void
    {
        $html = $this->renderBasicWidget('toggle', ['title' => 'Détails', 'text' => '<p>Informations</p>', 'initial' => 'open']);
        self::assertStringContainsString('<details open><summary>Détails</summary><div><p>Informations</p></div></details>', $html);
        self::assertStringContainsString('<details><summary>Détails</summary>', $this->renderBasicWidget('toggle', ['title' => 'Détails', 'initial' => 'closed']));
        $node = $this->element('toggle');
        $node['settings'] = ['initial' => 'open onclick=alert(1)'];
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
    }

    public function testSocialLinksUseNamedNavigationAndRejectUnsafeUrls(): void
    {
        $html = $this->renderBasicWidget('social', ['label' => 'Nos réseaux', 'facebook' => 'https://facebook.com/example', 'mastodon' => 'https://example.org/@user']);
        self::assertStringContainsString('<nav aria-label="Nos réseaux"><ul>', $html);
        self::assertStringContainsString('href="https://facebook.com/example">Facebook</a>', $html);
        self::assertStringContainsString('href="https://example.org/@user">Mastodon</a>', $html);
        $node = $this->element('social');
        $node['settings'] = ['facebook' => 'javascript:alert(1)'];
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
    }

    public function testStarRatingRendersOneAccessibleLabelAndFiveVisualStars(): void
    {
        $html = $this->renderBasicWidget('star_rating', ['value' => 4]);
        self::assertStringContainsString('<span role="img" aria-label="', $html);
        self::assertStringContainsString('<span aria-hidden="true">★★★★☆</span>', $html);
        $node = $this->element('star_rating');
        $node['settings'] = ['value' => 6];
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
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

    public function testPostsGridValidatesItsConfigurationAndRendersASafeFallback(): void
    {
        $node = $this->element('posts', 'grid_one');
        $node['settings'] = [
            'content_type' => 'post', 'taxonomy' => 'category', 'term_ids' => ' 7, 2, 7 ', 'author_id' => 2,
            'order_by' => 'title', 'order_direction' => 'asc', 'limit' => 12, 'pagination' => 'show',
            'layout' => 'cards', 'columns' => 4, 'show_excerpt' => 'show',
        ];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        self::assertSame('7,2', $result['data']['elements'][0]['settings']['term_ids']);
        $definitions = (new Registry())->all();
        self::assertSame('pro', $definitions['posts']['category']);
        self::assertSame('/assets/plugins/prime-builder/css/widgets/posts.css', $definitions['posts']['style']);
        self::assertSame('/assets/plugins/prime-builder/js/content.js', $definitions['posts']['script']);

        // The persisted HTML contains no other entries and needs no database to render.
        $connection = new Connection();
        $provider = new Content(new EntryQuery($connection, new EntryRepository($connection)), new ContentTypeRegistry(), new UrlGenerator(new PermalinkSettings()));
        $html = (new Renderer(new Registry(), new HtmlSanitizer(), content: $provider))->render($result['data']);
        self::assertStringContainsString('data-pb-id="grid_one"', $html);
        self::assertStringContainsString('href="/"', $html);
        self::assertStringNotContainsString('pb-post-list', $html);
        $node['settings']['content_type'] = 'page';
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        $html = (new Renderer(new Registry(), new HtmlSanitizer(), content: $provider))->render($result['data']);
        self::assertStringContainsString('href="/recherche"', $html);

        foreach (['0', '-2', '1,', '1 OR 1=1', str_repeat('9', 30), implode(',', range(1, 11))] as $invalid) {
            $node['settings']['term_ids'] = $invalid;
            self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors'], $invalid);
        }
        $node['settings']['term_ids'] = '';
        self::assertSame([], $this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        foreach (['limit' => 13, 'columns' => 5, 'author_id' => 0, 'order_by' => 'id; DROP TABLE entries', 'pagination' => 'yes'] as $field => $invalid) {
            $invalidNode = $node;
            $invalidNode['settings'][$field] = $invalid;
            self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$invalidNode]])['errors'], $field);
        }
    }

    public function testSearchFormRendersAValidatedAccessibleGetFormUsingTheCoreUrl(): void
    {
        $connection = new Connection(); // Lazy: rendering this form needs no database.
        $urls = new UrlGenerator(new PermalinkSettings(overrides: ['permalinks.search_base' => 'trouver-ici']));
        $content = new Content(new EntryQuery($connection, new EntryRepository($connection)), new ContentTypeRegistry(), $urls);
        $node = $this->element('search_form', 'search_one');
        $node['settings'] = ['search_label' => 'Chercher & découvrir', 'placeholder' => 'Mots "clés"', 'button_text' => 'Lancer'];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        $html = (new Renderer(new Registry(), new HtmlSanitizer(), content: $content))->render($result['data']);
        self::assertStringContainsString('role="search" aria-label="Chercher &amp; découvrir" method="get" action="/trouver-ici"', $html);
        self::assertStringContainsString('<label for="pb-search-search_one">Chercher &amp; découvrir</label>', $html);
        self::assertStringContainsString('type="search" id="pb-search-search_one" name="q" maxlength="200" placeholder="Mots &quot;clés&quot;" required', $html);
        self::assertStringContainsString('<button type="submit">Lancer</button>', $html);

        $raw = $content->searchForm(['search_label' => '"><img src=x>', 'placeholder' => '" autofocus', 'button_text' => '<script>'], 'second');
        self::assertStringContainsString('&quot;&gt;&lt;img src=x&gt;', $raw);
        self::assertStringContainsString('placeholder="&quot; autofocus"', $raw);
        self::assertStringNotContainsString('<script>', $raw);
        self::assertSame('', $content->searchForm([], 'bad id'));
        $node['settings']['search_label'] = str_repeat('a', 201);
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        $definition = (new Registry())->all()['search_form'];
        self::assertSame('/assets/plugins/prime-builder/css/widgets/search_form.css', $definition['style']);
        self::assertSame('/assets/plugins/prime-builder/js/content.js', $definition['script']);
    }

    public function testExternalVideoProvidersHaveInertAccessibleFallbacksBeforeActivation(): void
    {
        $youtube = $this->renderBasicWidget('video', ['url' => 'https://www.youtube.com/watch?v=M7lc1UVf-VE', 'video_title' => 'Présentation', 'transcript' => '<p>Transcription</p>']);
        self::assertStringContainsString('data-pb-provider="youtube" data-pb-video-id="M7lc1UVf-VE"', $youtube);
        self::assertStringContainsString('hidden data-pb-video-load data-pb-video-title="Présentation"', $youtube);
        self::assertStringContainsString('href="https://www.youtube.com/watch?v=M7lc1UVf-VE"', $youtube);
        self::assertStringContainsString('<p>Transcription</p>', $youtube);
        self::assertStringNotContainsString('<iframe', $youtube);
        self::assertStringNotContainsString('<source', $youtube);
        self::assertStringNotContainsString('<img', $youtube);

        $vimeo = $this->renderBasicWidget('video', ['url' => 'https://vimeo.com/12345678']);
        self::assertStringContainsString('data-pb-provider="vimeo" data-pb-video-id="12345678"', $vimeo);
        self::assertStringNotContainsString('<iframe', $vimeo);
        self::assertStringNotContainsString('<source', $vimeo);
        $invalid = $this->renderBasicWidget('video', ['url' => 'https://www.youtube.com/playlist?list=PL123']);
        self::assertStringContainsString('<a href="https://www.youtube.com/playlist?list=PL123"', $invalid);
        self::assertStringNotContainsString('<source', $invalid);
        self::assertStringNotContainsString('data-pb-embed-video', $invalid);
    }

    public function testNavigationValidatesMenuReferencesAndRendersASafeFallback(): void
    {
        $node = $this->element('navigation', 'menu');
        $node['settings'] = ['menu_id' => '42', 'label' => 'Navigation <b>projets</b> & actualités'];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        $entry = \Prime\Content\Entry::fromRow(['id' => 1]);
        $html = (new Renderer(new Registry(), new HtmlSanitizer(), entry: $entry))->render($result['data']);
        self::assertStringContainsString('data-pb-live="/api/prime-builder/v1/documents/1/live"', $html);
        self::assertStringContainsString('<nav class="pb-nav" aria-label="Navigation projets &amp; actualités"', $html);
        self::assertStringContainsString('data-pb-nav-breakpoint="767"', $html);
        self::assertStringContainsString('href="/"', $html);
        self::assertStringNotContainsString('<b>', $html);
        foreach (['0', '-1', '01', '1.5', '1e3', '1<script>', str_repeat('9', 10)] as $bad) {
            $node['settings'] = ['menu_id' => $bad];
            self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        }
        $node['settings'] = ['menu_id' => 42];
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        $definition = (new Registry())->all()['navigation'];
        self::assertSame('pro', $definition['category']);
        self::assertSame('/assets/plugins/prime-builder/css/widgets/navigation.css', $definition['style']);
        self::assertSame('/assets/plugins/prime-builder/js/content.js', $definition['script']);
        self::assertFileExists(dirname(__DIR__) . '/assets/css/widgets/navigation.css');
    }

    public function testNavigationResponsiveCssUsesTheBoundedConfiguredBreakpoint(): void
    {
        $navigation = $this->element('navigation', 'primary');
        $nested = $this->element('container', 'outer');
        $nested['children'] = [$navigation];
        $data = ['elements' => [$nested]];
        $css = (new Compiler(1200, 900))->compile($data);
        $guard = $this->legacyGuard($data);
        self::assertStringContainsString('@media(max-width:900px)', $css);
        self::assertStringContainsString('.pb-element[data-pb-id="primary"]' . $guard . ' > .pb-nav > .pb-nav__list{display:block;margin-top:.5rem}', $css);
        self::assertStringContainsString('.pb-element[data-pb-id="primary"]' . $guard . ' > .pb-nav .pb-nav__item{width:100%}', $css);
        self::assertStringNotContainsString('@media(max-width:767px)', $css);
        self::assertSame(599, Compiler::mobileBreakpoint(600, 9999));
        self::assertSame(1999, Compiler::mobileBreakpoint(9999, 9999));
        self::assertSame(320, Compiler::mobileBreakpoint(1000, -1));
        self::assertStringContainsString('@media(max-width:599px)', (new Compiler(600, 9999))->compile($data));
        self::assertStringNotContainsString('pb-nav__list', (new Compiler(1200, 900))->compile(['elements' => [$this->element('heading')]]));
        $fallback = \PrimeBuilder\Widgets\Navigation::render([], null, false, mobileBreakpoint: 900);
        self::assertStringContainsString('data-pb-nav-breakpoint="900"', $fallback);
        self::assertStringContainsString('data-pb-nav-breakpoint="320"', \PrimeBuilder\Widgets\Navigation::render([], null, false, mobileBreakpoint: -1));
        self::assertStringContainsString('data-pb-nav-breakpoint="1999"', \PrimeBuilder\Widgets\Navigation::render([], null, false, mobileBreakpoint: 9999));
    }

    public function testBreadcrumbsRenderSafeSemanticLinksAndConservativeFallback(): void
    {
        $node = $this->element('breadcrumbs', 'trail');
        $node['settings'] = ['label' => 'Votre <b>parcours</b> & ici'];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        $settings = $result['data']['elements'][0]['settings'];
        $html = Breadcrumbs::render($settings, [
            ['label' => 'Accueil', 'url' => '/'],
            ['label' => '<script>Malveillant</script>', 'url' => 'javascript:alert(1)'],
            ['label' => 'Actuel & ici', 'url' => '/actuel'],
        ], new HtmlSanitizer());
        self::assertStringContainsString('<nav class="pb-breadcrumb" aria-label="Votre parcours &amp; ici"><ol>', $html);
        self::assertStringContainsString('<li><a href="/">Accueil</a></li>', $html);
        self::assertStringContainsString('<li><span>&lt;script&gt;Malveillant&lt;/script&gt;</span></li>', $html);
        self::assertStringContainsString('<li><span aria-current="page">Actuel &amp; ici</span></li>', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('href="/actuel"', $html);
        self::assertSame('', Breadcrumbs::render([], [], new HtmlSanitizer()));
        $entry = \Prime\Content\Entry::fromRow(['id' => 1, 'title' => 'Secret', 'slug' => 'secret']);
        $fallback = (new Renderer(new Registry(), new HtmlSanitizer(), entry: $entry))->render($result['data']);
        self::assertStringContainsString('data-pb-live="/api/prime-builder/v1/documents/1/live"', $fallback);
        self::assertStringContainsString('href="/"', $fallback);
        self::assertStringNotContainsString('Secret', $fallback);
        self::assertStringNotContainsString('aria-current="page"', $fallback);
        $node['settings'] = ['label' => str_repeat('a', 201)];
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        $definition = (new Registry())->all()['breadcrumbs'];
        self::assertSame('pro', $definition['category']);
        self::assertSame('/assets/plugins/prime-builder/css/widgets/breadcrumbs.css', $definition['style']);
        self::assertSame('/assets/plugins/prime-builder/js/content.js', $definition['script']);
        self::assertFileExists(dirname(__DIR__) . '/assets/css/widgets/breadcrumbs.css');
    }

    public function testSiteTitleEscapesTheNameAndValidatesItsTagAndHomeLink(): void
    {
        $node = $this->element('site_title', 'brand-title');
        $node['settings'] = ['tag' => 'h3', 'link_home' => 'show'];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        self::assertSame('<h3 class="pb-site-title__text"><a href="/">Studio &amp; &lt;Co&gt;</a></h3>', SiteIdentity::titleMarkup($result['data']['elements'][0]['settings'], 'Studio & <Co>'));
        self::assertSame('<p class="pb-site-title__text">Studio</p>', SiteIdentity::titleMarkup(['tag' => 'script', 'link_home' => 'hide'], 'Studio'));
        $node['settings']['tag'] = 'script';
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        $definition = (new Registry())->all()['site_title'];
        self::assertSame('pro', $definition['category']);
        self::assertSame('/assets/plugins/prime-builder/css/widgets/site_title.css', $definition['style']);
        self::assertSame('/assets/plugins/prime-builder/js/content.js', $definition['script']);
        self::assertFileExists(dirname(__DIR__) . '/assets/css/widgets/site_title.css');
    }

    public function testSiteLogoHasAnAccessibleFallbackAndValidatesItsHomeLink(): void
    {
        $node = $this->element('site_logo', 'brand-logo');
        $node['settings'] = ['link_home' => 'show'];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        self::assertSame('<a class="pb-site-logo__link" href="/"><span class="pb-site-logo__fallback">Studio &amp; &lt;Co&gt;</span></a>', SiteIdentity::logoMarkup($result['data']['elements'][0]['settings'], 'Studio & <Co>', ''));
        $coreImage = '<img src="/uploads/logo.png" alt="Studio" width="120" height="40" loading="lazy">';
        self::assertSame('<span class="pb-site-logo__unlinked">' . $coreImage . '</span>', SiteIdentity::logoMarkup(['link_home' => 'hide'], 'Studio', $coreImage));
        $node['settings']['link_home'] = 'javascript:alert(1)';
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        $definition = (new Registry())->all()['site_logo'];
        self::assertSame('pro', $definition['category']);
        self::assertSame('/assets/plugins/prime-builder/css/widgets/site_logo.css', $definition['style']);
        self::assertSame('/assets/plugins/prime-builder/js/content.js', $definition['script']);
        self::assertFileExists(dirname(__DIR__) . '/assets/css/widgets/site_logo.css');
    }

    public function testEntryTitleUsesTheCoreViewAndValidatesItsTagAndLink(): void
    {
        $node = $this->element('entry_title', 'current-title');
        $node['settings'] = ['tag' => 'h3', 'link' => 'show'];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        $entry = \Prime\Content\Entry::fromRow(['id' => 9, 'type' => 'post', 'title' => 'Un titre & <test>', 'slug' => 'mon-guide']);
        $view = new \Prime\Site\View\EntryView($entry, new UrlGenerator(new PermalinkSettings()));
        self::assertSame('<h3 class="pb-entry-title__text"><a href="/blog/mon-guide">Un titre &amp; &lt;test&gt;</a></h3>', CurrentEntry::title($result['data']['elements'][0]['settings'], $view));
        self::assertSame('<h2 class="pb-entry-title__text">Un titre &amp; &lt;test&gt;</h2>', CurrentEntry::title(['tag' => 'script'], $view));
        self::assertSame('', CurrentEntry::title([], null));
        $node['settings']['tag'] = 'script';
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        $definition = (new Registry())->all()['entry_title'];
        self::assertSame('/assets/plugins/prime-builder/css/widgets/entry_title.css', $definition['style']);
        self::assertSame('/assets/plugins/prime-builder/js/content.js', $definition['script']);
        self::assertFileExists(dirname(__DIR__) . '/assets/css/widgets/entry_title.css');
    }

    public function testEntryExcerptUsesTheCoreFallbackAndValidatesItsLength(): void
    {
        $node = $this->element('entry_excerpt', 'current-excerpt');
        $node['settings'] = ['length' => 10];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        $entry = \Prime\Content\Entry::fromRow(['id' => 9, 'excerpt' => '<b>Bonjour</b> à tous & merci']);
        $view = new \Prime\Site\View\EntryView($entry, new UrlGenerator(new PermalinkSettings()));
        self::assertSame('<p class="pb-entry-excerpt__text">Bonjour à…</p>', CurrentEntry::excerpt($result['data']['elements'][0]['settings'], $view));
        $fallback = \Prime\Content\Entry::fromRow(['id' => 9, 'content_text' => 'Texte de repli & sûr']);
        self::assertStringContainsString('Texte de repli &amp; sûr', CurrentEntry::excerpt([], new \Prime\Site\View\EntryView($fallback, new UrlGenerator(new PermalinkSettings()))));
        $builder = \Prime\Content\Entry::fromRow(['id' => 9, 'content_text' => 'Texte du document', 'content' => '{"version":1,"blocks":[{"id":"layout","type":"prime-builder/layout","data":{"version":1,"elements":[]}}]}']);
        self::assertSame('<p class="pb-entry-excerpt__text">Texte du document</p>', CurrentEntry::excerpt([], new \Prime\Site\View\EntryView($builder, new UrlGenerator(new PermalinkSettings()))));
        self::assertSame('', CurrentEntry::excerpt([], null));
        $node['settings']['length'] = 1001;
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        $definition = (new Registry())->all()['entry_excerpt'];
        self::assertSame('/assets/plugins/prime-builder/css/widgets/entry_excerpt.css', $definition['style']);
        self::assertSame('/assets/plugins/prime-builder/js/content.js', $definition['script']);
        self::assertFileExists(dirname(__DIR__) . '/assets/css/widgets/entry_excerpt.css');
    }

    public function testEntryContentPreviewsItsContextAndNamespacesCopiedFragmentReferences(): void
    {
        $node = $this->element('entry_content', 'body');
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        $registry = new Registry();
        $definition = $registry->all()['entry_content'];
        self::assertSame('pro', $definition['category']);
        self::assertSame('/assets/plugins/prime-builder/css/widgets/entry_content.css', $definition['style']);
        self::assertArrayNotHasKey('script', $definition);
        self::assertFileExists(dirname(__DIR__) . '/assets/css/widgets/entry_content.css');
        $preview = (new Renderer($registry, new HtmlSanitizer(), live: true, trustedPreview: true))->render($result['data']);
        self::assertStringContainsString(Renderer::escape(t('plugin::prime-builder.ui.entry_content_preview')), $preview);
        $saved = (new Renderer($registry, new HtmlSanitizer()))->render($result['data']);
        self::assertStringNotContainsString('pb-entry-content__preview', $saved);

        $source = '<section id="intro"><h3 id="intro">Titre &amp; idées</h3><a href="#intro">Retour</a>'
            . '<label for="search">Chercher</label><input id="search" aria-describedby="intro outside">'
            . '<svg><linearGradient id="paint"></linearGradient><path fill="url(#paint)"></path></svg>'
            . '<script>alert(1)</script></section>';
        $html = \PrimeBuilder\Widgets\ContentFragment::scope($source, 'pbc-test');
        self::assertStringContainsString('id="pbc-test-1"', $html);
        self::assertStringContainsString('id="pbc-test-2"', $html);
        self::assertStringContainsString('href="#pbc-test-1"', $html);
        self::assertStringContainsString('for="pbc-test-3"', $html);
        self::assertStringContainsString('id="pbc-test-3" aria-describedby="pbc-test-1 outside"', $html);
        self::assertStringContainsString('fill="url(#pbc-test-4)"', $html);
        self::assertStringContainsString('Titre &amp; idées', $html);
        self::assertStringNotContainsString('<script', $html);
        $more = \PrimeBuilder\Widgets\ContentFragment::scope(
            '<p id="café">Café</p><a href="#caf%C3%A9">Aller</a><img src="/carte.png" alt="Plan" usemap="#plan"><map name="plan"><area href="#caf%C3%A9" alt="Café"></map>'
            . '<svg viewBox="0 0 10 10"><use xlink:href="#caf%C3%A9"></use><path style="fill:url(#caf%C3%A9)"></path></svg>',
            'pbc-other',
        );
        self::assertStringContainsString('id="pbc-other-1"', $more);
        self::assertStringContainsString('href="#pbc-other-1"', $more);
        self::assertStringContainsString('xlink:href="#pbc-other-1"', $more);
        self::assertStringContainsString('style="fill:url(#pbc-other-1)"', $more);
        self::assertStringContainsString('usemap="#pbc-other-map-2"', $more);
        self::assertStringContainsString('name="pbc-other-map-2"', $more);
        self::assertSame('', \PrimeBuilder\Widgets\ContentFragment::scope($source, 'invalid prefix'));
        self::assertSame('', \PrimeBuilder\Widgets\ContentFragment::scope('</div><p>Hors fragment</p>', 'pbc-test'));
        self::assertSame('', \PrimeBuilder\Widgets\ContentFragment::scope(str_repeat('x', 2 * 1024 * 1024 + 1), 'pbc-test'));
    }

    public function testEntryFeaturedImageUsesTheCoreMediaViewAndValidatesItsSize(): void
    {
        $node = $this->element('entry_featured_image', 'current-image');
        $node['settings'] = ['size' => 'medium'];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        $entry = \Prime\Content\Entry::fromRow(['id' => 9, 'title' => 'Titre alternatif']);
        $media = \Prime\Media\Media::fromRow([
            'id' => 3, 'kind' => 'image', 'disk_path' => 'photo.png', 'width' => 1200, 'height' => 600, 'alt' => 'Une & photo',
            'variants' => [
                'thumbnail' => ['path' => 'photo-thumb.png', 'width' => 150, 'height' => 150, 'webp' => 'photo-thumb.webp'],
                'medium' => ['path' => 'photo-medium.png', 'width' => 300, 'height' => 150, 'webp' => 'photo-medium.webp'],
            ],
        ]);
        $view = new \Prime\Site\View\EntryView($entry, new UrlGenerator(new PermalinkSettings()), featuredMedia: $media);
        $html = CurrentEntry::featuredImage($result['data']['elements'][0]['settings'], $view);
        self::assertStringContainsString('src="/uploads/photo-medium.png"', $html);
        self::assertStringContainsString('alt="Une &amp; photo"', $html);
        self::assertStringContainsString('width="300" height="150" loading="lazy"', $html);
        self::assertStringContainsString('<picture', $html);
        $thumbnail = CurrentEntry::featuredImage(['size' => 'thumbnail'], $view);
        self::assertStringContainsString('src="/uploads/photo-thumb.png"', $thumbnail);
        self::assertStringContainsString('alt="Une &amp; photo"', $thumbnail);
        self::assertStringContainsString('width="150" height="150" loading="lazy"', $thumbnail);
        self::assertStringNotContainsString('<picture', $thumbnail);
        self::assertStringNotContainsString('srcset=', $thumbnail);
        self::assertStringNotContainsString('sizes=', $thumbnail);
        $smallMedia = \Prime\Media\Media::fromRow([
            'id' => 4, 'kind' => 'image', 'disk_path' => 'small.png', 'width' => 100, 'height' => 50, 'alt' => 'Forêt « & »',
        ]);
        $smallView = new \Prime\Site\View\EntryView($entry, new UrlGenerator(new PermalinkSettings()), featuredMedia: $smallMedia);
        $fallback = CurrentEntry::featuredImage(['size' => 'thumbnail'], $smallView);
        self::assertStringContainsString('src="/uploads/small.png"', $fallback);
        self::assertStringContainsString('alt="Forêt « &amp; »"', $fallback);
        self::assertStringContainsString('width="100" height="50"', $fallback);
        self::assertStringNotContainsString('srcset=', $fallback);
        self::assertSame('', CurrentEntry::featuredImage([], null));
        $node['settings']['size'] = 'unsafe';
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        $definition = (new Registry())->all()['entry_featured_image'];
        self::assertSame('/assets/plugins/prime-builder/css/widgets/entry_featured_image.css', $definition['style']);
        self::assertSame('/assets/plugins/prime-builder/js/content.js', $definition['script']);
        self::assertFileExists(dirname(__DIR__) . '/assets/css/widgets/entry_featured_image.css');
    }

    public function testEntryInformationRendersPublicMetadataAndValidatesVisibility(): void
    {
        $node = $this->element('entry_info', 'current-info');
        $node['settings'] = ['date' => 'show', 'author' => 'show', 'categories' => 'show'];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        $term = \Prime\Content\Term::fromRow(['id' => 4, 'taxonomy' => 'category', 'name' => 'Arts & <idées>', 'slug' => 'arts']);
        $entry = \Prime\Content\Entry::fromRow(['id' => 9, 'author_id' => 7, 'published_at' => '2025-02-03 10:30:00'], terms: ['category' => [$term]], authorName: 'Camille & Co');
        $view = new \Prime\Site\View\EntryView($entry, new UrlGenerator(new PermalinkSettings()));
        $html = CurrentEntry::info($result['data']['elements'][0]['settings'], $view, true);
        self::assertStringContainsString('<ul class="pb-entry-info__list" aria-label=', $html);
        self::assertStringContainsString('<time datetime="2025-02-03T10:30:00+00:00">', $html);
        self::assertStringContainsString('>Camille &amp; Co</a>', $html);
        self::assertStringContainsString('>Arts &amp; &lt;idées&gt;</a>', $html);
        self::assertStringNotContainsString('Arts &amp;', CurrentEntry::info([], $view, false));
        self::assertStringNotContainsString('Camille', CurrentEntry::info([], $view, true, false));
        $unlinked = CurrentEntry::info(['date' => 'hide', 'categories' => 'hide'], $view, true, true, false);
        self::assertStringContainsString('Camille &amp; Co', $unlinked);
        self::assertStringNotContainsString('<a ', $unlinked);
        self::assertSame('', CurrentEntry::info(['date' => 'hide', 'author' => 'hide', 'categories' => 'hide'], $view, true));
        self::assertSame('', CurrentEntry::info([], null, true));
        $node['settings']['author'] = 'maybe';
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        $definition = (new Registry())->all()['entry_info'];
        self::assertSame('/assets/plugins/prime-builder/css/widgets/entry_info.css', $definition['style']);
        self::assertSame('/assets/plugins/prime-builder/js/content.js', $definition['script']);
        self::assertFileExists(dirname(__DIR__) . '/assets/css/widgets/entry_info.css');
    }

    public function testEntryNavigationEscapesLabelsAndTitlesAndValidatesItsControls(): void
    {
        $node = $this->element('entry_navigation', 'adjacent');
        $node['settings'] = ['label' => 'Explorer & apprendre', 'previous' => 'show', 'next' => 'show'];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        $urls = new UrlGenerator(new PermalinkSettings());
        $previous = new \Prime\Site\View\EntryView(\Prime\Content\Entry::fromRow(['id' => 4, 'type' => 'post', 'slug' => 'avant', 'title' => 'Avant & <après>']), $urls);
        $next = new \Prime\Site\View\EntryView(\Prime\Content\Entry::fromRow(['id' => 6, 'type' => 'post', 'slug' => 'apres', 'title' => 'Après']), $urls);
        $html = CurrentEntry::navigation($result['data']['elements'][0]['settings'], $previous, $next);
        self::assertStringContainsString('<nav class="pb-entry-navigation" aria-label="Explorer &amp; apprendre">', $html);
        self::assertStringContainsString('href="/blog/avant" rel="prev"', $html);
        self::assertStringContainsString('Avant &amp; &lt;après&gt;', $html);
        self::assertStringContainsString('href="/blog/apres" rel="next"', $html);
        self::assertStringNotContainsString('rel="prev"', CurrentEntry::navigation(['previous' => 'hide'], $previous, $next));
        self::assertSame('', CurrentEntry::navigation([], null, null));
        $node['settings']['next'] = 'invalid';
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        $definition = (new Registry())->all()['entry_navigation'];
        self::assertSame('pro', $definition['category']);
        self::assertSame('/assets/plugins/prime-builder/css/widgets/entry_navigation.css', $definition['style']);
        self::assertSame('/assets/plugins/prime-builder/js/content.js', $definition['script']);
        self::assertFileExists(dirname(__DIR__) . '/assets/css/widgets/entry_navigation.css');
    }

    public function testEntryAuthorEscapesProfileAndValidatesItsControls(): void
    {
        $node = $this->element('entry_author', 'author');
        $node['settings'] = ['avatar' => 'show', 'bio' => 'show', 'link' => 'show'];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        $user = new \Prime\Auth\User(
            id: 7,
            email: 'author@prime.test',
            displayName: 'Camille & <Co>',
            status: 'active',
            locale: 'fr',
            sessionVersion: 1,
            lastLoginAt: null,
            passwordChangedAt: null,
            twoFactorConfirmedAt: null,
            twoFactorLastStep: null,
            createdAt: null,
            updatedAt: null,
            username: 'camille',
            bio: 'Bio & <script>alert(1)</script>',
        );
        $entry = \Prime\Content\Entry::fromRow(['id' => 9, 'author_id' => 7]);
        $media = \Prime\Media\Media::fromRow(['id' => 3, 'kind' => 'image', 'disk_path' => 'avatar.png', 'width' => 100, 'height' => 100]);
        $view = new \Prime\Site\View\EntryView($entry, new UrlGenerator(new PermalinkSettings()), authorUser: $user, authorAvatarMedia: $media);
        $html = CurrentEntry::author($result['data']['elements'][0]['settings'], $view, true);
        self::assertStringContainsString('href="/auteur/camille">Camille &amp; &lt;Co&gt;</a>', $html);
        self::assertStringContainsString('Bio &amp; &lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
        self::assertStringContainsString('src="/uploads/avatar.png"', $html);
        self::assertStringNotContainsString('<script>', $html);
        $hidden = CurrentEntry::author(['avatar' => 'hide', 'bio' => 'hide', 'link' => 'hide'], $view, true);
        self::assertStringNotContainsString('href=', $hidden);
        self::assertStringNotContainsString('avatar', $hidden);
        self::assertStringNotContainsString('Bio', $hidden);
        self::assertStringNotContainsString('href=', CurrentEntry::author([], $view, false));
        self::assertSame('', CurrentEntry::author([], null, false));
        $node['settings']['avatar'] = 'invalid';
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        $definition = (new Registry())->all()['entry_author'];
        self::assertSame('pro', $definition['category']);
        self::assertSame('/assets/plugins/prime-builder/css/widgets/entry_author.css', $definition['style']);
        self::assertSame('/assets/plugins/prime-builder/js/content.js', $definition['script']);
        self::assertFileExists(dirname(__DIR__) . '/assets/css/widgets/entry_author.css');
    }

    public function testArchiveTitleUsesOnlySupportedPublicContextsAndEscapesValues(): void
    {
        $node = $this->element('archive_title', 'archive-title');
        $node['settings'] = ['tag' => 'h3'];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        $term = \Prime\Content\Term::fromRow(['id' => 4, 'taxonomy' => 'category', 'name' => 'Art & <idées>', 'slug' => 'art']);
        $taxonomy = new \Prime\Content\Taxonomy(name: 'category', labels: ['singular' => 'Catégorie'], public: true);
        $page = new \Prime\Site\PageContext(type: \Prime\Site\PageContext::TYPE_TERM, taxonomy: $taxonomy, term: $term);
        self::assertSame('<h3 class="pb-archive-title__text">Art &amp; &lt;idées&gt;</h3>', Archive::title($result['data']['elements'][0]['settings'], $page));
        $type = new \Prime\Content\ContentType(name: 'book', labels: ['plural' => 'Livres & revues']);
        $archive = new \Prime\Site\PageContext(type: \Prime\Site\PageContext::TYPE_ARCHIVE, contentType: $type);
        self::assertStringContainsString('Livres &amp; revues', Archive::title([], $archive));
        $search = new \Prime\Site\PageContext(type: \Prime\Site\PageContext::TYPE_SEARCH, searchQuery: '<script> & nature');
        self::assertStringContainsString(Renderer::escape(t('plugin::prime-builder.ui.archive_search_query', ['query' => '<script> & nature'])), Archive::title([], $search));
        self::assertStringNotContainsString('<script>', Archive::title([], $search));
        $private = new \Prime\Content\Taxonomy(name: 'category', labels: ['singular' => 'Catégorie'], public: false);
        self::assertSame('', Archive::title([], new \Prime\Site\PageContext(type: \Prime\Site\PageContext::TYPE_TERM, taxonomy: $private, term: $term)));
        self::assertSame('', Archive::title([], new \Prime\Site\PageContext(type: \Prime\Site\PageContext::TYPE_ENTRY)));
        self::assertSame('', Archive::title([], \Prime\Site\PageContext::notFound()));
        $node['settings']['tag'] = 'script';
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        $definition = (new Registry())->all()['archive_title'];
        self::assertSame('pro', $definition['category']);
        self::assertSame('/assets/plugins/prime-builder/css/widgets/archive_title.css', $definition['style']);
        self::assertArrayNotHasKey('script', $definition);
        self::assertFileExists(dirname(__DIR__) . '/assets/css/widgets/archive_title.css');
    }

    public function testArchiveListRendersResolvedEntriesAndAccessiblePaginationWithoutScript(): void
    {
        $node = $this->element('archive_list', 'archive-list');
        $node['settings'] = ['layout' => 'cards', 'columns' => 2, 'show_excerpt' => 'show', 'pagination' => 'show'];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        $entry = \Prime\Content\Entry::fromRow(['id' => 9, 'type' => 'post', 'slug' => 'nature', 'title' => 'Nature & <idées>', 'excerpt' => 'Un <b>texte</b> & plus']);
        $page = new \Prime\Site\PageContext(type: \Prime\Site\PageContext::TYPE_SEARCH, path: '/recherche/page/2', page: 2, totalPages: 3, searchQuery: 'art & idées');
        $urls = new UrlGenerator(new PermalinkSettings());
        $html = Archive::listing($result['data']['elements'][0]['settings'], $page, [$entry], $urls);
        self::assertStringContainsString('pb-archive-list__items--cards pb-archive-list__columns-2', $html);
        self::assertStringContainsString('<a href="/blog/nature">Nature &amp; &lt;idées&gt;</a>', $html);
        self::assertStringContainsString('<p>Un texte &amp; plus</p>', $html);
        self::assertStringContainsString('href="/recherche?q=art%20%26%20id%C3%A9es" rel="prev"', $html);
        self::assertStringContainsString('href="/recherche/page/3?q=art%20%26%20id%C3%A9es" rel="next"', $html);
        self::assertStringContainsString('aria-current="page"', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<p>Un texte', Archive::listing(['show_excerpt' => 'hide', 'pagination' => 'hide'], $page, [$entry], $urls));
        self::assertStringNotContainsString('<nav', Archive::listing(['pagination' => 'hide'], $page, [$entry], $urls));
        self::assertStringContainsString('pb-archive-list__empty', Archive::listing([], $page, [], $urls));
        $unsafePath = new \Prime\Site\PageContext(type: \Prime\Site\PageContext::TYPE_SEARCH, path: '//evil.test', page: 1, totalPages: 2);
        self::assertStringNotContainsString('<nav', Archive::listing([], $unsafePath, [$entry], $urls));
        self::assertSame('', Archive::listing([], new \Prime\Site\PageContext(type: \Prime\Site\PageContext::TYPE_ENTRY), [$entry], $urls));
        $node['settings']['columns'] = 5;
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        $definition = (new Registry())->all()['archive_list'];
        self::assertSame('pro', $definition['category']);
        self::assertSame('/assets/plugins/prime-builder/css/widgets/archive_list.css', $definition['style']);
        self::assertArrayNotHasKey('script', $definition);
        self::assertFileExists(dirname(__DIR__) . '/assets/css/widgets/archive_list.css');
    }

    public function testShareButtonsEncodePublicUrlsAndRejectUnsafeValues(): void
    {
        $node = $this->element('share_buttons', 'share');
        $node['settings'] = ['label' => 'Partager <b>ici</b> & ailleurs', 'message' => 'Bonjour & bienvenue', 'linkedin' => 'hide'];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        $html = ShareButtons::render($result['data']['elements'][0]['settings'], 'https://example.test/page?private_token=secret#fragment');
        self::assertStringContainsString('aria-label="Partager ici &amp; ailleurs"', $html);
        self::assertStringContainsString('data-pb-share-message="Bonjour &amp; bienvenue"', $html);
        self::assertStringContainsString('https://www.facebook.com/sharer/sharer.php?u=https%3A%2F%2Fexample.test%2Fpage', $html);
        self::assertStringContainsString('https://twitter.com/intent/tweet?url=https%3A%2F%2Fexample.test%2Fpage&amp;text=Bonjour%20%26%20bienvenue', $html);
        self::assertStringNotContainsString('private_token', $html);
        self::assertStringNotContainsString('secret', $html);
        self::assertStringNotContainsString('fragment', $html);
        self::assertStringContainsString('mailto:?subject=Bonjour%20%26%20bienvenue&amp;body=', $html);
        self::assertStringNotContainsString('linkedin.com', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<b>', $html);
        foreach (['javascript:alert(1)', '//example.test/page', 'https://user:pass@example.test/', "https://example.test/\r\nBcc:evil@example.test"] as $bad) {
            self::assertStringNotContainsString('<a ', ShareButtons::render([], $bad));
        }
        $controls = ShareButtons::render(['message' => "Bonjour\r\nBcc:bad@example.test"], 'https://example.test/');
        self::assertStringNotContainsString('%0A', $controls);
        self::assertStringNotContainsString('%0D', $controls);
        $node['settings'] = ['facebook' => 'yes'];
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        $node['settings'] = ['message' => str_repeat('a', 201)];
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        self::assertStringContainsString(Renderer::escape(t('plugin::prime-builder.ui.share_none')), ShareButtons::render(['facebook' => 'hide', 'linkedin' => 'hide', 'x' => 'hide', 'email' => 'hide'], 'https://example.test/'));
        $server = ShareButtons::render([], 'https://example.test/canonical?secret=token#fragment', false);
        self::assertStringContainsString('u=https%3A%2F%2Fexample.test%2Fcanonical', $server);
        self::assertStringNotContainsString('data-pb-share ', $server);
        self::assertStringNotContainsString('data-pb-share-message=', $server);
        self::assertStringNotContainsString('secret', $server);
        $definition = (new Registry())->all()['share_buttons'];
        self::assertSame('pro', $definition['category']);
        self::assertSame('/assets/plugins/prime-builder/css/widgets/share_buttons.css', $definition['style']);
        self::assertSame('/assets/plugins/prime-builder/js/share_buttons.js', $definition['script']);
        self::assertFileExists(dirname(__DIR__) . '/assets/css/widgets/share_buttons.css');
    }

    public function testTableOfContentsBuildsNestedLinksAndAvoidsReservedAnchors(): void
    {
        $toc = $this->element('table_of_contents', 'toc');
        $toc['settings'] = ['label' => 'Sommaire <b>utile</b>', 'min_level' => 2, 'max_level' => 4];
        $anchor = $this->element('anchor', 'manual');
        $anchor['settings'] = ['anchor' => 'pb-toc-intro'];
        $first = $this->element('heading', 'intro');
        $first['settings'] = ['text' => 'Un & deux', 'level' => 2];
        $block = $this->element('prime_block', 'coreheading');
        $block['settings'] = ['block_type' => 'heading', 'block_data' => '{"level":4,"text":"<em>Chapitre</em> &amp; suite"}', 'block_anchor' => 'legacy'];
        $animated = $this->element('animated_heading', 'animated');
        $animated['settings'] = ['before' => 'Créer', 'highlight' => 'ensemble', 'level' => 3];
        $last = $this->element('heading', 'last');
        $last['settings'] = ['text' => 'Conclusion', 'level' => 2];
        $hidden = $this->element('heading', 'hidden');
        $hidden['settings'] = ['text' => 'Secret masqué', 'level' => 2];
        $hidden['hidden'] = true;
        $nested = $this->element('container', 'nested');
        $nested['children'] = [$block, $animated, $hidden, $last];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$toc, $anchor, $first, $nested]]);
        self::assertSame([], $result['errors']);
        $renderer = new Renderer(new Registry(), new HtmlSanitizer());
        $html = $renderer->render($result['data']);
        self::assertStringContainsString('<nav class="pb-toc" aria-label="Sommaire utile">', $html);
        self::assertStringContainsString('<ol><li><a href="#pb-toc-intro-2">Un &amp; deux</a><ol><li><a href="#pb-toc-coreheading">Chapitre &amp; suite</a></li><li><a href="#pb-toc-animated">Créer ensemble</a></li></ol></li><li><a href="#pb-toc-last">Conclusion</a></li></ol>', $html);
        self::assertStringContainsString('data-pb-id="intro" id="pb-toc-intro-2" tabindex="-1"', $html);
        self::assertStringContainsString('id="pb-toc-intro"', $html);
        self::assertStringContainsString('id="legacy"', $html);
        self::assertStringNotContainsString('href="#pb-toc-hidden"', $html);
        self::assertStringNotContainsString('<b>', $html);
        $noToc = $renderer->render(['version' => 1, 'elements' => [$first]]);
        self::assertStringNotContainsString('pb-toc-intro', $noToc);
        self::assertStringContainsString('<h2>Un &amp; deux</h2>', $noToc);
        $empty = $renderer->render(['version' => 1, 'elements' => [$toc]]);
        self::assertStringContainsString(Renderer::escape(t('plugin::prime-builder.ui.toc_empty')), $empty);
        $toc['settings'] = ['min_level' => 7];
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$toc]])['errors']);
        $toc['settings'] = ['label' => str_repeat('x', 201)];
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$toc]])['errors']);
        $definition = (new Registry())->all()['table_of_contents'];
        self::assertSame('pro', $definition['category']);
        self::assertSame('/assets/plugins/prime-builder/css/widgets/table_of_contents.css', $definition['style']);
        self::assertArrayNotHasKey('script', $definition);
        self::assertFileExists(dirname(__DIR__) . '/assets/css/widgets/table_of_contents.css');
    }

    public function testTableOfContentsIncludesStaticWidgetTitlesOnlyWhenTheyRender(): void
    {
        $toc = $this->element('table_of_contents', 'toc');
        $toc['settings'] = ['label' => 'Plan', 'min_level' => 2, 'max_level' => 6];
        $anchor = $this->element('anchor', 'manual');
        $anchor['settings'] = ['anchor' => 'pb-toc-card'];
        $cases = [
            'card' => ['title' => 'Carte & conseil'],
            'icon_box' => ['title' => 'Boîte icône'],
            'image_box' => ['title' => 'Boîte image'],
            'form' => ['title' => 'Contact'],
            'call_to_action' => ['title' => 'Agir ensemble', 'level' => 4],
            'price_table' => ['title' => 'Offre', 'level' => 5],
            'price_list' => ['title' => 'Tarifs', 'level' => 3],
            'review' => ['title' => 'Avis', 'level' => 2],
            'countdown' => ['title' => 'Échéance', 'level' => 4, 'deadline' => '2030-01-02T03:04'],
        ];
        $elements = [$toc, $anchor];
        foreach ($cases as $type => $settings) {
            $node = $this->element($type, $type);
            $node['settings'] = $settings;
            $elements[] = $node;
        }
        $empty = $this->element('price_table', 'empty-title');
        $empty['settings'] = ['title' => ''];
        $noDeadline = $this->element('countdown', 'no-deadline');
        $noDeadline['settings'] = ['title' => 'Échéance sans date'];
        $defaultCountdown = $this->element('countdown', 'default-countdown');
        $defaultCountdown['settings'] = ['deadline' => '2030-01-02T03:04'];
        $zero = $this->element('price_table', 'zero-title');
        $zero['settings'] = ['title' => '0'];
        $hidden = $this->element('container', 'hidden-parent');
        $hidden['hidden'] = true;
        $hiddenChild = $this->element('review', 'hidden-review');
        $hiddenChild['settings'] = ['title' => 'Avis masqué'];
        $hidden['children'] = [$hiddenChild];
        $elements = [...$elements, $empty, $noDeadline, $defaultCountdown, $zero, $hidden];
        $validated = $this->validator()->validate(['version' => 1, 'elements' => $elements]);
        self::assertSame([], $validated['errors']);
        $renderer = new Renderer(new Registry(), new HtmlSanitizer());
        $html = $renderer->render($validated['data']);
        foreach ($cases as $type => $settings) {
            $target = 'pb-toc-' . $type . ($type === 'card' ? '-2' : '');
            $label = Renderer::escape($settings['title']);
            $level = $settings['level'] ?? (in_array($type, ['card', 'icon_box', 'image_box', 'form'], true) ? 3 : 2);
            self::assertStringContainsString('<a href="#' . $target . '">' . $label . '</a>', $html, $type);
            self::assertStringContainsString('data-pb-id="' . $type . '" id="' . $target . '" tabindex="-1"', $html, $type);
            self::assertStringContainsString('<h' . $level . '>' . $label . '</h' . $level . '>', $html, $type);
        }
        $defaultLabel = Renderer::escape(t('plugin::prime-builder.widgets.countdown'));
        self::assertStringContainsString('<a href="#pb-toc-default-countdown">' . $defaultLabel . '</a>', $html);
        self::assertStringContainsString('<h2>' . $defaultLabel . '</h2>', $html);
        self::assertStringContainsString('<a href="#pb-toc-zero-title">0</a>', $html);
        self::assertStringNotContainsString('href="#pb-toc-empty-title"', $html);
        self::assertStringNotContainsString('href="#pb-toc-no-deadline"', $html);
        self::assertStringNotContainsString('href="#pb-toc-hidden-review"', $html);
        self::assertStringNotContainsString('Échéance sans date</a>', $html);
        self::assertStringNotContainsString('Avis masqué</a>', $html);
        $withoutToc = $renderer->render(['version' => 1, 'elements' => array_slice($validated['data']['elements'], 1)]);
        self::assertStringNotContainsString('id="pb-toc-call_to_action"', $withoutToc);
        self::assertStringContainsString('<h4>Agir ensemble</h4>', $withoutToc);

        $elements[0]['settings']['min_level'] = 4;
        $elements[0]['settings']['max_level'] = 5;
        $filtered = $renderer->render($this->validator()->validate(['version' => 1, 'elements' => $elements])['data']);
        self::assertStringContainsString('href="#pb-toc-call_to_action"', $filtered);
        self::assertStringContainsString('href="#pb-toc-price_table"', $filtered);
        self::assertStringContainsString('href="#pb-toc-countdown"', $filtered);
        self::assertStringNotContainsString('href="#pb-toc-card-2"', $filtered);
        self::assertStringNotContainsString('href="#pb-toc-review"', $filtered);
    }

    public function testTableOfContentsIndexesRichTextHeadingsWithoutChangingTheirSafeContent(): void
    {
        $toc = $this->element('table_of_contents', 'toc');
        $toc['settings'] = ['label' => 'Plan', 'min_level' => 2, 'max_level' => 3];
        $deepToc = $this->element('table_of_contents', 'deep-toc');
        $deepToc['settings'] = ['label' => 'Détails', 'min_level' => 4, 'max_level' => 4];
        $anchor = $this->element('anchor', 'manual');
        $anchor['settings'] = ['anchor' => 'pb-toc-article-rich-1'];
        $rich = $this->element('text', 'article');
        $rich['settings'] = ['text' => '<h2 id="bad" onclick="evil()">Un <em>chapitre</em> &amp; suite</h2><p>Texte <strong>important</strong>.</p><h3><a href="/guide" onclick="evil()">Guide</a></h3><h4>Neige<br>blanche</h4><h2><br></h2><h3>0</h3><h2>&#8203; &nbsp;</h2><script>evil()</script>'];
        $normal = $this->element('heading', 'article-rich-2');
        $normal['settings'] = ['text' => 'Autre titre', 'level' => 2];
        $hidden = $this->element('container', 'hidden-parent');
        $hidden['hidden'] = true;
        $hiddenText = $this->element('text', 'hidden-text');
        $hiddenText['settings'] = ['text' => '<h2>Titre masqué</h2>'];
        $hidden['children'] = [$hiddenText];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$toc, $deepToc, $anchor, $rich, $normal, $hidden]]);
        self::assertSame([], $result['errors']);
        $renderer = new Renderer(new Registry(), new HtmlSanitizer());
        $html = $renderer->render($result['data']);

        self::assertStringContainsString('<a href="#pb-toc-article-rich-1-2">Un chapitre &amp; suite</a>', $html);
        self::assertStringContainsString('<h2 id="pb-toc-article-rich-1-2" tabindex="-1">Un <em>chapitre</em> &amp; suite</h2>', $html);
        self::assertStringContainsString('<a href="#pb-toc-article-rich-2">Guide</a>', $html);
        self::assertStringContainsString('<h3 id="pb-toc-article-rich-2" tabindex="-1"><a href="/guide">Guide</a></h3>', $html);
        self::assertStringContainsString('<a href="#pb-toc-article-rich-3">Neige blanche</a>', $html);
        self::assertStringContainsString('<h4 id="pb-toc-article-rich-3" tabindex="-1">Neige<br>blanche</h4>', $html);
        self::assertStringContainsString('<a href="#pb-toc-article-rich-5">0</a>', $html);
        self::assertStringContainsString('<h3 id="pb-toc-article-rich-5" tabindex="-1">0</h3>', $html);
        self::assertStringContainsString('<a href="#pb-toc-article-rich-2-2">Autre titre</a>', $html);
        self::assertStringContainsString('<p>Texte <strong>important</strong>.</p>', $html);
        self::assertStringContainsString('<h2><br></h2>', $html);
        self::assertStringNotContainsString('pb-toc-article-rich-6', $html);
        self::assertStringNotContainsString('href="#pb-toc-hidden-text', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertSame(1, substr_count($html, 'id="pb-toc-article-rich-1"'));
        self::assertSame(1, substr_count($html, 'id="pb-toc-article-rich-2"'));
        self::assertSame(1, preg_match('~<nav class="pb-toc" aria-label="Plan">(.*?)</nav>~s', $html, $plan));
        self::assertStringNotContainsString('Neige blanche</a>', $plan[1] ?? '');
        self::assertStringContainsString('Guide</a>', $plan[1] ?? '');
        self::assertSame(1, preg_match('~<nav class="pb-toc" aria-label="Détails">(.*?)</nav>~s', $html, $details));
        self::assertStringContainsString('Neige blanche</a>', $details[1] ?? '');
        self::assertStringNotContainsString('Guide</a>', $details[1] ?? '');

        $withoutToc = $renderer->render(['version' => 1, 'elements' => [$result['data']['elements'][3]]]);
        self::assertStringContainsString('<h2>Un <em>chapitre</em> &amp; suite</h2>', $withoutToc);
        self::assertStringNotContainsString('pb-toc-article', $withoutToc);
    }

    public function testTableOfContentsDistinguishesInvisibleAndPrivateUseCharacters(): void
    {
        $toc = $this->element('table_of_contents', 'toc');
        $privateUse = "\u{E000}";
        $unassigned = "\u{0378}";
        $invisible = "\u{200D}\u{034F}\u{FE0F}";
        $privateHeading = $this->element('heading', 'private');
        $privateHeading['settings'] = ['text' => $privateUse];
        $futureHeading = $this->element('heading', 'future');
        $futureHeading['settings'] = ['text' => $unassigned];
        $invisibleHeading = $this->element('heading', 'invisible');
        $invisibleHeading['settings'] = ['text' => $invisible];
        $text = $this->element('text', 'unicode');
        $text['settings'] = ['text' => '<h2>' . $invisible . '</h2><h2>' . $privateUse . '</h2><h2>' . $unassigned . '</h2><h2>Neige ❄️</h2>'];
        $elements = [$toc, $privateHeading, $futureHeading, $invisibleHeading, $text];
        foreach (["\u{115F}", "\u{17B4}", "\u{180F}", "\u{2065}", "\u{3164}", "\u{FFA0}", "\u{FFF0}", "\u{E0100}"] as $i => $value) {
            $node = $this->element('heading', 'ignorable-' . $i);
            $node['settings'] = ['text' => $value];
            $elements[] = $node;
        }
        $result = $this->validator()->validate(['version' => 1, 'elements' => $elements]);
        self::assertSame([], $result['errors']);
        $html = (new Renderer(new Registry(), new HtmlSanitizer()))->render($result['data']);
        foreach (range(0, 7) as $i) {
            self::assertStringNotContainsString('href="#pb-toc-ignorable-' . $i . '"', $html);
        }
        self::assertStringContainsString('<a href="#pb-toc-private">' . $privateUse . '</a>', $html);
        self::assertStringContainsString('<a href="#pb-toc-future">' . $unassigned . '</a>', $html);
        self::assertStringNotContainsString('href="#pb-toc-invisible"', $html);
        self::assertStringNotContainsString('href="#pb-toc-unicode-rich-1"', $html);
        self::assertStringContainsString('<a href="#pb-toc-unicode-rich-2">' . $privateUse . '</a>', $html);
        self::assertStringContainsString('<a href="#pb-toc-unicode-rich-3">' . $unassigned . '</a>', $html);
        self::assertStringContainsString('<a href="#pb-toc-unicode-rich-4">Neige ❄️</a>', $html);
        self::assertStringContainsString('<h2>' . $invisible . '</h2>', $html);
    }

    public function testTableOfContentsBoundsItsLinksAndShowsWhenHeadingsAreOmitted(): void
    {
        $toc = $this->element('table_of_contents', 'toc');
        $toc['settings'] = ['label' => 'Plan'];
        $long = str_repeat('é', 180);
        $rich = $this->element('text', 'article');
        $headings = '<h2>' . $long . '</h2>';
        for ($i = 2; $i <= 205; ++$i) {
            $headings .= '<h2>Titre ' . $i . '</h2>';
        }
        $rich['settings'] = ['text' => $headings];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$toc, $rich]]);
        self::assertSame([], $result['errors']);
        $html = (new Renderer(new Registry(), new HtmlSanitizer()))->render($result['data']);
        self::assertSame(200, substr_count($html, '<a href="#pb-toc-article-rich-'));
        $label = rtrim(mb_strcut($long, 0, 297, 'UTF-8')) . '…';
        self::assertStringContainsString('<a href="#pb-toc-article-rich-1">' . $label . '</a>', $html);
        self::assertStringContainsString('<h2 id="pb-toc-article-rich-1" tabindex="-1">' . $long . '</h2>', $html);
        self::assertStringContainsString('<a href="#pb-toc-article-rich-200">Titre 200</a>', $html);
        self::assertStringContainsString('<h2>Titre 201</h2>', $html);
        self::assertStringNotContainsString('pb-toc-article-rich-201', $html);
        self::assertStringContainsString(Renderer::escape(t('plugin::prime-builder.ui.toc_limit')), $html);

        $plainHeading = $this->element('heading', 'long-heading');
        $plainHeading['settings'] = ['text' => $long, 'level' => 2];
        $plain = (new Renderer(new Registry(), new HtmlSanitizer()))->render(['version' => 1, 'elements' => [$toc, $plainHeading]]);
        self::assertStringContainsString('<a href="#pb-toc-long-heading">' . $label . '</a>', $plain);
        self::assertStringContainsString('<h2>' . $long . '</h2>', $plain);
        self::assertStringNotContainsString(Renderer::escape(t('plugin::prime-builder.ui.toc_limit')), $plain);
    }

    public function testRepeatedTablesOfContentsKeepTheirGeneratedOutputBounded(): void
    {
        $elements = [];
        for ($i = 0; $i < 20; ++$i) {
            $toc = $this->element('table_of_contents', 'toc' . $i);
            $toc['settings'] = ['label' => 'Plan'];
            $elements[] = $toc;
        }
        for ($i = 0; $i < 3; ++$i) {
            $text = $this->element('text', 'text' . $i);
            $text['settings'] = ['text' => str_repeat('<h2>' . str_repeat('é', 100) . '</h2>', 70)];
            $elements[] = $text;
        }
        $result = $this->validator()->validate(['version' => 1, 'elements' => $elements]);
        self::assertSame([], $result['errors']);
        $html = (new Renderer(new Registry(), new HtmlSanitizer()))->render($result['data']);
        self::assertSame(4000, substr_count($html, '<a href="#pb-toc-text'));
        self::assertSame(20, substr_count($html, 'class="pb-toc__limit"'));
        self::assertLessThan(2000000, strlen($html));
    }

    public function testTableOfContentsNeverIndexesUnsafeRichTextBeforeSanitizing(): void
    {
        $toc = $this->element('table_of_contents', 'toc');
        $text = $this->element('text', 'unsafe');
        $text['settings'] = ['text' => '<script><h2>Faux titre</h2></script><h2 id="attacker" onclick="evil()">Sûr <a href="javascript:evil()">lien</a></h2><p>&lt;h3&gt;texte&lt;/h3&gt;</p>'];
        // Deliberately bypass document validation: Renderer must filter on its own.
        $html = (new Renderer(new Registry(), new HtmlSanitizer()))->render(['version' => 1, 'elements' => [$toc, $text]]);
        self::assertStringContainsString('<a href="#pb-toc-unsafe-rich-1">Sûr lien</a>', $html);
        self::assertStringContainsString('<h2 id="pb-toc-unsafe-rich-1" tabindex="-1">Sûr lien</h2>', $html);
        self::assertStringContainsString('&lt;h3&gt;texte&lt;/h3&gt;', $html);
        self::assertStringNotContainsString('Faux titre', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('id="attacker"', $html);
        self::assertStringNotContainsString('pb-toc-unsafe-rich-2', $html);
    }

    public function testThemeZoneValidatesTheSlugAndHasAReadableFallback(): void
    {
        $node = $this->element('theme_zone');
        $node['settings'] = ['zone' => 'sidebar'];
        $result = $this->validator()->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $result['errors']);
        $entry = \Prime\Content\Entry::fromRow(['id' => 1]);
        $html = (new Renderer(new Registry(), new HtmlSanitizer(), entry: $entry))->render($result['data']);
        self::assertStringContainsString('data-pb-live="/api/prime-builder/v1/documents/1/live"', $html);
        self::assertStringContainsString('class="pb-element pb-theme_zone"', $html);
        self::assertStringContainsString('<p>', $html);
        foreach (['../sidebar', 'SIDEbar', 'a b', str_repeat('a', 81)] as $bad) {
            $node['settings'] = ['zone' => $bad];
            self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        }
    }

    public function testMapRendersSafeLinksAndRejectsInvalidCoordinates(): void
    {
        $html = $this->renderBasicWidget('map', ['title' => 'Centre & gare', 'latitude' => '48.790000', 'longitude' => '2.450000', 'zoom' => 14]);
        self::assertStringContainsString('data-pb-map-title="Centre &amp; gare"', $html);
        self::assertStringContainsString('https://www.openstreetmap.org/export/embed.html?bbox=', $html);
        self::assertStringContainsString('marker=48.790000%2C2.450000', $html);
        self::assertStringContainsString('href="https://www.openstreetmap.org/?mlat=48.790000&amp;mlon=2.450000#map=14/48.790000/2.450000"', $html);
        self::assertStringContainsString('href="https://www.openstreetmap.org/copyright"', $html);
        self::assertStringContainsString('data-pb-map-load hidden', $html);
        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringNotContainsString('<script', $html);
        foreach ([['latitude' => '85.1'], ['latitude' => 'NaN'], ['latitude' => '48,79'], ['latitude' => '1\" onload=alert(1)'], ['longitude' => '-180.1'], ['longitude' => '1e3'], ['zoom' => 2]] as $bad) {
            $node = $this->element('map');
            $node['settings'] = $bad;
            self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$node]])['errors']);
        }
        $html = $this->renderBasicWidget('map', ['latitude' => '-85', 'longitude' => '180', 'zoom' => 18]);
        self::assertStringContainsString('marker=-85.000000%2C180.000000', $html);
        $html = $this->renderBasicWidget('map', []);
        self::assertStringNotContainsString('data-pb-map-src', $html);
    }

    public function testImageCarouselRendersEverySlideWithoutJavaScript(): void
    {
        $html = $this->renderBasicWidget('image_carousel', ['label' => 'Nos images', 'images' => "/uploads/a.jpg|Montagne & ciel\n/uploads/b.jpg|Océan"]);
        self::assertStringContainsString('data-pb-carousel-label="Nos images"', $html);
        self::assertSame(2, substr_count($html, 'data-pb-slide'));
        self::assertStringContainsString('src="/uploads/a.jpg" alt="Montagne &amp; ciel"', $html);
        self::assertStringContainsString('src="/uploads/b.jpg" alt="Océan"', $html);
        self::assertStringNotContainsString(' hidden', $html);
        self::assertStringNotContainsString('<button', $html);
        $bad = $this->element('image_carousel');
        $bad['settings'] = ['images' => 'javascript:alert(1)|Danger'];
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$bad]])['errors']);
    }

    public function testGalleryRendersAccessibleImageLinksAsNoScriptFallback(): void
    {
        $html = $this->renderBasicWidget('gallery', ['images' => "/uploads/a.jpg|Fleur & cascade\n/uploads/b.jpg|"]);
        self::assertStringContainsString('data-pb-gallery-item aria-label="', $html);
        self::assertStringContainsString('href="/uploads/a.jpg"', $html);
        self::assertStringContainsString('alt="Fleur &amp; cascade"', $html);
        self::assertStringContainsString('href="/uploads/b.jpg"', $html);
        self::assertStringContainsString('alt=""', $html);
        self::assertStringNotContainsString('<dialog', $html);
        self::assertStringNotContainsString('<script', $html);
        $bad = $this->element('gallery');
        $bad['settings'] = ['images' => 'javascript:alert(1)|Danger'];
        self::assertNotEmpty($this->validator()->validate(['version' => 1, 'elements' => [$bad]])['errors']);
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

    public function testFilteredHtmlSealsAreSanitizedEntryBoundAndCannotBeForged(): void
    {
        $registry = new Registry();
        $sanitizer = new HtmlSanitizer();
        $html = HtmlContent::fromAppKey(str_repeat('k', 32));
        self::assertNotNull($html);
        self::assertSame(null, HtmlContent::fromAppKey(''));
        $node = $this->element('html', 'snippet');
        $node['settings'] = ['markup' => '<p>Visible <strong>texte</strong><script>alert(1)</script><a href="javascript:alert(1)" onclick="alert(2)">lien</a></p>'];
        $normalized = (new Validator($registry, $sanitizer))->validate(['version' => 1, 'elements' => [$node]]);
        self::assertSame([], $normalized['errors']);
        $markup = $normalized['data']['elements'][0]['settings']['markup'];
        self::assertStringContainsString('<strong>texte</strong>', $markup);
        self::assertStringNotContainsString('<script', $markup);
        self::assertStringNotContainsString('javascript:', $markup);
        self::assertStringNotContainsString('onclick', $markup);
        $strict = new Validator($registry, $sanitizer, entryId: 1, html: $html, requireSealedHtml: true);
        self::assertNotEmpty($strict->validate($normalized['data'])['errors']);
        $sealed = $html->seal($normalized['data'], 1);
        self::assertSame([], $strict->validate($sealed)['errors']);
        self::assertNotEmpty((new Validator($registry, $sanitizer, entryId: 2, html: $html, requireSealedHtml: true))->validate($sealed)['errors']);
        $entry1 = \Prime\Content\Entry::fromRow(['id' => 1]);
        $entry2 = \Prime\Content\Entry::fromRow(['id' => 2]);
        self::assertStringContainsString('<strong>texte</strong>', (new Renderer($registry, $sanitizer, entry: $entry1, html: $html))->render($sealed));
        self::assertStringNotContainsString('<strong>texte</strong>', (new Renderer($registry, $sanitizer, entry: $entry2, html: $html))->render($sealed));
        $modified = $sealed;
        $modified['elements'][0]['settings']['markup'] = '<p>Falsifié</p>';
        self::assertNotEmpty($strict->validate($modified)['errors']);
        $previous = Layout::document($sealed);
        self::assertSame(true, $html->allows($sealed, $previous, 1, false));
        self::assertSame(false, $html->allows($modified, $previous, 1, false));
        $copy = $sealed;
        $copy['elements'][0]['id'] = 'copy';
        self::assertSame(false, $html->allows($copy, $previous, 1, false));
        self::assertSame(true, $html->allows($modified, $previous, 1, true));
        $portable = HtmlContent::withoutSeals($sealed);
        self::assertSame(false, isset($portable['elements'][0]['settings']['signature']));
        self::assertSame(false, isset($portable['elements'][0]['settings']['signed_entry']));
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
