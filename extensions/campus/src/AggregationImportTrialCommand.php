<?php
namespace UCASSer\Campus;

use Flarum\Console\AbstractCommand;

/** One-time import of independently checked college WeChat links into review. */
class AggregationImportTrialCommand extends AbstractCommand
{
    private const PUBLISHER = '社科大社会与民族学院';

    public function __construct(private Aggregation $aggregation) { parent::__construct(); }

    protected function configure(): void
    {
        $this->setName('campus:aggregation:import-college-trial')
            ->setDescription('Import verified college WeChat links as pending candidates')
            ->addArgument('file', \Symfony\Component\Console\Input\InputArgument::REQUIRED, 'JSON evidence file');
    }

    protected function fire(): int
    {
        $path = (string) $this->input->getArgument('file');
        $raw = @file_get_contents($path);
        $rows = $raw === false ? null : json_decode($raw, true);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) < 1 || count($rows) > 20) {
            $this->error('证据文件必须是含 1–20 篇文章的 JSON 数组');
            return 2;
        }
        foreach ($rows as $row) {
            if (!is_array($row) || ($row['publisher'] ?? null) !== self::PUBLISHER
                || !is_string($row['url'] ?? null)
                || !CollectorClient::isArticleUrl($row['url'], false)
                || !is_string($row['article_title'] ?? null)
                || mb_strlen(trim($row['article_title'])) < 3 || mb_strlen($row['article_title']) > 180
                || !is_string($row['site_page'] ?? null)
                || !preg_match('~^(?:xwzx|xstd)/[A-Za-z0-9/._-]+\.htm$~D', $row['site_page'])
                || !is_string($row['article_time'] ?? null)) {
                $this->error('证据文件含未核实账号、无效链接或缺失的学院官网出处');
                return 2;
            }
        }

        $db = $this->aggregation->db;
        $source = $db->table('campus_aggregation_sources')->where('adapter', 'college-wechat-trial')->first();
        if (!$source) {
            $now = gmdate('Y-m-d H:i:s');
            $id = $db->table('campus_aggregation_sources')->insertGetId([
                'name' => self::PUBLISHER, 'listing_url' => 'https://shymzxy.ucass.edu.cn/',
                'adapter' => 'college-wechat-trial', 'trust' => 'official', 'visibility' => 'public',
                'enabled' => false, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $source = $db->table('campus_aggregation_sources')->where('id', $id)->first();
        }
        $added = 0;
        foreach ($rows as $row) {
            $published = null;
            if (preg_match('/^(\d{4})年(\d{1,2})月(\d{1,2})日\s+(\d{1,2}):(\d{2})$/u', $row['article_time'], $m)) {
                $local = \DateTimeImmutable::createFromFormat('!Y-n-j G:i', "$m[1]-$m[2]-$m[3] $m[4]:$m[5]", new \DateTimeZone('Asia/Shanghai'));
                if ($local) $published = $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
            $title = trim($row['article_title']);
            $entry = [
                'url' => $row['url'], 'title' => $title,
                'summary' => '学院公众号文章，学院官网已收录原链接。请打开原文核对内容。',
                'published_at' => $published, 'category' => 'other',
                'content_hash' => hash('sha256', $title."\n".$row['publisher']."\n".$row['article_time']),
            ];
            if ($this->aggregation->ingestWeb($source, $entry)) $added++;
        }
        $this->info("学院公众号试验：新增 $added 条待审候选；共核对 ".count($rows).' 篇，未自动发布');
        return 0;
    }
}
