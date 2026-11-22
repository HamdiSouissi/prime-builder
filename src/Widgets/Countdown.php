<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use DateTimeImmutable;
use DateTimeZone;

/** Parse a minute-precision UTC deadline without accepting PHP's date rollovers. */
final class Countdown
{
    public static function canonical(string $value): ?string
    {
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$/D', $value) !== 1 || (int) substr($value, 0, 4) < 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i') !== $value) {
            return null;
        }
        return $value . ':00Z';
    }
}
