<?php
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'development') throw new RuntimeException('Development CLI only');
define('UCASSER_OPERATOR_PROVISION_LIBRARY', true);
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../scripts/provision_operator.php';

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Laminas\Diactoros\ServerRequest;
use UCASSer\Campus\Aggregation;
use UCASSer\Campus\AggregationPollCommand;
use UCASSer\Campus\CollectionJobCommand;
use UCASSer\Campus\CollectorClient;
use UCASSer\Campus\WechatImport;

class FixturePoll extends AggregationPollCommand {
    public array $report = ['sources' => 1, 'succeeded' => 1, 'failed' => 0, 'partial' => 0, 'added' => 0];
    public function result(): array { return $this->report; }
    protected function fire(): int { $this->info('Fixture source outcome'); return $this->report['failed'] ? 1 : 0; }
}
class FixtureWechat extends WechatImport {
    public int $calls = 0;
    public array $report = ['added' => 2, 'failed' => 0, 'accounts' => 2];
    public function __construct() {}
    public function run(int $limit = 5): array { $this->calls++; return $this->report; }
}
class FixtureCollector extends CollectorClient {
    public int $calls = 0;
    public function __construct() {}
    public function state(): array { $this->calls++; return ['state' => 'ready', 'message' => 'Fixture']; }
}
$c = (require __DIR__.'/../site.php')->bootApp()->getContainer();
$db = $c->make(Illuminate\Database\ConnectionInterface::class);
$aggregation = $c->make(Aggregation::class);
$check = function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$snapshot = function () use ($db): string {
    $state = [];
    foreach (['users', 'campus_operators', 'campus_user_tags', 'campus_user_stats', 'settings', 'campus_collection_jobs',
        'campus_aggregation_sources', 'campus_aggregation_candidates', 'campus_aggregation_items', 'campus_audit'] as $table) {
        $state[$table] = $db->table($table)->get()->toJson();
    }
    return hash('sha256', json_encode($state));
};
$original = $snapshot();
$db->beginTransaction();
try {
    $name = 'workflow_'.bin2hex(random_bytes(4));
    $provision = ucasserProvisionOperator($c, ['username' => $name, 'email' => $name.'@example.invalid',
        'password' => bin2hex(random_bytes(16)), 'owner_email_confirmed' => true], true);
    $actor = Flarum\User\User::findOrFail($provision['user_id']);
    $db->table('campus_collection_jobs')->delete();
    $controller = $c->make(UCASSer\Campus\OperationsController::class);
    $enqueue = function (array $body = []) use ($controller, $actor): array {
        $r = Flarum\Http\RequestUtil::withActor(new ServerRequest(), $actor)->withAttribute('routeName', 'campus.control.collect')->withParsedBody($body);
        $response = $controller->handle($r);
        if ($response->getStatusCode() !== 202) throw new RuntimeException('Queue did not return 202');
        return json_decode((string) $response->getBody(), true)['data'];
    };
    $first = $enqueue(); $repeat = $enqueue();
    $job = $db->table('campus_collection_jobs')->where('id', $first['id'])->first();
    $check($job->include_wechat && $first['id'] === $repeat['id'] && $db->table('campus_collection_jobs')->count() === 1, 'Enqueue must be fast, idempotent and include both channels without collector calls');
    $poll = new FixturePoll($aggregation); $wechat = new FixtureWechat(); $collector = new FixtureCollector();
    $worker = new CollectionJobCommand($db, $poll, $wechat, $collector);
    $run = fn () => $worker->run(new ArrayInput([]), new BufferedOutput());
    $check($run() === 0 && $wechat->calls === 1, 'Queued full job did not execute WeChat in background');
    $check($db->table('campus_collection_jobs')->where('id', $first['id'])->value('status') === 'succeeded', 'Successful outcome not saved');
    $check($run() === 0 && $wechat->calls === 1, 'Completed job refreshed WeChat again');
    $source = $db->table('campus_aggregation_sources')->whereIn('adapter', ['ucass-notices', 'ucass-vsb'])->first();
    $db->table('campus_aggregation_sources')->where('id', $source->id)->update(['enabled' => true]);
    $single = $enqueue(['source_id' => $source->id]); $calls = $collector->calls;
    $check(!$db->table('campus_collection_jobs')->where('id', $single['id'])->value('include_wechat'), 'Single website job unexpectedly includes WeChat');
    $check($run() === 0 && $wechat->calls === 1 && $collector->calls === $calls, 'Single website collection depends on collector authorization');
    $failedWeb = ['sources' => 2, 'succeeded' => 0, 'failed' => 2, 'partial' => 0, 'added' => 0];
    $emptyWechat = ['accounts' => 0, 'failed' => 0];
    $check(CollectionJobCommand::outcome($failedWeb, $emptyWechat) === 'failed', 'All websites failed but job did not');
    $check(CollectionJobCommand::outcome($failedWeb, ['accounts' => 2, 'failed' => 0]) === 'partial', 'Healthy WeChat must be retained as partial success');
    $check(CollectionJobCommand::outcome(['succeeded' => 1, 'added' => 0], $emptyWechat) === 'succeeded', 'Zero new candidates is not an error');
    $check(CollectionJobCommand::outcome(['partial' => 1], $emptyWechat) === 'partial', 'Partial article failures must be visible');
    $wechatCli = new UCASSer\Campus\AggregationWechatCommand($wechat);
    foreach ([0, 1, 2] as $failures) {
        $wechat->report = ['accounts' => 2, 'failed' => $failures, 'added' => 0];
        $check($wechatCli->run(new ArrayInput([]), new BufferedOutput()) === ($failures ? 1 : 0), 'WeChat CLI must not return success after collection failures');
    }
    $orphan = $enqueue();
    $db->table('campus_collection_jobs')->where('id', $orphan['id'])->update(['status' => 'running']);
    $check($run() === 0 && $db->table('campus_collection_jobs')->where('id', $orphan['id'])->value('status') === 'failed', 'Interrupted job must not block the queue forever');
    $lease = fopen(__DIR__.'/../storage/collection-worker.lock', 'c'); flock($lease, LOCK_EX | LOCK_NB);
    $waiting = $enqueue();
    $check($run() === 0 && $db->table('campus_collection_jobs')->where('id', $waiting['id'])->value('status') === 'queued', 'Concurrent worker must leave queued work alone');
    flock($lease, LOCK_UN); fclose($lease);
    $db->table('campus_aggregation_sources')->whereIn('adapter', ['ucass-notices', 'ucass-vsb', 'ucass-employment'])->update(['enabled' => false]);
    $db->table('campus_aggregation_sources')->where('adapter', 'we-mp-rss')->update(['enabled' => true]);
    $realPoll = $c->make(AggregationPollCommand::class);
    $check($realPoll->run(new ArrayInput([]), new BufferedOutput()) === 0 && $realPoll->result()['sources'] === 0, 'Website polling must exclude enabled WeChat adapters');
    foreach (['https://www.ucass.edu.cn/fixture-one.htm', 'https://www.ucass.edu.cn/fixture-two.htm', 'https://jw.ucass.edu.cn/fixture-three.htm'] as $url) {
        $db->table('campus_aggregation_sources')->insert(['name' => 'Workflow network fixture', 'listing_url' => $url,
            'adapter' => 'ucass-vsb', 'trust' => 'official', 'visibility' => 'public', 'enabled' => true,
            'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
    }
    $requests = 0;
    $handler = function ($request, $options) use (&$requests) {
        $requests++;
        return GuzzleHttp\Promise\Create::rejectionFor(new GuzzleHttp\Exception\ConnectException('Fixture connection timeout', $request));
    };
    $networkPoll = new AggregationPollCommand($aggregation, new GuzzleHttp\Client(['handler' => $handler]));
    $check($networkPoll->run(new ArrayInput([]), new BufferedOutput()) === 1 && $requests === 2 && $networkPoll->result()['failed'] === 3,
        'Connection failure must stay failed, skip repeated host waits and continue other hosts');
    echo "Fast/idempotent queue, background channels, website-only isolation, real outcomes, interrupted/concurrent workers and website adapter filtering OK\n";
} finally { $db->rollBack(); }
$check($snapshot() === $original, 'Collection workflow fixture did not roll back');
