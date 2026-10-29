<?php
namespace UCASSer\Campus;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;

/**
 * Build the resource-directory tree from the imported course catalog:
 * 校内资料 → 学院 → 课程类型 → 课程. Idempotent; only creates missing nodes and
 * stores each course's leaf category on its offering row.
 */
class ResourcesSyncCommand extends AbstractCommand
{
    public function __construct(private ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('campus:resources:sync')
            ->setDescription('Sync the resource directory tree from the course catalog')
            ->addOption('dry-run', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Only report what would be created');
    }

    protected function fire(): int
    {
        $dry = (bool) $this->input->getOption('dry-run');
        $db = $this->db;
        if (!$db->getSchemaBuilder()->hasTable('ucasser_resource_categories')) {
            $this->error('请先启用 ucasser-resources 扩展');
            return 1;
        }
        $rootId = (int) $db->table('ucasser_resource_categories')->where('kind', 'root')->where('name', '校内资料')->value('id');
        if (!$rootId) { $this->error('缺少「校内资料」根分类'); return 1; }
        $ensure = function (string $kind, string $name, ?int $parent) use ($db, $dry): int {
            $query = $db->table('ucasser_resource_categories')->where('kind', $kind)->where('name', $name);
            $parent ? $query->where('parent_id', $parent) : $query->whereNull('parent_id');
            $id = $query->value('id');
            if ($id) return (int) $id;
            if ($dry) return 0;
            return (int) $db->table('ucasser_resource_categories')->insertGetId([
                'kind' => $kind, 'name' => $name, 'parent_id' => $parent, 'position' => 0,
                'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        };
        $colleges = $db->table('campus_colleges')->where('status', 'active')->orderBy('position')->get(['id', 'name']);
        $created = ['college' => 0, 'group' => 0, 'course' => 0];
        foreach ($colleges as $college) {
            $collegeId = $ensure('college', $college->name, $rootId);
            $rows = $db->table('campus_course_offerings')
                ->join('campus_courses', 'campus_courses.id', '=', 'campus_course_offerings.course_id')
                ->where('campus_course_offerings.college_id', $college->id)
                ->get(['campus_course_offerings.id as offer_id', 'campus_courses.name', 'campus_courses.nature', 'campus_courses.type', 'campus_courses.id as course_id']);
            foreach ($rows as $row) {
                $groupName = trim((string) ($row->nature ?: $row->type)) ?: '未分类课程';
                $groupId = $ensure('course_group', $groupName, $collegeId ?: null);
                $leafName = (string) $row->name;
                $existing = $db->table('ucasser_resource_categories')->where('kind', 'course')->where('parent_id', $groupId ?: null)->where('name', $leafName)->value('id');
                if (!$existing && !$dry) $leafName = $leafName;
                $leafId = $ensure('course', $leafName, $groupId ?: null);
                if ($leafId && !$dry) $db->table('campus_course_offerings')->where('id', $row->offer_id)->update(['resource_category_id' => $leafId]);
            }
        }
        $this->info($dry ? '预览模式：仅统计缺失节点（未写入）' : '资源目录树已按课程表同步');
        $this->info('学院 '.count($colleges).' 个；课程叶节点已对齐到开课记录。');
        return 0;
    }
}
