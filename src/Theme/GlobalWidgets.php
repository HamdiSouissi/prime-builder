<?php

declare(strict_types=1);

namespace PrimeBuilder\Theme;

use Prime\Editor\BlockRegistry;
use Prime\Hooks\Hooks;
use Prime\Plugins\PluginContext;
use Prime\Security\HtmlSanitizer;
use Prime\Settings\Settings;
use PrimeBuilder\Document\Validator;
use PrimeBuilder\Styles\Scope;
use PrimeBuilder\Widgets\Registry;

/** Bounded, revalidated shared widget definitions, expanded only at rendering. */
final class GlobalWidgets
{
    public const MAX_NODES = 25;
    public const MAX_REFERENCES = 20;

    // No entry-bound, privileged, recursive or context-dependent widgets. This
    // deliberately small set can be rendered identically in a page or partial.
    public const ALLOWED = [
        'container', 'heading', 'text', 'image', 'button', 'separator', 'spacer',
        'quote', 'alert', 'progress', 'list', 'accordion', 'card', 'icon_box',
        'icon', 'icon_list', 'image_box', 'testimonial', 'counter', 'tabs',
        'toggle', 'social', 'map', 'star_rating', 'gallery', 'image_carousel',
        'video', 'audio', 'call_to_action', 'price_table', 'price_list',
        'countdown', 'review', 'flip_box', 'animated_heading', 'code',
        'testimonial_carousel', 'media_carousel', 'slideshow', 'portfolio',
    ];

    private readonly Models $models;
    /** @var array<string, array<string, mixed>|null> */
    private array $resolved = [];

    public function __construct(private readonly PluginContext $plugin)
    {
        $this->models = new Models($plugin->settings(), $plugin->app()->get(Settings::class));
    }

    public function invalidate(): void
    {
        $this->models->invalidate();
        $this->resolved = [];
    }

    /**
     * A definition is one widget, which may be a container of bounded children.
     *
     * @param array<string, mixed> $layout
     */
    public static function allowed(array $layout): bool
    {
        $elements = $layout['elements'] ?? null;
        if (!is_array($elements) || !array_is_list($elements) || count($elements) > 1) {
            return false;
        }
        $pending = $elements;
        $count = 0;
        while ($pending !== []) {
            $node = array_pop($pending);
            if (!is_array($node) || !in_array($node['type'] ?? null, self::ALLOWED, true) || ++$count > self::MAX_NODES) {
                return false;
            }
            foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
                $pending[] = $child;
            }
        }
        return true;
    }

    /** @param array<mixed> $elements */
    public static function countReferences(array $elements): int
    {
        $count = 0;
        $pending = $elements;
        while ($pending !== []) {
            $node = array_pop($pending);
            if (!is_array($node)) {
                continue;
            }
            if (($node['type'] ?? null) === 'global_widget') {
                $count++;
            }
            foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
                $pending[] = $child;
            }
        }
        return $count;
    }

    /**
     * The saved reference stays intact. Runtime-only children are namespaced per
     * occurrence and never reintroduced into the stored document. A removed,
     * disabled or malformed definition expands to an empty wrapper.
     *
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    public function expand(array $layout): array
    {
        $elements = is_array($layout['elements'] ?? null) ? $layout['elements'] : [];
        if (self::countReferences($elements) === 0) {
            return $layout;
        }
        $owner = Scope::forLayout($layout);
        $used = [];
        $pending = $elements;
        while ($pending !== []) {
            $node = array_pop($pending);
            if (!is_array($node)) {
                continue;
            }
            if (is_string($node['id'] ?? null)) {
                $used[$node['id']] = true;
            }
            foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
                $pending[] = $child;
            }
        }
        $number = 0;
        $walk = function (array $nodes) use (&$walk, &$used, &$number, $owner): array {
            foreach ($nodes as $index => $node) {
                if (!is_array($node)) {
                    continue;
                }
                if (($node['type'] ?? null) === 'global_widget') {
                    $node['children'] = [];
                    $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
                    $id = is_string($settings['global_id'] ?? null) ? $settings['global_id'] : '';
                    $reference = is_string($node['id'] ?? null) ? $node['id'] : '';
                    $definition = ++$number <= self::MAX_REFERENCES ? $this->definition($id) : null;
                    if ($definition !== null) {
                        // A malicious saved sibling may deliberately use a computed ID.
                        // Try deterministic alternate namespaces rather than duplicating it.
                        for ($salt = 0; $salt < 32; $salt++) {
                            $slot = 'g' . substr(hash('sha256', $owner . ':' . $reference . ':' . $id . ':' . $salt), 0, 16);
                            $namespaced = Partials::namespaceLayout($definition, $slot);
                            $ids = [];
                            $collect = is_array($namespaced['elements'] ?? null) ? $namespaced['elements'] : [];
                            while ($collect !== []) {
                                $child = array_pop($collect);
                                if (!is_array($child)) {
                                    continue;
                                }
                                if (is_string($child['id'] ?? null)) {
                                    $ids[] = $child['id'];
                                }
                                foreach (is_array($child['children'] ?? null) ? $child['children'] : [] as $item) {
                                    $collect[] = $item;
                                }
                            }
                            if (array_intersect($ids, array_keys($used)) !== []) {
                                continue;
                            }
                            foreach ($ids as $childId) {
                                $used[$childId] = true;
                            }
                            $node['children'] = $namespaced['elements'];
                            break;
                        }
                    }
                } elseif (is_array($node['children'] ?? null)) {
                    $node['children'] = $walk($node['children']);
                }
                $nodes[$index] = $node;
            }
            return $nodes;
        };
        $layout['elements'] = $walk($elements);
        return $layout;
    }

    /** @return array<string, mixed>|null */
    private function definition(string $id): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            return null;
        }
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }
        $model = null;
        foreach ($this->models->all() as $candidate) {
            if ($candidate['id'] === $id) {
                $model = $candidate;
                break;
            }
        }
        if ($model === null || $model['kind'] !== 'global' || $model['enabled'] !== true || !is_array($model['elements'] ?? null)) {
            return $this->resolved[$id] = null;
        }
        $validator = new Validator(
            new Registry($this->plugin->app()->get(Hooks::class)),
            $this->plugin->app()->get(HtmlSanitizer::class),
            $this->plugin->app()->get(BlockRegistry::class),
        );
        $result = $validator->validate(['version' => 1, 'elements' => $model['elements']]);
        if ($result['errors'] !== [] || !self::allowed($result['data'])) {
            return $this->resolved[$id] = null;
        }
        return $this->resolved[$id] = $result['data'];
    }
}
