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
            'GET' => new JsonResponse(['categories' => $this->db->table('ucasser_resource_categories')->orderBy('id')->get()
                ->each(function ($row) {
                    // TINYINT(1) arrives as a PHP int; expose a real JSON boolean.
                    $row->is_campus = (bool) $row->is_campus;
                })]),
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

        if (! $name) {
            return $this->error('名称不能为空，且不得超过 120 字');
        }
        // Campus identity is the explicit is_campus flag on the seeded root row;
        // do not infer it from the existence of external_topic siblings.
        if (! $parent || ! (($parent->kind === 'root' && (bool) $parent->is_campus && $parent->parent_id === null && $kind === 'college')
            || ($parent->kind === 'course_group' && $kind === 'course'))) {
            return $this->error('请选择校内根分类添加学院，或选择必修／选修分类添加课程');
        }

        if ($this->db->table('ucasser_resource_categories')->where('parent_id', $parentId)->where('name', $name)->exists()) {
            return $this->error('同一分类下已有这个名称');
        }

        $now = gmdate('Y-m-d H:i:s');
        try {
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
        } catch (\Illuminate\Database\QueryException $exception) {
            // The (parent_id, name) unique index is the race-proof backstop for the
            // exists() pre-check above; surface duplicates with the same message.
            if (($exception->getCode() === '23000' || $exception->getCode() === 23000)
                && str_contains($exception->getMessage(), 'Duplicate entry')) {
                return $this->error('同一分类下已有这个名称');
            }
            throw $exception;
        }

        return new JsonResponse(['id' => $id], 201);
    }

    private function rename(ServerRequestInterface $request): ResponseInterface
    {
        if ($denied = $this->admin($request)) {
            return $denied;
        }

        $id = filter_var($request->getQueryParams()['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $category = $id ? $this->db->table('ucasser_resource_categories')->where('id', $id)->first() : null;
        if (! $category) {
            return $this->error('分类不存在', 404);
        }
        if (! in_array($category->kind, ['college', 'course'], true)) {
            // Renaming roots/groups is a different error from "not found".
            return $this->error('只能修改学院和课程名称', 400);
        }

        $name = $this->text($this->input($request)['name'] ?? null, 120);
        if (! $name) {
            return $this->error('名称不能为空，且不得超过 120 字');
        }

        if ($this->db->table('ucasser_resource_categories')->where('parent_id', $category->parent_id)->where('name', $name)->where('id', '!=', $id)->exists()) {
            return $this->error('同一分类下已有这个名称');
        }

        try {
            $this->db->table('ucasser_resource_categories')->where('id', $id)->update(['name' => $name, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        } catch (\Illuminate\Database\QueryException $exception) {
            if (($exception->getCode() === '23000' || $exception->getCode() === 23000)
                && str_contains($exception->getMessage(), 'Duplicate entry')) {
                return $this->error('同一分类下已有这个名称');
            }
            throw $exception;
        }

        return new JsonResponse(['id' => $id, 'name' => $name]);
    }
}
