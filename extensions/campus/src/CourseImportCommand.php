<?php

namespace UCASSer\Campus;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/** Import an authorized CSV without retaining its private or unnecessary columns. */
class CourseImportCommand extends AbstractCommand
{
    public function __construct(private ConnectionInterface $db) { parent::__construct(); }

    protected function configure(): void
    {
        $this->setName('campus:courses:import')
            ->setDescription('Preview or import the four public columns of an authorized course export')
            ->addArgument('file', InputArgument::REQUIRED, 'CSV under .runtime/')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write after preview and authorization review');
    }

    protected function fire(): int
    {
        $file = (string) $this->input->getArgument('file');
        if (!preg_match('~^\.runtime/[A-Za-z0-9._-]+\.csv$~D', $file)) {
            $this->error('只接受 .runtime/ 下的 CSV 文件');
            return 2;
        }
        $runtime = realpath(dirname(__DIR__, 3).'/.runtime');
        $path = realpath(dirname(__DIR__, 3).'/'.$file);
        if (!$runtime || !$path || !str_starts_with($path, $runtime.DIRECTORY_SEPARATOR)) {
            $this->error('CSV 必须保存在本工作区的 .runtime/ 内');
            return 2;
        }
        try {
            $parsed = (new CourseCsvReader())->read($path);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());
            return 2;
        }
        if (!$this->db->getSchemaBuilder()->hasTable('campus_course_offerings')) {
            $this->error('请先执行 campus migrate，使课程导入表可用');
            return 2;
        }
        $existing = [];
        foreach (array_chunk(array_keys($parsed['rows']), 400) as $hashes) {
            foreach ($this->db->table('campus_course_offerings')->whereIn('identity_hash', $hashes)->get(['identity_hash', 'name']) as $row) {
                $existing[$row->identity_hash] = $row->name;
            }
        }
        $new = 0;
        $conflicts = 0;
        foreach ($parsed['rows'] as $hash => $row) {
            if (!isset($existing[$hash])) $new++;
            elseif ($existing[$hash] !== $row['name']) $conflicts++;
        }
        $this->info('校验通过：'.count($parsed['rows'])." 个唯一课程条目；新增 $new；名称变化需人工复核 $conflicts。未显示或保存额外列。 ");
        if (!$this->input->getOption('apply')) {
            $this->info('当前仅预览；核对导出授权与汇总后再加 --apply。');
            return 0;
        }
        $hasResources = $this->db->getSchemaBuilder()->hasTable('ucasser_resource_categories')
            && $this->db->getSchemaBuilder()->hasColumn('ucasser_resource_categories', 'is_campus');
        $root = $hasResources ? $this->db->table('ucasser_resource_categories')->where('kind', 'root')
            ->where('is_campus', 1)->whereNull('parent_id')->first() : null;
        $added = 0;
        $indexed = 0;
        $nameConflicts = 0;
        $now = gmdate('Y-m-d H:i:s');
        $this->db->transaction(function () use ($parsed, $root, $now, &$added, &$indexed, &$nameConflicts): void {
            foreach ($parsed['rows'] as $hash => $row) {
                $offer = $this->db->table('campus_course_offerings')->where('identity_hash', $hash)->first();
                if ($offer && $offer->name !== $row['name']) {
                    $nameConflicts++;
                    continue;
                }
                $categoryId = $root ? $this->resourceCategory((int) $root->id, $row, $now) : null;
                if ($offer) {
                    if (!$offer->resource_category_id && $categoryId) {
                        $this->db->table('campus_course_offerings')->where('id', $offer->id)->update(['resource_category_id' => $categoryId]);
                        $indexed++;
                    }
                    continue;
                }
                // A new course ID is deliberate: a same-name manual entry may refer
                // to a different course. Reviews therefore never move implicitly.
                $courseId = $this->db->table('campus_courses')->insertGetId([
                    'name' => $row['name'], 'type' => '官方开课', 'grade' => '',
                    'semester' => $row['semester'], 'nature' => '',
                    'assessment' => '', 'resources' => '', 'version' => 1,
                ]);
                $this->db->table('campus_course_offerings')->insert([
                    'course_id' => $courseId, 'identity_hash' => $hash,
                    'code' => $row['code'], 'name' => $row['name'],
                    'college' => $row['college'], 'semester' => $row['semester'],
                    'resource_category_id' => $categoryId, 'imported_at' => $now,
                ]);
                $added++;
                if ($categoryId) $indexed++;
            }
            $this->db->table('campus_course_import_runs')->insert([
                'file_sha256' => $parsed['file_sha256'], 'rows_read' => $parsed['rows_read'],
                'courses_added' => $added, 'resources_indexed' => $indexed,
                'name_conflicts' => $nameConflicts, 'created_at' => $now,
            ]);
        });
        $this->info("已导入 $added 门课程；资料分类关联 $indexed 条；名称变化待复核 $nameConflicts 条。原 CSV 不会复制进数据库。 ");
        if (!$root) $this->warn('资料目录扩展尚未启用或迁移，课程已入库；启用后重跑同一 CSV 可补建索引。');
        return 0;
    }

    private function resourceCategory(int $rootId, array $row, string $now): int
    {
        $table = $this->db->table('ucasser_resource_categories');
        $college = $table->where('parent_id', $rootId)->where('kind', 'college')->where('name', $row['college'])->first();
        $collegeId = $college?->id ?? $this->db->table('ucasser_resource_categories')->insertGetId([
            'parent_id' => $rootId, 'kind' => 'college', 'name' => $row['college'],
            'position' => 100, 'created_at' => $now, 'updated_at' => $now,
        ]);
        if (!$college) {
            foreach (['必修课', '选修课'] as $position => $name) {
                $this->db->table('ucasser_resource_categories')->insert([
                    'parent_id' => $collegeId, 'kind' => 'course_group', 'name' => $name,
                    'position' => $position, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        $group = $this->db->table('ucasser_resource_categories')->where('parent_id', $collegeId)
            ->where('kind', 'course_group')->where('name', '待分类课程')->first();
        $groupId = $group?->id ?? $this->db->table('ucasser_resource_categories')->insertGetId([
            'parent_id' => $collegeId, 'kind' => 'course_group', 'name' => '待分类课程',
            'position' => 2, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $label = $row['name'].'（'.$row['code'].'）';
        $category = $this->db->table('ucasser_resource_categories')->where('parent_id', $groupId)
            ->where('kind', 'course')->where('name', $label)->first();
        return $category?->id ?? $this->db->table('ucasser_resource_categories')->insertGetId([
            'parent_id' => $groupId, 'kind' => 'course', 'name' => $label,
            'position' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
