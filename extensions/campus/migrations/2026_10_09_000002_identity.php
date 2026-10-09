<?php
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;
return ['up' => function (Builder $s) {
    $s->create('campus_verifications', function (Blueprint $t) {
        $t->increments('id'); $t->unsignedInteger('user_id')->index();
        $t->string('status', 20)->default('pending'); $t->text('evidence')->nullable();
        $t->text('reason')->nullable(); $t->unsignedInteger('reviewer_id')->nullable();
        $t->dateTime('created_at'); $t->dateTime('reviewed_at')->nullable();
    });
    $s->create('campus_moderators', function (Blueprint $t) {
        $t->unsignedInteger('user_id'); $t->unsignedInteger('section_id'); $t->primary(['user_id','section_id']);
    });
    $s->create('campus_audit', function (Blueprint $t) {
        $t->increments('id'); $t->unsignedInteger('actor_id')->nullable();
        $t->string('action', 60)->index(); $t->string('target', 80); $t->text('reason')->nullable(); $t->dateTime('created_at');
    });
    $s->create('campus_rate_limits', function (Blueprint $t) {
        $t->string('bucket', 64)->primary(); $t->unsignedInteger('hits'); $t->unsignedInteger('expires');
    });
}, 'down' => function (Builder $s) { foreach (['campus_rate_limits','campus_audit','campus_moderators','campus_verifications'] as $table) $s->dropIfExists($table); }];
