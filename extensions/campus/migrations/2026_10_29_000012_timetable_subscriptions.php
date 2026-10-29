<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

// A member's own timetable: which published schedule entries they subscribed
// to. Entry ids come from the read-only timetable export; no course content is
// copied here, so a new export keeps subscriptions valid as long as the slot
// identity stays the same.
return [
    'up' => function (Builder $schema): void {
        if ($schema->hasTable('campus_course_subscriptions')) return;
        $schema->create('campus_course_subscriptions', function (Blueprint $table): void {
            $table->unsignedInteger('user_id');
            $table->char('entry_id', 12);
            $table->timestamp('created_at');
            $table->primary(['user_id', 'entry_id']);
            $table->index('entry_id');
        });
    },
    'down' => function (Builder $schema): void {
        $schema->dropIfExists('campus_course_subscriptions');
    },
];
