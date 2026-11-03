<?php
namespace UCASSer\Campus;

use Flarum\Foundation\ValidationException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

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

    public function __construct(?string $settingsPath = null, private ?ClientInterface $http = null)
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
            $token = $this->token();
            // QR status is transient singleton state, not the saved authorization.
            // Never return this endpoint's cookies, tickets or account data.
            $status = $this->call('GET', 'weread', null, $token);
            if (($status['data']['mp_configured'] ?? false) !== true) {
                return ['state' => 'authorization_required', 'message' => '尚未保存微信读书授权，请扫码授权'];
            }
            $test = $this->call('POST', 'weread/test', [], $token);
            if (($test['data']['ok'] ?? null) === true) return ['state' => 'ready', 'message' => '微信读书授权已验证'];
            // The upstream test conflates expired cookies and connection failures.
            // Do not force a new QR merely because the network check failed.
            return ['state' => 'unavailable', 'message' => '已保存授权，但连接校验未通过；请检查订阅服务网络或主动重新授权'];
        } catch (CollectorAuthorizationRequired $error) {
            return ['state' => 'authorization_required', 'message' => '微信读书要求重新授权，请扫码确认'];
        } catch (\Throwable $error) {
            return ['state' => 'unavailable', 'message' => '订阅服务连接或认证失败，请检查服务状态'];
        }
    }

    /** Fresh QR image served by the collector; the browser loads it directly. */
    public function qr(): array
    {
        $result = $this->call('GET', 'weread/qr/code', null, $this->token());
        $data = $result['data'] ?? [];
        if (empty($data['code'])) throw new ValidationException(['collector' => '订阅服务未返回二维码，请在本机订阅服务界面查看']);
        $uid = (string) ($data['uid'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{8,256}$/D', $uid)) throw new \RuntimeException('Unsupported QR identifier');
        return ['path' => $this->qrPath((string) $data['code']), 'uid' => $uid];
    }

    /** Only an active operator QR flow may advance the upstream login handshake. */
    public function qrState(): array
    {
        try {
            $status = $this->call('GET', 'weread/qr/status', null, $this->token());
            if (($status['data']['login_status'] ?? false) === true) return $this->state();
            return ['state' => 'authorization_required', 'message' => '正在等待手机确认'];
        } catch (\Throwable $error) {
            return ['state' => 'unavailable', 'message' => '扫码状态暂不可用，请稍后重试'];
        }
    }

    /** Raw QR image bytes, proxied so a cloud deployment needs no public port. */
    public function qrImage(string $path): string
    {
        $path = $this->qrPath($path);
        $serverRoot = preg_replace('#/api/v1/wx/$#', '', $this->base());
        $response = $this->request('GET', $serverRoot.$path);
        $body = $response->getBody()->read(1024 * 1024 + 1);
        if ($response->getStatusCode() !== 200 || strlen($body) < 100 || strlen($body) > 1024 * 1024
            || !str_starts_with($body, "\x89PNG\r\n\x1a\n")) throw new \RuntimeException('QR image unavailable');
        return $body;
    }

    private function qrPath(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $url = parse_url($path); $base = parse_url($this->base());
            if (!$url || ($url['scheme'] ?? '') !== $base['scheme'] || ($url['host'] ?? '') !== $base['host']
                || ($url['port'] ?? null) !== ($base['port'] ?? null) || isset($url['user']) || isset($url['pass'])
                || isset($url['query']) || isset($url['fragment'])) throw new \RuntimeException('Untrusted QR origin');
            $path = $url['path'] ?? '';
        }
        if (!preg_match('~^/static/[A-Za-z0-9_/-]+\.png$~D', $path)) throw new \RuntimeException('Unsupported QR image path');
        return $path;
    }

    /** Account names already subscribed in the collector. */
    public function subscribed(): array
    {
        $rows = $this->mpList($this->token());
        $names = [];
        foreach (OfficialSourceCatalog::WECHAT as [$name]) {
            foreach ($rows as $row) {
                if (self::publisherMatches((string) ($row['id'] ?? ''), $name, (string) ($row['mp_name'] ?? ''))) {
                    $names[] = $name;
                    break;
                }
            }
        }
        return $names;
    }

    /**
     * Ask the collector to add the account behind one of its own articles.
     * The collector resolves the publisher asynchronously, so we snapshot the
     * current list, wait for the new row and only accept it when the name
     * matches the registered account exactly.
     */
    public function subscribe(string $name, string $articleUrl): array
    {
        if (strlen($articleUrl) > 2048 || !self::isArticleUrl($articleUrl)) {
            throw new ValidationException(['article_url' => '请填写该公众号自己的 https://mp.weixin.qq.com 微信原文链接']);
        }
        $token = $this->token();
        parse_str((string) parse_url($articleUrl, PHP_URL_QUERY), $query);
        $biz = $query['__biz'] ?? null;
        if ($biz === null) {
            // This endpoint resolves public metadata without creating a feed.
            // The featured-article endpoint creates a different virtual feed.
            $info = $this->call('POST', 'mps/by_article?'.http_build_query(['url' => $articleUrl]), [], $token);
            $biz = $info['data']['mp_info']['biz'] ?? null;
        }
        $numeric = is_string($biz) ? base64_decode($biz, true) : false;
        if (!is_string($numeric) || !preg_match('/^[1-9][0-9]{0,19}$/D', $numeric)) {
            throw new ValidationException(['article_url' => '无法核验发布者，请复制包含 __biz 的原文分享链接']);
        }
        $id = 'MP_WXS_'.$numeric;
        $cover = $this->call('POST', 'weread/mp/test', ['mp_id' => $id], $token);
        if (($cover['data']['mp_id'] ?? null) !== $id || !self::publisherMatches($id, $name, (string) ($cover['data']['mp_name'] ?? ''))) {
            throw new ValidationException(['article_url' => '该链接的发布者与登记公众号不符，未添加订阅']);
        }
        foreach ($this->mpList($token) as $row) {
            if ((string) ($row['id'] ?? '') === $id || (string) ($row['faker_id'] ?? '') === $id) {
                return ['name' => $name, 'status' => 'subscribed', 'mp_id' => $id];
            }
        }
        $this->call('POST', 'mps', [
            'mp_name' => $name, 'mp_id' => base64_encode($numeric), 'mp_cover' => '', 'avatar' => '', 'mp_intro' => '',
        ], $token);
        return ['name' => $name, 'status' => 'subscribed', 'mp_id' => $id];
    }

    public static function publisherMatches(string $id, string $expected, string $actual): bool
    {
        if ($expected === $actual && $actual !== '') return true;
        $renamed = [
            'MP_WXS_3894823465' => ['中国社科大国际政治经济学院', '中国社科大全球与区域国别学院'],
            'MP_WXS_3888538840' => ['社科大哲学院', '中国社会科学院大学哲学院'],
            'MP_WXS_3093663298' => ['社科大科研处', '中国社科大科研处'],
        ];
        return isset($renamed[$id]) && $renamed[$id] === [$expected, $actual];
    }

    public static function sourceName(string $id, string $actual): string
    {
        foreach (OfficialSourceCatalog::WECHAT as [$name]) {
            if (self::publisherMatches($id, $name, $actual)) return $name;
        }
        return $actual;
    }

    /** Subscribed accounts with their RSS ids. */
    public function feeds(): array
    {
        return array_values(array_filter($this->mpList($this->token()), fn ($row) => preg_match('/^MP_WXS_[0-9]+$/', (string) ($row['id'] ?? ''))
            && (!array_key_exists('status', $row) || (int) $row['status'] === 1)));
    }

    /** Ask the collector to refresh one account, then return its RSS URL. */
    public function refreshFeed(string $id, string $name): string
    {
        $token = $this->token();
        $this->call('POST', 'weread/collect', ['mp_id' => $id, 'faker_id' => $id, 'mp_name' => $name, 'max_page' => 1, 'gather_content' => false], $token, 180);
        return preg_replace('#/api/v1/wx/$#', '', $this->base()).'/rss/'.$id;
    }

    /** Fetch and parse an RSS feed into import rows. */
    public function feedRows(string $url, int $limit = 5): array
    {
        $root = preg_replace('#/api/v1/wx/$#', '', $this->base());
        if (!preg_match('~^'.preg_quote($root, '~').'/rss/MP_WXS_\d+$~D', $url)) throw new \RuntimeException('Untrusted RSS origin');
        $response = $this->request('GET', $url);
        if ($response->getStatusCode() !== 200) throw new \RuntimeException('RSS request failed');
        $body = $response->getBody()->read(1024 * 1024 + 1);
        if (strlen($body) > 1024 * 1024) throw new \RuntimeException('RSS response too large');
        return self::parseFeedRows($body, $limit);
    }

    public static function parseFeedRows(string $body, int $limit = 5): array
    {
        $xml = @simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
        if (!$xml || !$xml->channel) throw new \RuntimeException('RSS response is not a channel');
        $rows = [];
        foreach ($xml->channel->item as $item) {
            $url = trim((string) $item->link);
            if (!self::isArticleUrl($url)) continue;
            $rows[] = [
                'url' => $url, 'title' => trim((string) $item->title),
                'summary' => trim(strip_tags((string) $item->description)),
                'published_at' => ($t = strtotime((string) $item->pubDate)) !== false ? gmdate('Y-m-d H:i:s', $t) : null,
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

    private function call(string $method, string $path, ?array $json, string $token, int $timeout = 10): array
    {
        $options = ['headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'], 'timeout' => $timeout];
        if ($json !== null) $options['json'] = $json;
        return $this->raw($method, $path, $options);
    }

    private function raw(string $method, string $path, array $options): array
    {
        $response = $this->request($method, $this->base().$path, $options);
        $raw = $response->getBody()->read(1024 * 1024 + 1);
        if (strlen($raw) > 1024 * 1024) throw new \RuntimeException('Collector response too large');
        $body = json_decode($raw, true);
        if (!is_array($body)) throw new \RuntimeException('订阅服务返回了无法解析的内容');
        $code = (string) ($body['code'] ?? '');
        $upstreamCode = (string) ($body['data']['code'] ?? '');
        if (in_array($code, ['-2012', '-2041', '-2010'], true) || in_array($upstreamCode, ['-2012', '-2041', '-2010'], true)) {
            throw new CollectorAuthorizationRequired('WeRead authorization required');
        }
        if ($path === 'weread/test' && ($body['data']['ok'] ?? null) === false && in_array($response->getStatusCode(), [200, 400], true)) return $body;
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300 || $code !== '0') {
            // Upstream messages can contain cookies/tokens. Only static text escapes.
            throw new \RuntimeException('Collector request failed');
        }
        return $body;
    }

    private function request(string $method, string $url, array $options = []): \Psr\Http\Message\ResponseInterface
    {
        return ($this->http ?? new Client())->request($method, $url, $options + [
            'timeout' => 10, 'connect_timeout' => 4, 'http_errors' => false, 'allow_redirects' => false,
        ]);
    }
}
