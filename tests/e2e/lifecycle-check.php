<?php

declare(strict_types=1);

// Runs only against the disposable CI database, before and after the real CLI uninstall.
$prefix = getenv('DB_PREFIX') ?: 'pc_';
$entryId = getenv('E2E_ENTRY_ID') ?: '';
if (!preg_match('/^[A-Za-z0-9_]+$/D', $prefix) || !ctype_digit($entryId) || (int) $entryId < 1) {
    throw new RuntimeException('Invalid database prefix or E2E_ENTRY_ID.');
}
$dsn = getenv('LIFECYCLE_DB_DSN') ?: sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_DATABASE'));
$pdo = new PDO(
    $dsn,
    (string) getenv('DB_USERNAME'),
    (string) getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

function countRows(PDO $pdo, string $sql, array $parameters = []): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    return (int) $statement->fetchColumn();
}

function expectCount(string $name, int $actual, int $expected): void
{
    if ($actual !== $expected) {
        throw new RuntimeException(sprintf('%s: expected %d, got %d', $name, $expected, $actual));
    }
}

$settings = "SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` LIKE ?";
$capabilities = "SELECT COUNT(*) FROM `{$prefix}role_capabilities` WHERE `capability` LIKE ? ESCAPE '!'";
$plugin = "SELECT COUNT(*) FROM `{$prefix}plugins` WHERE `slug` = ?";
$entry = $pdo->prepare("SELECT `content_html` FROM `{$prefix}entries` WHERE `id` = ?");
$entry->execute([(int) $entryId]);
$html = $entry->fetchColumn();
if (!is_string($html) || !str_contains($html, '<h2>Contenu conservé</h2>')) {
    throw new RuntimeException('The published entry or its stored HTML is missing.');
}
if (countRows($pdo, "SELECT COUNT(*) FROM `{$prefix}role_capabilities` WHERE `capability` = ?", ['*']) < 1) {
    throw new RuntimeException('The unrelated administrator capability is missing.');
}

if (($argv[1] ?? '') === 'seed') {
    $roleId = $pdo->query("SELECT `id` FROM `{$prefix}roles` WHERE `slug` = 'administrator'")->fetchColumn();
    if (!$roleId) {
        throw new RuntimeException('Administrator role not found.');
    }
    $roleCapability = "SELECT COUNT(*) FROM `{$prefix}role_capabilities` WHERE `role_id` = ? AND `capability` = ?";
    $roleParameters = [(int) $roleId, 'prime_builder.edit'];
    if (countRows($pdo, $roleCapability, $roleParameters) === 0) {
        $statement = $pdo->prepare("INSERT INTO `{$prefix}role_capabilities` (`role_id`, `capability`) VALUES (?, ?)");
        $statement->execute($roleParameters);
    }
    expectCount('plugin setting', countRows($pdo, "SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` = ?", ['plugin.prime-builder.configuration']), 1);
    expectCount('plugin capability', countRows($pdo, $roleCapability, $roleParameters), 1);
    expectCount('installed plugin', countRows($pdo, $plugin, ['prime-builder']), 1);
    echo "Lifecycle fixtures present before uninstall.\n";
} elseif (($argv[1] ?? '') === 'verify') {
    expectCount('plugin settings', countRows($pdo, $settings, ['plugin.prime-builder.%']), 0);
    expectCount('plugin capabilities', countRows($pdo, $capabilities, ['prime!_builder.%']), 0);
    expectCount('installed plugin', countRows($pdo, $plugin, ['prime-builder']), 0);
    echo "Uninstall removed plugin data and preserved content and administrator access.\n";
} else {
    throw new RuntimeException('Usage: lifecycle-check.php seed|verify');
}
