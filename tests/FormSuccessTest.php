<?php

declare(strict_types=1);

namespace PrimeBuilder\Tests;

use PHPUnit\Framework\TestCase;
use PrimeBuilder\Forms\Success;

require_once dirname(__DIR__) . '/src/Forms/Success.php';

final class FormSuccessTest extends TestCase
{
    public function testOnlyBoundedPlainFeedbackAndInternalRedirectsAreAccepted(): void
    {
        self::assertSame(['message' => '', 'redirect' => ''], Success::fromSettings([]));
        self::assertSame('Merci <b>Zoé</b> !', Success::message(' Merci <b>Zoé</b> ! '));
        self::assertSame(str_repeat('é', 500), Success::message(str_repeat('é', 500)));
        foreach ([null, [], str_repeat('a', 501), "Bonjour\nBcc: x", "\xff"] as $value) {
            self::assertNull(Success::message($value));
        }
        foreach (['', '/', '/merci', '/merci?source=formulaire#recu', '/a%20b'] as $value) {
            self::assertSame($value, Success::redirect($value));
        }
        foreach ([null, [], 'merci', 'https://example.test', '//example.test', '/\\example.test', '/%2fexample.test', '/%5Cexample.test', "/\t/evil", '/a%0d', '/a%ZZ', str_repeat('/', 2049)] as $value) {
            self::assertNull(Success::redirect($value));
        }
        self::assertNull(Success::fromSettings(['form_redirect' => null]));
        self::assertNull(Success::fromSettings(['form_success_message' => "ok\r\nno"]));
    }
}
