<?php

declare(strict_types=1);

namespace PrimeBuilder\Theme;

use Prime\Site\PageContext;

/** The same explicit display rules can be reused by theme models and popups. */
final class Conditions
{
    public const TYPES = ['all', 'content_type', 'entry', 'taxonomy', 'term', 'archive', 'front', 'search', 'not_found'];
    public const MAX_RULES = 100;

    /**
     * Strictly parse persisted or submitted rules. Values are canonical strings so
     * neither malformed IDs nor unknown rules can silently become a site-wide match.
     *
     * @param mixed $rules
     * @return list<array{type: string, value: string}>|null
     */
    public static function normalize(mixed $rules): ?array
    {
        if (!is_array($rules) || !array_is_list($rules) || count($rules) > self::MAX_RULES) {
            return null;
        }
        $result = [];
        foreach ($rules as $rule) {
            if (!is_array($rule) || !is_string($rule['type'] ?? null) || !in_array($rule['type'], self::TYPES, true)
                || !is_string($rule['value'] ?? null)) {
                return null;
            }
            $type = $rule['type'];
            $value = $rule['value'];
            $valid = match ($type) {
                'content_type', 'taxonomy' => preg_match('/^[a-z][a-z0-9_]{1,39}$/D', $value) === 1,
                'entry' => self::positiveId($value),
                'term' => preg_match('/^[a-z][a-z0-9_]{1,39}:[1-9][0-9]{0,18}$/D', $value) === 1
                    && self::positiveId(substr($value, (int) strrpos($value, ':') + 1)),
                default => $value === '',
            };
            if (!$valid) {
                return null;
            }
            $normalized = ['type' => $type, 'value' => $value];
            if (!in_array($normalized, $result, true)) {
                $result[] = $normalized;
            }
        }
        return $result;
    }

    private static function positiveId(string $id): bool
    {
        return preg_match('/^[1-9][0-9]{0,18}$/D', $id) === 1 && (string) (int) $id === $id;
    }

    /**
     * Exclusions always win within a model. Inclusions are alternatives; the
     * most specific matching inclusion is compared before numeric priority.
     *
     * @param list<array{type: string, value: string}> $include
     * @param list<array{type: string, value: string}> $exclude
     */
    public static function specificity(PageContext $page, array $include, array $exclude): ?int
    {
        foreach ($exclude as $rule) {
            if (self::match($page, $rule) !== null) {
                return null;
            }
        }
        $best = null;
        foreach ($include as $rule) {
            $score = self::match($page, $rule);
            if ($score !== null && ($best === null || $score > $best)) {
                $best = $score;
            }
        }
        return $best;
    }

    /** @param array{type: string, value: string} $rule */
    private static function match(PageContext $page, array $rule): ?int
    {
        $value = $rule['value'];
        return match ($rule['type']) {
            'all' => 10,
            'entry' => $page->entry !== null && (string) $page->entry->id === $value ? 100 : null,
            'term' => self::hasTerm($page, $value) ? 90 : null,
            'taxonomy' => self::hasTaxonomy($page, $value) ? 80 : null,
            'front' => $page->isFront() ? 70 : null,
            'search' => $page->isSearch() ? 70 : null,
            'not_found' => $page->isNotFound() ? 70 : null,
            'content_type' => ($page->entry?->type === $value || $page->contentType?->name === $value) ? 60 : null,
            'archive' => self::isArchive($page) ? 50 : null,
            default => null,
        };
    }

    public static function isArchive(PageContext $page): bool
    {
        return in_array($page->type, [PageContext::TYPE_BLOG, PageContext::TYPE_ARCHIVE, PageContext::TYPE_TERM, PageContext::TYPE_AUTHOR], true)
            || ($page->isFront() && $page->entry === null);
    }

    private static function hasTaxonomy(PageContext $page, string $taxonomy): bool
    {
        return $page->taxonomy?->name === $taxonomy || ($page->entry !== null && $page->entry->terms($taxonomy) !== []);
    }

    private static function hasTerm(PageContext $page, string $value): bool
    {
        [$taxonomy, $id] = explode(':', $value, 2);
        if ($page->term !== null && $page->term->taxonomy === $taxonomy && (string) $page->term->id === $id) {
            return true;
        }
        if ($page->entry !== null) {
            foreach ($page->entry->terms($taxonomy) as $term) {
                if ((string) $term->id === $id) {
                    return true;
                }
            }
        }
        return false;
    }
}
