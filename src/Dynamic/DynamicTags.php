<?php

declare(strict_types=1);

namespace PrimeBuilder\Dynamic;

final class DynamicTags
{
    /**
     * @param array<string, mixed> $context
     */
    public static function resolve(string $text, array $context): string
    {
        if (!str_contains($text, '{{')) {
            return $text;
        }

        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', static function (array $m) use ($context): string {
            $tag = $m[1];
            $value = match ($tag) {
                'site.title' => (string) ($context['site_title'] ?? ''),
                'site.tagline' => (string) ($context['site_tagline'] ?? ''),
                'entry.title' => (string) ($context['entry_title'] ?? ''),
                'entry.excerpt' => (string) ($context['entry_excerpt'] ?? ''),
                'entry.date' => (string) ($context['entry_date'] ?? ''),
                'entry.author' => (string) ($context['entry_author'] ?? ''),
                'entry.url' => (string) ($context['entry_url'] ?? ''),
                'entry.featured_image' => (string) ($context['entry_featured_image'] ?? ''),
                'date.now' => date('Y-m-d'),
                'user.name' => (string) ($context['user_name'] ?? ''),
                default => self::resolveCustom($tag, $context),
            };

            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }, $text) ?? $text;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function resolveCustom(string $tag, array $context): string
    {
        if (str_starts_with($tag, 'custom.')) {
            $key = substr($tag, 7);
            $fields = is_array($context['custom_fields'] ?? null) ? $context['custom_fields'] : [];

            return (string) ($fields[$key] ?? '');
        }

        return '';
    }
}
