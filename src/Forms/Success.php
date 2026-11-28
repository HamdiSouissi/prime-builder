<?php

declare(strict_types=1);

namespace PrimeBuilder\Forms;

/** Published, plain-text post-submit feedback and an optional same-origin path. */
final class Success
{
    public const MAX_MESSAGE_CHARS = 500;
    public const MAX_MESSAGE_BYTES = 2000;
    public const MAX_REDIRECT_BYTES = 2048;

    public static function message(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > self::MAX_MESSAGE_BYTES || !mb_check_encoding($value, 'UTF-8')
            || mb_strlen($value, 'UTF-8') > self::MAX_MESSAGE_CHARS || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return null;
        }
        return trim($value);
    }

    public static function redirect(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > self::MAX_REDIRECT_BYTES || !mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        if ($value === '') {
            return '';
        }
        // Reject network paths, backslashes and their encoded variants, controls,
        // and malformed percent escapes. The browser repeats the origin check.
        if (!str_starts_with($value, '/') || str_starts_with($value, '//')
            || preg_match('/[\x00-\x20\x7f\\\\]/', $value) === 1
            || preg_match('/%(?:2f|5c|0[0-9a-f]|1[0-9a-f]|7f)/i', $value) === 1
            || preg_match('/%(?![0-9a-f]{2})/i', $value) === 1) {
            return null;
        }
        $parts = parse_url($value);
        if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
            return null;
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{message: string, redirect: string}|null
     */
    public static function fromSettings(array $settings): ?array
    {
        $message = self::message(array_key_exists('form_success_message', $settings) ? $settings['form_success_message'] : '');
        $redirect = self::redirect(array_key_exists('form_redirect', $settings) ? $settings['form_redirect'] : '');
        return $message !== null && $redirect !== null ? ['message' => $message, 'redirect' => $redirect] : null;
    }
}
