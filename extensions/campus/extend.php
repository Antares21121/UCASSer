<?php
use Flarum\Extend;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Flarum\Tags\Tag;
use UCASSer\Campus;
return [
    (new Extend\ServiceProvider())->register(Campus\LocaleProvider::class),
    // First-party internal API calls (controllers re-dispatching through
    // Flarum\Api\Client to create or revise the backing discussions) must not be
    // flood-throttled as if the user were hammering the endpoints: on core 2.0.2
    // the 10s post window fires inside the inner pipe and breaks record writes a
    // few seconds apart. Campus HTTP routes keep their own per-user limits; only
    // the internal re-dispatch is exempt, matching core's bypassThrottling design.
    (new Extend\ThrottleApi())->set('campusInternalBypass', function ($request) {
        return RequestUtil::isInternal($request) ? false : null;
    }),
    new Extend\Locales(__DIR__.'/locale'),
    (new Extend\Middleware('forum'))->replace(\Flarum\Http\Middleware\SetLocale::class, Campus\ForumLocale::class),
    (new Extend\Middleware('api'))->replace(\Flarum\Http\Middleware\SetLocale::class, Campus\ForumLocale::class),
    (new Extend\ApiResource(\Flarum\Api\Resource\DiscussionResource::class))->fields(Campus\NativeRecordFields::class)
        ->endpoint('index', fn(\Flarum\Api\Endpoint\Index $endpoint) => $endpoint->addDefaultInclude(['firstPost'])->eagerLoad(['firstPost'])->after(Campus\NativeListFields::hydrate(...))),
    (new Extend\ApiResource(\Flarum\Api\Resource\UserResource::class))->fields(Campus\UserCampusFields::class),
    (new Extend\SearchDriver(\Flarum\Search\Database\DatabaseSearchDriver::class))->addFilter(\Flarum\Discussion\Search\DiscussionSearcher::class,Campus\NativeFilter::class),
    (new Extend\Frontend('forum'))->js(__DIR__.'/js/dist/forum.js')->css(__DIR__.'/resources/campus.less')->css(__DIR__.'/resources/aggregation.less')->css(__DIR__.'/resources/control.less')
        ->route('/campus', 'campus')->route('/campus/rules', 'campus.rules')->route('/campus/identity','campus.identity')->route('/campus/moderation','campus.moderation')
        ->route('/campus/records','campus.records')->route('/campus/record/{id}','campus.record')->route('/campus/publish','campus.publish'),
    (new Extend\Frontend('forum'))->route('/campus/courses','campus.courses'),
    (new Extend\Frontend('forum'))->route('/campus/me','campus.me'),
    (new Extend\Frontend('forum'))->route('/campus/search','campus.search'),
    (new Extend\Frontend('forum'))->route('/campus/cases','campus.cases'),
    (new Extend\Frontend('forum'))->route('/campus/governance','campus.governance'),
    (new Extend\Frontend('forum'))->route('/campus/tools','campus.toolbox')->route('/campus/tools/{tool}','campus.toolbox.tool'),
    (new Extend\Frontend('forum'))->route('/campus/aggregation','campus.aggregation')->route('/campus/aggregation/submit','campus.aggregation.submit')->route('/campus/aggregation/review','campus.aggregation.review')->route('/campus/aggregation/sources','campus.aggregation.source.manage')
        ->route('/campus/control','campus.control'),
    (new Extend\Frontend('admin'))->js(__DIR__.'/js/dist/admin.js')->css(__DIR__.'/resources/campus.less'),
    (new Extend\Routes('api'))->get('/campus/home', 'campus.home', Campus\Controller::class)
        ->get('/campus/rules', 'campus.rules', Campus\Controller::class)
        ->get('/campus/sections', 'campus.sections', Campus\Controller::class)
        ->patch('/campus/sections/{id}', 'campus.sections.update', Campus\Controller::class),
    (new Extend\ModelVisibility(Discussion::class))->scope(Campus\Visibility::class),
    (new Extend\ModelVisibility(Tag::class))->scope(Campus\TagVisibility::class),
    (new Extend\Policy())->modelPolicy(Discussion::class, Campus\SectionPolicy::class)->modelPolicy(Tag::class, Campus\SectionPolicy::class),
    (new Extend\Policy())->modelPolicy(Discussion::class,Campus\RecordPolicy::class)->modelPolicy(\Flarum\Post\Post::class,Campus\RecordPolicy::class),
    (new Extend\Console())->command(Campus\SetupCommand::class),
    (new Extend\Console())->command(Campus\BoardMigrateCommand::class),
    (new Extend\Console())->command(Campus\CourseImportCommand::class),
    (new Extend\Console())->command(Campus\AggregationPollCommand::class),
    (new Extend\Console())->command(Campus\AggregationSyncSourcesCommand::class),
    (new Extend\Console())->command(Campus\AggregationCollectorStatusCommand::class),
    (new Extend\Console())->command(Campus\AggregationImportTrialCommand::class),
    (new Extend\Console())->command(Campus\AggregationImportFeedCommand::class),
    (new Extend\Console())->command(Campus\AggregationImportWebLocalCommand::class),
    (new Extend\Console())->command(Campus\OperatorGrantCommand::class),
    (new Extend\Console())->command(Campus\CollectionJobCommand::class),
    (new Extend\Event())->listen(\Flarum\Post\Event\Saving::class,Campus\NativeRevision::class)->listen(\Flarum\Discussion\Event\Saving::class,Campus\NativeRevision::class)
        ->listen(\Flarum\Messages\DialogMessage\Event\Created::class,Campus\ChatImages::class),
    (new Extend\Event())->listen(\Flarum\Post\Event\Posted::class,Campus\PostImages::class)
        ->listen(\Flarum\Post\Event\Revised::class,Campus\PostImages::class)
        ->listen(\Flarum\Post\Event\Restored::class,Campus\PostImages::class),
    (new Extend\Event())->listen(\Flarum\User\Event\Registered::class,Campus\UserAccountSetup::class)
        ->listen(\Flarum\User\Event\Deleted::class,Campus\UserAccountSetup::class),
    // Hand-typed `@昵称#后缀` resolves to the stable user id before the
    // mentions extension parses, and rendered mentions carry the suffix.
    (new Extend\Formatter())->parse(Campus\SuffixMentionParser::class)->render(Campus\SuffixMentionRenderer::class),
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
    (new Extend\Routes('api'))->get('/campus/aggregation/items','campus.aggregation.items',Campus\AggregationController::class)
        ->get('/campus/aggregation/items/{id}','campus.aggregation.item',Campus\AggregationController::class)
        ->post('/campus/aggregation/submissions','campus.aggregation.submit',Campus\AggregationController::class)
        ->get('/campus/aggregation/mine','campus.aggregation.mine',Campus\AggregationController::class)
        ->get('/campus/aggregation/queue','campus.aggregation.queue',Campus\AggregationController::class)
        ->post('/campus/aggregation/candidates/{id}/review','campus.aggregation.review',Campus\AggregationController::class)
        ->get('/campus/aggregation/sources','campus.aggregation.sources',Campus\AggregationController::class)
        ->post('/campus/aggregation/sources','campus.aggregation.source.create',Campus\AggregationController::class)
        ->patch('/campus/aggregation/sources/{id}','campus.aggregation.source.update',Campus\AggregationController::class),
    (new Extend\Routes('api'))->get('/campus/control','campus.control',Campus\OperationsController::class)
        ->post('/campus/control/collect','campus.control.collect',Campus\OperationsController::class)
        ->post('/campus/control/import','campus.control.import',Campus\OperationsController::class)
        ->patch('/campus/control/sources/{id}','campus.control.source.update',Campus\OperationsController::class),
    (new Extend\Routes('api'))->post('/campus/chat-images','campus.chat.image.upload',Campus\ChatImageController::class)
        ->get('/campus/chat-images/{id}','campus.chat.image',Campus\ChatImageController::class),
    (new Extend\Routes('api'))->post('/campus/post-images','campus.post.image.upload',Campus\PostImageController::class)
        ->get('/campus/post-images/{id}','campus.post.image',Campus\PostImageController::class),
    (new Extend\Routes('api'))->get('/campus/colleges','campus.colleges',Campus\CatalogAdminController::class),
    (new Extend\Routes('api'))->get('/campus/control/catalog/categories','campus.control.catalog.categories',Campus\CatalogAdminController::class)
        ->get('/campus/control/catalog/colleges','campus.control.catalog.colleges',Campus\CatalogAdminController::class)
        ->post('/campus/control/catalog/colleges','campus.control.catalog.colleges.create',Campus\CatalogAdminController::class)
        ->patch('/campus/control/catalog/colleges/{id}','campus.control.catalog.colleges.update',Campus\CatalogAdminController::class)
        ->post('/campus/control/catalog/colleges/import','campus.control.catalog.colleges.import',Campus\CatalogAdminController::class)
        ->get('/campus/control/catalog/courses','campus.control.catalog.courses',Campus\CatalogAdminController::class)
        ->post('/campus/control/catalog/courses','campus.control.catalog.courses.create',Campus\CatalogAdminController::class)
        ->patch('/campus/control/catalog/courses/{id}','campus.control.catalog.courses.update',Campus\CatalogAdminController::class)
        ->post('/campus/control/catalog/courses/import','campus.control.catalog.courses.import',Campus\CatalogAdminController::class)
        ->get('/campus/control/catalog/resources','campus.control.catalog.resources',Campus\CatalogAdminController::class)
        ->post('/campus/control/catalog/resources','campus.control.catalog.resources.create',Campus\CatalogAdminController::class)
        ->patch('/campus/control/catalog/resources/{id}','campus.control.catalog.resources.update',Campus\CatalogAdminController::class)
        ->post('/campus/control/catalog/resources/import','campus.control.catalog.resources.import',Campus\CatalogAdminController::class)
        ->post('/campus/control/catalog/import/{id}/rollback','campus.control.catalog.import.rollback',Campus\CatalogAdminController::class),
    (new Extend\Routes('api'))->get('/campus/assets/{file}','campus.asset',Campus\CampusAssetController::class),
    (new Extend\Routes('api'))->get('/campus/tools/data','campus.tools.data',Campus\CampusToolsController::class),
    (new Extend\Routes('api'))->get('/campus/control/collector','campus.control.collector',Campus\CollectorController::class),
    (new Extend\Routes('api'))->post('/campus/control/collector/qr','campus.control.collector.qr',Campus\CollectorController::class),
    (new Extend\Routes('api'))->post('/campus/control/collector/subscribe','campus.control.collector.subscribe',Campus\CollectorController::class),
    // Retired discussion boards keep their old URLs alive via 301s.
    (new Extend\Middleware('forum'))->add(Campus\LegacyBoardRedirect::class),
    (new Extend\Routes('api'))->get('/campus/governance','campus.governance',Campus\GovernanceController::class)->post('/campus/governance','campus.governance.publish',Campus\GovernanceController::class),
    (new Extend\Routes('api'))->get('/campus/cases','campus.cases',Campus\CaseController::class)->post('/campus/cases','campus.cases.create',Campus\CaseController::class)
        ->get('/campus/cases/{id}','campus.case',Campus\CaseController::class)->patch('/campus/cases/{id}','campus.case.update',Campus\CaseController::class)
        ->post('/campus/cases/{id}/appeal','campus.case.appeal',Campus\CaseController::class)->post('/campus/cases/{id}/supplement','campus.case.supplement',Campus\CaseController::class),
    (new Extend\Routes('api'))->get('/campus/identity', 'campus.identity', Campus\IdentityController::class)
        ->post('/campus/identity', 'campus.identity.submit', Campus\IdentityController::class)
        ->get('/campus/identity/queue', 'campus.identity.queue', Campus\IdentityController::class)
        ->patch('/campus/identity/{id}', 'campus.identity.review', Campus\IdentityController::class)
        ->get('/campus/moderators', 'campus.moderators.list', Campus\IdentityController::class)
        ->post('/campus/moderators', 'campus.moderators', Campus\IdentityController::class)
        ->post('/campus/moderate', 'campus.moderate', Campus\IdentityController::class)
        ->get('/campus/audit', 'campus.audit', Campus\IdentityController::class),
    (new Extend\Middleware('api'))->add(Campus\RateLimit::class),
    (new Extend\Middleware('api'))->add(Campus\NativePagination::class),
    (new Extend\Notification())->type(Campus\Notice::class,['alert'])->type(Campus\AccountNotice::class,['alert'])->beforeSending(Campus\NotificationGuard::class),
    (new Extend\Middleware('api'))->add(Campus\NotificationGuard::class),
    (new Extend\Middleware('forum'))->add(Campus\RateLimit::class),
    (new Extend\Conditional())->whenExtensionEnabled('flarum-gdpr', fn()=>[(new \Flarum\Gdpr\Extend\UserData())->addType(Campus\CampusData::class),(new Extend\Middleware('forum'))->add(Campus\ExportGuard::class)]),
];
