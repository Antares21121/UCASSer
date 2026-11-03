<?php
namespace UCASSer\Campus;

use Flarum\Console\AbstractCommand;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;

class AggregationPollCommand extends AbstractCommand
{
    private array $lastResult = [];

    public function result(): array { return $this->lastResult; }
    public function __construct(private Aggregation $aggregation, private ?ClientInterface $http = null) { parent::__construct(); }

    protected function configure(): void
    {
        $this->setName('campus:aggregation:poll')->setDescription('Fetch approved public aggregation sources into the review queue')
            ->addOption('source', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Source ID')
            ->addOption('limit', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'Maximum articles per source', '5');
    }

    private function fetch(ClientInterface $client, string $url, string $host): string
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

    private function fetchEmploymentJson(ClientInterface $client, string $url, ?array $form = null, array $headers = []): string
    {
        if (!in_array($url, [UcassEmploymentAdapter::TOKEN_URL, UcassEmploymentAdapter::API_LIST_URL], true)
            && !preg_match('~^https://jyzd\.ucass\.edu\.cn/f/newsCenter/ajax_view\?id=[a-f0-9]{32}$~D', $url)) {
            throw new \RuntimeException('就业中心接口网址不在允许列表');
        }
        $options = ['stream' => true, 'headers' => ['Accept' => 'application/json'] + $headers];
        if ($form !== null) $options['form_params'] = $form;
        $response = $client->request($form === null ? 'GET' : 'POST', $url, $options);
        if ($response->getStatusCode() !== 200) throw new \RuntimeException('就业中心返回 HTTP '.$response->getStatusCode());
        if (!str_contains(strtolower($response->getHeaderLine('Content-Type')), 'application/json')) {
            throw new \RuntimeException('就业中心未返回 JSON');
        }
        $body = $response->getBody();
        $json = '';
        while (!$body->eof() && strlen($json) <= 2 * 1024 * 1024) {
            $chunk = $body->read(min(65536, 2 * 1024 * 1024 + 1 - strlen($json)));
            if ($chunk === '') break;
            $json .= $chunk;
        }
        if (strlen($json) > 2 * 1024 * 1024) throw new \RuntimeException('就业中心响应超过 2 MB');
        return $json;
    }

    protected function fire(): int
    {
        $this->lastResult = ['sources' => 0, 'succeeded' => 0, 'partial' => 0, 'failed' => 0, 'added' => 0];
        $db = $this->aggregation->db;
        $limit = (int) $this->input->getOption('limit');
        if ($limit < 1 || $limit > 10) { $this->error('limit 必须为 1–10'); return 2; }
        $sources = $db->table('campus_aggregation_sources')->where('enabled', true)
            ->whereIn('adapter', ['ucass-notices', 'ucass-vsb', 'ucass-employment']);
        if ($this->input->getOption('source')) $sources->where('id', (int) $this->input->getOption('source'));
        $sources = $sources->get();
        if ($sources->isEmpty()) { $this->comment('没有启用的来源'); return 0; }
        $client = $this->http ?? new Client(['allow_redirects' => false, 'http_errors' => false, 'timeout' => 15, 'connect_timeout' => 5,
            'headers' => ['User-Agent' => 'UCASSer-aggregation/0.1 (+reviewed campus information index)', 'Accept' => 'text/html']]);
        $failed = false;
        $unreachable = [];
        foreach ($sources as $source) {
            $this->lastResult['sources']++;
            $host = strtolower(parse_url($source->listing_url, PHP_URL_HOST) ?: '');
            try {
                if (isset($unreachable[$host])) throw new \RuntimeException('同一域名本轮连接失败，已跳过重复等待；下轮会重试，也可导入本地快照');
                $employmentToken = null;
                if ($source->adapter === 'ucass-notices') {
                    if ($host !== 'jw.ucass.edu.cn') throw new \RuntimeException('教务处适配器只接受教务处域名');
                    $adapter = new UcassNoticesAdapter();
                } elseif ($source->adapter === 'ucass-vsb') {
                    if ($host !== 'ucass.edu.cn' && !str_ends_with($host, '.ucass.edu.cn')) {
                        throw new \RuntimeException('官网适配器只接受社科大官方域名');
                    }
                    $adapter = new UcassVsbAdapter();
                } elseif ($source->adapter === 'ucass-employment') {
                    if ($source->listing_url !== UcassEmploymentAdapter::LIST_URL) {
                        throw new \RuntimeException('就业中心适配器只接受已核对的通知公告栏目');
                    }
                    $adapter = new UcassEmploymentAdapter();
                } else {
                    throw new \RuntimeException('尚未实现此来源适配器');
                }
                if ($source->adapter === 'ucass-employment') {
                    $employmentToken = $adapter->token($this->fetchEmploymentJson($client, UcassEmploymentAdapter::TOKEN_URL));
                    $listing = $adapter->listing($this->fetchEmploymentJson($client, UcassEmploymentAdapter::API_LIST_URL,
                        ['categoryId' => UcassEmploymentAdapter::CATEGORY_ID, 'pageNo' => '1', 'pageSize' => (string) $limit]), $limit);
                } else {
                    $listing = $adapter->listing($this->fetch($client, $source->listing_url, $host), $source->listing_url, $limit);
                }
                if (!$listing) throw new \RuntimeException('公告列表结构已变化或没有条目');
                $added = 0; $unavailable = 0;
                foreach ($listing as $entry) {
                    try {
                        if ($source->adapter === 'ucass-employment') {
                            $article = $adapter->article($this->fetchEmploymentJson($client,
                                UcassEmploymentAdapter::API_DETAIL_URL.'?id='.$entry['id'], null, ['token' => $employmentToken]), $entry['id']);
                        } else {
                            $article = $adapter->article($this->fetch($client, $entry['url'], $host));
                        }
                        if (isset($article['title']) && ($source->adapter === 'ucass-employment' || preg_match('/(?:\.{3}|…)$/u', $entry['title']))) {
                            $entry['title'] = $article['title'];
                        }
                        $entry += $article;
                        if ($this->aggregation->ingestWeb($source, $entry)) $added++;
                        usleep(250000);
                    } catch (\Throwable $error) {
                        if ($error instanceof ConnectException) throw $error;
                        if (str_contains($error->getMessage(), 'HTTP 403') || str_contains($error->getMessage(), 'HTTP 429')) throw $error;
                        $unavailable++;
                        $this->comment('跳过不可用公告：'.$entry['url'].'（'.$error->getMessage().'）');
                    }
                }
                $db->table('campus_aggregation_sources')->where('id', $source->id)->update(['last_checked_at' => gmdate('Y-m-d H:i:s'), 'last_error' => $unavailable ? "$unavailable 条公告暂不可用" : null]);
                $this->lastResult['added'] += $added;
                $this->lastResult[$unavailable === count($listing) ? 'failed' : ($unavailable ? 'partial' : 'succeeded')]++;
                if ($unavailable) $failed = true;
                $this->info($source->name."：新增 $added 条待审候选，跳过 $unavailable 条不可用公告");
            } catch (\Throwable $error) {
                if ($error instanceof ConnectException) $unreachable[$host] = true;
                $failed = true;
                $this->lastResult['failed']++;
                $db->table('campus_aggregation_sources')->where('id', $source->id)->update(['last_checked_at' => gmdate('Y-m-d H:i:s'), 'last_error' => mb_substr($error->getMessage(), 0, 500)]);
                $this->error($source->name.'：'.$error->getMessage());
            }
        }
        return $failed ? 1 : 0;
    }
}
