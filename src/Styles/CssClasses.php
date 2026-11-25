<?php

declare(strict_types=1);

namespace PrimeBuilder\Styles;

/** A small class-name allowlist; this never accepts declarations or selectors. */
final class CssClasses
{
    public const MAX_BYTES = 512;
    public const MAX_CLASSES = 20;
    public const MAX_CLASS_BYTES = 64;

    /** Return a space-separated canonical value, or null when the input is unsafe. */
    public static function normalize(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > self::MAX_BYTES) {
            return null;
        }
        $value = trim($value, " \t\r\n\f");
        if ($value === '') {
            return '';
        }
        $parts = preg_split('/[ \t\r\n\f]+/', $value);
        if (!is_array($parts) || count($parts) > self::MAX_CLASSES) {
            return null;
        }
        $seen = [];
        foreach ($parts as $part) {
            if (strlen($part) > self::MAX_CLASS_BYTES
                || preg_match('/^(?:-?[_a-zA-Z]|--)[_a-zA-Z0-9-]*$/D', $part) !== 1
                || preg_match('/^(?:pb|pc)(?:[-_]|$)/iD', $part) === 1) {
                return null;
            }
            $seen[$part] = true;
        }
        return implode(' ', array_keys($seen));
    }
}
