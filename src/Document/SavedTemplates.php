<?php

declare(strict_types=1);

namespace PrimeBuilder\Document;

use Prime\Plugins\PluginSettings;
use Prime\Settings\Settings;

/** Shared template snapshots, stored independently so concurrent creations do not overwrite one another. */
final class SavedTemplates
{
    public const MAX_TEMPLATES = 50;
    public const MAX_BYTES = 131072;
    private const PREFIX = 'saved_template.';

    public function __construct(private readonly PluginSettings $pluginSettings, private readonly Settings $settings)
    {
    }

    /** @return list<array{id: string, name: string, kind: string, elements: array<mixed>}> */
    public function all(): array
    {
        $prefix = $this->pluginSettings->prefix() . self::PREFIX;
        $result = [];
        foreach ($this->settings->allByPrefix($prefix) as $key => $value) {
            $id = substr($key, strlen($prefix));
            if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1 || !is_array($value)
                || !is_string($value['name'] ?? null) || !in_array($value['kind'] ?? null, ['page', 'section'], true)
                || !is_array($value['elements'] ?? null) || !array_is_list($value['elements'])) {
                continue;
            }
            $result[] = ['id' => $id, 'name' => $value['name'], 'kind' => $value['kind'], 'elements' => $value['elements']];
        }
        usort($result, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']) ?: strcmp($a['id'], $b['id']));
        return $result;
    }

    /**
     * @param array<mixed> $elements Already normalized by Validator.
     * @return array{id: string, name: string, kind: string, elements: array<mixed>}|null
     */
    public function create(string $name, string $kind, array $elements): ?array
    {
        if (count($this->all()) >= self::MAX_TEMPLATES) {
            return null;
        }
        do {
            $id = bin2hex(random_bytes(16));
            $key = self::PREFIX . $id;
        } while ($this->pluginSettings->has($key));
        $value = ['name' => $name, 'kind' => $kind, 'elements' => $elements];
        $this->pluginSettings->set($key, $value, false);
        return ['id' => $id] + $value;
    }

    public function delete(string $id): bool
    {
        return preg_match('/^[a-f0-9]{32}$/D', $id) === 1 && $this->pluginSettings->delete(self::PREFIX . $id);
    }
}
