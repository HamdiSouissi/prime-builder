<?php

declare(strict_types=1);

namespace PrimeBuilder\Theme;

use Prime\Plugins\PluginSettings;
use Prime\Settings\Settings;
use Prime\Site\PageContext;
use PrimeBuilder\Document\SavedTemplates;

/** Independently stored theme models; library snapshots and old partials remain untouched. */
final class Models
{
    public const KINDS = ['header', 'footer', 'single', 'archive', 'search', 'not_found'];
    public const MAX_MODELS = 50;
    private const PREFIX = 'theme_model.';
    /** @var list<array<string, mixed>>|null */
    private ?array $cached = null;

    public function __construct(private readonly PluginSettings $pluginSettings, private readonly Settings $settings)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }
        $prefix = $this->pluginSettings->prefix() . self::PREFIX;
        $result = [];
        foreach ($this->settings->allByPrefix($prefix) as $key => $value) {
            $id = substr($key, strlen($prefix));
            $model = $this->parse($id, $value);
            if ($model !== null) {
                $result[] = $model;
            }
        }
        usort($result, static fn (array $a, array $b): int => strnatcasecmp((string) $a['name'], (string) $b['name']) ?: strcmp((string) $a['id'], (string) $b['id']));
        return $this->cached = $result;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            return null;
        }
        return $this->parse($id, $this->pluginSettings->get(self::PREFIX . $id));
    }

    /**
     * @param array<mixed> $elements
     * @return array<string, mixed>|null
     */
    public function create(string $name, string $kind, array $elements = []): ?array
    {
        if (count($this->all()) >= self::MAX_MODELS || !in_array($kind, self::KINDS, true)) {
            return null;
        }
        do {
            $id = bin2hex(random_bytes(16));
        } while ($this->pluginSettings->has(self::PREFIX . $id));
        $model = [
            'id' => $id, 'name' => $name, 'kind' => $kind, 'enabled' => false, 'priority' => 0,
            'include' => [['type' => 'all', 'value' => '']], 'exclude' => [], 'elements' => $elements,
        ];
        return $this->save($model) ? $model : null;
    }

    /** @param array<string, mixed> $model */
    public function save(array $model): bool
    {
        $id = is_string($model['id'] ?? null) ? $model['id'] : '';
        unset($model['id']);
        $parsed = $this->parse($id, $model);
        if ($parsed === null) {
            return false;
        }
        unset($parsed['id']);
        $json = json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > SavedTemplates::MAX_BYTES + 32768) {
            return false;
        }
        $this->cached = null;
        $this->pluginSettings->set(self::PREFIX . $id, $parsed, false);
        return true;
    }

    public function delete(string $id): bool
    {
        $this->cached = null;
        return preg_match('/^[a-f0-9]{32}$/D', $id) === 1 && $this->pluginSettings->delete(self::PREFIX . $id);
    }

    public function invalidate(): void
    {
        $this->cached = null;
    }

    /** @return list<array<string, mixed>> */
    public function matching(string $kind, PageContext $page): array
    {
        if (!self::supports($kind, $page)) {
            return [];
        }
        $matches = [];
        foreach ($this->all() as $model) {
            if ($model['kind'] !== $kind || $model['enabled'] !== true) {
                continue;
            }
            /** @var list<array{type: string, value: string}> $include */
            $include = $model['include'];
            /** @var list<array{type: string, value: string}> $exclude */
            $exclude = $model['exclude'];
            $score = Conditions::specificity($page, $include, $exclude);
            if ($score !== null) {
                $matches[] = ['model' => $model, 'score' => $score];
            }
        }
        usort($matches, static fn (array $a, array $b): int => $b['score'] <=> $a['score']
            ?: ((int) $b['model']['priority'] <=> (int) $a['model']['priority'])
            ?: strcmp((string) $a['model']['id'], (string) $b['model']['id']));
        return array_map(static fn (array $match): array => $match['model'], $matches);
    }

    public function hasEnabled(): bool
    {
        foreach ($this->all() as $model) {
            if ($model['enabled'] === true) {
                return true;
            }
        }
        return false;
    }

    public static function supports(string $kind, PageContext $page): bool
    {
        if ($page->isRedirect()) {
            return false;
        }
        if ($page->isPlugin()) {
            // Plugin pages are already framed by the CMS, but still call theme partials.
            return in_array($kind, ['header', 'footer'], true);
        }
        return match ($kind) {
            'header', 'footer' => true,
            'single' => $page->entry !== null && ($page->isEntry() || $page->isFront()),
            'archive' => Conditions::isArchive($page),
            'search' => $page->isSearch(),
            'not_found' => $page->isNotFound(),
            default => false,
        };
    }

    /** @param array<mixed> $elements */
    public static function sourceHash(array $elements): string
    {
        return hash('sha256', json_encode($elements, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /** @return array<string, mixed>|null */
    private function parse(string $id, mixed $value): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1 || !is_array($value)
            || !is_string($value['name'] ?? null) || trim($value['name']) === '' || strlen($value['name']) > 320
            || preg_match('//u', $value['name']) !== 1 || mb_strlen($value['name'], 'UTF-8') > 100
            || preg_match('/[\x00-\x1F\x7F]/', $value['name']) === 1
            || !in_array($value['kind'] ?? null, self::KINDS, true) || !is_bool($value['enabled'] ?? null)
            || !is_int($value['priority'] ?? null) || $value['priority'] < -1000 || $value['priority'] > 1000
            || !is_array($value['elements'] ?? null) || !array_is_list($value['elements'])) {
            return null;
        }
        $include = Conditions::normalize($value['include'] ?? null);
        $exclude = Conditions::normalize($value['exclude'] ?? null);
        if ($include === null || $include === [] || $exclude === null) {
            return null;
        }
        return [
            'id' => $id, 'name' => $value['name'], 'kind' => $value['kind'], 'enabled' => $value['enabled'],
            'priority' => $value['priority'], 'include' => $include, 'exclude' => $exclude, 'elements' => $value['elements'],
        ];
    }
}
