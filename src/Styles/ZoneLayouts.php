<?php

declare(strict_types=1);

namespace PrimeBuilder\Styles;

use Prime\Site\Zone\ZoneRepository;
use Prime\Themes\ThemeManager;

/** Builder layouts in zones declared by the active theme and saved by the CMS. */
final class ZoneLayouts
{
    public function __construct(private readonly ThemeManager $themes, private readonly ZoneRepository $zones)
    {
    }

    /** A saved zone can affect any page, even after its document is cleared. */
    public function hasSavedDeclaredZone(): bool
    {
        foreach (array_keys($this->themes->zones()) as $slug) {
            if ($this->zones->find($slug) !== null) {
                return true;
            }
        }
        return false;
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
