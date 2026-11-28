<?php

declare(strict_types=1);

// Test fixture: simulate a disabled third-party renderer without changing its saved HTML.
$id = $argv[1] ?? '';
$prefix = getenv('DB_PREFIX') ?: 'pc_';
if (!ctype_digit($id) || (int) $id < 1 || preg_match('/^[A-Za-z0-9_]+$/D', $prefix) !== 1) {
    throw new RuntimeException('Invalid entry ID or database prefix.');
}
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_DATABASE'));
$pdo = new PDO($dsn, (string) getenv('DB_USERNAME'), (string) getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$statement = $pdo->prepare("SELECT `content` FROM `{$prefix}entries` WHERE `id` = ?");
$statement->execute([(int) $id]);
$content = $statement->fetchColumn();
if (!is_string($content)) {
    throw new RuntimeException('Fixture entry not found.');
}
$document = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
$indexes = array_slice($argv, 2);
if ($indexes === []) {
    $indexes = ['1'];
}
foreach ($indexes as $index) {
    if (!ctype_digit($index) || !is_array($document) || ($document['blocks'][(int) $index]['type'] ?? null) !== 'html') {
        throw new RuntimeException('Expected an HTML block at each fixture index.');
    }
    $document['blocks'][(int) $index]['type'] = 'missing/block-' . $index;
}
$encoded = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$update = $pdo->prepare("UPDATE `{$prefix}entries` SET `content` = ? WHERE `id` = ?");
$update->execute([$encoded, (int) $id]);
if ($update->rowCount() !== 1) {
    throw new RuntimeException('Fixture could not be updated.');
}
echo "Unavailable block fixture prepared; saved HTML retained.\n";
