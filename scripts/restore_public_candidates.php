<?php
/** Incremental, pending-only import of the reviewed historical public metadata bundle. */

function ucasserReadPublicBundle(string $root): array
{
    $bytes = file_get_contents($root.'/data/reconciliation/public-content.json');
    if ($bytes === false || hash('sha256', str_replace("\r\n", "\n", $bytes)) !== '0d9826543f565953f563cd481c568c53503ae84033e0387997461cee3d45d6b6') {
        throw new RuntimeException('Public metadata bundle missing or changed');
    }
    return json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
}

function ucasserValidatePublicBundle(array $bundle): array
{
    if (($bundle['format'] ?? null) !== 1 || !is_array($bundle['entries'] ?? null)
        || !array_is_list($bundle['entries']) || count($bundle['entries']) > 200) {
        throw new RuntimeException('Invalid public metadata bundle');
    }
    $catalog = [];
    foreach (UCASSer\Campus\OfficialSourceCatalog::WEB as [$name, $listing]) {
        $catalog[$name] = [$listing, parse_url($listing, PHP_URL_HOST) === 'jw.ucass.edu.cn' ? 'ucass-notices' : 'ucass-vsb'];
    }
    // The original installed source predates the shorter catalog label; keep its identity intact.
    $catalog['中国社会科学院大学教务处通知公告'] = $catalog['教务处通知公告'];
    foreach (UCASSer\Campus\OfficialSourceCatalog::EMPLOYMENT as [$name, $listing]) $catalog[$name] = [$listing, 'ucass-employment'];
    foreach (UCASSer\Campus\OfficialSourceCatalog::WECHAT as [$name, $listing]) $catalog[$name] = [$listing, 'we-mp-rss'];
    $seen = [];
    foreach ($bundle['entries'] as $row) {
        if (!is_array($row) || count($row) !== 8
            || array_diff(array_keys($row), ['title', 'summary', 'category', 'url', 'published_at', 'source_name', 'listing_url', 'adapter'])) {
            throw new RuntimeException('Unexpected article fields');
        }
        foreach (['title', 'summary', 'category', 'url', 'source_name', 'listing_url', 'adapter'] as $key) {
            if (!is_string($row[$key] ?? null)) throw new RuntimeException('Invalid article field type');
        }
        $expected = $catalog[$row['source_name']] ?? null;
        if (!$expected || $expected !== [$row['listing_url'], $row['adapter']]) throw new RuntimeException('Unregistered publisher or listing');
        if (mb_strlen(trim($row['title'])) < 3 || mb_strlen($row['title']) > 180 || mb_strlen($row['summary']) > 1000
            || preg_match('/示例|演示|验收|测试/u', $row['title']) || !isset(UCASSer\Campus\Aggregation::CATEGORIES[$row['category']])) {
            throw new RuntimeException('Invalid or demonstration article');
        }
        $url = $row['url'];
        $host = preg_quote((string) parse_url($row['listing_url'], PHP_URL_HOST), '~');
        $pattern = match ($row['adapter']) {
            'ucass-employment' => '~^https://jyzd\.ucass\.edu\.cn/frontpage/ucass/html/newsDetail\.html\?id=[a-f0-9]{32}$~D',
            'we-mp-rss' => '~^https://mp\.weixin\.qq\.com/s/[A-Za-z0-9_-]+$~D',
            default => '~^https://'.$host.'/info/\d+/\d+\.htm$~D',
        };
        if (!preg_match($pattern, $url) || isset($seen[$url])) throw new RuntimeException('Invalid or duplicate public article URL');
        $seen[$url] = true;
        if ($row['published_at'] !== null) {
            if (!is_string($row['published_at'])) throw new RuntimeException('Invalid publication date');
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $row['published_at'], new DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d H:i:s') !== $row['published_at']) throw new RuntimeException('Invalid publication date');
        }
    }
    $feeds = $bundle['public_subscriptions'] ?? null;
    if (!is_array($feeds) || !array_is_list($feeds) || count($feeds) > count(UCASSer\Campus\OfficialSourceCatalog::WECHAT)) {
        throw new RuntimeException('Invalid public subscription manifest');
    }
    $seen = [];
    foreach ($feeds as $feed) {
        if (!is_array($feed) || count($feed) !== 2 || !is_string($feed['publisher_name'] ?? null)
            || !is_string($feed['public_feed_id'] ?? null) || !preg_match('/^MP_WXS_\d+$/D', $feed['public_feed_id'])
            || ($catalog[$feed['publisher_name']][1] ?? null) !== 'we-mp-rss'
            || isset($seen[$feed['publisher_name']]) || isset($seen[$feed['public_feed_id']])) {
            throw new RuntimeException('Invalid or virtual collector subscription');
        }
        $seen[$feed['publisher_name']] = $seen[$feed['public_feed_id']] = true;
    }
    return $bundle['entries'];
}

function ucasserRestorePublicCandidates($container, array $bundle, string $actorName, bool $apply, bool $includeDisabled = false): array
{
    // Validate everything before opening the write transaction. No network requests or credential transfer.
    $rows = ucasserValidatePublicBundle($bundle);
    $actor = Flarum\User\User::where('username', $actorName)->first();
    if (!$actor) throw new RuntimeException('Audit actor not found');
    UCASSer\Campus\AccountAccess::assertConfirmed($actor);
    if (!$actor->isAdmin()) $container->make(UCASSer\Campus\OperatorAccess::class)->assert($actor);
    $db = $container->make(Illuminate\Database\ConnectionInterface::class);
    $aggregation = $container->make(UCASSer\Campus\Aggregation::class);
    $report = ['apply' => $apply, 'actor' => $actorName, 'entries' => count($rows), 'added' => 0,
        'existing_urls_preserved' => 0, 'disabled_sources_skipped' => 0, 'published' => 0,
        'subscription_identifiers' => count($bundle['public_subscriptions']), 'collector_configuration_changed' => false];
    $db->beginTransaction();
    try {
        $sources = [];
        foreach ($rows as $row) {
            $key = $row['source_name'];
            if (isset($sources[$key])) continue;
            $matches = $db->table('campus_aggregation_sources')->where('name', $key)->where('listing_url', $row['listing_url'])->lockForUpdate()->get();
            if ($matches->count() !== 1) throw new RuntimeException('Source identity missing or ambiguous: '.$key);
            $source = $matches->first();
            $adapters = $row['adapter'] === 'we-mp-rss' ? ['we-mp-rss', 'we-mp-rss-pending'] : [$row['adapter']];
            if (!in_array($source->adapter, $adapters, true) || $source->visibility !== 'public') throw new RuntimeException('Source configuration conflict: '.$key);
            $sources[$key] = $source;
        }
        foreach ($rows as $row) {
            $source = $sources[$row['source_name']];
            $hash = $aggregation->urlHash($row['url']);
            // All statuses count: retain pending reviews, rejections and published versions verbatim.
            if ($db->table('campus_aggregation_candidates')->where('url_hash', $hash)->lockForUpdate()->first()) {
                $report['existing_urls_preserved']++;
                continue;
            }
            if (!$source->enabled && !$includeDisabled) { $report['disabled_sources_skipped']++; continue; }
            if (!$aggregation->ingestWeb($source, $row)) throw new RuntimeException('Candidate insert failed');
            $report['added']++;
        }
        if ($report['added']) {
            $db->table('campus_audit')->insert(['actor_id' => $actor->id, 'action' => 'aggregation.history.import',
                'target' => 'aggregation_candidates', 'reason' => $report['added'].' pending; disabled snapshot inclusion='.(int) $includeDisabled,
                'created_at' => gmdate('Y-m-d H:i:s')]);
        }
        if ($apply) $db->commit(); else $db->rollBack();
        return $report;
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
}

if (!defined('UCASSER_PUBLIC_RECOVERY_LIBRARY')) {
    if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
    try {
        $args = array_slice($_SERVER['argv'] ?? [], 1);
        $actor = null; $apply = false; $disabled = false;
        for ($i = 0; $i < count($args); $i++) {
            if ($args[$i] === '--actor' && isset($args[$i + 1]) && $actor === null) $actor = $args[++$i];
            elseif ($args[$i] === '--apply') $apply = true;
            elseif ($args[$i] === '--include-disabled') $disabled = true;
            else throw new RuntimeException('Usage: php scripts/restore_public_candidates.php --actor USER [--include-disabled] [--apply]');
        }
        if (!$actor) throw new RuntimeException('Specify an existing confirmed operator as the audit actor');
        require getcwd().'/vendor/autoload.php';
        $container = (require getcwd().'/site.php')->bootApp()->getContainer();
        echo json_encode(ucasserRestorePublicCandidates($container, ucasserReadPublicBundle(getcwd()), $actor, $apply, $disabled), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
    } catch (Throwable $error) {
        fwrite(STDERR, 'Public metadata recovery failed: '.$error->getMessage()."\n");
        exit(1);
    }
}
