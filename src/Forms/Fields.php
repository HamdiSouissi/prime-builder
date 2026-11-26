<?php

declare(strict_types=1);

namespace PrimeBuilder\Forms;

/** Canonical, bounded definitions and strict server-side validation for custom fields. */
final class Fields
{
    public const MAX_FIELDS = 24;
    public const MAX_STEPS = 5;
    public const MAX_BYTES = 20000;
    public const TYPES = ['text', 'email', 'phone', 'number', 'textarea', 'select', 'checkbox', 'radio', 'date', 'acceptance', 'hidden'];
    private const CHOICES = ['select', 'checkbox', 'radio'];

    /**
     * @return list<array{id: string, type: string, label: string, required: bool, step: int, options: list<string>, value: string}>|null
     */
    public static function parse(string $json): ?array
    {
        if (strlen($json) > self::MAX_BYTES || !mb_check_encoding($json, 'UTF-8')) {
            return null;
        }
        $raw = json_decode($json, true);
        if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > self::MAX_FIELDS) {
            return null;
        }
        $fields = [];
        $seen = [];
        $visibleSteps = [];
        $maxStep = 0;
        foreach ($raw as $field) {
            if (!is_array($field) || count($field) !== 7
                || array_diff(array_keys($field), ['id', 'type', 'label', 'required', 'step', 'options', 'value']) !== []) {
                return null;
            }
            $id = $field['id'] ?? null;
            $type = $field['type'] ?? null;
            $label = $field['label'] ?? null;
            $required = $field['required'] ?? null;
            $step = $field['step'] ?? null;
            $options = $field['options'] ?? null;
            $value = $field['value'] ?? null;
            if (!is_string($id) || preg_match('/^f_[a-z0-9]{1,24}$/D', $id) !== 1 || isset($seen[$id])
                || !is_string($type) || !in_array($type, self::TYPES, true)
                || !is_string($label) || !self::singleLine($label, 100, false)
                || !is_bool($required) || !is_int($step) || $step < 1 || $step > self::MAX_STEPS
                || !is_array($options) || !array_is_list($options) || count($options) > 20
                || !is_string($value) || !self::singleLine($value, 160, true)) {
                return null;
            }
            $label = trim($label);
            $value = trim($value);
            $choices = [];
            foreach ($options as $option) {
                if (!is_string($option) || !self::singleLine($option, 100, false)) {
                    return null;
                }
                $option = trim($option);
                if (in_array($option, $choices, true)) {
                    return null;
                }
                $choices[] = $option;
            }
            if ((in_array($type, self::CHOICES, true) && $choices === [])
                || (!in_array($type, self::CHOICES, true) && $choices !== [])
                || ($type !== 'hidden' && $value !== '')
                || ($type === 'hidden' && $required)) {
                return null;
            }
            $seen[$id] = true;
            $maxStep = max($maxStep, $step);
            if ($type !== 'hidden') {
                $visibleSteps[$step] = true;
            }
            $fields[] = ['id' => $id, 'type' => $type, 'label' => $label, 'required' => $required, 'step' => $step, 'options' => $choices, 'value' => $value];
        }
        for ($step = 1; $step <= $maxStep; $step++) {
            if (!isset($visibleSteps[$step])) {
                return null;
            }
        }
        return $fields;
    }

    public static function canonical(string $json): ?string
    {
        $fields = self::parse($json);
        if ($fields === null) {
            return null;
        }
        $encoded = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) && strlen($encoded) <= self::MAX_BYTES ? $encoded : null;
    }

    /**
     * Validate exactly the published schema; unknown/missing fields are rejected.
     *
     * @param list<array{id: string, type: string, label: string, required: bool, step: int, options: list<string>, value: string}> $fields
     * @return array{name: string, email: string, message: string}|null
     */
    public static function submitted(array $fields, mixed $input): ?array
    {
        if (!is_array($input) || count($input) !== count($fields)) {
            return null;
        }
        $name = '';
        $email = '';
        $lines = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field['id'], $input)) {
                return null;
            }
            $raw = $input[$field['id']];
            $type = $field['type'];
            if ($type === 'checkbox') {
                if (!is_array($raw) || !array_is_list($raw) || count($raw) > count($field['options'])) {
                    return null;
                }
                $selected = [];
                foreach ($raw as $choice) {
                    if (!is_string($choice) || !in_array($choice, $field['options'], true) || in_array($choice, $selected, true)) {
                        return null;
                    }
                    $selected[] = $choice;
                }
                if ($field['required'] && $selected === []) {
                    return null;
                }
                $display = implode(', ', $selected);
            } elseif ($type === 'acceptance') {
                if (!is_bool($raw) || ($field['required'] && !$raw)) {
                    return null;
                }
                $display = $raw ? 'yes' : 'no';
            } else {
                if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')) {
                    return null;
                }
                $display = trim(str_replace(["\r\n", "\r"], "\n", $raw));
                if ($type === 'hidden') {
                    if (!hash_equals($field['value'], $display)) {
                        return null;
                    }
                    $display = $field['value'];
                } elseif ($field['required'] && $display === '') {
                    return null;
                }
                if (!self::validValue($type, $display, $field['options'])) {
                    return null;
                }
                if ($type === 'text' && $name === '') {
                    $name = $display;
                } elseif ($type === 'email' && $email === '') {
                    $email = $display;
                }
            }
            $lines[] = $field['label'] . ': ' . $display;
        }
        $message = implode("\n\n", $lines);
        if (mb_strlen($message, 'UTF-8') > 5000 || strlen($message) > 20000) {
            return null;
        }
        return ['name' => $name, 'email' => $email, 'message' => $message];
    }

    /** @param list<string> $options */
    private static function validValue(string $type, string $value, array $options): bool
    {
        if ($value === '') {
            return true;
        }
        if ($type === 'textarea') {
            return strlen($value) <= 8000 && mb_strlen($value, 'UTF-8') <= 2000
                && preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) !== 1;
        }
        if ($type === 'text' || $type === 'hidden') {
            return self::singleLine($value, 160, true);
        }
        if ($type === 'email') {
            return strlen($value) <= 254 && preg_match('/[\x00-\x20\x7f]/', $value) !== 1 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
        }
        if ($type === 'phone') {
            return strlen($value) <= 40 && preg_match('/^[0-9+().\/ -]{3,40}$/D', $value) === 1;
        }
        if ($type === 'number') {
            return preg_match('/^-?[0-9]{1,9}(?:\.[0-9]{1,4})?$/D', $value) === 1;
        }
        if ($type === 'date') {
            return preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts) === 1
                && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
        }
        return in_array($type, ['select', 'radio'], true) && in_array($value, $options, true);
    }

    private static function singleLine(string $value, int $maximum, bool $emptyAllowed): bool
    {
        return mb_check_encoding($value, 'UTF-8') && strlen($value) <= $maximum * 4 && mb_strlen($value, 'UTF-8') <= $maximum
            && ($emptyAllowed || trim($value) !== '') && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;
    }
}
