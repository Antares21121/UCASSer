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
$bundle = ucasserReadPublicBundle(dirname(__DIR__));
$check = function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$snapshot = function () use ($db): string {
    $state = [];
    foreach (['users', 'group_user', 'campus_operators', 'campus_aggregation_sources', 'campus_aggregation_candidates',
        'campus_aggregation_items', 'campus_aggregation_events', 'campus_audit', 'campus_courses', 'campus_colleges'] as $table) {
        $rows = array_map(fn ($r) => json_encode($r), $db->table($table)->get()->all());
        sort($rows); $state[$table] = $rows;
    }
    return hash('sha256', json_encode($state));
};
$original = $snapshot();
$db->beginTransaction();
try {
    $name = 'recovery_'.bin2hex(random_bytes(4));
    ucasserProvisionOperator($c, ['username' => $name, 'email' => $name.'@example.invalid',
        'password' => bin2hex(random_bytes(16)), 'owner_email_confirmed' => true], true);
    $sources = [];
    foreach ($bundle['entries'] as $row) {
        $key = $row['source_name'];
        if (isset($sources[$key])) continue;
        $source = $db->table('campus_aggregation_sources')->where('name', $key)->where('listing_url', $row['listing_url'])->first();
        if (!$source) {
            $id = $db->table('campus_aggregation_sources')->insertGetId(['name' => $key, 'listing_url' => $row['listing_url'],
                'adapter' => $row['adapter'] === 'we-mp-rss' ? 'we-mp-rss-pending' : $row['adapter'],
                'enabled' => $row['adapter'] !== 'we-mp-rss', 'trust' => 'unofficial', 'visibility' => 'public',
                'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
            $source = $db->table('campus_aggregation_sources')->where('id', $id)->first();
        }
        if ($row['adapter'] === 'we-mp-rss') {
            $db->table('campus_aggregation_sources')->where('id', $source->id)->update(['enabled' => false]);
            $source->enabled = false;
        }
        $sources[$key] = $source;
    }
    // Model the server's 42 existing candidates without depending on development fixture IDs.
    $existing = [];
    foreach (array_slice($bundle['entries'], 0, 42) as $row) {
        $hash = $aggregation->urlHash($row['url']);
        if (!$db->table('campus_aggregation_candidates')->where('url_hash', $hash)->exists()) {
            $aggregation->ingestWeb($sources[$row['source_name']], array_replace($row, ['summary' => 'Existing server review must remain unchanged']));
        }
        if (count($existing) < 2) {
            $db->table('campus_aggregation_candidates')->where('url_hash', $hash)->update([
                'status' => count($existing) === 0 ? 'rejected' : 'published', 'review_reason' => 'Retain prior review decision']);
        }
        $existing[$hash] = $db->table('campus_aggregation_candidates')->where('url_hash', $hash)->get()->toJson();
    }
    $before = $snapshot();
    $defaultPreview = ucasserRestorePublicCandidates($c, $bundle, $name, false);
    $check($defaultPreview['disabled_sources_skipped'] > 0 && $snapshot() === $before, 'Default must respect paused sources without changes');
    $preview = ucasserRestorePublicCandidates($c, $bundle, $name, false, true);
    $check($snapshot() === $before && $preview['existing_urls_preserved'] >= 42, 'Preview must preserve every row and existing URL');
    $grant = (array) $db->table('campus_operators')->where('user_id', Flarum\User\User::where('username', $name)->value('id'))->first();
    $db->table('campus_operators')->where('user_id', $grant['user_id'])->delete();
    $noGrant = $snapshot(); $failed = false;
    try { ucasserRestorePublicCandidates($c, $bundle, $name, true, true); } catch (Throwable $error) { $failed = true; }
    $check($failed && $snapshot() === $noGrant, 'Ordinary accounts must not authorize the import');
    $db->table('campus_operators')->insert($grant);
    foreach (['bad_url', 'bad_source', 'bad_date', 'secret_field', 'virtual_feed', 'duplicate'] as $case) {
        $bad = $bundle;
        if ($case === 'bad_url') $bad['entries'][50]['url'] = 'https://jw.ucass.edu.cn.attacker.invalid/info/1/2.htm';
        elseif ($case === 'bad_source') $bad['entries'][50]['source_name'] = 'Unknown source';
        elseif ($case === 'bad_date') $bad['entries'][50]['published_at'] = '2026-02-30 00:00:00';
        elseif ($case === 'secret_field') $bad['entries'][50]['cookie'] = 'must never import';
        elseif ($case === 'virtual_feed') $bad['public_subscriptions'][0]['public_feed_id'] = 'MP_WXS_FEATURED_ARTICLES';
        else $bad['entries'][] = $bad['entries'][0];
        $failed = false;
        try { ucasserRestorePublicCandidates($c, $bad, $name, true, true); } catch (Throwable $error) { $failed = true; }
        $check($failed && $snapshot() === $before, 'Reject invalid input atomically: '.$case);
    }
    $source = $sources[$bundle['entries'][60]['source_name']];
    $db->table('campus_aggregation_sources')->where('id', $source->id)->update(['visibility' => 'members']);
    $privateState = $snapshot(); $failed = false;
    try { ucasserRestorePublicCandidates($c, $bundle, $name, true, true); } catch (Throwable $error) { $failed = true; }
    $check($failed && $snapshot() === $privateState, 'Source visibility conflicts must roll back the complete import');
    $db->table('campus_aggregation_sources')->where('id', $source->id)->update(['visibility' => $source->visibility]);
    $guardTables = ['users', 'campus_operators', 'campus_aggregation_sources', 'campus_aggregation_items', 'campus_aggregation_events', 'campus_courses', 'campus_colleges'];
    $guard = fn () => array_map(fn ($t) => $db->table($t)->get()->toJson(), $guardTables);
    $guardBefore = $guard();
    $first = ucasserRestorePublicCandidates($c, $bundle, $name, true, true);
    $check($first['added'] === $preview['added'] && $first['published'] === 0, 'Apply must match preview and publish nothing');
    $after = $snapshot();
    $repeat = ucasserRestorePublicCandidates($c, $bundle, $name, true, true);
    $check($repeat['added'] === 0 && $snapshot() === $after, 'Repeat must preserve all rows including audit');
    $check($guard() === $guardBefore, 'Import must not alter accounts, source flags, items, events or catalog');
    foreach ($existing as $hash => $bytes) $check($db->table('campus_aggregation_candidates')->where('url_hash', $hash)->get()->toJson() === $bytes, 'Existing server review changed');
    echo "Public metadata preview, 42-existing-URL preservation, pending-only apply, repeat and malformed/source-conflict rollback OK\n";
} finally { $db->rollBack(); }
$check($snapshot() === $original, 'All test fixtures must roll back');
