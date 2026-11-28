<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        // The contents live in the database, not in the CMS public or media directories.
        $schema->create('prime_builder_form_files', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('submission_id');
            $table->string('field_id', 26);
            $table->string('field_label', 100);
            $table->string('filename', 160);
            $table->string('mime', 80);
            $table->unsignedInteger('bytes');
            $table->mediumText('content_base64');
            $table->index('submission_id', 'prime_builder_form_files_submission');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('prime_builder_form_files');
    }
};
