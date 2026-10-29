<?php
namespace UCASSer\Campus;

use Flarum\Http\RequestUtil;
use Flarum\Foundation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;

class AggregationController implements RequestHandlerInterface
{
    public function __construct(private Aggregation $aggregation, private OperatorAccess $operators) {}

    public function handle(Request $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $route = $request->getAttribute('routeName');
        $query = $request->getQueryParams();
        $input = $request->getParsedBody() ?? [];
        $db = $this->aggregation->db;
        if ($route === 'campus.aggregation.items') return new JsonResponse($this->aggregation->visibleItems($actor, (int) ($query['page'] ?? 1)));
        if ($route === 'campus.aggregation.item') {
            $item = $db->table('campus_aggregation_items')->where('id', (int) ($query['id'] ?? 0))->first();
            if (!$item) throw new ModelNotFoundException;
            return new JsonResponse(['data' => $this->aggregation->serializeItem($actor, $item)]);
        }
        if ($route === 'campus.aggregation.submit') {
            $id = $this->aggregation->submit($actor, $input);
            return new JsonResponse(['data' => ['id' => $id, 'status' => 'pending']], 201);
        }
        if ($route === 'campus.aggregation.mine') {
            $actor->assertRegistered();
            $rows = $db->table('campus_aggregation_candidates')->where('submitter_id', $actor->id)->orderByDesc('id')->limit(50)
                ->get(['id', 'title', 'status', 'item_id', 'review_reason', 'created_at']);
            return new JsonResponse(['data' => $rows->all()]);
        }
        if (!$actor->isAdmin()) $this->operators->assert($actor);
        if ($route === 'campus.aggregation.queue') {
            $page = max(1, min(10000, (int) ($query['page'] ?? 1)));
            $pending = $db->table('campus_aggregation_candidates')->where('status', 'pending');
            $total = (clone $pending)->count();
            $rows = $pending->orderByDesc('id')->offset(($page - 1) * 20)->limit(20)->get();
            return new JsonResponse(['data' => $rows->all(), 'meta' => ['total' => $total, 'page' => $page, 'page_size' => 20]]);
        }
        if ($route === 'campus.aggregation.review') {
            return new JsonResponse(['data' => $this->aggregation->review($actor, (int) ($query['id'] ?? 0), $input)]);
        }
        if ($route === 'campus.aggregation.sources') {
            $status = json_decode((string) $db->table('settings')->where('key', 'ucasser.aggregation.collector_status')->value('value'), true);
            if (($query['status_only'] ?? '') === '1') {
                return new JsonResponse(['meta' => ['collector' => is_array($status) ? $status : ['state' => 'unknown']]]);
            }
            return new JsonResponse([
                'data' => $db->table('campus_aggregation_sources')->orderByDesc('id')->get()->all(),
                'meta' => ['collector' => is_array($status) ? $status : ['state' => 'unknown']],
            ]);
        }
        if ($route === 'campus.aggregation.source.update') {
            $id = (int) ($query['id'] ?? 0);
            $source = $db->table('campus_aggregation_sources')->where('id', $id)->first();
            if (!$source) throw new ModelNotFoundException;
            if (!in_array($source->adapter, ['ucass-notices', 'ucass-vsb', 'ucass-employment'], true)) {
                throw new ValidationException(['source' => '此来源尚未接入站内网页采集，请在对应订阅服务中管理']);
            }
            if (!array_key_exists('enabled', $input) || !is_bool($input['enabled'])) {
                throw new ValidationException(['enabled' => '请选择开启或暂停']);
            }
            if ((bool) $source->enabled !== $input['enabled']) {
                $db->transaction(function () use ($db, $id, $actor, $input) {
                    $now = gmdate('Y-m-d H:i:s');
                    $db->table('campus_aggregation_sources')->where('id', $id)->update([
                        'enabled' => $input['enabled'], 'updated_at' => $now
                    ]);
                    $db->table('campus_audit')->insert([
                        'actor_id' => $actor->id, 'action' => $input['enabled'] ? 'aggregation.source.resume' : 'aggregation.source.pause',
                        'target' => 'aggregation_source:'.$id, 'reason' => null, 'created_at' => $now
                    ]);
                });
            }
            return new JsonResponse(['data' => ['id' => $id, 'enabled' => $input['enabled']]]);
        }
        if ($route === 'campus.aggregation.source.create') {
            $kind = trim((string) ($input['kind'] ?? ''));
            $name = trim((string) ($input['name'] ?? ''));
            $url = trim((string) ($input['url'] ?? ''));
            $parts = parse_url($url);
            $host = strtolower((string) ($parts['host'] ?? ''));
            if (!in_array($kind, ['web', 'wechat'], true)) throw new ValidationException(['kind' => '请选择网页或公众号']);
            if (mb_strlen($name) < 2 || mb_strlen($name) > 120) throw new ValidationException(['name' => '来源名称需为 2–120 字']);
            if (mb_strlen($url) > 1024 || ($parts['scheme'] ?? '') !== 'https' || !$host || isset($parts['user']) || isset($parts['pass'])) {
                throw new ValidationException(['url' => '请输入不含账号密码的 HTTPS 链接']);
            }
            if ($kind === 'wechat') {
                if ($host !== 'mp.weixin.qq.com' || !preg_match('~^/s(?:/[A-Za-z0-9_-]+|\?[A-Za-z0-9_=&%.-]+)$~D',
                    ($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : ''))) {
                    throw new ValidationException(['url' => '请填写该公众号自行发布的一篇微信原文链接']);
                }
            } elseif (!filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || !str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP)) {
                throw new ValidationException(['url' => '请填写公开网站的域名，不能填写本机或 IP 地址']);
            }
            if ($db->table('campus_aggregation_sources')->where('listing_url', $url)->exists()) {
                throw new ValidationException(['url' => '该链接已经登记']);
            }
            $now = gmdate('Y-m-d H:i:s');
            $id = $db->table('campus_aggregation_sources')->insertGetId([
                'name' => $name, 'listing_url' => $url,
                'adapter' => $kind === 'wechat' ? 'we-mp-rss-pending' : 'pending-web',
                'trust' => 'unofficial', 'visibility' => 'public', 'enabled' => false,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            return new JsonResponse(['data' => ['id' => $id, 'status' => 'pending_adapter']], 201);
        }
        throw new ValidationException(['route' => '未知的信息汇集操作']);
    }
}
