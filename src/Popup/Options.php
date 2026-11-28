<?php

declare(strict_types=1);

namespace PrimeBuilder\Popup;

/** Strict limits apply both to persisted settings and to the administrator's form. */
final class Options
{
    public const TRIGGERS = ['click', 'load', 'scroll', 'exit', 'idle'];
    public const FREQUENCIES = ['always', 'session', 'days'];

    /** @return array{trigger: string, delay: int, scroll: int, idle: int, frequency: string, days: int, hide_logged_in: bool} */
    public static function defaults(): array
    {
        return ['trigger' => 'click', 'delay' => 3, 'scroll' => 50, 'idle' => 30, 'frequency' => 'session', 'days' => 1, 'hide_logged_in' => false];
    }

    /** @return array{trigger: string, delay: int, scroll: int, idle: int, frequency: string, days: int, hide_logged_in: bool}|null */
    public static function normalize(mixed $value): ?array
    {
        if (!is_array($value) || !in_array($value['trigger'] ?? null, self::TRIGGERS, true)
            || !in_array($value['frequency'] ?? null, self::FREQUENCIES, true)
            || (array_key_exists('hide_logged_in', $value) && !is_bool($value['hide_logged_in']))) {
            return null;
        }
        foreach (['delay' => [0, 120], 'scroll' => [1, 100], 'idle' => [5, 1800], 'days' => [1, 365]] as $key => [$min, $max]) {
            if (!is_int($value[$key] ?? null) || $value[$key] < $min || $value[$key] > $max) {
                return null;
            }
        }
        return [
            'trigger' => $value['trigger'], 'delay' => $value['delay'], 'scroll' => $value['scroll'],
            'idle' => $value['idle'], 'frequency' => $value['frequency'], 'days' => $value['days'],
            // Older popup definitions did not have this option.
            'hide_logged_in' => $value['hide_logged_in'] ?? false,
        ];
    }

    /**
     * Accept canonical decimal strings from a form, not floats, arrays, signs or exponent notation.
     *
     * @param array<string, mixed> $input
     * @return array{trigger: string, delay: int, scroll: int, idle: int, frequency: string, days: int, hide_logged_in: bool}|null
     */
    public static function fromForm(array $input): ?array
    {
        $hide = $input['hide_logged_in'] ?? null;
        if (!in_array($hide, [null, '1'], true)) {
            return null;
        }
        $input['hide_logged_in'] = $hide === '1';
        foreach (['delay', 'scroll', 'idle', 'days'] as $key) {
            $number = $input[$key] ?? null;
            if (!is_string($number) || preg_match('/^(0|[1-9][0-9]{0,3})$/D', $number) !== 1) {
                return null;
            }
            $input[$key] = (int) $number;
        }
        return self::normalize($input);
    }
}
