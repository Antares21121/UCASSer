<?php
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;
return ['up'=>function (Builder $s) {
    $s->create('campus_records', function (Blueprint $t) {
        $t->increments('id'); $t->unsignedInteger('discussion_id')->unique(); $t->unsignedInteger('user_id')->nullable()->index(); $t->unsignedInteger('section_id')->index();
        $t->string('type',30)->index(); $t->string('category',50)->index(); $t->string('status',30)->index(); $t->text('data'); $t->unsignedInteger('version')->default(1);
        $t->unsignedInteger('course_id')->nullable()->index(); $t->string('semester',80)->nullable()->index(); $t->unsignedInteger('accepted_post_id')->nullable();
        $t->boolean('featured')->default(false); $t->dateTime('deadline')->nullable()->index(); $t->dateTime('starts_at')->nullable()->index(); $t->dateTime('created_at'); $t->dateTime('updated_at');
        $t->index(['type','status','id']);
    });
    $s->create('campus_revisions', function (Blueprint $t) {
        $t->increments('id'); $t->unsignedInteger('record_id')->index(); $t->unsignedInteger('actor_id')->nullable(); $t->unsignedInteger('version'); $t->text('snapshot'); $t->string('reason',500); $t->dateTime('created_at'); $t->unique(['record_id','version']);
    });
    $s->create('campus_feedback', function (Blueprint $t) {
        $t->increments('id'); $t->unsignedInteger('record_id')->index(); $t->unsignedInteger('user_id')->nullable(); $t->string('kind',30); $t->text('message'); $t->string('status',30)->default('pending'); $t->unsignedInteger('reviewer_id')->nullable(); $t->string('resolution',500)->nullable(); $t->dateTime('created_at');
    });
},'down'=>function (Builder $s) { foreach (['campus_feedback','campus_revisions','campus_records'] as $table) $s->dropIfExists($table); }];
