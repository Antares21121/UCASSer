<?php
namespace UCASSer\Campus;

use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputOption;

/** Pull every subscribed WeChat account into the admin review queue. */
class AggregationWechatCommand extends AbstractCommand
{
    public function __construct(private WechatImport $import)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('campus:aggregation:wechat')
            ->setDescription('Import subscribed WeChat accounts into the review queue')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Articles per account', '5');
    }

    protected function fire(): int
    {
        $result = $this->import->run((int) $this->input->getOption('limit'));
        $this->info('完成：新增 '.$result['added'].' 条，失败 '.$result['failed'].' 个（'.$result['accounts'].' 个账号）');
        return 0;
    }
}
