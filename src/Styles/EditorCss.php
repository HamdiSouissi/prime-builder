<?php

declare(strict_types=1);

namespace PrimeBuilder\Styles;

/** A deliberately small CSS palette for the builder UI, not arbitrary site CSS. */
final class EditorCss
{
    private const VARIABLES = ['--pb-brand', '--pb-accent', '--pb-border', '--pb-muted'];
    private const MAX_BYTES = 512;

    /** Return canonical declarations, or null if any part is unsafe or unsupported. */
    public static function normalize(mixed $input): ?string
    {
        if (!is_string($input) || strlen($input) > self::MAX_BYTES) {
            return null;
        }
        $input = trim($input);
        if ($input === '') {
            return '';
        }
        $parts = explode(';', $input);
        if (trim((string) end($parts)) === '') {
            array_pop($parts);
        }
        $declarations = [];
        foreach ($parts as $part) {
            if (preg_match('/^\s*(--pb-[a-z-]+)\s*:\s*(#[a-f0-9]{3}(?:[a-f0-9]{3})?)\s*$/iD', $part, $matches) !== 1) {
                return null;
            }
            $variable = strtolower($matches[1]);
            if (!in_array($variable, self::VARIABLES, true) || isset($declarations[$variable])) {
                return null;
            }
            $declarations[$variable] = strtolower($matches[2]);
        }
        $result = [];
        foreach ($declarations as $variable => $value) {
            $result[] = $variable . ': ' . $value . ';';
        }
        return implode("\n", $result);
    }

    /** Compile again at the point of use: even corrupt stored settings cannot inject markup. */
    public static function compile(mixed $input): string
    {
        $declarations = self::normalize($input);
        return $declarations === null || $declarations === '' ? '' : ':root{' . str_replace("\n", '', $declarations) . '}';
    }
}
