<?php
namespace UCASSer\Campus;

use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Operator endpoints for the WeChat subscription service: state, one-off QR
 * hand-off and identity-checked subscription of a registered account.
 */
class CollectorController implements RequestHandlerInterface
{
    public function __construct(private CollectorClient $collector, private OperatorAccess $access, private \Illuminate\Database\ConnectionInterface $db)
    {
    }

    public function handle(Request $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $this->access->assert($actor);
        $route = (string) $request->getAttribute('routeName');

        if ($route === 'campus.control.collector') {
            $uid = $request->getQueryParams()['qr'] ?? null;
            if ($uid !== null) {
                $cache = resolve(\Illuminate\Contracts\Cache\Repository::class);
                if (!is_string($uid) || !preg_match('/^[A-Za-z0-9_-]{8,256}$/D', $uid)
                    || !$cache->has($this->qrKey($actor, $uid)) || $cache->get('campus.collector.active_qr') !== $uid) {
                    throw new ValidationException(['collector' => '二维码已失效，请重新获取']);
                }
            }
            $state = $uid === null ? $this->collector->state() : $this->collector->qrState();
            try { $subscribed = $state['state'] === 'ready' ? $this->collector->subscribed() : []; }
            catch (\Throwable $error) {
                $subscribed = [];
                $state = ['state' => 'unavailable', 'message' => '已验证授权，但暂时无法读取订阅清单，请稍后刷新'];
            }
            $accounts = array_map(fn ($row) => ['name' => $row[0], 'url' => $row[1], 'subscribed' => in_array($row[0], $subscribed, true)], OfficialSourceCatalog::WECHAT);
            return new JsonResponse(['data' => [
                'state' => $state['state'], 'message' => $state['message'],
                'configured' => $this->collector->configured(),
                'accounts' => $accounts,
                'subscribed_total' => count($subscribed),
            ]]);
        }

        if ($route === 'campus.control.collector.qr') {
            $state = $this->collector->state();
            if ($state['state'] === 'unconfigured') throw new ValidationException(['collector' => $state['message']]);
            try {
                $qr = $this->collector->qr();
            } catch (\Flarum\Foundation\ValidationException $error) {
                throw $error;
            } catch (\Throwable $error) {
                throw new ValidationException(['collector' => '订阅服务暂不可用，请检查服务和网络']);
            }
            $cache = resolve(\Illuminate\Contracts\Cache\Repository::class);
            $cache->put($this->qrKey($actor, $qr['uid']), $qr['path'], 300);
            $cache->put('campus.collector.active_qr', $qr['uid'], 300);
            $this->audit($actor, 'aggregation.collector.qr', 'collector');
            return new JsonResponse(['data' => [
                'uid' => $qr['uid'], 'image' => '/api/campus/control/collector/qr-image?v='.rawurlencode($qr['uid']),
                'state' => $state['state'],
            ]], 200, ['Cache-Control' => 'no-store']);
        }

        if ($route === 'campus.control.collector.qr-page') {
            // Plain HTML page: the operator can always reach the QR even when a
            // POST from the console is blocked by CSRF or stale session state.
            $qr = $this->collector->qr();
            $cache = resolve(\Illuminate\Contracts\Cache\Repository::class);
            $cache->put($this->qrKey($actor, $qr['uid']), $qr['path'], 300);
            $cache->put('campus.collector.active_qr', $qr['uid'], 300);
            $image = '/api/campus/control/collector/qr-image?v='.rawurlencode($qr['uid']);
            $html = '<!doctype html><html lang="zh-Hans"><meta charset="utf-8">'
                .'<meta name="viewport" content="width=device-width,initial-scale=1"><title>扫码授权公众号采集</title>'
                .'<style>html,body{margin:0;min-height:100%;background:#141A24;color:#EDF2F8;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}'
                .'main{max-width:420px;margin:0 auto;padding:32px 20px;text-align:center}h1{font-size:18px;margin:0 0 8px}'
                .'p{color:#AAB7C6;font-size:13px;line-height:1.7;margin:8px 0}'
                .'img{width:240px;height:240px;background:#fff;border-radius:12px;padding:8px;margin:12px 0}'
                .'a{color:#FF8FA2}</style><main><h1>扫码授权公众号采集</h1>'
                .'<p>用手机微信扫描下方二维码完成「微信读书」授权；二维码 5 分钟内有效。</p>'
                .'<img src="'.$image.'" alt="微信读书授权二维码" width="240" height="240">'
                .'<p><a href="">刷新二维码</a> · <a href="/campus/control">返回控制台</a></p>'
                .'</main></html>';
            return new HtmlResponse($html, 200, ['Cache-Control' => 'no-store']);
        }

        if ($route === 'campus.control.collector.qr-image') {
            try {
                $uid = $request->getQueryParams()['v'] ?? '';
                if (!is_string($uid) || !preg_match('/^[A-Za-z0-9_-]{8,256}$/D', $uid)) throw new \RuntimeException('Invalid QR');
                $path = resolve(\Illuminate\Contracts\Cache\Repository::class)->get($this->qrKey($actor, $uid));
                if (!is_string($path) || resolve(\Illuminate\Contracts\Cache\Repository::class)->get('campus.collector.active_qr') !== $uid) throw new \RuntimeException('QR expired');
                // Reading the image must not generate another QR and invalidate
                // the one already handed to the operator.
                $bytes = $this->collector->qrImage($path);
            } catch (\Throwable $error) {
                return new JsonResponse(['errors' => [['detail' => '二维码已失效或暂不可用，请刷新二维码']]], 502, ['Cache-Control' => 'no-store']);
            }
            $response = new Response();
            $response->getBody()->write($bytes);
            return $response->withHeader('Content-Type', 'image/png')
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        }

        if ($route === 'campus.control.collector.subscribe') {
            $input = json_decode((string) $request->getBody(), true);
            $name = trim((string) ($input['name'] ?? ''));
            $url = trim((string) ($input['article_url'] ?? ''));
            $known = array_column(OfficialSourceCatalog::WECHAT, 0);
            if (!in_array($name, $known, true)) throw new ValidationException(['name' => '只能订阅来源清单中已登记且核对过身份的公众号']);
            try {
                $result = $this->collector->subscribe($name, $url);
            } catch (\Flarum\Foundation\ValidationException $error) {
                throw $error;
            } catch (\Throwable $error) {
                throw new ValidationException(['collector' => '订阅服务暂不可用，请检查服务和网络']);
            }
            $this->audit($actor, 'aggregation.collector.subscribe', $result['name']);
            return new JsonResponse(['data' => $result]);
        }

        throw new ValidationException(['route' => '未知的公众号授权操作']);
    }

    private function audit(User $actor, string $action, string $target): void
    {
        $this->db->table('campus_audit')->insert([
            'actor_id' => $actor->id, 'action' => $action, 'target' => $target,
            'reason' => null, 'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function qrKey(User $actor, string $uid): string
    {
        return 'campus.collector.qr.'.hash('sha256', $actor->id.':'.$uid);
    }
}
