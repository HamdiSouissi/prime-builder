<?php

declare(strict_types=1);

namespace PrimeBuilder;

use Prime\Plugins\PluginSettings;

/** Read the atomic settings bundle, falling back to individually stored pre-bundle values. */
final class Configuration
{
    public static function get(PluginSettings $settings, string $key, mixed $default = null): mixed
    {
        $bundle = $settings->get('configuration');
        if (is_array($bundle) && array_key_exists($key, $bundle)) {
            return $bundle[$key];
        }

        return $settings->get($key, $default);
    }

    /** @param array<string, bool|int|string> $values */
    public static function save(PluginSettings $settings, array $values): void
    {
        // A single CMS setting is one SQL upsert: listeners see either the old or the new bundle,
        // rather than several settings.updated events inside a multi-row transaction.
        $settings->set('configuration', $values);
    }
}
