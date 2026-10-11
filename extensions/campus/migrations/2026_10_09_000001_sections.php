<?php
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;
return [
    'up' => function (Builder $schema) {
        $schema->create('campus_sections', function (Blueprint $t) {
            $t->increments('id');
            $t->unsignedInteger('tag_id')->unique();
            $t->string('key', 64)->unique();
            $t->boolean('is_open')->default(true);
            $t->string('visibility', 20)->default('public');
        });
    },
    'down' => function (Builder $schema) { $schema->dropIfExists('campus_sections'); },
];
