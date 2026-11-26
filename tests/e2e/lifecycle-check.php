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

/** @param list<int|string> $parameters */
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
$formTables = 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?, ?, ?)';
$formTableNames = [$prefix . 'prime_builder_submissions', $prefix . 'prime_builder_form_tokens', $prefix . 'prime_builder_form_limits', $prefix . 'prime_builder_form_files'];
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
    $roleQuery = $pdo->query("SELECT `id` FROM `{$prefix}roles` WHERE `slug` = 'administrator'");
    if ($roleQuery === false) {
        throw new RuntimeException('Could not query administrator role.');
    }
    $roleId = $roleQuery->fetchColumn();
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
    $themeKey = 'plugin.prime-builder.theme_model.' . str_repeat('a', 32);
    $theme = json_encode(['name' => 'Lifecycle fixture', 'kind' => 'header', 'enabled' => false, 'priority' => 0, 'include' => [['type' => 'all', 'value' => '']], 'exclude' => [], 'elements' => []], JSON_THROW_ON_ERROR);
    $now = gmdate('Y-m-d H:i:s');
    $themeInsert = $pdo->prepare("INSERT INTO `{$prefix}settings` (`name`, `value`, `autoload`, `created_at`, `updated_at`) VALUES (?, ?, 0, ?, ?)");
    $themeInsert->execute([$themeKey, $theme, $now, $now]);
    expectCount('theme model fixture', countRows($pdo, "SELECT COUNT(*) FROM `{$prefix}settings` WHERE `name` = ?", [$themeKey]), 1);
    expectCount('plugin capability', countRows($pdo, $roleCapability, $roleParameters), 1);
    expectCount('installed plugin', countRows($pdo, $plugin, ['prime-builder']), 1);
    expectCount('form tables', countRows($pdo, $formTables, $formTableNames), 4);
    $submission = $pdo->prepare("INSERT INTO `{$prefix}prime_builder_submissions` (`entry_id`, `node_id`, `sender_name`, `sender_email`, `message`, `created_at`) VALUES (?, ?, ?, ?, ?, ?)");
    $submission->execute([(int) $entryId, 'fixture', 'Test', 'test@example.test', 'Fixture', gmdate('Y-m-d H:i:s')]);
    $file = $pdo->prepare("INSERT INTO `{$prefix}prime_builder_form_files` (`submission_id`, `field_id`, `field_label`, `filename`, `mime`, `bytes`, `content_base64`) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $file->execute([(int) $pdo->lastInsertId(), 'f_file', 'File', 'fixture.txt', 'text/plain', 7, base64_encode('Fixture')]);
    expectCount('form fixture', countRows($pdo, "SELECT COUNT(*) FROM `{$prefix}prime_builder_submissions`"), 1);
    expectCount('form file fixture', countRows($pdo, "SELECT COUNT(*) FROM `{$prefix}prime_builder_form_files`"), 1);
    echo "Lifecycle fixtures present before uninstall.\n";
} elseif (($argv[1] ?? '') === 'verify') {
    expectCount('plugin settings', countRows($pdo, $settings, ['plugin.prime-builder.%']), 0);
    expectCount('plugin capabilities', countRows($pdo, $capabilities, ['prime!_builder.%']), 0);
    expectCount('installed plugin', countRows($pdo, $plugin, ['prime-builder']), 0);
    expectCount('form tables', countRows($pdo, $formTables, $formTableNames), 0);
    echo "Uninstall removed plugin data and preserved content and administrator access.\n";
} else {
    throw new RuntimeException('Usage: lifecycle-check.php seed|verify');
}
