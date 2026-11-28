<?php

declare(strict_types=1);

namespace PrimeBuilder\Forms;

/** Validate notification configuration and ensure a single safe mail recipient. */
final class NotificationSettings
{
    public static function address(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > 254 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        // Core's MIME builder also accepts lists: reject list separators, quoted
        // addresses, comments, controls and whitespace so this is exactly one address.
        if (preg_match('/[\x00-\x20\x7f,;"()<>\\\\]/', $value) === 1 || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{admin: bool, receipt: bool}|null
     */
    public static function fromSettings(array $settings): ?array
    {
        $admin = array_key_exists('form_admin_notice', $settings) ? $settings['form_admin_notice'] : '';
        $receipt = array_key_exists('form_receipt', $settings) ? $settings['form_receipt'] : '';
        if (!is_string($admin) || !in_array($admin, ['', 'send', 'none'], true)
            || !is_string($receipt) || !in_array($receipt, ['', 'send', 'none'], true)) {
            return null;
        }
        if ($receipt === 'send' && array_key_exists('form_fields', $settings)) {
            $fields = is_string($settings['form_fields']) ? Fields::parse($settings['form_fields']) : null;
            if ($fields === null || !in_array('email', array_column($fields, 'type'), true)) {
                return null;
            }
        }
        return ['admin' => $admin === 'send', 'receipt' => $receipt === 'send'];
    }
}
