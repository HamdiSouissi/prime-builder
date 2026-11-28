<?php

declare(strict_types=1);

namespace PrimeBuilder\Styles;

use Prime\Database\Connection;
use Prime\Site\Zone\ZoneRepository;
use Prime\Themes\ThemeManager;

/** Builder layouts in zones declared by the active theme and saved by the CMS. */
final class ZoneLayouts
{
    public function __construct(
        private readonly ThemeManager $themes,
        private readonly ZoneRepository $zones,
        private readonly Connection $db,
    ) {
    }

    /** A saved zone can affect any page, even after its document is cleared. */
    public function hasSavedDeclaredZone(): bool
    {
        $slugs = array_keys($this->themes->zones());
        if ($slugs === []) {
            return false;
        }
        // The CMS has no zone-save event. Read fresh, before the cache lookup,
        // using its (theme, slug) index; the repository memoizes across reads.
        return $this->db->table('zones')
            ->where('theme', '=', $this->zones->activeThemeSlug())
            ->whereIn('slug', $slugs)
            ->exists();
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $layouts = [];
        foreach (array_keys($this->themes->zones()) as $slug) {
            // ZoneRepository caches its indexed read for the request and forgets
            // it after save. A theme can use a declared zone outside a widget.
            foreach ($this->zones->document($slug)['blocks'] as $block) {
                if ($block['type'] === 'prime-builder/layout') {
                    $layouts[] = $block['data'];
                }
            }
        }
        return $layouts;
    }
}
