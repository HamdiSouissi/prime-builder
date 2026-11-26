<?php

declare(strict_types=1);

namespace PrimeBuilder\Tests;

use PHPUnit\Framework\TestCase;
use PrimeBuilder\Forms\NotificationSettings;

require_once dirname(__DIR__) . '/src/Forms/FileInput.php';
require_once dirname(__DIR__) . '/src/Forms/Fields.php';
require_once dirname(__DIR__) . '/src/Forms/NotificationSettings.php';

final class FormNotificationTest extends TestCase
{
    public function testOneAddressOnlyAndNoHeaderInjection(): void
    {
        self::assertSame('', NotificationSettings::address('  '));
        self::assertSame('admin@example.test', NotificationSettings::address(' admin@example.test '));
        foreach ([null, [], 'bad', 'one@example.test,two@example.test', 'one@example.test;two@example.test',
            'Name <one@example.test>', '"a,b"@example.test', 'a(comment)@example.test', "a@example.test\r\nBcc: victim@example.test",
            "\na@example.test", str_repeat('a', 250) . '@example.test', "\xff@example.test"] as $invalid) {
            self::assertNull(NotificationSettings::address($invalid));
        }
    }

    public function testActionsAreExplicitAndReceiptsRequireAnEmailField(): void
    {
        self::assertSame(['admin' => false, 'receipt' => false], NotificationSettings::fromSettings([]));
        self::assertSame(['admin' => true, 'receipt' => true], NotificationSettings::fromSettings(['form_admin_notice' => 'send', 'form_receipt' => 'send']));
        self::assertSame(['admin' => false, 'receipt' => false], NotificationSettings::fromSettings(['form_admin_notice' => 'none', 'form_receipt' => 'none']));
        foreach ([['form_admin_notice' => 'yes'], ['form_receipt' => true], ['form_receipt' => null], ['form_receipt' => 'send', 'form_fields' => 'invalid']] as $invalid) {
            self::assertNull(NotificationSettings::fromSettings($invalid));
        }
        $field = ['id' => 'f_a', 'type' => 'text', 'label' => 'Nom', 'required' => true, 'step' => 1, 'options' => [], 'value' => ''];
        $encode = static fn (array $fields): string => (string) json_encode($fields);
        self::assertNull(NotificationSettings::fromSettings(['form_receipt' => 'send', 'form_fields' => $encode([$field])]));
        $field['type'] = 'email';
        self::assertSame(['admin' => false, 'receipt' => true], NotificationSettings::fromSettings(['form_receipt' => 'send', 'form_fields' => $encode([$field])]));
    }
}
