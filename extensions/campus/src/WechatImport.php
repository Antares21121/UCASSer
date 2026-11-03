<?php
namespace UCASSer\Campus;

/** Pull every subscribed WeChat account into the review queue (shared by the console and the CLI). */
class WechatImport
{
    public function __construct(private CollectorClient $collector, private Aggregation $aggregation)
    {
    }

    /** @return array{added:int,failed:int,accounts:int} */
    public function run(int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        $feeds = $this->collector->feeds();
        if (!$feeds) return ['added' => 0, 'failed' => 0, 'accounts' => 0];
        $db = $this->aggregation->db;
        $added = $failed = 0;
        foreach ($feeds as $index => $feed) {
            $name = CollectorClient::sourceName((string) $feed['id'], (string) ($feed['mp_name'] ?? $feed['id']));
            $source = $db->table('campus_aggregation_sources')->where('adapter', 'we-mp-rss')->where('name', $name)->first()
                ?? $db->table('campus_aggregation_sources')->where('adapter', 'we-mp-rss-pending')->where('name', $name)->first();
            try {
                $rows = $this->collector->feedRows($this->collector->refreshFeed((string) $feed['id'], $name), $limit);
                $n = $db->transaction(function () use (&$source, $rows, $name, $db) {
                if ($source && $source->adapter !== 'we-mp-rss') {
                    $db->table('campus_aggregation_sources')->where('id', $source->id)->update(['adapter' => 'we-mp-rss', 'updated_at' => gmdate('Y-m-d H:i:s')]);
                    $source->adapter = 'we-mp-rss';
                }
                if (!$source) {
                    $now = gmdate('Y-m-d H:i:s');
                    $id = $db->table('campus_aggregation_sources')->insertGetId([
                        'name' => $name, 'listing_url' => 'https://mp.weixin.qq.com/', 'adapter' => 'we-mp-rss',
                        'trust' => 'unofficial', 'visibility' => 'public', 'enabled' => false,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $source = $db->table('campus_aggregation_sources')->where('id', $id)->first();
                }
                $n = 0;
                foreach ($rows as $row) {
                    if ($this->aggregation->ingestWeb($source, [
                        'url' => $row['url'], 'title' => $row['title'], 'summary' => mb_substr($row['summary'], 0, 1000),
                        'published_at' => $row['published_at'], 'category' => 'other',
                    ])) $n++;
                }
                $db->table('campus_aggregation_sources')->where('id', $source->id)->update(['last_checked_at' => gmdate('Y-m-d H:i:s'), 'last_error' => null]);
                return $n;
                });
                $added += $n;
            } catch (\Throwable $error) {
                $failed++;
                if ($source) $db->table('campus_aggregation_sources')->where('id', $source->id)->update([
                    'last_checked_at' => gmdate('Y-m-d H:i:s'), 'last_error' => '公众号抓取或导入失败，请检查订阅服务和网络',
                ]);
                if ($error instanceof CollectorAuthorizationRequired) { $failed += count($feeds) - $index - 1; break; }
            }
        }
        return ['added' => $added, 'failed' => $failed, 'accounts' => count($feeds)];
    }
}
