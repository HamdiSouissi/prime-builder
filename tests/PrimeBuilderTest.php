<?php

declare(strict_types=1);

namespace PrimeBuilder\Tests;

use PHPUnit\Framework\TestCase;
use Prime\Security\HtmlSanitizer;
use Prime\Tests\Support\ApiTestFixture;
use PrimeBuilder\Document\DocumentValidator;
use PrimeBuilder\Dynamic\DisplayConditions;
use PrimeBuilder\Dynamic\DynamicTags;
use PrimeBuilder\Render\Renderer;
use PrimeBuilder\Style\CssCompiler;
use PrimeBuilder\ThemeBuilder\TemplateResolver;
use PrimeBuilder\Widgets\BuiltinWidgets;
use PrimeBuilder\Widgets\WidgetRegistry;

final class PrimeBuilderTest extends TestCase
{
    use ApiTestFixture;

    public function testValidatorSanitizesUrlsCustomCssAndUnknownWidgets(): void
    {
        $registry = new WidgetRegistry();
        BuiltinWidgets::registerAll($registry);
        $validator = new DocumentValidator($registry->types());

        $doc = [
            'version' => 1,
            'elements' => [
                [
                    'id' => 'c1',
                    'elType' => 'container',
                    'settings' => [
                        'custom_css' => 'selector { color: red; } @import url("evil.css"); </style><script>alert(1)</script>',
                    ],
                    'elements' => [
                        [
                            'id' => 'b1',
                            'elType' => 'widget',
                            'widgetType' => 'button',
                            'settings' => ['text' => 'Go', 'url' => 'javascript:alert(1)'],
                        ],
                        [
                            'id' => 'bad',
                            'elType' => 'widget',
                            'widgetType' => 'unknown_widget',
                            'settings' => [],
                        ],
                    ],
                ],
            ],
        ];

        $result = $validator->validate($doc, new HtmlSanitizer());
        $this->assertNotEmpty($result['errors']);
        $container = $result['data']['elements'][0];
        $this->assertStringNotContainsString('@import', $container['settings']['custom_css']);
        $this->assertStringNotContainsString('</style>', $container['settings']['custom_css']);
        $this->assertCount(1, $container['elements']);
        $this->assertSame('', $container['elements'][0]['settings']['url']);
    }

    public function testRendererAndCssCompilerWorkTogether(): void
    {
        $registry = new WidgetRegistry();
        BuiltinWidgets::registerAll($registry);
        $renderer = new Renderer($registry);
        $compiler = new CssCompiler(1024, 767);

        $doc = [
            'version' => 1,
            'elements' => [
                [
                    'id' => 'el_1',
                    'elType' => 'widget',
                    'widgetType' => 'tabs',
                    'settings' => [
                        'text_color' => '#111111',
                        'text_color_mobile' => '#222222',
                        'items' => [['title' => 'T1', 'content' => 'C1']],
                    ],
                ],
            ],
        ];

        $html = $renderer->render($doc);
        $this->assertStringContainsString('data-pb-tabs', $html);
        $this->assertSame(['tabs.js'], $renderer->requiredScripts());

        $css = $compiler->compileDocument($doc);
        $this->assertStringContainsString('[data-pb-id="el_1"]{color:#111111}', $css);
        $this->assertStringContainsString('@media (max-width: 767px)', $css);
    }

    public function testDynamicTagsAndDisplayConditions(): void
    {
        $resolved = DynamicTags::resolve('Bonjour {{site.title}} — {{entry.title}}', [
            'site_title' => 'Prime',
            'entry_title' => '<script>x</script>',
        ]);
        $this->assertSame('Bonjour Prime — &lt;script&gt;x&lt;/script&gt;', $resolved);

        $this->assertFalse(DisplayConditions::shouldRender(['condition_auth' => 'logged_in'], ['is_logged_in' => false]));
        $this->assertTrue(DisplayConditions::shouldRender(['condition_auth' => 'logged_in'], ['is_logged_in' => true]));
    }

    public function testThemeBuilderResolverRespectsPriorityAndExclusions(): void
    {
        $resolver = new TemplateResolver();
        $templates = [
            ['id' => 1, 'type' => 'header', 'status' => 'published', 'priority' => 20, 'conditions_json' => '{"include":[{"type":"all"}]}'],
            ['id' => 2, 'type' => 'header', 'status' => 'published', 'priority' => 5, 'conditions_json' => '{"include":[{"type":"home"}],"exclude":[{"type":"entry_id","value":99}]}'],
        ];

        $home = $resolver->resolve('header', $templates, ['is_home' => true, 'entry_id' => 1]);
        $this->assertSame(2, $home['id']);

        $excluded = $resolver->resolve('header', $templates, ['is_home' => true, 'entry_id' => 99]);
        $this->assertSame(1, $excluded['id']);
    }
}
