<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema): void {
        $schema->create('campus_operators', function (Blueprint $table): void {
            $table->unsignedInteger('user_id')->primary();
            $table->dateTime('granted_at');
        });
        $schema->create('campus_collection_jobs', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('requested_by');
            $table->string('status', 20)->index();
            $table->unsignedInteger('source_id')->nullable();
            $table->text('result')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
        });
        $schema->create('campus_chat_images', function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->unsignedInteger('owner_id')->index();
            $table->unsignedInteger('dialog_id')->nullable()->index();
            $table->unsignedInteger('bytes');
            $table->dateTime('created_at')->index();
        });
    },
    'down' => function (Builder $schema): void {
        $schema->dropIfExists('campus_chat_images');
        $schema->dropIfExists('campus_collection_jobs');
        $schema->dropIfExists('campus_operators');
    },
];
