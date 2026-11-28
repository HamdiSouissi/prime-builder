<?php

declare(strict_types=1);

namespace PrimeBuilder\Dynamic;

use Prime\Auth\User;

/** Prepare a runtime-only copy; neither user data nor conditional markup is stored as fallback HTML. */
final class Layout
{
    /** @param array<mixed> $elements */
    public static function uses(array $elements): bool
    {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }
            if (isset($element['conditions']) && $element['conditions'] !== []) {
                return true;
            }
            foreach (is_array($element['settings'] ?? null) ? $element['settings'] : [] as $value) {
                if (is_string($value) && str_contains($value, '{{')) {
                    return true;
                }
            }
            if (is_array($element['children'] ?? null) && self::uses($element['children'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<mixed> $elements
     * @param array<string, array<string, mixed>> $definitions
     * @param callable(string): string|null $resolve
     * @return list<array<string, mixed>>
     */
    public static function prepare(array $elements, array $definitions, bool $runtime, ?User $user, ?callable $resolve, ?RenderState $state = null): array
    {
        $output = [];
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }
            if (array_key_exists('conditions', $element)) {
                $rules = Conditions::normalize($element['conditions']);
                if ($rules !== null && (isset($rules['start']) || isset($rules['end']))) {
                    // Mark before testing visibility: a currently hidden element
                    // can become visible while an otherwise cacheable page lives.
                    $state?->markTimeDependent();
                }
                // Unknown or damaged conditions fail closed, including in direct renderer calls.
                if ($rules === null || ($rules !== [] && (!$runtime || !Conditions::matches($rules, $user)))) {
                    continue;
                }
            }
            $type = is_string($element['type'] ?? null) ? $element['type'] : '';
            $fields = is_array($definitions[$type]['fields'] ?? null) ? $definitions[$type]['fields'] : [];
            $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];
            foreach ($settings as $key => $value) {
                $schema = is_array($fields[$key] ?? null) ? $fields[$key] : [];
                $kind = $schema['type'] ?? null;
                if (!is_string($value) || !str_contains($value, '{{') || !in_array($kind, ['text', 'multiline', 'rich', 'href', 'url'], true)) {
                    continue;
                }
                // Rich replacements are text, never new HTML. URLs are filtered again at the sink.
                $settings[$key] = Tags::replace($value, $runtime ? $resolve : null, $kind === 'rich');
            }
            $element['settings'] = $settings;
            $element['children'] = self::prepare(is_array($element['children'] ?? null) ? $element['children'] : [], $definitions, $runtime, $user, $resolve, $state);
            $output[] = $element;
        }
        return $output;
    }
}
