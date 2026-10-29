<?php

namespace UCASSer\Resources\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ReportsController extends Support implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'GET') {
            if ($denied = $this->admin($request)) {
                return $denied;
            }

            return new JsonResponse(['reports' => $this->db->table('ucasser_resource_reports as reports')
                ->join('ucasser_resources as resources', 'resources.id', '=', 'reports.resource_id')
                ->where('reports.status', 'pending')
                ->orderByDesc('reports.created_at')
                ->limit(50)
                ->get(['reports.id', 'reports.resource_id', 'reports.reason', 'reports.created_at', 'resources.title', 'resources.url'])]);
        }

        if ($request->getMethod() === 'PATCH') {
            if ($denied = $this->admin($request)) {
                return $denied;
            }

            $id = filter_var($request->getQueryParams()['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (! $id || ($this->input($request)['status'] ?? null) !== 'resolved') {
                return $this->error('无法处理这条反馈');
            }

            $updated = $this->db->table('ucasser_resource_reports')->where('id', $id)->where('status', 'pending')->update(['status' => 'resolved']);
            if ($updated) {
                return new JsonResponse(['id' => $id]);
            }
            $exists = $this->db->table('ucasser_resource_reports')->where('id', $id)->exists();
            // Already processed is a conflict, not "not found".
            return $this->error($exists ? '这条反馈已处理过' : '反馈不存在', $exists ? 409 : 404);
        }

        if ($request->getMethod() !== 'POST') {
            return $this->error('不支持的操作', 405);
        }

        $actor = RequestUtil::getActor($request);
        if ($actor->isGuest() || ! $actor->is_email_confirmed) {
            return $this->error('请登录并确认邮箱后再反馈链接问题', 403);
        }

        $input = $this->input($request);
        $resourceId = filter_var($input['resource_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reason = $this->text($input['reason'] ?? null, 255);
        if (! $resourceId || ! $reason || ! $this->db->table('ucasser_resources')->where('id', $resourceId)->exists()) {
            return $this->error('请选择资料并填写问题说明');
        }

        $table = $this->db->table('ucasser_resource_reports');
        if ($table->where('resource_id', $resourceId)->where('reporter_id', $actor->id)->where('status', 'pending')->exists()) {
            return $this->error('你已反馈过这条资料，等待维护者核对');
        }

        $id = $table->insertGetId([
            'resource_id' => $resourceId,
            'reporter_id' => $actor->id,
            'reason' => $reason,
            'status' => 'pending',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return new JsonResponse(['id' => $id], 201);
    }
}
