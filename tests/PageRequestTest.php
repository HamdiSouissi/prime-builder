<?php

declare(strict_types=1);

namespace PrimeBuilder\Tests;

use PHPUnit\Framework\TestCase;
use Prime\Content\Entry;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Site\PageContext;
use PrimeBuilder\Theme\PageRequest;

require_once dirname(__DIR__) . '/src/Theme/PageRequest.php';

final class PageRequestTest extends TestCase
{
    public function testRequestsAreIsolatedAndNestedDispatchesRestoreTheirContext(): void
    {
        $scope = new PageRequest();
        $outerPage = PageContext::plugin('/outer', 'Outer', '');
        $innerPage = PageContext::plugin('/inner', 'Inner', '');
        $scope->resolved($outerPage, Request::create('GET', '/outer', query: ['pb_page_x' => '9']));
        self::assertSame(['pb_page_x' => '9'], $scope->query($outerPage));
        self::assertSame([], $scope->query(PageContext::plugin('/outer', 'Other', '')));

        $outer = Request::create('GET', '/outer/', query: ['pb_page_x' => '2']);
        $response = $scope->process($outer, static function (Request $request) use ($scope, $outerPage, $innerPage): Response {
            self::assertSame(['pb_page_x' => '2'], $scope->query($outerPage));
            self::assertSame([], $scope->query($innerPage));
            $scope->resolved($outerPage, $request);
            $nested = $scope->process(Request::create('GET', '/inner', query: ['pb_page_x' => '3']), static function (Request $request) use ($scope, $outerPage, $innerPage): Response {
                self::assertSame([], $scope->query($outerPage));
                self::assertSame(['pb_page_x' => '3'], $scope->query($innerPage));
                $scope->resolved($innerPage, $request);
                return Response::html('nested');
            });
            self::assertSame('nested', $nested->body());
            self::assertSame(['pb_page_x' => '2'], $scope->query($outerPage));
            return Response::html('outer');
        });
        self::assertSame('outer', $response->body());
        self::assertSame([], $scope->query($outerPage), 'No context from a completed request is retained');
        self::assertSame([], $scope->query($innerPage));

        try {
            $scope->process($outer, static function () use ($scope, $outerPage): Response {
                self::assertSame(['pb_page_x' => '2'], $scope->query($outerPage));
                throw new \RuntimeException('abort');
            });
            self::fail('The handler must throw');
        } catch (\RuntimeException $error) {
            self::assertSame('abort', $error->getMessage());
        }
        self::assertSame([], $scope->query($outerPage));
    }

    public function testEntryPreviewAcceptsOnlyItsOwnAuthenticatedRoute(): void
    {
        $scope = new PageRequest();
        $entry = Entry::fromRow(['id' => 42, 'type' => 'post', 'status' => 'published', 'title' => 'Test']);
        $page = new PageContext(type: PageContext::TYPE_ENTRY, path: '/blog/test', entry: $entry, isPreview: true);
        foreach (['/admin/content/post/41/preview', '/admin/content/page/42/preview', '/admin/content/post/42/edit'] as $path) {
            $scope->process(Request::create('GET', $path, query: ['pb_page_x' => '2']), static function () use ($scope, $page): Response {
                self::assertSame([], $scope->query($page));
                return Response::html('');
            });
        }
        $scope->process(Request::create('GET', '/admin/content/post/42/preview', query: ['pb_page_x' => '2']), static function () use ($scope, $page): Response {
            self::assertSame(['pb_page_x' => '2'], $scope->query($page));
            return Response::html('');
        });
        self::assertSame([], $scope->query($page));
    }
}
