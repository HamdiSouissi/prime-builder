<?php

declare(strict_types=1);

namespace PrimeBuilder\Tests;

use PHPUnit\Framework\TestCase;
use Prime\Security\HtmlSanitizer;
use PrimeBuilder\Document\Library;
use PrimeBuilder\Document\Validator;
use PrimeBuilder\Widgets\Registry;

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
