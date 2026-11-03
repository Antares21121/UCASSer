<?php
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'development') throw new RuntimeException('Development CLI only');
require __DIR__.'/../vendor/autoload.php';
use UCASSer\Campus\Aggregation;
use UCASSer\Campus\CollectorClient;
use UCASSer\Campus\CollectorAuthorizationRequired;
use UCASSer\Campus\WechatImport;
class ImportFixtureClient extends CollectorClient {
    public array $list = [['id' => 'MP_WXS_3894823465', 'mp_name' => '中国社科大全球与区域国别学院']];
    public bool $expired = false;
    public int $calls = 0;
    public function __construct() {}
    public function feeds(): array { return $this->list; }
    public function refreshFeed(string $id, string $name): string {
        $this->calls++;
        if ($this->expired) throw new CollectorAuthorizationRequired('Fixture expired');
        return 'fixture';
    }
    public function feedRows(string $url, int $limit = 5): array { return [
        ['url' => 'https://mp.weixin.qq.com/s/regression-import-one', 'title' => '验收文章一', 'summary' => '', 'published_at' => null],
        ['url' => 'https://mp.weixin.qq.com/s/regression-import-two', 'title' => '验收文章二', 'summary' => '', 'published_at' => null],
    ]; }
}
class ImportFailSecond extends Aggregation {
    public int $calls = 0;
    public function ingestWeb(object $source, array $entry): bool {
        if (++$this->calls === 2) throw new RuntimeException('Fixture second insert failed');
        return parent::ingestWeb($source, $entry);
    }
}
$c = (require __DIR__.'/../site.php')->bootApp()->getContainer();
$db = $c->make(Illuminate\Database\ConnectionInterface::class);
$check = function ($ok, $message) { if (!$ok) throw new RuntimeException($message); };
$db->beginTransaction();
try {
    $name = '中国社科大国际政治经济学院';
    $source = $db->table('campus_aggregation_sources')->where('name', $name)->whereIn('adapter', ['we-mp-rss', 'we-mp-rss-pending'])->first();
    if (!$source) {
        $id = $db->table('campus_aggregation_sources')->insertGetId(['name' => $name, 'listing_url' => 'https://mp.weixin.qq.com/', 'adapter' => 'we-mp-rss-pending', 'trust' => 'official', 'visibility' => 'public', 'enabled' => false]);
    } else $id = $source->id;
    $db->table('campus_aggregation_sources')->where('id', $id)->update(['enabled' => false]);
    $before = $db->table('campus_aggregation_candidates')->count();
    $client = new ImportFixtureClient();
    $failed = new WechatImport($client, $c->make(ImportFailSecond::class));
    $result = $failed->run();
    $check($result['failed'] === 1 && $db->table('campus_aggregation_candidates')->count() === $before, 'One publisher import must roll back on second-write failure');
    $check($db->table('campus_aggregation_sources')->where('id', $id)->value('last_error'), 'Publisher failure must be visible in source status');
    $normal = new WechatImport($client, $c->make(Aggregation::class));
    $check($normal->run()['added'] === 2 && $normal->run()['added'] === 0, 'Import must preserve zero-added success and repeated import idempotence');
    $check(!$db->table('campus_aggregation_sources')->where('id', $id)->value('enabled') && !$db->table('campus_aggregation_sources')->where('name', $client->list[0]['mp_name'])->exists(), 'Reviewed rename must preserve original source and pause choice');
    $client->list[] = ['id' => 'MP_WXS_3518809936', 'mp_name' => '中国社会科学院大学'];
    $client->expired = true; $calls = $client->calls;
    $result = $normal->run();
    $check($client->calls === $calls + 1 && $result['failed'] === 2, 'Expired authorization must stop without calling more accounts or claiming skipped accounts succeeded');
    echo "WeChat per-publisher rollback, idempotence, reviewed rename/source continuity, pause preservation and expired-session stop OK\n";
} finally { $db->rollBack(); }
