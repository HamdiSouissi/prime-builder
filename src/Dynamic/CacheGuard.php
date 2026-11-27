<?php

declare(strict_types=1);

namespace PrimeBuilder\Dynamic;

use Prime\Plugins\PluginContext;
use PrimeBuilder\Popup\Options as PopupOptions;
use PrimeBuilder\Styles\ZoneLayouts;
use PrimeBuilder\Theme\Models;
use PrimeBuilder\Theme\Partials;
use Throwable;

/**
 * Decide before the core page-cache lookup. Conservative site-wide bypass is
 * intentional: core cannot enumerate dependencies of theme/zone/plugin pages.
 */
final class CacheGuard
{
    private readonly Models $models;

    public function __construct(
        private readonly PluginContext $plugin,
        private readonly Partials $partials,
        private readonly ZoneLayouts $zones,
    ) {
        $this->models = new Models($plugin->settings(), $plugin->app()->get(\Prime\Settings\Settings::class));
    }

    /**
     * The core cache excludes authenticated requests, but an external proxy may
     * not. A popup hidden from connected visitors must never be shared by one.
     */
    public function privateResponse(): bool
    {
        if ($this->sensitive()) {
            return true;
        }
        try {
            foreach ($this->models->all() as $model) {
                if (($model['kind'] ?? null) !== 'popup' || ($model['enabled'] ?? false) !== true) {
                    continue;
                }
                $options = PopupOptions::normalize($model['popup'] ?? null);
                if ($options === null || $options['hide_logged_in']) {
                    return true;
                }
            }
            return false;
        } catch (Throwable) {
            return true;
        }
    }

    public function sensitive(): bool
    {
        try {
            if ($this->zones->hasSavedDeclaredZone()) {
                return true;
            }
            $stored = $this->partials->stored();
            foreach (['header', 'footer'] as $slot) {
                $elements = $stored[$slot]['elements'] ?? [];
                if (is_array($elements) && Layout::uses($elements)) {
                    return true;
                }
            }
            // Includes globals: a definition can be embedded on arbitrary pages.
            // Read fresh because another request may have updated settings.
            $this->models->invalidate();
            foreach ($this->models->all() as $model) {
                $elements = $model['elements'] ?? [];
                if (is_array($elements) && Layout::uses($elements)) {
                    return true;
                }
            }
            // Entries are canonically encoded by the core. A bounded existence
            // query avoids decoding every document and errs toward false positives.
            return $this->plugin->db()->table('entries')->where(static function ($query): void {
                $query->where('content', 'LIKE', '%{{%')->orWhere('content', 'LIKE', '%"conditions"%');
            })->exists();
        } catch (Throwable) {
            // Hooks swallow exceptions; a failed check must not permit shared caching.
            return true;
        }
    }
}
