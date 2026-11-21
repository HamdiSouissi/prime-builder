<?php

declare(strict_types=1);

namespace PrimeBuilder\Document;

use Prime\Editor\BlockRegistry;
use Prime\Security\HtmlSanitizer;
use PrimeBuilder\Styles\Values;
use PrimeBuilder\Widgets\OriginalContent;
use PrimeBuilder\Widgets\PrimeBlock;
use PrimeBuilder\Widgets\Registry;

/** Normalizes untrusted layout data, placing hard limits on size, depth and node count. */
final class Validator
{
    public const MAX_BYTES = 1048576;
    public const MAX_ELEMENTS = 500;
    public const MAX_DEPTH = 12;

    public function __construct(
        private readonly Registry $registry,
        private readonly HtmlSanitizer $sanitizer,
        private readonly ?BlockRegistry $blocks = null,
        private readonly ?OriginalContent $originals = null,
        private readonly ?int $entryId = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array{data: array<string, mixed>, errors: list<string>}
     */
    public function validate(array $data): array
    {
        $encoded = json_encode($data);
        if (!is_string($encoded) || strlen($encoded) > self::MAX_BYTES) {
            return ['data' => ['version' => 1, 'elements' => []], 'errors' => [t('plugin::prime-builder.validation.size')]];
        }
        $errors = [];
        if (($data['version'] ?? null) !== 1 || !isset($data['elements']) || !is_array($data['elements']) || !array_is_list($data['elements'])) {
            $errors[] = t('plugin::prime-builder.validation.structure');
        }
        $seen = [];
        $count = 0;
        $definitions = $this->registry->all();
        $elements = is_array($data['elements'] ?? null) && array_is_list($data['elements'])
            ? $this->elements($data['elements'], 0, $count, $seen, $errors, $definitions)
            : [];

        return ['data' => ['version' => 1, 'elements' => $elements], 'errors' => $errors];
    }

    /**
     * @param list<mixed> $items
     * @param array<string, true> $seen
     * @param list<string> $errors
     * @param array<string, array<string, mixed>> $definitions
     * @return list<array<string, mixed>>
     */
    private function elements(array $items, int $depth, int &$count, array &$seen, array &$errors, array $definitions): array
    {
        if ($depth > self::MAX_DEPTH) {
            $errors[] = t('plugin::prime-builder.validation.depth');
            return [];
        }
        $result = [];
        foreach ($items as $item) {
            $count++;
            if ($count > self::MAX_ELEMENTS) {
                $errors[] = t('plugin::prime-builder.validation.count');
                break;
            }
            if (!is_array($item)) {
                $errors[] = t('plugin::prime-builder.validation.structure');
                continue;
            }
            $id = $item['id'] ?? null;
            $type = $item['type'] ?? null;
            if (!is_string($id) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) !== 1 || isset($seen[$id])) {
                $errors[] = t('plugin::prime-builder.validation.id');
                continue;
            }
            $seen[$id] = true;
            if (!is_string($type) || !isset($definitions[$type])) {
                $errors[] = t('plugin::prime-builder.validation.widget');
                continue;
            }
            $definition = $definitions[$type];
            $input = $item['settings'] ?? [];
            if (!is_array($input)) {
                $errors[] = t('plugin::prime-builder.validation.settings');
                $input = [];
            }
            $settings = [];
            foreach ($input as $key => $value) {
                $schema = $definition['fields'][$key] ?? null;
                if (!is_string($key) || !is_array($schema)) {
                    $errors[] = t('plugin::prime-builder.validation.settings');
                    continue;
                }
                $normalized = $this->field($value, $schema);
                if ($normalized === null) {
                    $errors[] = t('plugin::prime-builder.validation.settings');
                    continue;
                }
                $settings[$key] = $normalized;
            }
            if ($type === 'prime_block') {
                $blockType = is_string($settings['block_type'] ?? null) ? $settings['block_type'] : 'paragraph';
                $blockData = is_string($settings['block_data'] ?? null) ? $settings['block_data'] : '{}';
                $normalizedBlock = (new PrimeBlock($this->blocks ?? new BlockRegistry(), $this->sanitizer))->normalize($blockType, $blockData);
                if ($normalizedBlock === null) {
                    $errors[] = t('plugin::prime-builder.validation.prime_block');
                } else {
                    $settings['block_type'] = $blockType;
                    $settings['block_data'] = $normalizedBlock;
                }
            }
            $isOriginal = in_array($type, ['original_content', 'original_group'], true);
            if ($isOriginal) {
                $payload = $settings['payload'] ?? null;
                $original = is_string($payload) ? $this->originals?->open($payload) : null;
                // The authenticated marker prevents relabelling a whole document as one block.
                if (count($settings) !== 1 || $original === null || $original['grouped'] !== ($type === 'original_group')) {
                    $errors[] = t('plugin::prime-builder.validation.settings');
                }
            }
            $styles = $this->styles($item['styles'] ?? [], $errors);
            if ($isOriginal && $styles !== []) {
                $errors[] = t('plugin::prime-builder.validation.styles');
            }
            $children = $item['children'] ?? [];
            if (!is_array($children) || !array_is_list($children) || (($definition['container'] ?? false) !== true && $children !== [])) {
                $errors[] = t('plugin::prime-builder.validation.structure');
                $children = [];
            }
            $element = ['id' => $id, 'type' => $type, 'settings' => $settings, 'styles' => $styles, 'children' => $this->elements($children, $depth + 1, $count, $seen, $errors, $definitions)];
            if (array_key_exists('label', $item)) {
                if (!is_string($item['label']) || strlen($item['label']) > 320) {
                    $errors[] = t('plugin::prime-builder.validation.settings');
                } else {
                    $element['label'] = trim(strip_tags($item['label']));
                }
            }
            if (array_key_exists('hidden', $item)) {
                if (!is_bool($item['hidden'])) {
                    $errors[] = t('plugin::prime-builder.validation.settings');
                } else {
                    $element['hidden'] = $item['hidden'];
                }
            }
            $result[] = $element;
        }

        return $result;
    }

    /** @param array<string, mixed> $schema */
    private function field(mixed $value, array $schema): string|int|null
    {
        $type = $schema['type'] ?? '';
        if ($type === 'integer') {
            return is_int($value) && $value >= ($schema['min'] ?? 0) && $value <= ($schema['max'] ?? 10000) ? $value : null;
        }
        if (!is_string($value) || strlen($value) > (int) ($schema['max'] ?? 1000)) {
            return null;
        }
        if ($type === 'protected') {
            $original = $this->originals?->open($value);
            return $original !== null && ($this->entryId === null || $original['entry'] === $this->entryId) ? $value : null;
        }
        return match ($type) {
            'text', 'multiline' => trim(strip_tags($value)),
            'json_object' => is_object(json_decode($value)) ? $value : null,
            'media_list' => $this->mediaList($value),
            'rich' => $this->sanitizer->sanitizeBasic($value),
            'href' => $value === '' ? '' : $this->sanitizer->sanitizeHref($value),
            'url' => $value === '' ? '' : $this->sanitizer->sanitizeResourceUrl($value),
            'slug' => $value === '' || preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/', $value) === 1 ? $value : null,
            'enum' => in_array($value, is_array($schema['values'] ?? null) ? $schema['values'] : [], true) ? $value : null,
            default => null,
        };
    }

    private function mediaList(string $value): ?string
    {
        if (trim($value) === '') {
            return '';
        }
        $lines = preg_split('/\R/u', trim($value));
        if (!is_array($lines) || count($lines) > 20) {
            return null;
        }
        $output = [];
        foreach ($lines as $line) {
            $parts = explode('|', $line, 2);
            $url = $this->sanitizer->sanitizeResourceUrl(trim($parts[0]));
            $alt = trim(strip_tags($parts[1] ?? ''));
            if ($url === null || strlen($alt) > 300) {
                return null;
            }
            $output[] = $url . '|' . $alt;
        }
        return implode("\n", $output);
    }

    /**
     * @param list<string> $errors
     * @return array<string, array<string, string|int>>
     */
    private function styles(mixed $input, array &$errors): array
    {
        if (!is_array($input)) {
            $errors[] = t('plugin::prime-builder.validation.styles');
            return [];
        }
        $output = [];
        foreach ($input as $mode => $values) {
            if (!in_array($mode, ['desktop', 'tablet', 'mobile'], true) || !is_array($values)) {
                $errors[] = t('plugin::prime-builder.validation.styles');
                continue;
            }
            foreach ($values as $key => $value) {
                $valid = match ($key) {
                    'color', 'background' => Values::color($value) !== null,
                    'padding', 'margin', 'gap', 'font_size', 'radius', 'min_height' => Values::length($value) !== null,
                    'width' => Values::length($value, true) !== null,
                    'align' => is_string($value) && in_array($value, ['left', 'center', 'right'], true),
                    'layout_mode' => is_string($value) && in_array($value, ['flex', 'grid'], true),
                    'flow' => is_string($value) && in_array($value, ['row', 'column'], true),
                    'grid_columns' => is_int($value) && $value >= 1 && $value <= 6,
                    'wrap' => is_string($value) && in_array($value, ['wrap', 'nowrap'], true),
                    'justify' => is_string($value) && in_array($value, ['start', 'center', 'end', 'space-between'], true),
                    'items_align' => is_string($value) && in_array($value, ['start', 'center', 'end', 'stretch'], true),
                    default => false,
                };
                if (!$valid) {
                    $errors[] = t('plugin::prime-builder.validation.styles');
                    continue;
                }
                $output[$mode][$key] = $value;
            }
        }
        return $output;
    }
}
