<?php
namespace UCASSer\Campus;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;

class OperationsController implements RequestHandlerInterface
{
    public function __construct(private Aggregation $aggregation, private OperatorAccess $access,
        private AggregationImportWebLocalCommand $webImport) {}

    public function handle(Request $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $this->access->assert($actor);
        $db = $this->aggregation->db;
        $route = $request->getAttribute('routeName');
        $input = $request->getParsedBody() ?? [];
        if ($route === 'campus.control.collect') {
            $sourceId = isset($input['source_id']) ? (int) $input['source_id'] : null;
            if ($sourceId !== null && (!$sourceId || !$db->table('campus_aggregation_sources')->where('id', $sourceId)
                ->where('enabled', true)->whereIn('adapter', ['ucass-notices', 'ucass-vsb', 'ucass-employment'])->exists())) {
                throw new \Flarum\Foundation\ValidationException(['source_id' => '来源不存在或尚未启用']);
            }
            $existing = $db->table('campus_collection_jobs')->whereIn('status', ['queued', 'running'])->first();
            if ($existing) return new JsonResponse(['data' => ['id' => $existing->id, 'status' => $existing->status]], 202);
            $id = $db->table('campus_collection_jobs')->insertGetId([
                'requested_by' => $actor->id, 'status' => 'queued', 'source_id' => $sourceId,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            return new JsonResponse(['data' => ['id' => $id, 'status' => 'queued']], 202);
        }
        if ($route === 'campus.control.import') {
            $snapshot = $input['snapshot'] ?? null;
            if (!is_string($snapshot)) throw new \Flarum\Foundation\ValidationException(['snapshot' => '请选择本地官网快照文件']);
            $result = $this->webImport->import($snapshot);
            $db->table('campus_audit')->insert([
                'actor_id' => $actor->id, 'action' => 'aggregation.snapshot.import',
                'target' => 'aggregation_sources', 'reason' => $result['added'].' candidates',
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            return new JsonResponse(['data' => $result]);
        }
        if ($route === 'campus.control.source.update') {
            $id = (int) ($request->getQueryParams()['id'] ?? 0);
            $source = $db->table('campus_aggregation_sources')->where('id', $id)->first();
            if (!$source || !in_array($source->adapter, ['ucass-notices', 'ucass-vsb', 'ucass-employment'], true)
                || !is_bool($input['enabled'] ?? null)) {
                throw new \Flarum\Foundation\ValidationException(['source' => '请选择已接入的网站来源和有效状态']);
            }
            $db->table('campus_aggregation_sources')->where('id', $id)->update([
                'enabled' => $input['enabled'], 'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $db->table('campus_audit')->insert([
                'actor_id' => $actor->id, 'action' => $input['enabled'] ? 'aggregation.source.resume' : 'aggregation.source.pause',
                'target' => 'aggregation_source:'.$id, 'reason' => null, 'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            return new JsonResponse(['data' => ['id' => $id, 'enabled' => $input['enabled']]]);
        }
        if ($route !== 'campus.control') throw new \Flarum\Foundation\ValidationException(['route' => '未知的控制台操作']);
        $count = fn (string $table) => $db->table($table)->count();
        $sources = $db->table('campus_aggregation_sources')->orderByDesc('id')->get([
            'id', 'name', 'adapter', 'enabled', 'last_checked_at', 'last_error'
        ]);
        $collectorStatus = json_decode((string) $db->table('settings')->where('key', 'ucasser.aggregation.collector_status')->value('value'), true);
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
                'collector' => is_array($collectorStatus) ? $collectorStatus : ['state' => 'unknown'],
                'total' => $sources->count(),
                'active' => $sources->filter(fn ($source) => in_array($source->adapter, ['ucass-notices', 'ucass-vsb', 'ucass-employment'], true) && $source->enabled)->count(),
                'errors' => $sources->filter(fn ($source) => $source->last_error !== null && $source->last_error !== '')->count(),
                'recent' => $sources->take(6)->values()->all(),
            ],
            'collection_job' => $db->table('campus_collection_jobs')->orderByDesc('id')->first(),
        ]]);
    }
}
