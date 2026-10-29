<?php
namespace UCASSer\Campus;

use Flarum\Http\RequestUtil;
use Flarum\Foundation\ValidationException;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Operator catalog endpoints: colleges, courses and shared-drive link
 * metadata with preview-first batch imports, audit and per-batch rollback.
 * Every write requires the independent operator role (campus_operators).
 */
class CatalogAdminController implements RequestHandlerInterface
{
    public function __construct(private OperatorAccess $access, private Catalog $catalog, private ConnectionInterface $db) {}

    public function handle(Request $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $route = $request->getAttribute('routeName');
        $input = $request->getParsedBody() ?? [];
        $query = $request->getQueryParams();
        // Public read layer: active colleges only, for course filters and the directory.
        if ($route === 'campus.colleges') {
            $rows = $this->db->table('campus_colleges')->where('status', 'active')->orderBy('position')->orderBy('name')->get(['id', 'code', 'name', 'aliases'])->all();
            return new JsonResponse(['data' => $rows]);
        }
        $this->access->assert($actor);
        $method = $request->getMethod();
        $id = (int) ($query['id'] ?? 0);
        if ($route === 'campus.control.catalog.import.rollback') {
            return new JsonResponse($this->catalog->rollback($id, $actor));
        }
        if ($route === 'campus.control.catalog.categories') {
            // The whole tree is returned: the course dialog needs the college and
            // course-type ancestors to tell same-named courses apart.
            $categories = $this->db->table('ucasser_resource_categories')->orderBy('kind')->orderBy('name')->get(['id', 'kind', 'name', 'parent_id'])->all();
            // Resolve the ancestry here so the console can label a course with
            // "学院 / 课程类型 / 课程" and never confuse namesakes.
            $byId = [];
            foreach ($categories as $row) $byId[(int) $row->id] = $row;
            foreach ($categories as $row) {
                $parts = [];
                $node = $row;
                for ($guard = 0; $node && $guard < 8; $guard++) {
                    array_unshift($parts, $node->name);
                    $node = $node->parent_id ? ($byId[(int) $node->parent_id] ?? null) : null;
                }
                $row->path = implode(' / ', array_slice($parts, 1));
            }
            return new JsonResponse(['data' => ['categories' => $categories, 'colleges' => $this->db->table('campus_colleges')->orderBy('position')->orderBy('name')->get(['id', 'code', 'name'])->all()]]);
        }
        $kind = null;
        if (str_starts_with($route, 'campus.control.catalog.colleges')) $kind = 'colleges';
        elseif (str_starts_with($route, 'campus.control.catalog.courses')) $kind = 'courses';
        elseif (str_starts_with($route, 'campus.control.catalog.resources')) $kind = 'resources';
        if (!$kind) throw new ValidationException(['route' => '未知的目录操作']);

        if (str_ends_with($route, '.import')) {
            $rows = $this->catalog->rowsFromPayload($input['rows'] ?? null);
            $apply = ($input['apply'] ?? false) === true;
            return new JsonResponse(['data' => $this->catalog->import($actor, $kind, $rows, $apply)]);
        }
        if ($method === 'GET') return new JsonResponse(['data' => $this->listing($kind, $query)]);
        if ($method === 'POST' && !$id) {
            return new JsonResponse($this->save($kind, $actor, $input, null), 201);
        }
        if ($method === 'PATCH' && $id) {
            return new JsonResponse($this->save($kind, $actor, $input, $id));
        }
        throw new ValidationException(['route' => '不支持的操作']);
    }

    private function save(string $kind, $actor, array $input, ?int $id): array
    {
        if ($id) $input['id'] = $id;
        return match ($kind) {
            'colleges' => $this->catalog->saveCollege($actor, $input),
            'courses' => $this->catalog->saveCourse($actor, $input),
            'resources' => $this->catalog->saveResource($actor, $input),
        };
    }

    private function listing(string $kind, array $query): array
    {
        $search = trim((string) ($query['search'] ?? ''));
        $page = max(1, min(10000, (int) ($query['page'] ?? 1)));
        $size = 25;
        if ($kind === 'colleges') {
            $rows = $this->db->table('campus_colleges')->when($search, fn ($q) => $q->where(fn ($b) => $b->where('name', 'like', "%$search%")->orWhere('aliases', 'like', "%$search%")->orWhere('code', 'like', "%$search%")))->orderBy('position')->orderBy('name');
            $total = (clone $rows)->count();
            return ['items' => $rows->offset(($page - 1) * $size)->limit($size)->get()->all(), 'meta' => ['total' => $total, 'page' => $page]];
        }
        if ($kind === 'courses') {
            $rows = $this->db->table('campus_courses')->leftJoin('campus_course_offerings', 'campus_course_offerings.course_id', '=', 'campus_courses.id')->leftJoin('campus_colleges', 'campus_colleges.id', '=', 'campus_courses.college_id')
                ->select('campus_courses.*', 'campus_course_offerings.code', 'campus_course_offerings.nature AS offering_nature', 'campus_course_offerings.description AS offering_description', 'campus_course_offerings.external_key AS offering_external_key', 'campus_colleges.name AS college_name')
                ->when($search, fn ($q) => $q->where(fn ($b) => $b->where('campus_courses.name', 'like', "%$search%")->orWhere('campus_course_offerings.code', 'like', "%$search%")->orWhere('campus_colleges.name', 'like', "%$search%")))
                ->orderBy('campus_courses.name');
            $total = (clone $rows)->count();
            return ['items' => $rows->offset(($page - 1) * $size)->limit($size)->get()->all(), 'meta' => ['total' => $total, 'page' => $page]];
        }
        $rows = $this->db->table('ucasser_resources')->leftJoin('ucasser_resource_categories', 'ucasser_resource_categories.id', '=', 'ucasser_resources.category_id')
            ->select('ucasser_resources.*', 'ucasser_resource_categories.name AS category_name', 'ucasser_resource_categories.kind AS category_kind')
            ->when($search, fn ($q) => $q->where(fn ($b) => $b->where('ucasser_resources.title', 'like', "%$search%")->orWhere('ucasser_resources.url', 'like', "%$search%")))
            ->orderByDesc('ucasser_resources.updated_at');
        $total = (clone $rows)->count();
        return ['items' => $rows->offset(($page - 1) * $size)->limit($size)->get()->all(), 'meta' => ['total' => $total, 'page' => $page]];
    }
}
