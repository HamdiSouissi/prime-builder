<?php

declare(strict_types=1);

namespace PrimeBuilder\Document;

/** Shipped templates are data, never executable PHP. */
final class Library
{
    public function __construct(private readonly string $directory)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $result = [];
        foreach (['sections', 'pages'] as $group) {
            $files = glob($this->directory . '/' . $group . '/*.json') ?: [];
            sort($files);
            foreach ($files as $file) {
                $raw = file_get_contents($file);
                $data = is_string($raw) ? json_decode($raw, true) : null;
                if (!is_array($data) || !is_string($data['name'] ?? null) || !is_array($data['elements'] ?? null)) {
                    continue;
                }
                $name = $data['name'];
                if (preg_match('/^[a-z][a-z-]{0,40}$/', $name) !== 1) {
                    continue;
                }
                $result[] = [
                    'name' => t('plugin::prime-builder.templates.names.' . str_replace('-', '_', $name)),
                    'kind' => $group === 'pages' ? 'page' : 'section',
                    'elements' => $this->translate($data['elements']),
                ];
            }
        }
        return $result;
    }

    private function translate(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = $this->translate($child);
            }
            return $value;
        }
        if (is_string($value) && str_starts_with($value, '@pb:')) {
            return t('plugin::prime-builder.' . substr($value, 4));
        }
        return $value;
    }
}
