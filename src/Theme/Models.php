<?php

declare(strict_types=1);

namespace PrimeBuilder\Theme;

use Prime\Hooks\Hooks;
use Prime\Plugins\PluginContext;
use Prime\Plugins\PluginSettings;
use Prime\Security\Encrypter;
use Prime\Settings\Settings;
use Prime\Site\PageContext;
use PrimeBuilder\Document\SavedTemplates;
use PrimeBuilder\Dynamic\Layout as DynamicLayout;
use PrimeBuilder\Popup\Options as PopupOptions;
use PrimeBuilder\Widgets\Registry;
use Throwable;

/** Independently stored theme models; library snapshots and old partials remain untouched. */
final class Models
{
    public const KINDS = ['header', 'footer', 'single', 'archive', 'search', 'not_found', 'popup', 'global'];
    public const MAX_MODELS = 50;
    public const INDEX = 'theme_model_index';
    public const PREFIX = 'theme_model.';
    private const INDEX_VERSION = 1;
    // Bump when validation or sanitization rules change in a future release.
    private const VALIDATION_VERSION = 2;
    private static int $maintaining = 0;
    /** @var list<array<string, mixed>>|null */
    private ?array $cached = null;
    /** @var list<array<string, mixed>>|null */
    private ?array $cachedIndex = null;

    public function __construct(
        private readonly PluginSettings $pluginSettings,
        private readonly Settings $settings,
        private readonly ?PluginContext $plugin = null,
    ) {
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

    /**
     * A small autoloaded directory contains only routing metadata, never layouts.
     * Legacy installations are indexed on first use without changing their models.
     * If the key is unavailable or the index is damaged, fall back to the full
     * records rather than trusting an incomplete directory.
     *
     * @return list<array<string, mixed>>
     */
    public function index(): array
    {
        if ($this->cachedIndex !== null) {
            return $this->cachedIndex;
        }
        $key = $this->indexKey();
        if ($key === null) {
            $this->cached = null;
            return $this->cachedIndex = array_map(self::metadata(...), $this->all());
        }
        $stored = $this->pluginSettings->get(self::INDEX);
        if (!$this->validIndex($stored, $key)) {
            $this->rebuildIndex();
            $stored = $this->pluginSettings->get(self::INDEX);
            if (!$this->validIndex($stored, $key)) {
                throw new \RuntimeException('Unable to build the theme model index.');
            }
        }
        /** @var list<array<string, mixed>> $items */
        $items = $stored['items'];
        return $this->cachedIndex = $items;
    }

    public static function maintainingIndex(): bool
    {
        return self::$maintaining > 0;
    }

    /** Rebuild after a legacy or external settings write, under the same index lock. */
    public function rebuildIndex(): void
    {
        if ($this->plugin === null) {
            $this->cached = null;
            return;
        }
        $this->withIndexLock(static fn (): bool => true);
    }

    /**
     * Serialize model/index writes so two administrators cannot silently lose a
     * model from the directory. Source records and the index commit together.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function withIndexLock(callable $operation): mixed
    {
        if ($this->plugin === null) {
            $this->invalidate();
            try {
                return $operation();
            } finally {
                $this->invalidate();
            }
        }
        $db = $this->plugin->db();
        try {
            $result = $db->transaction(function () use ($db, $operation): mixed {
                $table = '`' . $db->prefixed('settings') . '`';
                $name = $this->pluginSettings->prefix() . self::INDEX;
                $now = gmdate('Y-m-d H:i:s');
                $insert = $db->driverName() === 'sqlite'
                    ? 'INSERT OR IGNORE INTO ' . $table . ' (`name`, `value`, `autoload`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?)'
                    : 'INSERT INTO ' . $table . ' (`name`, `value`, `autoload`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE `name` = `name`';
                $db->statement($insert, [$name, 'null', 0, $now, $now]);
                // Works as a row lock on MySQL and as a write lock on SQLite.
                $db->update('UPDATE ' . $table . ' SET `name` = `name` WHERE `name` = ?', [$name]);
                // A second application instance may have changed or deleted a
                // record while we waited. Settings caches missing and deleted
                // keys as well as values, so refresh after acquiring the lock.
                $this->settings->flushCache();
                $this->invalidate();
                self::$maintaining++;
                try {
                    $result = $operation();
                    $this->cached = null;
                    $models = $this->all();
                    $key = $this->indexKey();
                    if ($key !== null) {
                        // Backfill old records without replacing their original source.
                        // Invalid records remain untouched and are revalidated on use.
                        foreach ($models as $offset => $model) {
                            if ($this->trustedLayout($model) !== null) {
                                continue;
                            }
                            $seal = $this->sealLayout($model);
                            if ($seal === null) {
                                continue;
                            }
                            $record = $this->pluginSettings->get(self::PREFIX . $model['id']);
                            if (!is_array($record)) {
                                continue;
                            }
                            $record['_validation'] = $seal;
                            $this->pluginSettings->set(self::PREFIX . $model['id'], $record, false);
                            $models[$offset]['_validation'] = $seal;
                        }
                        $payload = ['version' => self::INDEX_VERSION, 'items' => array_map(self::metadata(...), $models)];
                        $this->pluginSettings->set(self::INDEX, $payload + ['signature' => $this->indexSignature($payload, $key)], true);
                    }
                    $this->cached = null;
                    $this->cachedIndex = null;
                    return $result;
                } finally {
                    self::$maintaining--;
                }
            });
            // The source and directory are committed before invalidating pages.
            $this->plugin->app()->get(\Prime\Cache\PageCache::class)->purgeAll();
            return $result;
        } catch (Throwable $exception) {
            // Settings caches writes in memory even if a database transaction rolls back.
            $this->cached = null;
            $this->cachedIndex = null;
            $this->settings->flushCache();
            throw $exception;
        }
    }

    /** @param array<string, mixed> $model
     * @return array<string, mixed>
     */
    private static function metadata(array $model): array
    {
        $metadata = [
            'id' => $model['id'], 'name' => $model['name'], 'kind' => $model['kind'], 'enabled' => $model['enabled'],
            'priority' => $model['priority'], 'include' => $model['include'], 'exclude' => $model['exclude'],
            'dynamic' => DynamicLayout::uses(is_array($model['elements'] ?? null) ? $model['elements'] : []),
        ];
        if ($model['kind'] === 'popup') {
            $metadata['popup'] = $model['popup'];
        }
        return $metadata;
    }

    private function indexKey(): ?string
    {
        $appKey = $this->plugin?->app()->config()->get('app.key');
        if (!is_string($appKey)) {
            return null;
        }
        try {
            new Encrypter($appKey);
            return hash_hkdf('sha256', $appKey, 32, 'prime-builder:model-index:v1');
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $payload */
    private function indexSignature(array $payload, string $key): string
    {
        return hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', $key);
    }

    /**
     * Return a previously normalized layout only when it is authenticated for
     * this exact source, model, validator version, CMS and widget schemas.
     * Damaged, old or unverifiable records take the original validation path.
     *
     * @param array<string, mixed> $model
     * @return array<string, mixed>|null
     */
    public function validatedLayout(array $model, bool $allowEmpty = false): ?array
    {
        $layout = $this->trustedLayout($model);
        if ($layout === null && $this->plugin !== null && is_array($model['elements'] ?? null)) {
            $layout = (new Partials($this->plugin))->normalize($model['elements'], allowEmpty: true, contextual: true);
            if ($layout !== null && ($model['kind'] ?? null) === 'global' && !GlobalWidgets::allowed($layout)) {
                $layout = null;
            }
        }
        return $layout !== null && ($allowEmpty || ($layout['elements'] ?? []) !== []) ? $layout : null;
    }

    /** @param array<string, mixed> $model
     * @return array<string, mixed>|null
     */
    private function trustedLayout(array $model): ?array
    {
        $key = $this->indexKey();
        $fingerprint = $this->schemaFingerprint();
        $seal = $model['_validation'] ?? null;
        if ($key === null || $fingerprint === null || !is_array($seal) || !is_array($model['elements'] ?? null)
            || !is_string($model['id'] ?? null) || !is_string($model['kind'] ?? null)
            || ($seal['version'] ?? null) !== self::VALIDATION_VERSION
            || ($seal['schema'] ?? null) !== $fingerprint || ($seal['source'] ?? null) !== self::sourceHash($model['elements'])
            || !array_key_exists('normalized', $seal) || ($seal['normalized'] !== null && !is_array($seal['normalized']))
            || !is_string($seal['signature'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $seal['signature']) !== 1
            || self::containsPrimeBlock($model['elements'])) {
            return null;
        }
        $payload = ['version' => $seal['version'], 'schema' => $seal['schema'], 'source' => $seal['source'], 'normalized' => $seal['normalized']];
        if (!hash_equals($this->layoutSignature($model, $payload, $key), $seal['signature'])) {
            return null;
        }
        $elements = $seal['normalized'] ?? $model['elements'];
        return array_is_list($elements) ? ['version' => 1, 'elements' => $elements] : null;
    }

    /** @param array<string, mixed> $model
     * @return array<string, mixed>|null
     */
    private function sealLayout(array $model): ?array
    {
        if ($this->plugin === null || !is_array($model['elements'] ?? null)) {
            return null;
        }
        $layout = (new Partials($this->plugin))->normalize($model['elements'], allowEmpty: true, contextual: true);
        if ($layout === null || (($model['kind'] ?? null) === 'global' && !GlobalWidgets::allowed($layout))) {
            return null;
        }
        return $this->sealNormalized($model, $layout);
    }

    /** @param array<string, mixed> $model
     * @param array<string, mixed> $layout
     * @return array<string, mixed>|null
     */
    private function sealNormalized(array $model, array $layout): ?array
    {
        $key = $this->indexKey();
        $fingerprint = $this->schemaFingerprint();
        $elements = $model['elements'] ?? null;
        $normalized = $layout['elements'] ?? null;
        if ($key === null || $fingerprint === null || !is_array($elements) || !is_array($normalized)
            || self::containsPrimeBlock($elements)) {
            return null;
        }
        $payload = [
            'version' => self::VALIDATION_VERSION, 'schema' => $fingerprint, 'source' => self::sourceHash($elements),
            // Avoid duplicating ordinary, already-normalized editor documents.
            'normalized' => $normalized === $elements ? null : $normalized,
        ];
        return $payload + ['signature' => $this->layoutSignature($model, $payload, $key)];
    }

    /** @param array<string, mixed> $model
     * @param array<string, mixed> $payload
     */
    private function layoutSignature(array $model, array $payload, string $key): string
    {
        return hash_hmac('sha256', json_encode(['id' => $model['id'], 'kind' => $model['kind'], 'validation' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', $key);
    }

    /** Widget extensions may change their declarative validation rules at runtime. */
    private function schemaFingerprint(): ?string
    {
        if ($this->plugin === null) {
            return null;
        }
        $schema = [];
        foreach ((new Registry($this->plugin->app()->get(Hooks::class)))->all() as $type => $definition) {
            $schema[$type] = ['fields' => $definition['fields'] ?? null, 'container' => $definition['container'] ?? false];
        }
        if (!self::scalarTree($schema)) {
            return null;
        }
        $json = json_encode([self::VALIDATION_VERSION, $this->plugin->app()->version(), $schema], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? hash('sha256', $json) : null;
    }

    private static function scalarTree(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::scalarTree($item)) {
                    return false;
                }
            }
            return true;
        }
        return $value === null || is_scalar($value);
    }

    /** Core block validators may depend on mutable registry or media data.
     *
     * @param array<mixed> $elements
     */
    private static function containsPrimeBlock(array $elements): bool
    {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }
            if (($element['type'] ?? null) === 'prime_block'
                || (is_array($element['children'] ?? null) && self::containsPrimeBlock($element['children']))) {
                return true;
            }
        }
        return false;
    }

    private function validIndex(mixed $stored, string $key): bool
    {
        if (!is_array($stored) || ($stored['version'] ?? null) !== self::INDEX_VERSION
            || !is_array($stored['items'] ?? null) || !array_is_list($stored['items'])
            || !is_string($stored['signature'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $stored['signature']) !== 1) {
            return false;
        }
        $payload = ['version' => self::INDEX_VERSION, 'items' => $stored['items']];
        if (!hash_equals($this->indexSignature($payload, $key), $stored['signature'])) {
            return false;
        }
        $seen = [];
        foreach ($stored['items'] as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null) || isset($seen[$item['id']])
                || !is_bool($item['dynamic'] ?? null)
                || $this->parse($item['id'], $item + ['elements' => []]) === null) {
                return false;
            }
            $seen[$item['id']] = true;
        }
        return true;
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
        if (!in_array($kind, self::KINDS, true)) {
            return null;
        }
        do {
            $id = bin2hex(random_bytes(16));
        } while ($this->pluginSettings->has(self::PREFIX . $id));
        $model = [
            'id' => $id, 'name' => $name, 'kind' => $kind, 'enabled' => false, 'priority' => 0,
            'include' => [['type' => 'all', 'value' => '']], 'exclude' => [], 'elements' => $elements,
        ];
        if ($kind === 'popup') {
            $model['popup'] = PopupOptions::defaults();
        }
        return $this->save($model) ? $model : null;
    }

    /** @param array<string, mixed> $model */
    public function save(array $model): bool
    {
        $id = is_string($model['id'] ?? null) ? $model['id'] : '';
        $parsed = $this->prepare($model);
        if ($parsed === null) {
            return false;
        }
        return $this->withIndexLock(function () use ($id, $parsed): bool {
            if (!$this->pluginSettings->has(self::PREFIX . $id) && count($this->all()) >= self::MAX_MODELS) {
                return false;
            }
            $this->pluginSettings->set(self::PREFIX . $id, $parsed, false);
            return true;
        });
    }

    /**
     * Compare the layout under the same lock as its write. For an editor save,
     * retain the latest metadata rather than overwriting a simultaneous change.
     * A deleted or changed model must never be silently resurrected.
     *
     * @param array<string, mixed> $model
     * @return 'saved'|'conflict'|'invalid'
     */
    public function saveIfSourceUnchanged(array $model, string $expectedSource, bool $keepCurrentMetadata = false): string
    {
        $id = is_string($model['id'] ?? null) ? $model['id'] : '';
        return $this->withIndexLock(function () use ($id, $model, $expectedSource, $keepCurrentMetadata): string {
            $current = $this->find($id);
            if ($current === null || !is_array($current['elements'] ?? null) || ($current['kind'] ?? null) !== ($model['kind'] ?? null)
                || !hash_equals(self::sourceHash($current['elements']), $expectedSource)) {
                return 'conflict';
            }
            if ($keepCurrentMetadata) {
                $current['elements'] = $model['elements'] ?? null;
                $model = $current;
            }
            $parsed = $this->prepare($model);
            if ($parsed === null) {
                return 'invalid';
            }
            $this->pluginSettings->set(self::PREFIX . $id, $parsed, false);
            return 'saved';
        });
    }

    /**
     * @param array<string, mixed> $model
     * @return array<string, mixed>|null
     */
    private function prepare(array $model): ?array
    {
        $id = is_string($model['id'] ?? null) ? $model['id'] : '';
        unset($model['id']);
        $parsed = $this->parse($id, $model);
        if ($parsed === null) {
            return null;
        }
        if ($this->plugin !== null) {
            $layout = (new Partials($this->plugin))->normalize($parsed['elements'], allowEmpty: true, contextual: true);
            if ($layout === null || ($parsed['kind'] === 'global' && !GlobalWidgets::allowed($layout))) {
                return null;
            }
            $parsed['elements'] = $layout['elements'];
            unset($parsed['_validation']);
            $seal = $this->sealNormalized($parsed, $layout);
            if ($seal !== null) {
                $parsed['_validation'] = $seal;
            }
        }
        unset($parsed['id']);
        $json = json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) && strlen($json) <= SavedTemplates::MAX_BYTES + 32768 ? $parsed : null;
    }

    public function delete(string $id): bool
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            return false;
        }
        return $this->withIndexLock(fn (): bool => $this->pluginSettings->delete(self::PREFIX . $id));
    }

    public function invalidate(): void
    {
        $this->cached = null;
        $this->cachedIndex = null;
    }

    /** Refresh even when another process changed the autoloaded index. */
    public function refresh(): void
    {
        $this->settings->flushCache();
        $this->invalidate();
    }

    /**
     * Resolve a global definition only against the same authenticated directory
     * used for cache-sensitivity decisions. A source changed since that snapshot
     * must not be rendered under its previous, potentially static classification.
     *
     * @return array<string, mixed>|null
     */
    public function findIndexed(string $id): ?array
    {
        foreach ($this->index() as $metadata) {
            if ($metadata['id'] !== $id) {
                continue;
            }
            $model = $this->find($id);
            return $model !== null && self::metadata($model) === $metadata ? $model : null;
        }
        return null;
    }

    /**
     * Match and order lightweight metadata first, then read each candidate only
     * when the caller needs it. A damaged winner cannot hide a valid fallback.
     *
     * @return iterable<array<string, mixed>>
     */
    public function matching(string $kind, PageContext $page): iterable
    {
        if (!self::supports($kind, $page)) {
            return;
        }
        $matches = [];
        foreach ($this->index() as $model) {
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
        foreach ($matches as $match) {
            $metadata = $match['model'];
            $model = $this->find((string) $metadata['id']);
            // An out-of-band change or a damaged index must not apply a stale
            // rule to a different or newly sensitive model.
            if ($model !== null && self::metadata($model) === $metadata) {
                yield $model;
            }
        }
    }

    public function hasEnabled(): bool
    {
        foreach ($this->index() as $model) {
            if ($model['enabled'] === true && $model['kind'] !== 'global') {
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
            // Plugin pages are already framed by the CMS, but still call theme partials and foot.
            return in_array($kind, ['header', 'footer', 'popup'], true);
        }
        return match ($kind) {
            'header', 'footer', 'popup' => true,
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
        $result = [
            'id' => $id, 'name' => $value['name'], 'kind' => $value['kind'], 'enabled' => $value['enabled'],
            'priority' => $value['priority'], 'include' => $include, 'exclude' => $exclude, 'elements' => $value['elements'],
        ];
        if ($value['kind'] === 'popup') {
            $options = PopupOptions::normalize($value['popup'] ?? null);
            if ($options === null) {
                return null;
            }
            $result['popup'] = $options;
        }
        if (is_array($value['_validation'] ?? null)) {
            $result['_validation'] = $value['_validation'];
        }
        return $result;
    }
}
