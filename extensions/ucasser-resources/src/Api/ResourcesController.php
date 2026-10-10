<?php

namespace UCASSer\Resources\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ResourcesController extends Support implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return match ($request->getMethod()) {
            'GET' => $this->index($request),
            'POST' => $this->save($request),
            'PATCH' => $this->save($request),
            default => $this->error('不支持的操作', 405),
        };
    }

    private function index(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $categoryId = filter_var($query['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $page = min($page, 10000);
        $search = $this->text($query['q'] ?? '', 100);
        $rows = $this->db->table('ucasser_resources')->orderByDesc('updated_at')->orderByDesc('id');

        if ($categoryId) {
            $rows->where('category_id', $categoryId);
        }

        if ($search) {
            $rows->where(function ($builder) use ($search) {
                $builder->where('title', 'like', '%'.addcslashes($search, '%_\\').'%')
                    ->orWhere('description', 'like', '%'.addcslashes($search, '%_\\').'%');
            });
        }

        $items = $rows->offset(($page - 1) * 25)->limit(26)->get();

        return new JsonResponse([
            'resources' => $items->take(25)->values(),
            'has_more' => $items->count() > 25,
            'page' => $page,
            'can_manage' => RequestUtil::getActor($request)->isAdmin(),
        ]);
    }

    private function save(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->admin($request)) {
            return $denied;
        }

        $input = $this->input($request);
        $categoryId = filter_var($input['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $category = $categoryId ? $this->db->table('ucasser_resource_categories')->where('id', $categoryId)->first() : null;
        if (! $category || ! in_array($category->kind, ['course', 'external_topic'], true)) {
            return $this->error('资料只能放在具体课程、四六级或计算机二级下');
        }

        $title = $this->text($input['title'] ?? null, 180);
        $url = $this->url($input['url'] ?? null);
        $description = $this->text($input['description'] ?? null, 2000);
        $sourceUrl = ($input['source_url'] ?? '') === '' ? null : $this->url($input['source_url']);
        $status = $input['status'] ?? 'active';
        $reason = ($input['reason'] ?? '') === '' ? null : $this->text($input['reason'], 255);

        if (! $title || ! $url || ! in_array($status, ['active', 'needs_review'], true)
            || (($input['description'] ?? '') !== '' && ! $description)
            || (($input['source_url'] ?? '') !== '' && ! $sourceUrl)
            || (($input['reason'] ?? '') !== '' && ! $reason)) {
            return $this->error('请填写有效标题和 http(s) 链接；简介最多 2000 字');
        }

        $id = null;
        $previous = null;
        if ($request->getMethod() === 'PATCH') {
            $id = filter_var($request->getQueryParams()['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $previous = $id ? $this->db->table('ucasser_resources')->where('id', $id)->first() : null;
            if (! $previous) {
                return $this->error('资料不存在', 404);
            }
            if (! $reason) {
                return $this->error('修改资料时请填写修订原因');
            }
        }

        $now = date('Y-m-d H:i:s');
        $actorId = RequestUtil::getActor($request)->id;
        $values = [
            'category_id' => $categoryId,
            'title' => $title,
            'url' => $url,
            'description' => $description,
            'source_url' => $sourceUrl,
            'status' => $status,
            'updated_by' => $actorId,
            'updated_at' => $now,
        ];

        $id = $this->db->transaction(function () use ($id, $previous, $values, $now, $actorId, $reason) {
            $table = $this->db->table('ucasser_resources');
            if ($id) {
                $table->where('id', $id)->update($values);
            } else {
                $id = $table->insertGetId($values + ['created_at' => $now]);
            }

            $this->db->table('ucasser_resource_revisions')->insert([
                'resource_id' => $id,
                'editor_id' => $actorId,
                'previous_data' => $previous ? json_encode($previous, JSON_UNESCAPED_UNICODE) : null,
                'new_data' => json_encode($values, JSON_UNESCAPED_UNICODE),
                'reason' => $reason,
                'created_at' => $now,
            ]);

            return $id;
        });

        return new JsonResponse(['id' => $id], $previous ? 200 : 201);
    }
}
