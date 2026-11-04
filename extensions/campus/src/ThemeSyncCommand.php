<?php
namespace UCASSer\Campus;

use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;

/** Converge theme colours and section tag styling to the campus palette. */
class ThemeSyncCommand extends AbstractCommand
{
    public function __construct(private ThemeSync $sync)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('campus:theme:sync')
            ->setDescription('Align theme colours and section tags with the campus palette')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite customised colours as well')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Report differences without writing');
    }

    protected function fire(): int
    {
        $check = (bool) $this->input->getOption('check');
        $force = (bool) $this->input->getOption('force');
        $rows = $check ? $this->sync->plan($force) : $this->sync->apply($force);
        $pending = 0;
        foreach ($rows as $row) {
            if ($row['change']) {
                $pending++;
                $this->info(($check ? '[diff] ' : '[set] ').$row['label'].' '.$row['column'].': '.$row['current'].' -> '.$row['expected']);
            }
        }
        $this->info($pending ? '共 '.$pending.' 项需要调整' : '颜色已与品牌规范一致');
        if ($check && $pending) return 1;
        if (!$check && $pending) {
            $this->info('提示：若页面未变化，执行 php flarum cache:clear 重新编译样式');
        }
        return 0;
    }
}
