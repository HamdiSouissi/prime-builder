<?php

declare(strict_types=1);

use Prime\Database\Identifier;
use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        // The signed directory needs the site's application key and validator;
        // a marker causes the active plugin to build it on its first request.
        // Do not touch legacy model records or overwrite an existing directory.
        $db = $schema->connection();
        $table = Identifier::quoteSimple($db->prefixed('settings'));
        $sql = $db->driverName() === 'sqlite'
            ? 'INSERT OR IGNORE INTO ' . $table . ' (`name`, `value`, `autoload`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?)'
            : 'INSERT INTO ' . $table . ' (`name`, `value`, `autoload`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE `name` = `name`';
        $now = gmdate('Y-m-d H:i:s');
        $db->statement($sql, ['plugin.prime-builder.theme_model_index', 'null', 1, $now, $now]);
    }

    public function down(Schema $schema): void
    {
        // Derived data only: source model records are kept intact.
        $schema->connection()->table('settings')->where('name', '=', 'plugin.prime-builder.theme_model_index')->delete();
    }
};
