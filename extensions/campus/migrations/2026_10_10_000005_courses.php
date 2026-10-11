<?php
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Blueprint;
return ['up'=>function (Builder $s) {
    $s->create('campus_courses',function (Blueprint $t) { $t->increments('id'); $t->string('name',80); $t->string('type',80); $t->string('grade',80); $t->string('semester',80); $t->string('nature',80); $t->text('assessment'); $t->text('resources'); $t->unsignedInteger('version')->default(1); $t->index(['type','semester']); });
    $s->create('campus_votes',function (Blueprint $t) { $t->unsignedInteger('user_id'); $t->unsignedInteger('record_id'); $t->primary(['user_id','record_id']); });
    $s->table('campus_records',function (Blueprint $t) { $t->unique(['user_id','course_id','semester'],'campus_review_once'); });
},'down'=>function (Builder $s) { $s->table('campus_records',fn(Blueprint $t)=>$t->dropUnique('campus_review_once')); $s->dropIfExists('campus_votes'); $s->dropIfExists('campus_courses'); }];
