<?php
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'development') throw new RuntimeException('Development CLI only');
define('UCASSER_PUBLIC_RECOVERY_LIBRARY', true);
define('UCASSER_OPERATOR_PROVISION_LIBRARY', true);
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/../scripts/restore_public_candidates.php';
require __DIR__.'/../scripts/provision_operator.php';
$c = (require __DIR__.'/../site.php')->bootApp()->getContainer();
$db = $c->make(Illuminate\Database\ConnectionInterface::class);
$aggregation = $c->make(UCASSer\Campus\Aggregation::class);
$check = function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$bundle = ucasserReadPublicBundle(dirname(__DIR__), true);
$history = ucasserReadPublicBundle(dirname(__DIR__));
$historyUrls = array_column($history['entries'], null, 'url');
$old = []; $new = [];
foreach ($bundle['entries'] as $row) {
    if (isset($historyUrls[$row['url']])) $old[] = $row; else $new[] = $row;
}
$check(count($old) === 76 && count($new) === 2 && $bundle['source_count'] === 40, 'Index overlap must be explicit');
$bundle['entries'] = array_merge($old, $new);
$marker = (string) random_int(100000000, 999999999);
foreach ($bundle['entries'] as $i => &$row) {
    $row['url'] = 'https://'.parse_url($row['listing_url'], PHP_URL_HOST).'/info/'.$marker.'/'.($i + 1).'.htm';
}
unset($row);
$snapshot = function () use ($db): string {
    $state = [];
    foreach (['users', 'group_user', 'campus_operators', 'campus_aggregation_sources', 'campus_aggregation_candidates',
        'campus_aggregation_items', 'campus_aggregation_events', 'campus_audit', 'campus_courses', 'campus_colleges'] as $t) {
        $state[$t] = $db->table($t)->get()->toJson();
    }
    return hash('sha256', json_encode($state));
};
$original = $snapshot();
$db->beginTransaction();
try {
    $actor = 'web_index_'.bin2hex(random_bytes(4));
    ucasserProvisionOperator($c, ['username' => $actor, 'email' => $actor.'@example.invalid',
        'password' => bin2hex(random_bytes(16)), 'owner_email_confirmed' => true], true);
    $sources = [];
    foreach ($bundle['entries'] as $i => $row) {
        $name = $row['source_name'];
        if (!isset($sources[$name])) {
            $source = $db->table('campus_aggregation_sources')->where('name', $name)->where('listing_url', $row['listing_url'])->first();
            $values = ['name' => $name, 'listing_url' => $row['listing_url'], 'adapter' => $row['adapter'],
                'trust' => 'official', 'visibility' => 'public', 'enabled' => true];
            if ($source) $db->table('campus_aggregation_sources')->where('id', $source->id)->update($values);
            else $source = (object) ['id' => $db->table('campus_aggregation_sources')->insertGetId($values + [
                'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')])];
            $sources[$name] = $db->table('campus_aggregation_sources')->where('id', $source->id)->first();
        }
        if ($i < 76) {
            $aggregation->ingestWeb($sources[$name], $row + ['content_hash' => hash('sha256', 'older body')]);
            $db->table('campus_aggregation_candidates')->where('url_hash', $aggregation->urlHash($row['url']))->update([
                'status' => ['pending', 'rejected', 'published'][$i % 3], 'review_reason' => 'Retain existing decision']);
        }
    }
    $before = $snapshot();
    $preview = ucasserRestorePublicCandidates($c, $bundle, $actor, false, false, true);
    $check($preview['added'] === 2 && $preview['existing_urls_preserved'] === 76 && $snapshot() === $before, 'Preview must retain all existing versions and decisions');
    $last = $sources[$bundle['entries'][77]['source_name']];
    $db->table('campus_aggregation_sources')->where('id', $last->id)->update(['enabled' => false]);
    $paused = $snapshot();
    $result = ucasserRestorePublicCandidates($c, $bundle, $actor, false, false, true);
    $check($result['added'] === 1 && $result['disabled_sources_skipped'] === 1 && $snapshot() === $paused, 'Paused sources must stay paused');
    $db->table('campus_aggregation_sources')->where('id', $last->id)->update(['enabled' => true, 'trust' => 'unofficial']);
    $conflict = $snapshot(); $failed = false;
    try { ucasserRestorePublicCandidates($c, $bundle, $actor, true, false, true); } catch (Throwable $e) { $failed = true; }
    $check($failed && $snapshot() === $conflict, 'Untrusted source must reject the complete import');
    $db->table('campus_aggregation_sources')->where('id', $last->id)->update(['trust' => 'official']);
    $existing = $db->table('campus_aggregation_candidates')->get()->toJson();
    $sourceState = $db->table('campus_aggregation_sources')->get()->toJson();
    $itemState = $db->table('campus_aggregation_items')->get()->toJson();
    $result = ucasserRestorePublicCandidates($c, $bundle, $actor, true, false, true);
    $check($result['added'] === 2 && $result['published'] === 0, 'Apply must add exactly two pending records');
    foreach ($new as $i => $_) {
        $row = $db->table('campus_aggregation_candidates')->where('url_hash', $aggregation->urlHash($bundle['entries'][76 + $i]['url']))->first();
        $check($row && $row->status === 'pending', 'New record must require review');
    }
    $check($db->table('campus_aggregation_sources')->get()->toJson() === $sourceState, 'Do not claim cloud sources were checked');
    $check($db->table('campus_aggregation_items')->get()->toJson() === $itemState, 'Do not publish');
    $after = $snapshot();
    $repeat = ucasserRestorePublicCandidates($c, $bundle, $actor, true, false, true);
    $check($repeat['added'] === 0 && $repeat['existing_urls_preserved'] === 78 && $snapshot() === $after, 'Repeat must not add versions or audit rows');
    $newIds = [];
    foreach (array_slice($bundle['entries'], 76) as $row) $newIds[] = $db->table('campus_aggregation_candidates')->where('url_hash', $aggregation->urlHash($row['url']))->value('id');
    $check($db->table('campus_aggregation_candidates')->whereNotIn('id', $newIds)->get()->toJson() === $existing, 'Existing review records must remain byte-for-byte identical');
    echo "Official index: 40 columns, 76 preserved URLs, 2 pending additions; preview, paused/trust guards, repeat and rollback OK\n";
} finally { $db->rollBack(); }
$check($snapshot() === $original, 'All test fixtures must roll back');
