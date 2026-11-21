<?php

declare(strict_types=1);

namespace PrimeBuilder\Styles;

/** The same small CSS value allowlist is used for validation and compilation. */
final class Values
{
    public static function length(mixed $value, bool $width = false): ?string
    {
        if (is_int($value)) {
            return $value >= 0 && $value <= 2000 ? $value . 'px' : null;
        }
        if (!is_string($value) || preg_match('/^(\d{1,4}(?:\.\d{1,2})?)(px|%|em|rem|vw|vh)$/D', $value, $matches) !== 1) {
            return null;
        }
        $number = (float) $matches[1];
        if ($number > 2000 || ($width && $matches[2] === '%' && $number > 100)) {
            return null;
        }
        return $value;
    }

    public static function color(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        return match ($value) {
            'kit:primary' => 'var(--pb-kit-primary)',
            'kit:text' => 'var(--pb-kit-text)',
            default => preg_match('/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/D', $value) === 1 ? strtolower($value) : null,
        };
    }
}
