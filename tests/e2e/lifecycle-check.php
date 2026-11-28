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
$formTables = 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?, ?, ?, ?, ?)';
$formTableNames = [$prefix . 'prime_builder_submissions', $prefix . 'prime_builder_form_tokens', $prefix . 'prime_builder_form_limits', $prefix . 'prime_builder_form_files', $prefix . 'prime_builder_form_notifications', $prefix . 'prime_builder_form_file_gc'];
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
    expectCount('form tables', countRows($pdo, $formTables, $formTableNames), 6);
    $submission = $pdo->prepare("INSERT INTO `{$prefix}prime_builder_submissions` (`entry_id`, `node_id`, `sender_name`, `sender_email`, `message`, `created_at`) VALUES (?, ?, ?, ?, ?, ?)");
    $submission->execute([(int) $entryId, 'fixture', 'Test', 'test@example.test', 'Fixture', gmdate('Y-m-d H:i:s')]);
    $submissionId = (int) $pdo->lastInsertId();
    $file = $pdo->prepare("INSERT INTO `{$prefix}prime_builder_form_files` (`submission_id`, `field_id`, `field_label`, `filename`, `mime`, `bytes`, `content_base64`) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $file->execute([$submissionId, 'f_file', 'File', 'fixture.txt', 'text/plain', 7, base64_encode('Fixture')]);
    // Seed a real private file as well as a legacy database-backed attachment.
    $directory = getcwd() . '/storage/prime-builder/form-files';
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
        throw new RuntimeException('Could not create private file fixture.');
    }
    $storageKey = bin2hex(random_bytes(32));
    $privatePath = $directory . '/' . $storageKey;
    if (file_put_contents($privatePath, 'Private') !== 7 || !chmod($privatePath, 0600)) {
        throw new RuntimeException('Could not write private file fixture.');
    }
    $diskFile = $pdo->prepare("INSERT INTO `{$prefix}prime_builder_form_files` (`submission_id`, `field_id`, `field_label`, `filename`, `mime`, `bytes`, `content_base64`, `storage_key`, `content_sha256`) VALUES (?, ?, ?, ?, ?, ?, '', ?, ?)");
    $diskFile->execute([$submissionId, 'f_disk', 'Private', 'private.txt', 'text/plain', 7, $storageKey, hash('sha256', 'Private')]);
    $notification = $pdo->prepare("INSERT INTO `{$prefix}prime_builder_form_notifications` (`submission_id`, `kind`, `recipient`, `job_id`) VALUES (?, ?, ?, NULL)");
    $job = $pdo->prepare("INSERT INTO `{$prefix}jobs` (`queue`, `handler`, `payload`, `available_at`, `reserved_at`, `failed_at`, `created_at`) VALUES ('default', ?, ?, ?, ?, ?, ?)");
    $link = $pdo->prepare("UPDATE `{$prefix}prime_builder_form_notifications` SET `job_id` = ? WHERE `id` = ?");
    foreach (['reserved', 'failed'] as $state) {
        $notification->execute([$submissionId, $state === 'reserved' ? 'admin' : 'receipt', 'test@example.test']);
        $notificationId = (int) $pdo->lastInsertId();
        $job->execute(['prime-builder.form-notification', json_encode(['notification_id' => $notificationId], JSON_THROW_ON_ERROR), $now, $state === 'reserved' ? $now : null, $state === 'failed' ? $now : null, $now]);
        $link->execute([(int) $pdo->lastInsertId(), $notificationId]);
    }
    $job->execute(['other-plugin.fixture', '{}', $now, null, null, $now]);
    expectCount('form fixture', countRows($pdo, "SELECT COUNT(*) FROM `{$prefix}prime_builder_submissions`"), 1);
    expectCount('form file fixtures', countRows($pdo, "SELECT COUNT(*) FROM `{$prefix}prime_builder_form_files`"), 2);
    expectCount('notification fixtures', countRows($pdo, "SELECT COUNT(*) FROM `{$prefix}prime_builder_form_notifications`"), 2);
    echo "Lifecycle fixtures present before uninstall.\n";
} elseif (($argv[1] ?? '') === 'verify') {
    expectCount('plugin settings', countRows($pdo, $settings, ['plugin.prime-builder.%']), 0);
    expectCount('plugin capabilities', countRows($pdo, $capabilities, ['prime!_builder.%']), 0);
    expectCount('installed plugin', countRows($pdo, $plugin, ['prime-builder']), 0);
    expectCount('form tables', countRows($pdo, $formTables, $formTableNames), 0);
    expectCount('notification jobs', countRows($pdo, "SELECT COUNT(*) FROM `{$prefix}jobs` WHERE `handler` = ?", ['prime-builder.form-notification']), 0);
    expectCount('unrelated job', countRows($pdo, "SELECT COUNT(*) FROM `{$prefix}jobs` WHERE `handler` = ?", ['other-plugin.fixture']), 1);
    echo "Uninstall removed plugin data and preserved content and administrator access.\n";
} else {
    throw new RuntimeException('Usage: lifecycle-check.php seed|verify');
}
