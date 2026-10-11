<?php

namespace UCASSer\Resources\Api;

use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RevisionsController extends Support implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->admin($request)) return $denied;
        $id = filter_var($request->getQueryParams()['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id || !$this->db->table('ucasser_resources')->where('id', $id)->exists()) return $this->error('资料不存在', 404);
        $rows = $this->db->table('ucasser_resource_revisions')->where('resource_id', $id)->orderByDesc('id')->limit(100)->get();
        $editors = User::query()->whereIn('id', $rows->pluck('editor_id'))->get()->keyBy('id');
        $revisions = [];
        foreach ($rows as $row) {
            $previous = json_decode($row->previous_data ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?: [];
            $next = json_decode($row->new_data, true, 512, JSON_THROW_ON_ERROR);
            $changes = [];
            foreach (['title', 'url', 'source_url', 'description', 'status', 'category_id'] as $field) {
                if (($previous[$field] ?? null) !== ($next[$field] ?? null)) $changes[$field] = ['old' => $previous[$field] ?? null, 'new' => $next[$field] ?? null];
            }
            $revisions[] = ['id' => $row->id, 'editor' => $editors->get($row->editor_id)?->display_name ?? '已删除账号', 'created_at' => $row->created_at, 'reason' => $row->reason, 'changes' => $changes];
        }
        return new JsonResponse(['revisions' => $revisions]);
    }
}
