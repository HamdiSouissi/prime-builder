<?php

declare(strict_types=1);

// Only run on an installed disposable CMS and database. Independent processes
// exercise the real shared flock and InnoDB transactions, not mocks.
use Prime\Database\Connection;
use Prime\Hooks\Hooks;
use PrimeBuilder\Forms\FileInput;
use PrimeBuilder\Forms\FileMaintenance;
use PrimeBuilder\Forms\PrivateFiles;
use PrimeBuilder\Forms\Submissions;

function fileRaceMark(string $path): void
{
    if (file_put_contents($path, '1') === false) {
        throw new RuntimeException('Could not write race marker.');
    }
}

function fileRaceWait(string $path): void
{
    $deadline = microtime(true) + 25;
    while (!is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Timed out waiting for ' . basename($path));
        }
        usleep(10000);
        clearstatcache(true, $path);
    }
}

/** @return resource */
function fileRaceChild(string $mode, string $dir, int $entry, int $id = 0)
{
    $command = [PHP_BINARY];
    $ini = php_ini_loaded_file();
    if (is_string($ini)) {
        array_push($command, '-c', $ini);
    }
    array_push($command, __FILE__, $mode, $dir, (string) $entry, (string) $id);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $dir . '/' . $mode . '.out', 'a'], 2 => ['file', $dir . '/' . $mode . '.err', 'a']], $pipes, getcwd() ?: null);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start race child.');
    }
    fclose($pipes[0]);
    return $process;
}

/** @param resource $process */
function fileRaceFinish($process, string $mode, string $dir): void
{
    $status = proc_close($process);
    if ($status !== 0) {
        throw new RuntimeException($mode . ' failed with status ' . $status . ': ' . (string) @file_get_contents($dir . '/' . $mode . '.err'));
    }
}

$mode = $argv[1] ?? 'main';
$app = require getcwd() . '/bootstrap/app.php';
$db = $app->get(Connection::class);
$storage = PrivateFiles::forApp($app);
$repository = new Submissions($db, $storage);
$maintenance = new FileMaintenance($db, $storage);
if ($mode !== 'main') {
    $dir = $argv[2] ?? '';
    $entry = (int) ($argv[3] ?? 0);
    $id = (int) ($argv[4] ?? 0);
    if (!is_dir($dir) || $entry < 1) {
        throw new RuntimeException('Invalid race arguments.');
    }
    if ($mode === 'writer') {
        $file = FileInput::validate(['name' => 'race.txt', 'content' => base64_encode('Concurrent private file')]);
        if ($file === null || !$repository->create($entry, 'race_write', [
            'name' => 'Race', 'email' => 'race@example.test', 'message' => 'Race',
            'files' => [['field_id' => 'f_file', 'field_label' => 'File'] + $file],
        ], hash('sha256', basename($dir)), time(), static function () use ($dir): void {
            fileRaceMark($dir . '/writer-held');
            fileRaceWait($dir . '/release-writer');
        })) {
            throw new RuntimeException('Could not create race submission.');
        }
    } elseif ($mode === 'sweep') {
        fileRaceMark($dir . '/sweep-started');
        // A future clock makes the freshly written file old enough to sweep if
        // the database transaction were not protected by the same flock.
        $maintenance->collect(time() + 7200, 500);
    } elseif ($mode === 'migrate') {
        $app->get(Hooks::class)->on('db.query', static function (string $sql) use ($dir): void {
            if (str_starts_with($sql, 'UPDATE') && str_contains($sql, 'prime_builder_form_files') && str_contains($sql, 'storage_key')) {
                fileRaceMark($dir . '/migrate-held');
                fileRaceWait($dir . '/release-migrate');
            }
        });
        if ($maintenance->migrate(1) !== 1) {
            throw new RuntimeException('Legacy race file was not migrated.');
        }
    } elseif ($mode === 'delete' && $id > 0) {
        fileRaceMark($dir . '/delete-started');
        if (!$repository->delete($id)) {
            throw new RuntimeException('Could not delete race submission.');
        }
    } else {
        throw new RuntimeException('Invalid race mode.');
    }
    fileRaceMark($dir . '/' . $mode . '-done');
    exit(0);
}

$entry = (int) (getenv('E2E_ENTRY_ID') ?: 0);
if ($entry < 1 || $db->table(Submissions::TABLE)->where('node_id', '=', 'race_write')->exists()
    || $db->table(Submissions::TABLE)->where('node_id', '=', 'race_migrate')->exists()) {
    throw new RuntimeException('A disposable E2E_ENTRY_ID and clean race fixture are required.');
}
$pending = $db->select('SELECT `id` FROM `' . $db->prefixed(Submissions::FILE_TABLE) . '` WHERE `storage_key` IS NULL AND `migration_error` IS NULL LIMIT 1');
if ($pending !== []) {
    throw new RuntimeException('The disposable fixture must not contain pending legacy files.');
}
$dir = sys_get_temp_dir() . '/pb-file-race-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) {
    throw new RuntimeException('Could not create race directory.');
}
$children = [];
try {
    $children['writer'] = fileRaceChild('writer', $dir, $entry);
    fileRaceWait($dir . '/writer-held');
    $children['sweep'] = fileRaceChild('sweep', $dir, $entry);
    fileRaceWait($dir . '/sweep-started');
    usleep(350000);
    clearstatcache();
    if (is_file($dir . '/sweep-done')) {
        throw new RuntimeException('Collector passed a writer before its database commit.');
    }
    fileRaceMark($dir . '/release-writer');
    fileRaceWait($dir . '/writer-done');
    fileRaceWait($dir . '/sweep-done');
    foreach (['writer', 'sweep'] as $name) {
        $child = $children[$name] ?? null;
        if (!is_resource($child)) {
            throw new RuntimeException('Race child is missing: ' . $name);
        }
        fileRaceFinish($child, $name, $dir);
        unset($children[$name]);
    }
    $submission = $db->table(Submissions::TABLE)->where('node_id', '=', 'race_write')->first();
    $file = $submission !== null ? $db->table(Submissions::FILE_TABLE)->where('submission_id', '=', (int) $submission['id'])->first() : null;
    if ($file === null || ($repository->fileContents($file)['content'] ?? null) !== 'Concurrent private file') {
        throw new RuntimeException('Collector removed or corrupted a committed file.');
    }
    $repository->delete((int) $submission['id']);
    foreach (glob($dir . '/*') ?: [] as $path) {
        unlink($path);
    }

    $id = $db->table(Submissions::TABLE)->insertGetId(['entry_id' => $entry, 'node_id' => 'race_migrate', 'sender_name' => 'Race', 'sender_email' => 'race@example.test', 'message' => 'Race', 'created_at' => gmdate('Y-m-d H:i:s')]);
    $db->table(Submissions::FILE_TABLE)->insert(['submission_id' => $id, 'field_id' => 'f_file', 'field_label' => 'File', 'filename' => 'legacy.txt', 'mime' => 'text/plain', 'bytes' => 11, 'content_base64' => base64_encode('Legacy race')]);
    $children['migrate'] = fileRaceChild('migrate', $dir, $entry, $id);
    fileRaceWait($dir . '/migrate-held');
    $children['delete'] = fileRaceChild('delete', $dir, $entry, $id);
    fileRaceWait($dir . '/delete-started');
    $children['sweep'] = fileRaceChild('sweep', $dir, $entry);
    fileRaceWait($dir . '/sweep-started');
    usleep(350000);
    clearstatcache();
    if (is_file($dir . '/delete-done') || is_file($dir . '/sweep-done')) {
        throw new RuntimeException('Delete or sweep passed an uncommitted migration.');
    }
    fileRaceMark($dir . '/release-migrate');
    foreach (['migrate', 'delete', 'sweep'] as $name) {
        fileRaceWait($dir . '/' . $name . '-done');
        $child = $children[$name] ?? null;
        if (!is_resource($child)) {
            throw new RuntimeException('Race child is missing: ' . $name);
        }
        fileRaceFinish($child, $name, $dir);
        unset($children[$name]);
    }
    $maintenance->collect(time() + 7200, 500);
    if ($db->table(Submissions::TABLE)->find($id) !== null || $db->table(Submissions::FILE_TABLE)->where('submission_id', '=', $id)->exists()
        || $db->table(Submissions::FILE_GC_TABLE)->exists()) {
        throw new RuntimeException('Migration/deletion race left database rows or queued files.');
    }
    echo "Private files: writer/collector and migration/deletion/collector races passed.\n";
} finally {
    foreach ($children as $child) {
        proc_terminate($child);
        proc_close($child);
    }
    foreach (['race_write', 'race_migrate'] as $node) {
        foreach ($db->table(Submissions::TABLE)->where('node_id', '=', $node)->get() as $row) {
            $repository->delete((int) $row['id']);
        }
    }
    foreach (glob($dir . '/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($dir);
}
