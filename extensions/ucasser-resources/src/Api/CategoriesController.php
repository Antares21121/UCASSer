<?php

namespace UCASSer\Resources\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CategoriesController extends Support implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return match ($request->getMethod()) {
            'GET' => new JsonResponse(['categories' => $this->db->table('ucasser_resource_categories')->orderBy('id')->get()]),
            'POST' => $this->create($request),
            'PATCH' => $this->rename($request),
            default => $this->error('不支持的操作', 405),
        };
    }

    private function create(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->admin($request)) {
            return $denied;
        }

        $input = $this->input($request);
        $name = $this->text($input['name'] ?? null, 120);
        $parentId = filter_var($input['parent_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $parent = $parentId ? $this->db->table('ucasser_resource_categories')->where('id', $parentId)->first() : null;
        $kind = $input['kind'] ?? null;

        if (! $name || ! $parent || ! (($parent->kind === 'root' && $parent->name === '校内资料' && $kind === 'college')
            || ($parent->kind === 'course_group' && $kind === 'course'))) {
            return $this->error('请选择校内根分类添加学院，或选择必修／选修分类添加课程');
        }

        if ($this->db->table('ucasser_resource_categories')->where('parent_id', $parentId)->where('name', $name)->exists()) {
            return $this->error('同一分类下已有这个名称');
        }

        $now = date('Y-m-d H:i:s');
        $id = $this->db->transaction(function () use ($parentId, $kind, $name, $now) {
            $table = $this->db->table('ucasser_resource_categories');
            $id = $table->insertGetId(['parent_id' => $parentId, 'kind' => $kind, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);

            if ($kind === 'college') {
                $table->insert([
                    ['parent_id' => $id, 'kind' => 'course_group', 'name' => '必修课', 'position' => 0, 'created_at' => $now, 'updated_at' => $now],
                    ['parent_id' => $id, 'kind' => 'course_group', 'name' => '选修课', 'position' => 1, 'created_at' => $now, 'updated_at' => $now],
                ]);
            }

            return $id;
        });

        return new JsonResponse(['id' => $id], 201);
    }

    private function rename(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->admin($request)) {
            return $denied;
        }

        $id = filter_var($request->getQueryParams()['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $category = $id ? $this->db->table('ucasser_resource_categories')->where('id', $id)->first() : null;
        if (! $category || ! in_array($category->kind, ['college', 'course'], true)) {
            return $this->error('只能修改学院和课程名称', 404);
        }

        $name = $this->text($this->input($request)['name'] ?? null, 120);
        if (! $name) {
            return $this->error('名称不能为空，且不得超过 120 字');
        }

        if ($this->db->table('ucasser_resource_categories')->where('parent_id', $category->parent_id)->where('name', $name)->where('id', '!=', $id)->exists()) {
            return $this->error('同一分类下已有这个名称');
        }

        $this->db->table('ucasser_resource_categories')->where('id', $id)->update(['name' => $name, 'updated_at' => date('Y-m-d H:i:s')]);

        return new JsonResponse(['id' => $id, 'name' => $name]);
    }
}
