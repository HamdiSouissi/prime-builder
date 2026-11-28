<?php

declare(strict_types=1);

namespace PrimeBuilder\Tests;

use PHPUnit\Framework\TestCase;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\Entry;
use Prime\Content\TaxonomyRegistry;
use Prime\Content\Term;
use Prime\Site\PageContext;
use PrimeBuilder\Theme\Conditions;
use PrimeBuilder\Theme\Models;

require_once dirname(__DIR__) . '/src/Theme/Conditions.php';
require_once dirname(__DIR__) . '/src/Theme/Models.php';

final class ThemeConditionsTest extends TestCase
{
    public function testEveryConditionAndExclusionAgainstRealPageContexts(): void
    {
        $term = new Term(7, 'category', 'Actualités', 'actualites', null, 0, 0, 0, '', '');
        $entry = Entry::fromRow(['id' => 42, 'type' => 'post', 'status' => 'published', 'title' => 'Article'], [], ['category' => [$term]]);
        $types = new ContentTypeRegistry();
        $taxonomy = (new TaxonomyRegistry())->get('category');
        $entryPage = new PageContext(type: PageContext::TYPE_ENTRY, entry: $entry, contentType: $types->get('post'));
        $termPage = new PageContext(type: PageContext::TYPE_TERM, taxonomy: $taxonomy, term: $term);
        $front = new PageContext(type: PageContext::TYPE_FRONT);
        $search = new PageContext(type: PageContext::TYPE_SEARCH);
        $notFound = PageContext::notFound();
        $blog = new PageContext(type: PageContext::TYPE_BLOG);
        $archive = new PageContext(type: PageContext::TYPE_ARCHIVE, contentType: $types->get('post'));
        $score = static fn (PageContext $page, string $type, string $value = ''): ?int => Conditions::specificity($page, [['type' => $type, 'value' => $value]], []);

        self::assertSame(10, $score($entryPage, 'all'));
        self::assertSame(100, $score($entryPage, 'entry', '42'));
        self::assertNull($score($entryPage, 'entry', '43'));
        self::assertSame(60, $score($entryPage, 'content_type', 'post'));
        self::assertSame(60, $score($archive, 'content_type', 'post'));
        self::assertNull($score($entryPage, 'content_type', 'page'));
        self::assertSame(80, $score($entryPage, 'taxonomy', 'category'));
        self::assertSame(80, $score($termPage, 'taxonomy', 'category'));
        self::assertSame(90, $score($entryPage, 'term', 'category:7'));
        self::assertSame(90, $score($termPage, 'term', 'category:7'));
        self::assertNull($score($termPage, 'term', 'category:8'));
        self::assertNull($score($entryPage, 'taxonomy', 'tag'));
        self::assertSame(70, $score($front, 'front'));
        self::assertSame(50, $score($front, 'archive'));
        self::assertSame(50, $score($blog, 'archive'));
        self::assertSame(50, $score($termPage, 'archive'));
        self::assertSame(50, $score($archive, 'archive'));
        self::assertNull($score($search, 'archive'));
        self::assertSame(70, $score($search, 'search'));
        self::assertSame(70, $score($notFound, 'not_found'));
        self::assertNull($score($entryPage, 'not_found'));
        self::assertSame(100, Conditions::specificity($entryPage, [['type' => 'all', 'value' => ''], ['type' => 'entry', 'value' => '42']], []));
        self::assertNull(Conditions::specificity($entryPage, [['type' => 'entry', 'value' => '42']], [['type' => 'all', 'value' => '']]));
        self::assertNull(Conditions::specificity($entryPage, [], []));
        self::assertTrue(Models::supports('single', $entryPage));
        self::assertTrue(Models::supports('archive', $termPage));
        self::assertFalse(Models::supports('archive', $search));
        self::assertTrue(Models::supports('search', $search));
        self::assertTrue(Models::supports('not_found', $notFound));
        self::assertTrue(Models::supports('header', PageContext::plugin('/test', 'Test', '')));
        self::assertTrue(Models::supports('popup', PageContext::plugin('/test', 'Test', '')));
        self::assertTrue(Models::supports('popup', $entryPage));
        self::assertFalse(Models::supports('single', PageContext::plugin('/test', 'Test', '')));
        self::assertFalse(Models::supports('single', PageContext::redirect('/old', '/new')));
        self::assertFalse(Models::supports('popup', PageContext::redirect('/old', '/new')));
    }

    public function testMalformedRulesNeverBecomeGlobalAndDuplicatesAreRemoved(): void
    {
        self::assertSame([['type' => 'entry', 'value' => '42']], Conditions::normalize([['type' => 'entry', 'value' => '42'], ['type' => 'entry', 'value' => '42']]));
        foreach ([
            null, ['type' => 'all'], [['type' => 'unknown', 'value' => '']], [['type' => 'all', 'value' => 'post']],
            [['type' => 'entry', 'value' => '0']], [['type' => 'entry', 'value' => '01']], [['type' => 'entry', 'value' => '9999999999999999999']],
            [['type' => 'term', 'value' => 'category:0']], [['type' => 'term', 'value' => '../:7']],
            [['type' => 'content_type', 'value' => 'Page']], array_fill(0, 101, ['type' => 'all', 'value' => '']),
        ] as $invalid) {
            self::assertNull(Conditions::normalize($invalid));
        }
    }
}
