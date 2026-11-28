<?php

declare(strict_types=1);

use Prime\Database\Identifier;
use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        // DDL can commit independently on MySQL. Each part is safe to retry;
        // legacy bytes remain in place until the separate migration has verified
        // and persisted their private file and committed the corresponding row.
        $db = $schema->connection();
        $tableName = $db->prefixed('prime_builder_form_files');
        $table = Identifier::quoteSimple($tableName);
        foreach (['storage_key' => 'CHAR(64) NULL', 'content_sha256' => 'CHAR(64) NULL', 'migration_error' => 'VARCHAR(32) NULL'] as $name => $definition) {
            if (!$schema->hasColumn('prime_builder_form_files', $name)) {
                $db->statement('ALTER TABLE ' . $table . ' ADD COLUMN `' . $name . '` ' . $definition);
            }
        }
        $indexName = 'prime_builder_file_storage';
        if ($db->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $indexes = $db->select('PRAGMA index_list(' . $table . ')');
            $exists = in_array($indexName, array_column($indexes, 'name'), true);
        } else {
            $indexes = $db->select('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', [$tableName, $indexName]);
            $exists = $indexes !== [];
        }
        if (!$exists) {
            $db->statement('CREATE INDEX `' . $indexName . '` ON ' . $table . ' (`storage_key`)');
        }
        if (!$schema->hasTable('prime_builder_form_file_gc')) {
            $schema->create('prime_builder_form_file_gc', static function (Blueprint $table): void {
                $table->char('storage_key', 64)->primary();
            });
        }
    }

    public function down(Schema $schema): void
    {
        // Existing file references and legacy bytes are not discarded by a
        // partial rollback. Full uninstall first removes the private files.
        $schema->dropIfExists('prime_builder_form_file_gc');
    }
};
