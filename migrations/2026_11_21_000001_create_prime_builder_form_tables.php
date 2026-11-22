<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('prime_builder_submissions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('entry_id');
            $table->string('node_id', 64);
            $table->string('sender_name', 160);
            $table->string('sender_email', 254);
            $table->text('message');
            $table->dateTime('created_at');
            $table->index('created_at', 'prime_builder_submission_created');
        });
        $schema->create('prime_builder_form_tokens', static function (Blueprint $table): void {
            $table->char('token_hash', 64)->primary();
            $table->dateTime('expires_at');
            $table->index('expires_at', 'prime_builder_form_token_expiry');
        });
        $schema->create('prime_builder_form_limits', static function (Blueprint $table): void {
            $table->char('key_hash', 64)->primary();
            $table->unsignedInteger('hits');
            $table->dateTime('expires_at');
            $table->index('expires_at', 'prime_builder_form_limit_expiry');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('prime_builder_form_limits');
        $schema->dropIfExists('prime_builder_form_tokens');
        $schema->dropIfExists('prime_builder_submissions');
    }
};
