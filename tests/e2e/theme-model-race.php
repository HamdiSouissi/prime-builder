<?php

declare(strict_types=1);

// Run only on a disposable installed CMS. The workers have independent
// settings caches and database connections; the first holds the real index lock.
use Prime\Hooks\Hooks;
use Prime\Plugins\PluginContext;
use Prime\Plugins\PluginManager;
use Prime\Settings\Settings;
use PrimeBuilder\Theme\Models;

function modelRaceMark(string $path): void
{
    if (file_put_contents($path, '1') === false) {
        throw new RuntimeException('Could not write model race marker.');
    }
}

function modelRaceWait(string $path): void
{
    $until = microtime(true) + 25;
    while (!is_file($path)) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Timed out waiting for ' . basename($path));
        }
        usleep(10000);
        clearstatcache(true, $path);
    }
}

/** @return resource */
function modelRaceChild(string $mode, string $dir, string $id)
{
    $command = [PHP_BINARY];
    $ini = php_ini_loaded_file();
    if (is_string($ini)) {
        array_push($command, '-c', $ini);
    }
    array_push($command, __FILE__, $mode, $dir, $id);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $dir . '/' . $mode . '.out', 'a'], 2 => ['file', $dir . '/' . $mode . '.err', 'a']], $pipes, getcwd() ?: null);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start model race child.');
    }
    fclose($pipes[0]);
    return $process;
}

/** @param resource $process */
function modelRaceFinish($process, string $mode, string $dir): void
{
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException($mode . ' failed with status ' . $status . ': ' . (string) @file_get_contents($dir . '/' . $mode . '.err'));
    }
}

$mode = $argv[1] ?? 'main';
$app = require getcwd() . '/bootstrap/app.php';
$plugin = $app->get(PluginManager::class)->loaded()['prime-builder'] ?? null;
if (!$plugin instanceof PluginContext) {
    throw new RuntimeException('An active Prime Builder installation is required.');
}
$settings = $app->get(Settings::class);
$models = new Models($plugin->settings(), $settings, $plugin);
if ($mode !== 'main') {
    $dir = $argv[2] ?? '';
    $id = $argv[3] ?? '';
    if (!in_array($mode, ['winner', 'contender'], true) || !is_dir($dir) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
        throw new RuntimeException('Invalid model race arguments.');
    }
    $model = $models->find($id);
    if ($model === null || !is_array($model['elements'] ?? null)) {
        throw new RuntimeException('The model race fixture is missing.');
    }
    $hash = Models::sourceHash($model['elements']);
    $model['elements'][0]['settings']['text'] = $mode === 'winner' ? 'Winning update' : 'Stale update';
    if ($mode === 'winner') {
        $held = false;
        $app->get(Hooks::class)->on('db.query', static function (string $sql) use ($dir, &$held): void {
            if (!$held && str_starts_with($sql, 'UPDATE `') && str_contains($sql, 'settings` SET `name` = `name` WHERE `name` = ?')) {
                $held = true;
                modelRaceMark($dir . '/winner-held');
                modelRaceWait($dir . '/release-winner');
            }
        });
    } else {
        modelRaceMark($dir . '/contender-ready');
        modelRaceWait($dir . '/start-contender');
        modelRaceMark($dir . '/contender-started');
    }
    $result = $models->saveIfSourceUnchanged($model, $hash, keepCurrentMetadata: true);
    if ($result !== ($mode === 'winner' ? 'saved' : 'conflict')) {
        throw new RuntimeException('Unexpected ' . $mode . ' result: ' . $result);
    }
    modelRaceMark($dir . '/' . $mode . '-done');
    exit(0);
}

if ((int) (getenv('E2E_ENTRY_ID') ?: 0) < 1) {
    throw new RuntimeException('A disposable E2E installation is required.');
}
$fixture = $models->create('Concurrent editor test ' . bin2hex(random_bytes(4)), 'header', [
    ['id' => 'title', 'type' => 'heading', 'settings' => ['text' => 'Original'], 'styles' => [], 'children' => []],
]);
if ($fixture === null || !is_string($fixture['id'] ?? null)) {
    throw new RuntimeException('Could not create the temporary model.');
}
$id = $fixture['id'];
$dir = sys_get_temp_dir() . '/pb-model-race-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) {
    $models->delete($id);
    throw new RuntimeException('Could not create model race directory.');
}
$children = [];
try {
    $children['contender'] = modelRaceChild('contender', $dir, $id);
    modelRaceWait($dir . '/contender-ready');
    $children['winner'] = modelRaceChild('winner', $dir, $id);
    modelRaceWait($dir . '/winner-held');
    modelRaceMark($dir . '/start-contender');
    modelRaceWait($dir . '/contender-started');
    usleep(350000);
    clearstatcache(true, $dir . '/contender-done');
    if (is_file($dir . '/contender-done')) {
        throw new RuntimeException('The contender passed the held index lock.');
    }
    modelRaceMark($dir . '/release-winner');
    foreach (['winner', 'contender'] as $name) {
        modelRaceWait($dir . '/' . $name . '-done');
        $child = $children[$name] ?? null;
        if (!is_resource($child)) {
            throw new RuntimeException('Missing model race child: ' . $name);
        }
        modelRaceFinish($child, $name, $dir);
        unset($children[$name]);
    }
    $settings->flushCache();
    $models->invalidate();
    $saved = $models->find($id);
    $indexIds = array_column($models->index(), 'id');
    if (($saved['elements'][0]['settings']['text'] ?? null) !== 'Winning update' || !in_array($id, $indexIds, true)) {
        throw new RuntimeException('The winning source or its index entry was lost.');
    }
    echo "Theme models: concurrent source comparison and index lock passed.\n";
} finally {
    foreach ($children as $child) {
        proc_terminate($child);
        proc_close($child);
    }
    $settings->flushCache();
    $models->delete($id);
    foreach (glob($dir . '/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($dir);
}
