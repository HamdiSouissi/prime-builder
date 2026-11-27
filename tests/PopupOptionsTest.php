<?php

declare(strict_types=1);

namespace PrimeBuilder\Tests;

use PHPUnit\Framework\TestCase;
use PrimeBuilder\Popup\Options;

require_once dirname(__DIR__) . '/src/Popup/Options.php';

final class PopupOptionsTest extends TestCase
{
    public function testValidTriggersFrequenciesAndBoundaries(): void
    {
        $defaults = Options::defaults();
        self::assertSame($defaults, Options::normalize($defaults));
        foreach (Options::TRIGGERS as $trigger) {
            foreach (Options::FREQUENCIES as $frequency) {
                $options = $defaults;
                $options['trigger'] = $trigger;
                $options['frequency'] = $frequency;
                $options['delay'] = 0;
                $options['scroll'] = 100;
                $options['idle'] = 1800;
                $options['days'] = 365;
                self::assertSame($options, Options::normalize($options));
                $form = array_map('strval', $options);
                unset($form['hide_logged_in']);
                self::assertSame($options, Options::fromForm($form));
                $form['hide_logged_in'] = '1';
                $options['hide_logged_in'] = true;
                self::assertSame($options, Options::fromForm($form));
            }
        }
    }

    public function testMalformedStoredAndFormValuesFailClosed(): void
    {
        $defaults = Options::defaults();
        foreach ([
            ['trigger', 'auto'], ['trigger', []], ['frequency', 'forever'], ['delay', -1], ['delay', 121],
            ['scroll', 0], ['scroll', 101], ['idle', 4], ['idle', 1801], ['days', 0], ['days', 366],
            ['days', '1'], ['days', true], ['hide_logged_in', '1'], ['hide_logged_in', null],
        ] as [$key, $bad]) {
            $invalid = $defaults;
            $invalid[$key] = $bad;
            self::assertNull(Options::normalize($invalid));
        }
        self::assertNull(Options::normalize(null));
        $legacy = $defaults;
        unset($legacy['hide_logged_in']);
        self::assertSame($defaults, Options::normalize($legacy), 'Older popup options remain readable');
        $form = array_map('strval', $defaults);
        unset($form['hide_logged_in']);
        foreach (['0', 'true', [], false] as $bad) {
            $invalid = $form;
            $invalid['hide_logged_in'] = $bad;
            self::assertNull(Options::fromForm($invalid));
        }
        foreach (['-1', '+1', '01', '1e2', '1.0', ' 1', '10000', [], null] as $bad) {
            $invalid = $form;
            $invalid['days'] = $bad;
            self::assertNull(Options::fromForm($invalid));
        }
        unset($form['scroll']);
        self::assertNull(Options::fromForm($form));
    }
}
