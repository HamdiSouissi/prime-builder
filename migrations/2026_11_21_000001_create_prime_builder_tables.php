<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('prime_builder_templates', static function (Blueprint $table): void {
            $table->id();
            $table->string('title', 200);
            $table->string('type', 32); // page, section, widget, popup, header, footer, single, archive, search, 404
            $table->string('status', 24)->default('published'); // draft, published
            $table->longText('document_json');
            $table->text('conditions_json')->nullable();
            $table->text('triggers_json')->nullable();
            $table->integer('priority')->default(10);
            $table->unsignedBigInteger('author_id')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index(['type', 'status', 'priority'], 'pb_templates_lookup');
        });

        $schema->create('prime_builder_submissions', static function (Blueprint $table): void {
            $table->id();
            $table->string('form_id', 64);
            $table->unsignedBigInteger('entry_id')->nullable();
            $table->string('form_name', 160)->default('');
            $table->longText('data_json');
            $table->text('meta_json')->nullable();
            $table->string('ip_hash', 64)->default('');
            $table->string('status', 24)->default('unread'); // unread, read
            $table->dateTime('created_at');
            $table->index(['form_id', 'created_at'], 'pb_submissions_form');
            $table->index(['status', 'created_at'], 'pb_submissions_status');
        });

        $schema->create('prime_builder_files', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('submission_id');
            $table->string('field_name', 80);
            $table->string('original_name', 255);
            $table->string('storage_path', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->dateTime('created_at');
            $table->index(['submission_id'], 'pb_files_submission');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('prime_builder_files');
        $schema->dropIfExists('prime_builder_submissions');
        $schema->dropIfExists('prime_builder_templates');
    }
};
