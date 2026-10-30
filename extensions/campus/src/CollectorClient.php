<?php
namespace UCASSer\Campus;

use Flarum\Foundation\ValidationException;
use GuzzleHttp\Client;

/**
 * Thin client for the local we-mp-rss subscription service.
 *
 * The service runs next to the forum (127.0.0.1:8001) and owns the WeChat
 * session; the forum only relays its QR image, polls the login state and asks
 * it to subscribe an account after verifying the publisher identity from one
 * of that account's own article links. Credentials live in
 * `.runtime/we-mp-rss.env` and are never returned to the browser.
 */
class CollectorClient
{
    public const ARTICLE_SHORT_PATTERN = '#^https://mp\.weixin\.qq\.com/s/[A-Za-z0-9_~-]+$#D';
    public const ARTICLE_PATTERN = '#^https://mp\.weixin\.qq\.com/s(?:/[A-Za-z0-9_~-]+|\?[A-Za-z0-9_=&%.-]+)$#D';

    public static function isArticleUrl(string $url, bool $allowQuery = true): bool
    {
        return preg_match($allowQuery ? self::ARTICLE_PATTERN : self::ARTICLE_SHORT_PATTERN, $url) === 1;
    }

    private ?array $settings;

    /** Server-side base URL: defaults to the co-located service. */
    private function base(): string
    {
        return rtrim($this->settings['BASE_URL'] ?? (getenv('MP_RSS_URL') ?: 'http://127.0.0.1:8001'), '/').'/api/v1/wx/';
    }

    /** Browser-facing base URL for the QR image (the operator's own machine). */
    private function publicBase(): string
    {
        return rtrim(getenv('MP_RSS_PUBLIC_URL') ?: (getenv('MP_RSS_URL') ?: 'http://127.0.0.1:8001'), '/');
    }

    public function __construct(?string $settingsPath = null)
    {
        $path = $settingsPath ?? __DIR__.'/../../../.runtime/we-mp-rss.env';
        $this->settings = null;
        if (is_file($path) && is_readable($path)) {
            $values = [];
            foreach (preg_split('/\r?\n/', (string) file_get_contents($path)) as $line) {
                if (str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    $values[trim($key)] = trim($value);
                }
            }
            if (!empty($values['USERNAME']) && !empty($values['PASSWORD'])) $this->settings = $values;
        }
    }

    public function configured(): bool
    {
        return $this->settings !== null;
    }

    /** ready | authorization_required | unavailable | unconfigured */
    public function state(): array
    {
        if (!$this->configured()) return ['state' => 'unconfigured', 'message' => '缺少 .runtime/we-mp-rss.env（订阅服务账号密码）'];
        try {
            $status = $this->call('GET', 'weread/qr/status', null, $this->token());
            $logged = (bool) (($status['data'] ?? [])['login_status'] ?? false);
            return $logged
                ? ['state' => 'ready', 'message' => '微信读书已授权']
                : ['state' => 'authorization_required', 'message' => (($status['data'] ?? [])['msg'] ?? '尚未扫码授权')];
        } catch (\Throwable $error) {
            return ['state' => 'unavailable', 'message' => '订阅服务不可用：'.mb_substr($error->getMessage(), 0, 120)];
        }
    }

    /** Fresh QR image served by the collector; the browser loads it directly. */
    public function qr(): array
    {
        $result = $this->call('GET', 'weread/qr/code', null, $this->token());
        $data = $result['data'] ?? [];
        if (empty($data['code'])) throw new ValidationException(['collector' => '订阅服务未返回二维码，请在本机订阅服务界面查看']);
        $path = (string) $data['code'];
        $image = str_starts_with($path, 'http') ? $path : $this->publicBase().'/'.ltrim($path, '/');
        return ['image' => $image, 'uid' => $data['uid'] ?? ''];
    }

    /** Raw QR image bytes, proxied so a cloud deployment needs no public port. */
    public function qrImage(): string
    {
        $client = new Client(['base_uri' => $this->base(), 'timeout' => 10, 'connect_timeout' => 4, 'http_errors' => false]);
        $data = $this->call('GET', 'weread/qr/code', null, $this->token());
        $path = (string) (($data['data'] ?? [])['code'] ?? '');
        if ($path === '') throw new ValidationException(['collector' => '订阅服务未返回二维码']);
        // The proxy fetches server-side, so it must use the server-reachable
        // origin (host.docker.internal in Docker), never the browser one.
        $serverRoot = preg_replace('#/api/v1/wx/$#', '', $this->base());
        $url = str_starts_with($path, 'http') ? $path : $serverRoot.'/'.ltrim($path, '/');
        $response = (new Client(['timeout' => 10, 'http_errors' => false]))->request('GET', $url);
        $body = (string) $response->getBody();
        if ($response->getStatusCode() !== 200 || strlen($body) < 100) throw new ValidationException(['collector' => '无法读取二维码图片']);
        return $body;
    }

    /** Account names already subscribed in the collector. */
    public function subscribed(): array
    {
        try {
            $result = $this->call('GET', 'mps?limit=100&offset=0', null, $this->token());
            return array_values(array_filter(array_map(fn ($row) => (string) ($row['mp_name'] ?? ''), $result['data']['list'] ?? [])));
        } catch (\Throwable $error) {
            return [];
        }
    }

    /**
     * Ask the collector to add the account behind one of its own articles.
     * The collector resolves the publisher asynchronously, so we snapshot the
     * current list, wait for the new row and only accept it when the name
     * matches the registered account exactly.
     */
    public function subscribe(string $name, string $articleUrl): array
    {
        // Both the short (/s/xxxx) and the classic long form (/s?__biz=...)
        // are canonical article URLs; the collector accepts either.
        if (!self::isArticleUrl($articleUrl)) {
            throw new ValidationException(['article_url' => '请填写该公众号一篇原文的 https://mp.weixin.qq.com/s/... 链接']);
        }
        $token = $this->token();
        // A canonical article URL carries the account's __biz id, which is all
        // the collector needs to add it. This avoids depending on its async
        // article resolver, which only understands short links.
        if (preg_match('/[?&]__biz=([A-Za-z0-9+\/=]+)/', $articleUrl, $match)) {
            $biz = urldecode($match[1]);
            if (strlen($biz) >= 8) {
                $result = $this->call('POST', 'mps', [
                    'mp_name' => $name, 'mp_id' => $biz, 'mp_cover' => '', 'avatar' => '', 'mp_intro' => '',
                ], $token);
                if (($result['code'] ?? 1) === 0) {
                    return ['name' => $name, 'status' => 'subscribed', 'mp_id' => $biz];
                }
            }
        }
        $existing = $this->mpList($token);
        foreach ($existing as $row) {
            if ((string) ($row['mp_name'] ?? '') === $name) {
                return ['name' => $name, 'status' => 'subscribed', 'mp_id' => (string) $row['id']];
            }
        }
        $before = array_map(fn ($row) => (string) $row['id'], $existing);
        $result = $this->call('POST', 'mps/featured/article', ['url' => $articleUrl], $token);
        if (($result['code'] ?? 1) !== 0) {
            throw new ValidationException(['article_url' => '订阅服务未能解析该文章：'.(string) ($result['message'] ?? '请核对链接')]);
        }
        $deadline = microtime(true) + 25;
        $created = null;
        while (microtime(true) < $deadline) {
            usleep(1500000);
            foreach ($this->mpList($token) as $row) {
                if (!in_array((string) $row['id'], $before, true)) { $created = $row; break 2; }
            }
        }
        if (!$created) {
            // The collector accepted the task; the account may still be
            // fetching. Treat it as queued instead of failing the operator.
            return ['name' => $name, 'status' => 'pending'];
        }
        $actual = (string) ($created['mp_name'] ?? '');
        if ($actual !== $name) {
            try { $this->call('DELETE', 'mps/'.rawurlencode((string) $created['id']), null, $token); } catch (\Throwable $ignored) {}
            throw new ValidationException(['article_url' => '该文章属于「'.$actual.'」，与登记名称「'.$name.'」不一致，已撤回订阅']);
        }
        return ['name' => $name, 'status' => 'subscribed', 'mp_id' => (string) $created['id']];
    }

    /** Subscribed accounts with their RSS ids. */
    public function feeds(): array
    {
        return array_values(array_filter($this->mpList($this->token()), fn ($row) => preg_match('/^MP_WXS_[0-9]+$/', (string) ($row['id'] ?? ''))));
    }

    /** Ask the collector to refresh one account, then return its RSS URL. */
    public function refreshFeed(string $id, string $name): string
    {
        $token = $this->token();
        $this->call('POST', 'weread/collect', ['mp_id' => $id, 'faker_id' => $id, 'mp_name' => $name, 'max_page' => 1, 'gather_content' => false], $token);
        return preg_replace('#/api/v1/wx/$#', '', $this->base()).'/rss/'.$id;
    }

    /** Fetch and parse an RSS feed into import rows. */
    public function feedRows(string $url, int $limit = 5): array
    {
        return self::parseFeedRows((string) (new Client(['timeout' => 30, 'http_errors' => false]))->request('GET', $url)->getBody(), $limit);
    }

    public static function parseFeedRows(string $body, int $limit = 5): array
    {
        $xml = @simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
        if (!$xml) return [];
        $rows = [];
        foreach ($xml->channel->item as $item) {
            $url = trim((string) $item->link);
            if (!self::isArticleUrl($url)) continue;
            $rows[] = [
                'url' => $url, 'title' => trim((string) $item->title),
                'summary' => trim(strip_tags((string) $item->description)),
                'published_at' => ($t = strtotime((string) $item->pubDate)) ? gmdate('Y-m-d H:i:s', $t + 8 * 3600) : null,
            ];
            if (count($rows) >= $limit) break;
        }
        return $rows;
    }

    /** Current subscriptions (id + display name). */
    private function mpList(string $token): array
    {
        $result = $this->call('GET', 'mps?limit=100&offset=0', null, $token);
        return $result['data']['list'] ?? [];
    }

    private function token(): string
    {
        if (!$this->settings) throw new ValidationException(['collector' => '缺少 .runtime/we-mp-rss.env']);
        $result = $this->raw('POST', 'auth/login', [
            'form_params' => ['username' => $this->settings['USERNAME'], 'password' => $this->settings['PASSWORD']],
        ]);
        $token = (string) (($result['data'] ?? [])['access_token'] ?? '');
        if ($token === '') throw new \RuntimeException('订阅服务登录失败，请核对 .runtime/we-mp-rss.env');
        return $token;
    }

    private function call(string $method, string $path, ?array $json, string $token): array
    {
        $options = ['headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']];
        if ($json !== null) $options['json'] = $json;
        return $this->raw($method, $path, $options);
    }

    private function raw(string $method, string $path, array $options): array
    {
        $client = new Client(['base_uri' => $this->base(), 'timeout' => 10, 'connect_timeout' => 4, 'http_errors' => false]);
        $response = $client->request($method, $path, $options);
        $body = json_decode((string) $response->getBody(), true);
        if (!is_array($body)) throw new \RuntimeException('订阅服务返回了无法解析的内容');
        return $body;
    }
}
