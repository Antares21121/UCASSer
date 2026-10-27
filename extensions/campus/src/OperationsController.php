<?php
namespace UCASSer\Campus;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;

class OperationsController implements RequestHandlerInterface
{
    public function __construct(private Aggregation $aggregation) {}

    public function handle(Request $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();
        $db = $this->aggregation->db;
        $count = fn (string $table) => $db->table($table)->count();
        $sources = $db->table('campus_aggregation_sources')->orderByDesc('id')->get([
            'id', 'name', 'adapter', 'enabled', 'last_checked_at', 'last_error'
        ]);
        return new JsonResponse(['data' => [
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'community' => [
                'users' => $count('users'),
                'discussions' => $count('discussions'),
                'sections' => $count('campus_sections'),
            ],
            'content' => [
                'records' => $count('campus_records'),
                'courses' => $count('campus_courses'),
                'aggregation_published' => $count('campus_aggregation_items'),
            ],
            'work' => [
                'aggregation_pending' => $db->table('campus_aggregation_candidates')->where('status', 'pending')->count(),
                'identity_pending' => $db->table('campus_verifications')->where('status', 'pending')->count(),
                'cases_pending' => $db->table('campus_cases')->whereIn('status', ['pending', 'in_progress', 'needs_info', 'appealed'])->count(),
            ],
            'sources' => [
                'total' => $sources->count(),
                'active' => $sources->filter(fn ($source) => $source->adapter === 'ucass-notices' && $source->enabled)->count(),
                'errors' => $sources->filter(fn ($source) => $source->last_error !== null && $source->last_error !== '')->count(),
                'recent' => $sources->take(6)->values()->all(),
            ],
        ]]);
    }
}
