<?php

declare(strict_types=1);

use Prime\Auth\UserRepository;
use Prime\Cache\PageCache;
use Prime\Content\EntryRepository;
use Prime\Content\EntryService;
use Prime\Database\Connection;
use Prime\Http\Kernel;
use Prime\Http\Request;
use Prime\Plugins\PluginManager;
use Prime\Settings\Settings;
use PrimeBuilder\Theme\Models;

// Destructive fixture: use only against a disposable, freshly installed CMS.
// php tools/benchmark-theme.php prepare|measure|cleanup /path/to/cms /path/to/state.json
if (getenv('PB_BENCH_DISPOSABLE') !== '1' || count($argv) !== 4) {
    fwrite(STDERR, "Set PB_BENCH_DISPOSABLE=1 and provide prepare|measure|cleanup, CMS path and state path.\n");
    exit(1);
}
[$script, $operation, $path, $statePath] = $argv;
$cms = realpath($path);
if ($cms === false || !is_file($cms . '/bootstrap/app.php') || !in_array($operation, ['prepare', 'measure', 'cleanup'], true)) {
    throw new RuntimeException('Invalid CMS path or operation.');
}
$app = require $cms . '/bootstrap/app.php';
if (!$app instanceof \Prime\Kernel\Application) {
    throw new RuntimeException('CMS bootstrap did not return an application.');
}
$context = $app->get(PluginManager::class)->loaded()['prime-builder'] ?? null;
if (!$context instanceof \Prime\Plugins\PluginContext) {
    throw new RuntimeException('Prime Builder must already be active.');
}
$models = new Models($context->settings(), $app->get(Settings::class), $context);
$entries = $app->get(EntryRepository::class);
$service = $app->get(EntryService::class);
$email = getenv('PB_BENCH_ADMIN_EMAIL') ?: 'builder@prime.test';
$actor = $app->get(UserRepository::class)->findByEmail($email);
if ($actor === null) {
    throw new RuntimeException('Disposable fixture administrator not found.');
}

if ($operation === 'prepare') {
    if (file_exists($statePath) || $models->all() !== []) {
        throw new RuntimeException('Refusing to overwrite state or use an installation with existing models.');
    }
    $nonce = bin2hex(random_bytes(8));
    $slug = 'pb-theme-benchmark-' . $nonce;
    $entry = $service->create('post', [
        'title' => 'Benchmark article ' . $nonce, 'slug' => $slug, 'status' => 'published',
        'content' => ['version' => 1, 'blocks' => [['id' => 'paragraph', 'type' => 'paragraph', 'data' => ['text' => 'Benchmark article body']]]],
    ], $actor);
    $state = ['nonce' => $nonce, 'slug' => $slug, 'entry_id' => $entry->id, 'path' => $entry->url(), 'models' => []];
    $write = static function (array $value) use ($statePath): void {
        if (file_put_contents($statePath, json_encode($value, JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Unable to save benchmark fixture state.');
        }
    };
    $write($state);
    foreach (['header', 'footer', 'single'] as $kind) {
        $elements = [[
            'id' => 'heading_' . $kind, 'type' => 'heading',
            'settings' => ['text' => 'F2 benchmark ' . $kind, 'level' => 2], 'styles' => [], 'children' => [],
        ]];
        if ($kind === 'single') {
            $elements[] = ['id' => 'entry_content', 'type' => 'entry_content', 'settings' => [], 'styles' => [], 'children' => []];
        }
        $model = $models->create('Benchmark ' . $nonce . ' ' . $kind, $kind, $elements);
        if ($model === null) {
            throw new RuntimeException('Cannot create ' . $kind . ' model.');
        }
        $state['models'][] = $model['id'];
        $write($state);
        $model['enabled'] = true;
        if (!$models->save($model)) {
            throw new RuntimeException('Cannot enable ' . $kind . ' model.');
        }
    }
    echo "Benchmark fixture prepared.\n";
    exit(0);
}

$raw = file_get_contents($statePath);
$state = is_string($raw) ? json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : null;
if (!is_array($state) || !is_string($state['nonce'] ?? null) || preg_match('/^[a-f0-9]{16}$/D', $state['nonce']) !== 1
    || !is_int($state['entry_id'] ?? null) || !is_string($state['slug'] ?? null)
    || $state['slug'] !== 'pb-theme-benchmark-' . $state['nonce'] || !is_string($state['path'] ?? null)
    || !str_starts_with($state['path'], '/') || !is_array($state['models'] ?? null) || count($state['models']) > 3) {
    throw new RuntimeException('Invalid benchmark state.');
}
$entry = $entries->find($state['entry_id']);
if ($entry === null || $entry->slug !== $state['slug']) {
    throw new RuntimeException('Benchmark article does not match state.');
}
foreach ($state['models'] as $id) {
    $model = is_string($id) ? $models->find($id) : null;
    if ($model === null || !str_starts_with((string) $model['name'], 'Benchmark ' . $state['nonce'] . ' ')) {
        throw new RuntimeException('Benchmark model does not match state.');
    }
    if ($operation === 'measure' && $model['enabled'] !== true) {
        throw new RuntimeException('Benchmark model is not enabled.');
    }
}
if ($operation === 'cleanup') {
    foreach ($state['models'] as $id) {
        if (!$models->delete($id)) {
            throw new RuntimeException('Cannot delete benchmark model.');
        }
    }
    $service->trash($entry->id, $actor);
    $service->delete($entry->id, $actor);
    if (!unlink($statePath)) {
        throw new RuntimeException('Cannot remove benchmark state.');
    }
    echo "Benchmark fixture removed.\n";
    exit(0);
}
if (count($state['models']) !== 3) {
    throw new RuntimeException('All three benchmark models must be enabled.');
}
// Exclude application bootstrap; measure one real anonymous request with page caching off.
$app->get(PageCache::class)->setEnabled(false);
$db = $app->get(Connection::class);
$db->enableQueryLog();
$db->clearQueryLog();
$start = hrtime(true);
$response = $app->get(Kernel::class)->handle(Request::create('GET', $state['path'], headers: ['Accept' => 'text/html']));
$elapsed = (hrtime(true) - $start) / 1e6;
foreach (['F2 benchmark header', 'F2 benchmark footer', 'F2 benchmark single', 'Benchmark article body'] as $marker) {
    if (!str_contains($response->body(), $marker)) {
        throw new RuntimeException('Benchmark page is missing ' . $marker . '.');
    }
}
if ($response->status() !== 200) {
    throw new RuntimeException('Benchmark request failed: ' . $response->status());
}
printf("theme_benchmark status=%d queries=%d time_ms=%.2f body_bytes=%d cache=off models=3\n", $response->status(), count($db->queryLog()), $elapsed, strlen($response->body()));
