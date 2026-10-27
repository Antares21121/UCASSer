<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema): void {
        $schema->create('campus_course_offerings', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('course_id')->unique();
            $table->char('identity_hash', 64)->unique();
            $table->string('code', 32);
            $table->string('name', 80);
            $table->string('college', 120)->index();
            $table->string('semester', 80)->index();
            $table->unsignedInteger('resource_category_id')->nullable()->index();
            $table->timestamp('imported_at');
        });
        $schema->create('campus_course_import_runs', function (Blueprint $table): void {
            $table->increments('id');
            $table->char('file_sha256', 64);
            $table->unsignedInteger('rows_read');
            $table->unsignedInteger('courses_added');
            $table->unsignedInteger('resources_indexed');
            $table->unsignedInteger('name_conflicts');
            $table->timestamp('created_at');
        });
    },
    'down' => function (Builder $schema): void {
        $schema->dropIfExists('campus_course_import_runs');
        $schema->dropIfExists('campus_course_offerings');
    },
];
