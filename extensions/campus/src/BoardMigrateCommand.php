<?php
namespace UCASSer\Campus;

use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Preview or apply the retired-board migration.
 *
 *   php flarum campus:boards:migrate            # dry-run preview only
 *   php flarum campus:boards:migrate --apply    # move and record
 *
 * The schema migration runs the same mover automatically on deploy so that
 * closing a board can never hide content; this command exists so operators
 * can audit the plan first and re-run it safely after restoring a backup.
 */
class BoardMigrateCommand extends AbstractCommand
{
    public function __construct(private BoardMigrator $migrator, private \Illuminate\Database\ConnectionInterface $db) { parent::__construct(); }

    protected function configure(): void
    {
        $this->setName('campus:boards:migrate')
            ->setDescription('Preview or apply the discussion migration out of retired boards')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write the migration instead of only previewing it');
    }

    protected function fire(): int
    {
        $this->migrator->ensureBoards();
        $plan = $this->migrator->plan();
        $applied = (int) $this->db->table('campus_board_migrations')->count();
        $sections = $this->db->table('campus_sections')->get()->keyBy('key');
        $lines = [['discussion_id', 'section_key', 'target', 'status', 'note']];
        $counts = [];
        foreach ($plan as $row) {
            $section = $sections->get($row['section_key']);
            $counts[$row['section_key']] = ($counts[$row['section_key']] ?? 0) + 1;
            $lines[] = [
                (string) $row['discussion_id'], $row['section_key'],
                $section ? $section->name : '（缺失）',
                $row['status'], (string) $row['note'],
            ];
        }
        $this->info('待迁移讨论 '.count($plan).' 条；已迁移记录 '.$applied.' 条。');
        foreach ($counts as $key => $count) $this->info(' - '.$key.': '.$count);
        foreach ($lines as $line) $this->output->writeln(implode("\t", $line));
        if (!$this->input->getOption('apply')) {
            $this->info('当前为预览模式（dry-run）。核对目标后再加 --apply；迁移幂等，重跑不会重复或丢失。');
            return 0;
        }
        $moved = $this->migrator->apply();
        $this->info('已迁移 '.$moved.' 条讨论；帖子、回复、作者与时间保持不变，/d/{id} 旧链接继续有效。');
        $manual = $this->db->table('campus_board_migrations')->where('status', 'manual')->count();
        if ($manual) $this->info('其中 '.$manual.' 条进入人工复核清单（记录于 campus_board_migrations.note）。');
        return 0;
    }
}
