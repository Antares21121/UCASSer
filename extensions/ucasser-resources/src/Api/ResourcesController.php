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
        // Hard cap keeps the offset arithmetic bounded; 10,000 pages x 25 rows is far
        // beyond any realistic directory size, and the effective page is echoed back.
        $page = min($page, 10000);
        $rawSearch = $query['q'] ?? '';
        if (isset($query['q']) && ! is_string($rawSearch)) {
            return $this->error('搜索词格式无效');
        }
        $search = $this->text($rawSearch, 100);
        if ($rawSearch !== '' && ! $search) {
            // An over-long or whitespace/invisible-only keyword must not silently
            // disable the filter and return the unfiltered full list.
            return $this->error(mb_strlen($rawSearch) > 100 ? '搜索词最长 100 字' : '搜索词不能只包含空白或不可见字符');
        }
        $rows = $this->db->table('ucasser_resources')->orderByDesc('updated_at')->orderByDesc('id');

        if ($categoryId) {
            $ids = [(int)$categoryId];
            $categories = $this->db->table('ucasser_resource_categories')->get(['id', 'parent_id']);
            do {
                $before = count($ids);
                foreach ($categories as $category) {
                    if (in_array((int)$category->parent_id, $ids, true) && !in_array((int)$category->id, $ids, true)) $ids[] = (int)$category->id;
                }
            } while (count($ids) !== $before);
            $rows->whereIn('category_id', $ids);
        }

        if ($search) {
            $rows->where(function ($builder) use ($search) {
                $builder->where('title', 'like', '%'.addcslashes($search, '%_\\').'%')
                    ->orWhere('description', 'like', '%'.addcslashes($search, '%_\\').'%');
            });
        }

        $total = (clone $rows)->count();
        $items = $rows->offset(($page - 1) * 25)->limit(26)->get();

        return new JsonResponse([
            'resources' => $items->take(25)->values(),
            'has_more' => $items->count() > 25,
            'page' => $page,
            'total' => $total,
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

        if (! $title) {
            return $this->error('请填写有效标题（最长 180 字）');
        }
        if (! $url) {
            return $this->error('请填写有效的 http(s) 访问链接');
        }
        if (! in_array($status, ['active', 'needs_review'], true)) {
            return $this->error('资料状态只能是 active 或 needs_review');
        }
        if (($input['description'] ?? '') !== '' && ! $description) {
            return $this->error('简介最长 2000 字');
        }
        if (($input['source_url'] ?? '') !== '' && ! $sourceUrl) {
            return $this->error('来源链接必须是有效的 http(s) 地址');
        }
        if (($input['reason'] ?? '') !== '' && ! $reason) {
            return $this->error('修订原因最长 255 字');
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

        $now = gmdate('Y-m-d H:i:s');
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

        $id = $this->db->transaction(function () use ($id, $values, $now, $actorId, $reason) {
            $table = $this->db->table('ucasser_resources');
            $previous = null;
            if ($id) {
                // Lock the row so concurrent edits cannot interleave: previous_data in
                // the revision log must describe the state this transaction replaces.
                $previous = $table->where('id', $id)->lockForUpdate()->first();
                if (! $previous) {
                    throw new \RuntimeException('资料不存在');
                }
                $table->where('id', $id)->update($values);
            } else {
                $id = $table->insertGetId($values + ['created_at' => $now]);
            }

            // Keep the two JSON snapshots key-compatible (both carry id/created_at)
            // so per-field diffs do not have to ignore structural key differences.
            $newData = $values + ['id' => (int) $id, 'created_at' => $previous->created_at ?? $now];

            $this->db->table('ucasser_resource_revisions')->insert([
                'resource_id' => $id,
                'editor_id' => $actorId,
                'previous_data' => $previous ? json_encode($previous, JSON_UNESCAPED_UNICODE) : null,
                'new_data' => json_encode($newData, JSON_UNESCAPED_UNICODE),
                'reason' => $reason,
                'created_at' => $now,
            ]);

            return $id;
        });

        return new JsonResponse(['id' => $id], $previous ? 200 : 201);
    }
}
