<?php
namespace UCASSer\Campus;

use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response;
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
            $state = $this->collector->state();
            $subscribed = $state['state'] === 'ready' ? $this->collector->subscribed() : [];
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
                throw new ValidationException(['collector' => '订阅服务不可用：'.mb_substr($error->getMessage(), 0, 120)]);
            }
            $this->audit($actor, 'aggregation.collector.qr', 'collector');
            return new JsonResponse(['data' => $qr + ['state' => $state['state']]]);
        }

        if ($route === 'campus.control.collector.qr-image') {
            try {
                $bytes = $this->collector->qrImage();
            } catch (\Throwable $error) {
                return new JsonResponse(['errors' => [['detail' => '二维码不可用：'.mb_substr($error->getMessage(), 0, 120)]]], 502);
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
                throw new ValidationException(['collector' => '订阅服务不可用：'.mb_substr($error->getMessage(), 0, 120)]);
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
}
