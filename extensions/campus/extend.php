<?php
use Flarum\Extend;
use Flarum\Discussion\Discussion;
use Flarum\Tags\Tag;
use UCASSer\Campus;
return [
    (new Extend\Frontend('forum'))->js(__DIR__.'/js/dist/forum.js')->css(__DIR__.'/resources/campus.less')
        ->route('/campus', 'campus')->route('/campus/rules', 'campus.rules')->route('/campus/identity','campus.identity')->route('/campus/moderation','campus.moderation')
        ->route('/campus/records','campus.records')->route('/campus/record/{id}','campus.record')->route('/campus/publish','campus.publish'),
    (new Extend\Frontend('forum'))->route('/campus/courses','campus.courses'),
    (new Extend\Frontend('forum'))->route('/campus/me','campus.me'),
    (new Extend\Frontend('forum'))->route('/campus/search','campus.search'),
    (new Extend\Frontend('forum'))->route('/campus/cases','campus.cases'),
    (new Extend\Frontend('forum'))->route('/campus/governance','campus.governance'),
    (new Extend\Frontend('admin'))->js(__DIR__.'/js/dist/admin.js'),
    (new Extend\Routes('api'))->get('/campus/home', 'campus.home', Campus\Controller::class)
        ->get('/campus/rules', 'campus.rules', Campus\Controller::class)
        ->get('/campus/sections', 'campus.sections', Campus\Controller::class)
        ->patch('/campus/sections/{id}', 'campus.sections.update', Campus\Controller::class),
    (new Extend\ModelVisibility(Discussion::class))->scope(Campus\Visibility::class),
    (new Extend\ModelVisibility(Tag::class))->scope(Campus\TagVisibility::class),
    (new Extend\Policy())->modelPolicy(Discussion::class, Campus\SectionPolicy::class)->modelPolicy(Tag::class, Campus\SectionPolicy::class),
    (new Extend\Policy())->modelPolicy(Discussion::class,Campus\RecordPolicy::class)->modelPolicy(\Flarum\Post\Post::class,Campus\RecordPolicy::class),
    (new Extend\Console())->command(Campus\SetupCommand::class),
    (new Extend\Event())->listen(\Flarum\Post\Event\Saving::class,Campus\NativeRevision::class)->listen(\Flarum\Discussion\Event\Saving::class,Campus\NativeRevision::class),
    (new Extend\Routes('api'))->get('/campus/catalog','campus.catalog',Campus\RecordController::class)
        ->get('/campus/records','campus.records',Campus\RecordController::class)->post('/campus/records','campus.records.create',Campus\RecordController::class)
        ->get('/campus/records/{id}','campus.record',Campus\RecordController::class)->patch('/campus/records/{id}','campus.record.update',Campus\RecordController::class)
        ->post('/campus/records/{id}/comments','campus.comment',Campus\RecordController::class)
        ->post('/campus/records/{id}/bookmark','campus.bookmark',Campus\RecordController::class)
        ->post('/campus/records/{id}/accept','campus.accept',Campus\RecordController::class)->post('/campus/records/{id}/convert','campus.convert',Campus\RecordController::class)
        ->post('/campus/records/{id}/useful','campus.useful',Campus\RecordController::class)
        ->get('/campus/records/{id}/revisions/{version}','campus.revision',Campus\RecordController::class)
        ->get('/campus/categories','campus.categories',Campus\RecordController::class)->patch('/campus/categories','campus.categories.update',Campus\RecordController::class)
        ->post('/campus/records/{id}/feedback','campus.feedback',Campus\RecordController::class)->post('/campus/records/{id}/feedback/resolve','campus.resolveFeedback',Campus\RecordController::class),
    (new Extend\Routes('api'))->get('/campus/courses','campus.courses',Campus\CourseController::class)->post('/campus/courses','campus.courses.create',Campus\CourseController::class)->patch('/campus/courses/{id}','campus.courses.update',Campus\CourseController::class),
    (new Extend\Routes('api'))->get('/campus/me','campus.me',Campus\PersonalController::class)
        ->get('/campus/discussions/{id}/bookmark','campus.discussion.bookmark',Campus\PersonalController::class)->post('/campus/discussions/{id}/bookmark','campus.discussion.bookmark.update',Campus\PersonalController::class),
    (new Extend\Routes('api'))->get('/campus/search','campus.search',Campus\SearchController::class),
    (new Extend\Routes('api'))->get('/campus/governance','campus.governance',Campus\GovernanceController::class)->post('/campus/governance','campus.governance.publish',Campus\GovernanceController::class),
    (new Extend\Routes('api'))->get('/campus/cases','campus.cases',Campus\CaseController::class)->post('/campus/cases','campus.cases.create',Campus\CaseController::class)
        ->get('/campus/cases/{id}','campus.case',Campus\CaseController::class)->patch('/campus/cases/{id}','campus.case.update',Campus\CaseController::class)
        ->post('/campus/cases/{id}/appeal','campus.case.appeal',Campus\CaseController::class)->post('/campus/cases/{id}/supplement','campus.case.supplement',Campus\CaseController::class),
    (new Extend\Routes('api'))->get('/campus/identity', 'campus.identity', Campus\IdentityController::class)
        ->post('/campus/identity', 'campus.identity.submit', Campus\IdentityController::class)
        ->get('/campus/identity/queue', 'campus.identity.queue', Campus\IdentityController::class)
        ->patch('/campus/identity/{id}', 'campus.identity.review', Campus\IdentityController::class)
        ->post('/campus/moderators', 'campus.moderators', Campus\IdentityController::class)
        ->post('/campus/moderate', 'campus.moderate', Campus\IdentityController::class)
        ->get('/campus/audit', 'campus.audit', Campus\IdentityController::class),
    (new Extend\Middleware('api'))->add(Campus\RateLimit::class),
    (new Extend\Notification())->type(Campus\Notice::class,['alert'])->type(Campus\AccountNotice::class,['alert'])->beforeSending(Campus\NotificationGuard::class),
    (new Extend\Middleware('api'))->add(Campus\NotificationGuard::class),
    (new Extend\Middleware('forum'))->add(Campus\RateLimit::class),
    (new Extend\Conditional())->whenExtensionEnabled('flarum-gdpr', fn()=>[(new \Flarum\Gdpr\Extend\UserData())->addType(Campus\CampusData::class),(new Extend\Middleware('forum'))->add(Campus\ExportGuard::class)]),
];
