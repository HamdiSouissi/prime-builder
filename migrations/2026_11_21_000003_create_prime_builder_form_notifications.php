<?php

declare(strict_types=1);

use Prime\Database\Migrations\Migration;
use Prime\Database\Schema\Blueprint;
use Prime\Database\Schema\Schema;

return new class () extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('prime_builder_form_notifications', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('submission_id');
            $table->string('kind', 16);
            $table->string('recipient', 254);
            $table->unsignedBigInteger('job_id')->nullable();
            $table->index('submission_id', 'prime_builder_form_notification_submission');
            $table->index('job_id', 'prime_builder_form_notification_job');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('prime_builder_form_notifications');
    }
};
