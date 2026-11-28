<?php

declare(strict_types=1);

namespace PrimeBuilder\Dynamic;

use DateTimeImmutable;
use DateTimeZone;
use Prime\Auth\User;
use PrimeBuilder\Widgets\Countdown;

/** Bounded, conjunctive per-element display rules; dates are UTC, end exclusive. */
final class Conditions
{
    /** @return array<string, string>|null */
    public static function normalize(mixed $input): ?array
    {
        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            return null;
        }
        $output = [];
        foreach ($input as $key => $value) {
            if (!is_string($key) || !in_array($key, ['auth', 'device', 'role', 'start', 'end'], true) || !is_string($value)) {
                return null;
            }
            if ($value === '') {
                continue;
            }
            $valid = match ($key) {
                'auth' => in_array($value, ['any', 'guest', 'authenticated'], true),
                'device' => in_array($value, ['any', 'desktop', 'tablet', 'mobile'], true),
                'role' => preg_match('/^[a-z][a-z0-9_-]{0,79}$/D', $value) === 1,
                'start', 'end' => Countdown::canonical($value) !== null,
            };
            if (!$valid) {
                return null;
            }
            if (!(in_array($key, ['auth', 'device'], true) && $value === 'any')) {
                $output[$key] = $value;
            }
        }
        if (isset($output['role']) && ($output['auth'] ?? '') === 'guest') {
            return null;
        }
        if (isset($output['start'], $output['end']) && $output['start'] >= $output['end']) {
            return null;
        }
        return $output;
    }

    /** Device is evaluated by responsive CSS, not a guess from User-Agent. */
    public static function matches(mixed $input, ?User $user, ?DateTimeImmutable $now = null): bool
    {
        $rules = self::normalize($input);
        if ($rules === null) {
            return false;
        }
        $authenticated = $user !== null && $user->isActive();
        if (($rules['auth'] ?? '') === 'guest' && $authenticated) {
            return false;
        }
        if (($rules['auth'] ?? '') === 'authenticated' && !$authenticated) {
            return false;
        }
        if (isset($rules['role']) && (!$authenticated || !$user->hasRole($rules['role']))) {
            return false;
        }
        $current = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i');
        return (!isset($rules['start']) || $current >= $rules['start'])
            && (!isset($rules['end']) || $current < $rules['end']);
    }
}
