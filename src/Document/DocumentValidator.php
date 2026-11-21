<?php

declare(strict_types=1);

namespace PrimeBuilder\Document;

use Prime\Security\HtmlSanitizer;

final class DocumentValidator
{
    public const MAX_ELEMENTS = 500;
    public const MAX_DEPTH = 10;
    public const MAX_CUSTOM_CSS_LENGTH = 8192;

    /**
     * @param list<string> $allowedWidgets
     */
    public function __construct(
        private readonly array $allowedWidgets,
        private readonly bool $allowUnfilteredHtml = false,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array{data: array<string, mixed>, errors: list<string>}
     */
    public function validate(array $data, HtmlSanitizer $sanitizer): array
    {
        $errors = [];
        $version = (int) ($data['version'] ?? 1);
        if ($version !== 1) {
            $errors[] = 'Unsupported Prime Builder document version.';
        }

        $rawElements = $data['elements'] ?? [];
        if (!is_array($rawElements)) {
            return [
                'data' => ['version' => 1, 'elements' => []],
                'errors' => ['Elements must be an array.'],
            ];
        }

        $count = 0;
        $elements = $this->validateElements($rawElements, $sanitizer, 0, $count, $errors);

        return [
            'data' => [
                'version' => 1,
                'elements' => $elements,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<mixed> $rawElements
     * @param list<string> $errors
     * @return list<array<string, mixed>>
     */
    private function validateElements(
        array $rawElements,
        HtmlSanitizer $sanitizer,
        int $depth,
        int &$count,
        array &$errors,
    ): array {
        if ($depth > self::MAX_DEPTH) {
            $errors[] = 'Maximum container nesting depth exceeded.';

            return [];
        }

        $result = [];
        foreach ($rawElements as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            ++$count;
            if ($count > self::MAX_ELEMENTS) {
                $errors[] = 'Maximum element count exceeded.';

                break;
            }

            $id = $this->sanitizeId((string) ($raw['id'] ?? ''));
            $elType = (string) ($raw['elType'] ?? 'widget');
            if (!in_array($elType, ['container', 'widget'], true)) {
                $elType = 'widget';
            }

            $widgetType = null;
            if ($elType === 'widget') {
                $widgetType = (string) ($raw['widgetType'] ?? '');
                if ($widgetType === '' || !in_array($widgetType, $this->allowedWidgets, true)) {
                    $errors[] = sprintf('Unknown widget type: %s', $widgetType !== '' ? $widgetType : '(empty)');

                    continue;
                }
            }

            $settings = is_array($raw['settings'] ?? null) ? $raw['settings'] : [];
            $cleanSettings = $this->sanitizeSettings($settings, $elType, $widgetType, $sanitizer);

            $children = [];
            if ($elType === 'container' && is_array($raw['elements'] ?? null)) {
                $children = $this->validateElements($raw['elements'], $sanitizer, $depth + 1, $count, $errors);
            }

            $item = [
                'id' => $id,
                'elType' => $elType,
                'settings' => $cleanSettings,
            ];
            if ($widgetType !== null) {
                $item['widgetType'] = $widgetType;
            }
            if ($elType === 'container') {
                $item['elements'] = $children;
            }

            $result[] = $item;
        }

        return $result;
    }

    private function sanitizeId(string $id): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', $id) ?? '';
        if ($clean === '') {
            return 'el_' . substr(md5(uniqid('', true)), 0, 8);
        }

        return substr($clean, 0, 40);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function sanitizeSettings(
        array $settings,
        string $elType,
        ?string $widgetType,
        HtmlSanitizer $sanitizer,
    ): array {
        $clean = [];
        foreach ($settings as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $cleanKey = preg_replace('/[^a-zA-Z0-9_.-]/', '', $key) ?? '';
            if ($cleanKey === '') {
                continue;
            }

            if ($cleanKey === 'custom_css') {
                if (is_string($value)) {
                    $clean[$cleanKey] = self::sanitizeCustomCss($value);
                }

                continue;
            }

            if ($cleanKey === 'html' && $widgetType === 'html' && is_string($value)) {
                $clean[$cleanKey] = $this->allowUnfilteredHtml ? $value : $sanitizer->sanitizeBasic($value);

                continue;
            }

            if (in_array($cleanKey, ['text', 'content', 'description', 'caption', 'quote'], true) && is_string($value)) {
                $clean[$cleanKey] = $sanitizer->sanitizeBasic($value);

                continue;
            }

            if (in_array($cleanKey, ['url', 'link', 'href', 'src', 'poster_url', 'redirect_url'], true) && is_string($value)) {
                $clean[$cleanKey] = self::sanitizeUrl($value);

                continue;
            }

            if (is_array($value)) {
                $clean[$cleanKey] = $this->sanitizeNestedValue($value, $sanitizer);

                continue;
            }

            if (is_string($value)) {
                $clean[$cleanKey] = mb_substr($value, 0, 20000);
            } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $clean[$cleanKey] = $value;
            }
        }

        return $clean;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function sanitizeNestedValue(array $value, HtmlSanitizer $sanitizer): array
    {
        $out = [];
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $out[$k] = $this->sanitizeNestedValue($v, $sanitizer);
            } elseif (is_string($v)) {
                if (in_array((string) $k, ['url', 'link', 'href', 'src'], true)) {
                    $out[$k] = self::sanitizeUrl($v);
                } elseif (in_array((string) $k, ['text', 'content', 'description'], true)) {
                    $out[$k] = $sanitizer->sanitizeBasic($v);
                } else {
                    $out[$k] = mb_substr($v, 0, 10000);
                }
            } elseif (is_int($v) || is_float($v) || is_bool($v) || $v === null) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    public static function sanitizeUrl(string $url): string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return '';
        }
        if (str_starts_with($trimmed, '/') || str_starts_with($trimmed, '#')) {
            return $trimmed;
        }
        if (preg_match('/^(https?|mailto|tel):/i', $trimmed) === 1) {
            return $trimmed;
        }

        return '';
    }

    public static function sanitizeCustomCss(string $css): string
    {
        $css = mb_substr($css, 0, self::MAX_CUSTOM_CSS_LENGTH);
        $css = preg_replace('/<\/?style[^>]*>/i', '', $css) ?? '';
        $css = preg_replace('/@import\b[^;]*;?/i', '', $css) ?? '';
        $css = preg_replace('/expression\s*\([^)]*\)/i', '', $css) ?? '';
        $css = preg_replace('/url\s*\(\s*[\'"]?\s*(?:javascript|vbscript|data):[^)]*\)/i', 'url()', $css) ?? '';

        return trim($css);
    }
}
