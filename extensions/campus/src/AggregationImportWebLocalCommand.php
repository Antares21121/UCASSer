<?php
namespace UCASSer\Campus;

use Flarum\Console\AbstractCommand;

/** Import an operator-transferred snapshot fetched on a trusted local computer. */
class AggregationImportWebLocalCommand extends AbstractCommand
{
    public function __construct(private Aggregation $aggregation) { parent::__construct(); }

    protected function configure(): void
    {
        $this->setName('campus:aggregation:import-web-local')
            ->setDescription('Import a local official website snapshot into the admin review queue');
    }

    protected function fire(): int
    {
        $path = '.runtime/aggregation-web-local.json';
        if (!is_file($path) || filesize($path) > 1024 * 1024) {
            $this->error('缺少本地快照，或快照超过 1 MB');
            return 2;
        }
        try {
            $snapshot = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($snapshot) || ($snapshot['version'] ?? null) !== 1
                || !is_array($snapshot['sources'] ?? null) || !array_is_list($snapshot['sources'])
                || count($snapshot['sources']) > count(OfficialSourceCatalog::WEB)) {
                throw new \RuntimeException('快照格式不正确');
            }
            $catalog = array_column(OfficialSourceCatalog::WEB, 0, 1);
            $validated = [];
            foreach ($snapshot['sources'] as $item) {
                $listing = $item['listing_url'] ?? null;
                if (!is_string($listing) || !isset($catalog[$listing]) || ($item['name'] ?? null) !== $catalog[$listing]
                    || !in_array($item['adapter'] ?? null, ['ucass-vsb', 'ucass-notices'], true)
                    || !is_array($item['entries'] ?? null) || !array_is_list($item['entries'])
                    || count($item['entries']) < 1 || count($item['entries']) > 10
                    || isset($validated[$listing])) {
                    throw new \RuntimeException('快照包含未登记的来源或无效的条目数量');
                }
                $host = strtolower((string) parse_url($listing, PHP_URL_HOST));
                if ($item['adapter'] !== ($host === 'jw.ucass.edu.cn' ? 'ucass-notices' : 'ucass-vsb')) {
                    throw new \RuntimeException('来源解析器不匹配');
                }
                foreach ($item['entries'] as $entry) {
                    if (!is_array($entry) || !is_string($entry['url'] ?? null)
                        || !preg_match('~^https://'.preg_quote($host, '~').'/info/\d+/\d+\.htm$~D', $entry['url'])
                        || !is_string($entry['title'] ?? null) || mb_strlen(trim($entry['title'])) < 3
                        || mb_strlen($entry['title']) > 180 || !is_string($entry['summary'] ?? null)
                        || mb_strlen($entry['summary']) > 1000 || !is_string($entry['content_hash'] ?? null)
                        || !preg_match('/^[a-f0-9]{64}$/D', $entry['content_hash'])
                        || ($entry['category'] ?? null) !== 'notice'
                        || !is_string($entry['published_at'] ?? null)
                        || !preg_match('/^20\d\d-\d\d-\d\d \d\d:\d\d:\d\d$/D', $entry['published_at'])) {
                        throw new \RuntimeException('快照包含无效公告');
                    }
                }
                $validated[$listing] = $item['entries'];
            }
            $db = $this->aggregation->db;
            $sources = [];
            foreach ($validated as $listing => $_) {
                $source = $db->table('campus_aggregation_sources')->where('listing_url', $listing)->first();
                if (!$source || $source->trust !== 'official'
                    || !in_array($source->adapter, ['ucass-vsb', 'ucass-notices'], true)) {
                    throw new \RuntimeException('来源未在服务器登记为官网：'.$catalog[$listing]);
                }
                $sources[$listing] = $source;
            }
            $added = 0; $skipped = 0;
            foreach ($validated as $listing => $entries) {
                if (!$sources[$listing]->enabled) { $skipped++; continue; }
                foreach ($entries as $entry) {
                    if ($this->aggregation->ingestWeb($sources[$listing], $entry)) $added++;
                }
                $db->table('campus_aggregation_sources')->where('id', $sources[$listing]->id)->update([
                    'last_checked_at' => gmdate('Y-m-d H:i:s'), 'last_error' => null,
                ]);
            }
            $this->info('本地快照：'.count($validated)." 个来源，跳过 $skipped 个已暂停来源，新增 $added 条待审候选；未自动发布");
            return 0;
        } catch (\Throwable $error) {
            $this->error('导入失败：'.$error->getMessage());
            return 2;
        }
    }
}
