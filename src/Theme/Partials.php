<?php

declare(strict_types=1);

namespace PrimeBuilder\Theme;

use Prime\Editor\BlockRegistry;
use Prime\Hooks\Hooks;
use Prime\Plugins\PluginContext;
use Prime\Security\HtmlSanitizer;
use Prime\Site\PageContext;
use PrimeBuilder\Document\HtmlContent;
use PrimeBuilder\Document\Validator;
use PrimeBuilder\Widgets\Registry;

/** Validated theme layouts, including the original header/footer snapshots. */
final class Partials
{
    public const SETTING = 'theme_partials';
    private readonly Models $models;

    public function __construct(private readonly PluginContext $plugin)
    {
        $this->models = new Models($plugin->settings(), $plugin->app()->get(\Prime\Settings\Settings::class), $plugin);
    }

    /**
     * @return array{header: array<string, mixed>|null, footer: array<string, mixed>|null, exclude: list<int>}
     */
    public function stored(): array
    {
        $value = $this->plugin->settings()->get(self::SETTING, []);
        if (!is_array($value)) {
            $value = [];
        }
        $result = ['header' => null, 'footer' => null, 'exclude' => []];
        foreach (['header', 'footer'] as $slot) {
            $item = $value[$slot] ?? null;
            if (is_array($item) && is_string($item['id'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $item['id']) === 1
                && is_string($item['name'] ?? null) && strlen($item['name']) <= 320
                && is_array($item['elements'] ?? null) && array_is_list($item['elements'])) {
                $result[$slot] = ['id' => $item['id'], 'name' => $item['name'], 'elements' => $item['elements']];
            }
        }
        foreach (is_array($value['exclude'] ?? null) ? $value['exclude'] : [] as $id) {
            if (is_int($id) && $id > 0 && !in_array($id, $result['exclude'], true) && count($result['exclude']) < 100) {
                $result['exclude'][] = $id;
            }
        }
        return $result;
    }

    /**
     * Revalidate stored data as well as editor input. Historical snapshots keep
     * their original restrictions; new models can resolve more widgets against
     * the current public page. Forms and entry-bound original fragments cannot
     * be reused as theme documents without a suitable independent identity.
     *
     * @param array<mixed> $elements
     * @return array<string, mixed>|null
     */
    public function normalize(array $elements, bool $allowEmpty = false, bool $contextual = false): ?array
    {
        $validator = new Validator(
            new Registry($this->plugin->app()->get(Hooks::class)),
            $this->plugin->app()->get(HtmlSanitizer::class),
            $this->plugin->app()->get(BlockRegistry::class),
        );
        $result = $validator->validate(['version' => 1, 'elements' => $elements]);
        $normalized = $result['data']['elements'] ?? null;
        $anchors = [];
        if ($result['errors'] !== [] || !is_array($normalized) || (!$allowEmpty && $normalized === []) || self::unsupported($normalized, $anchors, $contextual)) {
            return null;
        }
        return HtmlContent::withoutSeals($result['data']);
    }

    /**
     * Return a validated, namespaced layout only when the slot applies to this page.
     *
     * @return array<string, mixed>|null
     */
    public function layout(string $slot, PageContext $page): ?array
    {
        if (!in_array($slot, ['header', 'footer'], true)) {
            return null;
        }
        $model = $this->modelLayout($slot, $page);
        if ($model !== null) {
            return $model;
        }
        $stored = $this->stored();
        if ($page->entry !== null && in_array($page->entry->id, $stored['exclude'], true)) {
            return null;
        }
        $item = $stored[$slot];
        if (!is_array($item) || !is_array($item['elements'] ?? null)) {
            return null;
        }
        $layout = $this->normalize($item['elements']);
        return $layout === null ? null : $this->plugin->app()->get(GlobalWidgets::class)->expand(self::namespaceLayout($layout, $slot));
    }

    /** The best valid model wins; a damaged model cannot hide a valid fallback.
     *
     * @return array<string, mixed>|null
     */
    public function modelLayout(string $kind, PageContext $page): ?array
    {
        foreach ($this->models->matching($kind, $page) as $model) {
            $elements = $model['elements'] ?? null;
            if (!is_array($elements)) {
                continue;
            }
            $layout = $this->models->validatedLayout($model);
            if ($layout !== null) {
                return $this->plugin->app()->get(GlobalWidgets::class)->expand(self::namespaceLayout($layout, $kind));
            }
        }
        return null;
    }

    /** A full-page model is selected according to the resolved CMS context.
     *
     * @return array<string, mixed>|null
     */
    public function pageLayout(PageContext $page): ?array
    {
        foreach (['single', 'archive', 'search', 'not_found'] as $kind) {
            $layout = $this->modelLayout($kind, $page);
            if ($layout !== null) {
                return $layout;
            }
        }
        return null;
    }

    /** Layouts for content-addressed CSS recovery when snapshot storage is unavailable.
     *
     * @return list<array<string, mixed>>
     */
    public function modelLayouts(): array
    {
        $layouts = [];
        foreach ($this->models->all() as $model) {
            $elements = $model['elements'] ?? null;
            $kind = $model['kind'] ?? null;
            if (!is_array($elements) || !is_string($kind)) {
                continue;
            }
            $layout = $this->models->validatedLayout($model);
            if ($layout !== null && $kind !== 'global') {
                $layouts[] = $this->plugin->app()->get(GlobalWidgets::class)->expand(self::namespaceLayout($layout, self::modelSlot($model)));
            }
        }
        return $layouts;
    }

    public function hasEnabledModels(): bool
    {
        return $this->models->hasEnabled();
    }

    public function invalidate(): void
    {
        $this->models->invalidate();
    }

    /**
     * @param array<mixed> $elements
     * @param array<string, true> $anchors
     */
    private static function unsupported(array $elements, array &$anchors, bool $contextual): bool
    {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }
            $type = $element['type'] ?? null;
            $forbidden = $contextual ? ['form', 'original_content', 'original_group'] : ['posts', 'current_content', 'theme_zone', 'form'];
            if (in_array($type, $forbidden, true)) {
                return true;
            }
            $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];
            $anchor = $type === 'anchor' ? ($settings['anchor'] ?? null) : ($type === 'prime_block' ? ($settings['block_anchor'] ?? null) : null);
            if (is_string($anchor) && $anchor !== '') {
                if (isset($anchors[$anchor])) {
                    return true;
                }
                $anchors[$anchor] = true;
            }
            if (is_array($element['children'] ?? null) && self::unsupported($element['children'], $anchors, $contextual)) {
                return true;
            }
        }
        return false;
    }

    /** Whether either stored theme snapshot uses a widget, including nested ones. */
    public function usesWidget(string $type): bool
    {
        return $this->usesAnyWidget([$type]);
    }

    /** @param list<string> $types */
    public function usesAnyWidget(array $types): bool
    {
        $wanted = array_fill_keys($types, true);
        $contains = static function (array $elements) use (&$contains, $wanted): bool {
            foreach ($elements as $element) {
                if (!is_array($element)) {
                    continue;
                }
                $type = $element['type'] ?? null;
                if (is_string($type) && isset($wanted[$type])) {
                    return true;
                }
                if (is_array($element['children'] ?? null) && $contains($element['children'])) {
                    return true;
                }
            }
            return false;
        };
        $stored = $this->stored();
        foreach (['header', 'footer'] as $slot) {
            $elements = $stored[$slot]['elements'] ?? [];
            if (is_array($elements) && $contains($elements)) {
                return true;
            }
        }
        foreach ($this->models->all() as $model) {
            $elements = $model['elements'] ?? [];
            if (($model['enabled'] ?? false) === true && is_array($elements) && $contains($elements)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $model */
    public static function modelSlot(array $model): string
    {
        $kind = is_string($model['kind'] ?? null) ? $model['kind'] : '';
        // Several popups may match one page, so their widget IDs, anchors and
        // styles must remain independent even when their source IDs are equal.
        return $kind === 'popup' ? 'popup_' . substr(hash('sha256', (string) ($model['id'] ?? '')), 0, 16) : $kind;
    }

    /**
     * Widget IDs must not collide with the page or the other theme slot. Built-in
     * anchors and links to them are also namespaced within the chosen template.
     *
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    public static function namespaceLayout(array $layout, string $slot): array
    {
        $anchors = [];
        $collect = static function (array $elements) use (&$collect, &$anchors, $slot): void {
            foreach ($elements as $element) {
                if (!is_array($element)) {
                    continue;
                }
                $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];
                $type = $element['type'] ?? null;
                $anchor = $type === 'anchor' ? ($settings['anchor'] ?? null) : ($type === 'prime_block' ? ($settings['block_anchor'] ?? null) : null);
                if (is_string($anchor) && $anchor !== '') {
                    $anchors[$anchor] = 'pbt-' . $slot . '-' . substr(hash('sha256', $anchor), 0, 20);
                }
                if (is_array($element['children'] ?? null)) {
                    $collect($element['children']);
                }
            }
        };
        $collect(is_array($layout['elements'] ?? null) ? $layout['elements'] : []);
        $walk = static function (array $elements) use (&$walk, $slot, $anchors): array {
            foreach ($elements as $index => $element) {
                if (!is_array($element)) {
                    continue;
                }
                $id = is_string($element['id'] ?? null) ? $element['id'] : '';
                $element['id'] = 'pbt_' . $slot . '_' . substr(hash('sha256', $id), 0, 24);
                $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];
                foreach (['anchor', 'block_anchor'] as $key) {
                    if (is_string($settings[$key] ?? null) && isset($anchors[$settings[$key]])) {
                        $settings[$key] = $anchors[$settings[$key]];
                    }
                }
                if (is_string($settings['url'] ?? null) && str_starts_with($settings['url'], '#')) {
                    $anchor = substr($settings['url'], 1);
                    if (isset($anchors[$anchor])) {
                        $settings['url'] = '#' . $anchors[$anchor];
                    }
                }
                $element['settings'] = $settings;
                if (is_array($element['children'] ?? null)) {
                    $element['children'] = $walk($element['children']);
                }
                $elements[$index] = $element;
            }
            return $elements;
        };
        $layout['elements'] = $walk(is_array($layout['elements'] ?? null) ? $layout['elements'] : []);
        return $layout;
    }
}
