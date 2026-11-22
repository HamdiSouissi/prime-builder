<?php

declare(strict_types=1);

namespace PrimeBuilder\Tests;

use PHPUnit\Framework\TestCase;
use Prime\Security\HtmlSanitizer;
use PrimeBuilder\Document\Library;
use PrimeBuilder\Document\Validator;
use PrimeBuilder\Widgets\Registry;

require_once dirname(__DIR__) . '/src/Styles/Values.php';
require_once dirname(__DIR__) . '/src/Document/Library.php';
require_once dirname(__DIR__) . '/src/Document/Validator.php';
require_once dirname(__DIR__) . '/src/Widgets/PrimeBlock.php';
require_once dirname(__DIR__) . '/src/Widgets/Registry.php';

final class CatalogTest extends TestCase
{
    public function testTranslationsHaveIdenticalKeys(): void
    {
        $fr = require dirname(__DIR__) . '/lang/fr/messages.php';
        $en = require dirname(__DIR__) . '/lang/en/messages.php';
        self::assertSame($this->keys($fr), $this->keys($en));
        self::assertSame('Titre', $fr['ui']['title']);
        self::assertSame('Éditeur visuel', $fr['ui']['editor_title']);
        self::assertSame('Carrousel de médias', $fr['widgets']['media_carousel']);
        self::assertSame('Media carousel', $en['widgets']['media_carousel']);
        self::assertStringContainsString('landscape.svg', $fr['defaults']['media_carousel_items']);
        self::assertStringContainsString('landscape.svg', $en['defaults']['media_carousel_items']);
        self::assertSame('Diaporama', $fr['widgets']['slideshow']);
        self::assertSame('Slideshow', $en['widgets']['slideshow']);
        self::assertStringContainsString('landscape.svg', $fr['defaults']['slideshow_items']);
        self::assertStringContainsString('landscape.svg', $en['defaults']['slideshow_items']);
        self::assertSame('Portfolio', $fr['widgets']['portfolio']);
        self::assertSame('Portfolio', $en['widgets']['portfolio']);
        self::assertSame('Menu de navigation', $fr['widgets']['navigation']);
        self::assertSame('Navigation menu', $en['widgets']['navigation']);
        self::assertSame('Boutons de partage', $fr['widgets']['share_buttons']);
        self::assertSame('Share buttons', $en['widgets']['share_buttons']);
        self::assertSame('Sommaire automatique', $fr['widgets']['table_of_contents']);
        self::assertSame('Table of contents', $en['widgets']['table_of_contents']);
        self::assertStringContainsString('landscape.svg', $fr['defaults']['portfolio_items']);
        self::assertStringContainsString('landscape.svg', $en['defaults']['portfolio_items']);
    }

    public function testAllShippedTemplatesValidate(): void
    {
        $library = new Library(dirname(__DIR__) . '/templates');
        $templates = $library->all();
        self::assertSame(9, count($templates));
        $validator = new Validator(new Registry(), new HtmlSanitizer());
        foreach ($templates as $template) {
            self::assertSame([], $validator->validate(['version' => 1, 'elements' => $template['elements']])['errors'], (string) $template['name']);
        }
    }

    /**
     * @param array<string, mixed> $tree
     * @return list<string>
     */
    private function keys(array $tree, string $prefix = ''): array
    {
        $result = [];
        foreach ($tree as $key => $value) {
            $path = $prefix . (string) $key;
            if (is_array($value)) {
                $result = array_merge($result, $this->keys($value, $path . '.'));
            } else {
                $result[] = $path;
            }
        }
        sort($result);
        return $result;
    }
}
