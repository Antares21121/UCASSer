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
    private ?array $settings;

    /** Server-side base URL: defaults to the co-located service. */
    private function base(): string
    {
        return rtrim(getenv('MP_RSS_URL') ?: 'http://127.0.0.1:8001', '/').'/api/v1/wx/';
    }

    /** Browser-facing base URL for the QR image (the operator's own machine). */
    private function publicBase(): string
    {
        return rtrim(getenv('MP_RSS_PUBLIC_URL') ?: (getenv('MP_RSS_URL') ?: 'http://127.0.0.1:8001'), '/');
    }

    public function __construct()
    {
        $path = __DIR__.'/../../../.runtime/we-mp-rss.env';
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
        $url = str_starts_with($path, 'http') ? $path : $this->publicBase().'/'.ltrim($path, '/');
        $response = (new Client(['timeout' => 10, 'http_errors' => false]))->request('GET', $url);
        $body = (string) $response->getBody();
        if ($response->getStatusCode() !== 200 || strlen($body) < 100) throw new ValidationException(['collector' => '无法读取二维码图片']);
        return $body;
    }

    /** Account names already subscribed in the collector. */
    public function subscribed(): array
    {
        try {
            $result = $this->call('GET', 'mps?limit=1000&offset=0&status=1', null, $this->token());
            return array_values(array_filter(array_map(fn ($row) => (string) ($row['mp_name'] ?? ''), $result['data']['list'] ?? [])));
        } catch (\Throwable $error) {
            return [];
        }
    }

    /**
     * Verify one article belongs to the registered account, then subscribe it.
     * The name must match exactly, so a look-alike account can never be added.
     */
    public function subscribe(string $name, string $articleUrl): array
    {
        $host = strtolower((string) parse_url($articleUrl, PHP_URL_HOST));
        if (!str_starts_with($articleUrl, 'https://') || $host !== 'mp.weixin.qq.com') {
            throw new ValidationException(['article_url' => '请填写该公众号一篇原文的 https://mp.weixin.qq.com/s/... 链接']);
        }
        $token = $this->token();
        $lookup = $this->call('POST', 'mps/featured/article?url='.rawurlencode($articleUrl), [], $token);
        if (($lookup['code'] ?? 1) !== 0) {
            throw new ValidationException(['article_url' => '无法识别该文章所属公众号：'.(string) ($lookup['message'] ?? '请核对链接')]);
        }
        $info = ($lookup['data'] ?? [])['mp_info'] ?? [];
        $mpName = (string) ($info['mp_name'] ?? '');
        $biz = (string) ($info['biz'] ?? (($lookup['data'] ?? [])['mp_id'] ?? ''));
        if ($mpName === '' || $biz === '') throw new ValidationException(['article_url' => '文章缺少公众号身份信息，请换一篇该号发布的原文']);
        if ($mpName !== $name) {
            throw new ValidationException(['article_url' => '该文章属于「'.$mpName.'」，与登记名称「'.$name.'」不一致']);
        }
        $result = $this->call('POST', 'mps', [
            'mp_name' => $mpName, 'mp_id' => $biz,
            'mp_cover' => (string) ($info['logo'] ?? ''), 'avatar' => (string) ($info['logo'] ?? ''),
            'mp_intro' => '',
        ], $token);
        if (($result['code'] ?? 1) !== 0) {
            throw new ValidationException(['article_url' => '订阅失败：'.(string) ($result['message'] ?? '订阅服务拒绝该请求')]);
        }
        return ['name' => $mpName, 'biz' => $biz];
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
