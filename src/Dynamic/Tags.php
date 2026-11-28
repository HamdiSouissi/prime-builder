<?php

declare(strict_types=1);

namespace PrimeBuilder\Dynamic;

/** The token vocabulary is closed; a custom field is still subject to an administrator allowlist. */
final class Tags
{
    public const PATTERN = '/\{\{\s*([a-z][a-z0-9_.]{0,150})\s*\}\}/';

    /** @var list<string> */
    public const NAMES = [
        'site.title', 'site.tagline', 'entry.title', 'entry.excerpt', 'entry.date',
        'entry.author', 'entry.featured_image', 'entry.terms', 'entry.url', 'today', 'user.name',
    ];

    public static function valid(string $name): bool
    {
        return in_array($name, self::NAMES, true)
            || preg_match('/^entry\.field\.[a-z][a-z0-9_]{0,120}$/D', $name) === 1;
    }

    /** URL controls accept one entire token, never an unchecked interpolation of a URL. */
    public static function urlToken(string $value): bool
    {
        return preg_match('/^\{\{\s*([a-z][a-z0-9_.]{0,150})\s*\}\}$/D', $value, $match) === 1
            && self::valid($match[1]);
    }

    /** @param callable(string): string|null $resolve */
    public static function replace(string $value, ?callable $resolve, bool $rich = false): string
    {
        return preg_replace_callback(self::PATTERN, static function (array $match) use ($resolve, $rich): string {
            $replacement = self::valid($match[1]) && $resolve !== null ? $resolve($match[1]) : '';
            return $rich ? htmlspecialchars($replacement, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') : $replacement;
        }, $value) ?? '';
    }
}
