<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

/** Parse a small, explicit list of core term IDs for a public content query. */
final class TermIds
{
    /** @return list<int>|null */
    public static function parse(string $input): ?array
    {
        if (trim($input) === '') {
            return [];
        }
        $parts = explode(',', $input);
        if (count($parts) > 10) {
            return null;
        }
        $ids = [];
        foreach ($parts as $part) {
            $value = trim($part);
            if (preg_match('/^[1-9][0-9]{0,18}$/D', $value) !== 1) {
                return null;
            }
            $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!is_int($number)) {
                return null;
            }
            $ids[$number] = $number;
        }
        return array_values($ids);
    }
}
