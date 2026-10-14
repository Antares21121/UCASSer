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
    public function __construct(private Aggregation $aggregation) {}

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
        $actor->assertAdmin();
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
            return new JsonResponse(['data' => $db->table('campus_aggregation_sources')->orderBy('id')->get()->all()]);
        }
        throw new ValidationException(['route' => '未知的信息汇集操作']);
    }
}
