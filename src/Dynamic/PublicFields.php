<?php

declare(strict_types=1);

namespace PrimeBuilder\Dynamic;

/** Explicit administrator opt-in: custom fields have no public flag in Prime CMS 1.0.1/1.0.2. */
final class PublicFields
{
    /** @return list<string>|null */
    public static function parse(mixed $input): ?array
    {
        if (!is_string($input) || strlen($input) > 8192) {
            return null;
        }
        if (trim($input) === '') {
            return [];
        }
        $fields = [];
        foreach (preg_split('/[\r\n,]+/', $input) ?: [] as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if (preg_match('/^[a-z][a-z0-9_]{1,39}\.[a-z][a-z0-9_]{0,120}$/D', $item) !== 1) {
                return null;
            }
            $fields[$item] = $item;
            if (count($fields) > 50) {
                return null;
            }
        }
        return array_values($fields);
    }
}
