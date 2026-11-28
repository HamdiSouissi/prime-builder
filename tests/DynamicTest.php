<?php

declare(strict_types=1);

namespace PrimeBuilder\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Prime\Auth\Role;
use Prime\Auth\User;
use PrimeBuilder\Dynamic\Conditions;
use PrimeBuilder\Dynamic\PublicFields;
use PrimeBuilder\Dynamic\Tags;
use PrimeBuilder\Widgets\Countdown;

require_once dirname(__DIR__) . '/src/Widgets/Countdown.php';
require_once dirname(__DIR__) . '/src/Dynamic/Conditions.php';
require_once dirname(__DIR__) . '/src/Dynamic/Tags.php';
require_once dirname(__DIR__) . '/src/Dynamic/PublicFields.php';
require_once dirname(__DIR__) . '/src/Theme/PageRequest.php';
require_once dirname(__DIR__) . '/src/Dynamic/RenderScope.php';

final class DynamicTest extends TestCase
{
    public function testConditionsAreBoundedConjunctiveAndUseExclusiveUtcEnd(): void
    {
        $role = new Role(2, 'editor', 'Éditeur', null, true);
        $user = new User(7, 'not-public@example.test', 'Nom public', User::STATUS_ACTIVE, 'fr', 1, null, null, null, null, null, null, [$role]);
        $rules = ['auth' => 'authenticated', 'role' => 'editor', 'device' => 'mobile', 'start' => '2026-04-01T10:30', 'end' => '2026-04-01T12:00'];
        self::assertSame($rules, Conditions::normalize($rules));
        self::assertSame([], Conditions::normalize(['auth' => 'any', 'device' => 'any', 'role' => '']));
        self::assertTrue(Conditions::matches($rules, $user, new DateTimeImmutable('2026-04-01T12:30:00+02:00')));
        self::assertFalse(Conditions::matches($rules, $user, new DateTimeImmutable('2026-04-01T10:29:59Z')));
        self::assertFalse(Conditions::matches($rules, $user, new DateTimeImmutable('2026-04-01T12:00:00Z')));
        self::assertFalse(Conditions::matches($rules, null, new DateTimeImmutable('2026-04-01T11:00:00Z')));
        self::assertTrue(Conditions::matches(['auth' => 'guest'], null));
        self::assertFalse(Conditions::matches(['auth' => 'guest'], $user));
        self::assertFalse(Conditions::matches(['role' => 'author'], $user));
        self::assertFalse(Conditions::matches(['auth' => 'guest', 'role' => 'editor'], null));
        foreach ([null, 'guest', ['guest'], ['unknown' => ''], ['auth' => 'administrator'], ['role' => '../admin'], ['device' => 'watch'],
            ['start' => '2026-02-30T00:00'], ['end' => '0000-01-01T00:00'], ['start' => '2026-05-01T10:00', 'end' => '2026-05-01T10:00'],
            ['start' => '2026-05-02T10:00', 'end' => '2026-05-01T10:00'], ['role' => 'editor', 'auth' => 'guest'],
        ] as $bad) {
            self::assertNull(Conditions::normalize($bad));
            self::assertFalse(Conditions::matches($bad, $user));
        }
        self::assertNotNull(Countdown::canonical('2026-02-28T23:59'));
    }

    public function testTagsAreClosedAndRichValuesAreEscapedWithoutRecursiveEvaluation(): void
    {
        self::assertTrue(Tags::urlToken('{{entry.url}}'));
        self::assertTrue(Tags::urlToken('{{ entry.field.photo }}'));
        foreach (['javascript:{{entry.url}}', '{{entry.url}}?x=1', '{{user.email}}', '{{entry.field._private}}', '{{entry.field.foo-bar}}'] as $bad) {
            self::assertFalse(Tags::urlToken($bad));
        }
        $resolve = static fn (string $name): string => $name === 'user.name' ? '<script>alert(1)</script> & {{entry.title}}' : 'Titre';
        self::assertSame('Avant &lt;script&gt;alert(1)&lt;/script&gt; &amp; {{entry.title}} /  après', Tags::replace('Avant {{user.name}} / {{user.email}} après', $resolve, true));
        self::assertSame('Titre / ', Tags::replace('{{entry.title}} / {{user.email}}', $resolve));
        self::assertSame(' / ', Tags::replace('{{entry.title}} / {{user.name}}', null));
    }

    public function testNestedRequestsAndZoneScopesRestoreTheirOwnContextEvenOnFailure(): void
    {
        $scope = new \PrimeBuilder\Dynamic\RenderScope();
        $requests = new \PrimeBuilder\Theme\PageRequest();
        $outer = \Prime\Http\Request::create('GET', '/outer', query: ['page' => 'outer']);
        $inner = \Prime\Http\Request::create('GET', '/inner', query: ['page' => 'inner']);
        $outerPage = new \Prime\Site\PageContext(type: \Prime\Site\PageContext::TYPE_ENTRY, path: '/outer');
        $innerPage = new \Prime\Site\PageContext(type: \Prime\Site\PageContext::TYPE_ENTRY, path: '/inner');
        self::assertNull($scope->page());
        self::assertNull($scope->request());
        self::assertNull($requests->currentPage());
        $result = $requests->process($outer, function ($request) use ($requests, $inner, $outerPage, $innerPage, $scope): \Prime\Http\Response {
            $requests->resolved($outerPage, $request);
            self::assertSame($outerPage, $requests->currentPage());
            self::assertSame(['page' => 'outer'], $requests->query($outerPage));
            self::assertNull($requests->request($innerPage));
            $scope->within($outerPage, function () use ($scope, $requests, $inner, $request, $outerPage, $innerPage): string {
                self::assertSame($outerPage, $scope->page());
                self::assertSame($request, $scope->request());
                try {
                    $requests->process($inner, function ($request) use ($requests, $innerPage, $outerPage, $scope): \Prime\Http\Response {
                        $requests->resolved($innerPage, $request);
                        self::assertSame($innerPage, $requests->currentPage());
                        self::assertSame(['page' => 'inner'], $requests->query($innerPage));
                        self::assertNull($requests->request($outerPage));
                        $scope->within($innerPage, static function () use ($scope, $innerPage, $request): string {
                            self::assertSame($innerPage, $scope->page());
                            self::assertSame($request, $scope->request());
                            throw new \RuntimeException('nested failure');
                        }, $request);
                        return \Prime\Http\Response::text('not reached');
                    });
                    self::fail('The nested error should propagate');
                } catch (\RuntimeException $exception) {
                    self::assertSame('nested failure', $exception->getMessage());
                }
                self::assertSame($outerPage, $scope->page());
                self::assertSame($request, $scope->request());
                self::assertSame($outerPage, $requests->currentPage());
                self::assertSame(['page' => 'outer'], $requests->query($outerPage));
                return '';
            }, $request);
            self::assertNull($scope->page());
            self::assertNull($scope->request());
            return \Prime\Http\Response::text('ok');
        });
        self::assertSame('ok', $result->body());
        self::assertNull($scope->page());
        self::assertNull($requests->currentPage());
        self::assertNull($requests->request($outerPage));
    }

    public function testPublicCustomFieldsRequireAnExplicitBoundedAllowlist(): void
    {
        self::assertSame([], PublicFields::parse(''));
        self::assertSame(['post.subtitle', 'page.photo'], PublicFields::parse("post.subtitle, page.photo\npost.subtitle"));
        foreach ([null, 'post._secret', 'p.field', 'Post.title', 'post.any.field', 'post.*', implode(',', array_map(static fn (int $i): string => 'post.field' . $i, range(0, 50)))] as $bad) {
            self::assertNull(PublicFields::parse($bad));
        }
    }
}
