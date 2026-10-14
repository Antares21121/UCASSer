<?php
namespace UCASSer\Campus;

use Flarum\Console\AbstractCommand;

/** Import a locally fetched RSS feed snapshot without sharing collector credentials. */
class AggregationImportFeedCommand extends AbstractCommand
{
    public function __construct(private Aggregation $aggregation) { parent::__construct(); }

    protected function configure(): void
    {
        $this->setName('campus:aggregation:import-feed')
            ->setDescription('Import a local公众号 RSS snapshot into the admin review queue')
            ->addArgument('file', \Symfony\Component\Console\Input\InputArgument::REQUIRED, 'JSON snapshot in .runtime')
            ->addArgument('name', \Symfony\Component\Console\Input\InputArgument::REQUIRED, 'Publisher name');
    }

    protected function fire(): int
    {
        $path = (string) $this->input->getArgument('file');
        $name = trim((string) $this->input->getArgument('name'));
        if (!preg_match('~^\.runtime/[a-zA-Z0-9._-]+\.json$~D', $path)
            || mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $this->error('快照须在 .runtime 中，且来源名称为 2–120 字');
            return 2;
        }
        $raw = @file_get_contents($path);
        $rows = $raw === false ? null : json_decode($raw, true);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 20) {
            $this->error('快照必须是最多 20 篇文章的 JSON 数组');
            return 2;
        }
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['url'] ?? null)
                || !preg_match('~^https://mp\.weixin\.qq\.com/s(?:/[A-Za-z0-9_-]+|\?[A-Za-z0-9_=&%.-]+)$~D', $row['url'])
                || !is_string($row['title'] ?? null)
                || mb_strlen(trim($row['title'])) < 3 || mb_strlen($row['title']) > 180
                || !is_string($row['summary'] ?? null)
                || !is_null($row['published_at'] ?? null) && !preg_match('~^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$~D', $row['published_at'])) {
                $this->error('快照包含无效文章或非微信原文链接，未导入任何条目');
                return 2;
            }
        }
        $db = $this->aggregation->db;
        $source = $db->table('campus_aggregation_sources')->where('adapter', 'we-mp-rss')->where('name', $name)->first();
        if (!$source) {
            $now = gmdate('Y-m-d H:i:s');
            $id = $db->table('campus_aggregation_sources')->insertGetId([
                'name' => $name, 'listing_url' => 'https://mp.weixin.qq.com/',
                'adapter' => 'we-mp-rss', 'trust' => 'unofficial', 'visibility' => 'public',
                'enabled' => false, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $source = $db->table('campus_aggregation_sources')->where('id', $id)->first();
        }
        $added = 0;
        foreach ($rows as $row) {
            $entry = ['url' => $row['url'], 'title' => trim($row['title']),
                'summary' => mb_substr(trim($row['summary']), 0, 1000),
                'published_at' => $row['published_at'] ?? null, 'category' => 'other'];
            if ($this->aggregation->ingestWeb($source, $entry)) $added++;
        }
        $db->table('campus_aggregation_sources')->where('id', $source->id)->update(['last_checked_at' => gmdate('Y-m-d H:i:s'), 'last_error' => null]);
        $this->info("$name：新增 $added 条待审候选；读取 ".count($rows).' 篇，未自动发布');
        return 0;
    }
}
