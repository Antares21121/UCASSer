<?php
namespace UCASSer\Campus;

use Flarum\Console\AbstractCommand;
use GuzzleHttp\Client;

class AggregationPollCommand extends AbstractCommand
{
    public function __construct(private Aggregation $aggregation) { parent::__construct(); }

    protected function configure(): void
    {
        $this->setName('campus:aggregation:poll')->setDescription('Fetch approved public aggregation sources into the review queue')
            ->addOption('source', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Source ID')
            ->addOption('limit', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Maximum articles per source', '5');
    }

    private function fetch(Client $client, string $url, string $host): string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || strtolower($parts['host'] ?? '') !== $host || isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('抓取网址不属于已登记的 HTTPS 来源');
        }
        $response = $client->get($url, ['stream' => true]);
        if ($response->getStatusCode() !== 200) throw new \RuntimeException('来源返回 HTTP '.$response->getStatusCode());
        $type = strtolower($response->getHeaderLine('Content-Type'));
        if ($type && !str_contains($type, 'text/html')) throw new \RuntimeException('来源不是 HTML 页面');
        $body = $response->getBody();
        $html = '';
        while (!$body->eof() && strlen($html) <= 1024 * 1024) {
            $chunk = $body->read(min(65536, 1024 * 1024 + 1 - strlen($html)));
            if ($chunk === '') break;
            $html .= $chunk;
        }
        if (strlen($html) > 1024 * 1024) throw new \RuntimeException('来源页面超过 1 MB');
        return $html;
    }

    protected function fire(): int
    {
        $db = $this->aggregation->db;
        $limit = (int) $this->input->getOption('limit');
        if ($limit < 1 || $limit > 10) { $this->error('limit 必须为 1–10'); return 2; }
        $sources = $db->table('campus_aggregation_sources')->where('enabled', true);
        if ($this->input->getOption('source')) $sources->where('id', (int) $this->input->getOption('source'));
        $sources = $sources->get();
        if ($sources->isEmpty()) { $this->warn('没有启用的来源'); return 0; }
        $client = new Client(['allow_redirects' => false, 'http_errors' => false, 'timeout' => 15, 'connect_timeout' => 5,
            'headers' => ['User-Agent' => 'UCASSer-aggregation/0.1 (+reviewed campus information index)', 'Accept' => 'text/html']]);
        $failed = false;
        foreach ($sources as $source) {
            try {
                if ($source->adapter !== 'ucass-notices') throw new \RuntimeException('尚未实现此来源适配器');
                $host = strtolower(parse_url($source->listing_url, PHP_URL_HOST) ?: '');
                if ($host !== 'jw.ucass.edu.cn') throw new \RuntimeException('教务处适配器只接受教务处域名');
                $adapter = new UcassNoticesAdapter();
                $listing = $adapter->listing($this->fetch($client, $source->listing_url, $host), $source->listing_url, $limit);
                if (!$listing) throw new \RuntimeException('公告列表结构已变化或没有条目');
                $added = 0; $unavailable = 0;
                foreach ($listing as $entry) {
                    try {
                        $entry += $adapter->article($this->fetch($client, $entry['url'], $host));
                        if ($this->aggregation->ingestWeb($source, $entry)) $added++;
                    } catch (\Throwable $error) {
                        $unavailable++;
                        $this->warn('跳过不可用公告：'.$entry['url'].'（'.$error->getMessage().'）');
                    }
                }
                $db->table('campus_aggregation_sources')->where('id', $source->id)->update(['last_checked_at' => gmdate('Y-m-d H:i:s'), 'last_error' => $unavailable ? "$unavailable 条公告暂不可用" : null]);
                $this->info($source->name."：新增 $added 条待审候选，跳过 $unavailable 条不可用公告");
            } catch (\Throwable $error) {
                $failed = true;
                $db->table('campus_aggregation_sources')->where('id', $source->id)->update(['last_checked_at' => gmdate('Y-m-d H:i:s'), 'last_error' => mb_substr($error->getMessage(), 0, 500)]);
                $this->error($source->name.'：'.$error->getMessage());
            }
        }
        return $failed ? 1 : 0;
    }
}
