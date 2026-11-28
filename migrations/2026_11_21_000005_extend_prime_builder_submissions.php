<?php

declare(strict_types=1);

use Prime\Database\Identifier;
use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        // Old submissions remain readable. Independent, nullable columns also
        // make a partially applied MySQL DDL migration safely retryable.
        $table = Identifier::quoteSimple($schema->connection()->prefixed('prime_builder_submissions'));
        foreach (['model_id' => 'CHAR(32) NULL', 'fields_json' => 'TEXT NULL'] as $name => $definition) {
            if (!$schema->hasColumn('prime_builder_submissions', $name)) {
                $schema->connection()->statement('ALTER TABLE ' . $table . ' ADD COLUMN `' . $name . '` ' . $definition);
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Never discard submission data on rollback. Full uninstall subsequently
        // removes the whole plugin-owned submissions table in migration 001.
    }
};
