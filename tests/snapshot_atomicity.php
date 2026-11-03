<?php
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'development') throw new RuntimeException('Development CLI only');
require __DIR__.'/../vendor/autoload.php';
$c = (require __DIR__.'/../site.php')->bootApp()->getContainer();
$db = $c->make(Illuminate\Database\ConnectionInterface::class);
$check = function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$snapshot = fn () => $db->table('campus_aggregation_candidates')->get()->toJson().'|'.$db->table('campus_aggregation_sources')->get()->toJson();
$original = $snapshot();
$db->beginTransaction();
try {
    [$name, $url] = UCASSer\Campus\OfficialSourceCatalog::WEB[0];
    $db->table('campus_aggregation_sources')->where('listing_url', $url)->delete();
    $db->table('campus_aggregation_sources')->insert(['name' => $name, 'listing_url' => $url, 'adapter' => 'ucass-vsb',
        'enabled' => true, 'trust' => 'official', 'visibility' => 'public', 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
    $marker = random_int(100000000, 999999999); $entries = [];
    foreach ([1, 2] as $i) $entries[] = ['url' => 'https://www.ucass.edu.cn/info/'.$marker.'/'.$i.'.htm',
        'title' => 'Atomic public article '.$i, 'summary' => 'Public metadata fixture', 'content_hash' => hash('sha256', 'fixture'.$i),
        'category' => 'notice', 'published_at' => '2026-11-03 00:00:00'];
    $payload = ['version' => 1, 'sources' => [['name' => $name, 'listing_url' => $url, 'adapter' => 'ucass-vsb', 'entries' => $entries]]];
    $failing = new class($db, $c->make(UCASSer\Campus\OperatorAccess::class)) extends UCASSer\Campus\Aggregation {
        private int $calls = 0;
        public function ingestWeb(object $source, array $entry): bool {
            if (++$this->calls === 2) throw new RuntimeException('Injected write failure');
            return parent::ingestWeb($source, $entry);
        }
    };
    $command = new UCASSer\Campus\AggregationImportWebLocalCommand($failing);
    $before = $snapshot(); $failed = false;
    try { $command->import(json_encode($payload)); } catch (Throwable $e) { $failed = true; }
    $check($failed && $snapshot() === $before, 'Second write failure left a partial snapshot import');
    $normal = $c->make(UCASSer\Campus\AggregationImportWebLocalCommand::class);
    $invalid = $payload; $invalid['sources'][0]['entries'][1]['published_at'] = '2026-02-30 00:00:00';
    $failed = false;
    try { $normal->import(json_encode($invalid)); } catch (Throwable $e) { $failed = true; }
    $check($failed && $snapshot() === $before, 'Impossible date was accepted or altered existing rows');
    $check($normal->import(json_encode($payload))['added'] === 2, 'Valid snapshot did not apply');
    $check($normal->import(json_encode($payload))['added'] === 0, 'Repeat snapshot produced duplicates');
    echo "Snapshot second-write rollback, real dates and repeat import OK\n";
} finally { $db->rollBack(); }
$check($snapshot() === $original, 'Snapshot test fixture did not roll back');
