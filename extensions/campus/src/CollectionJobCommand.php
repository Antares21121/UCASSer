<?php
namespace UCASSer\Campus;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class CollectionJobCommand extends AbstractCommand
{
    public function __construct(private ConnectionInterface $db, private AggregationPollCommand $poll,
        private WechatImport $wechat, private CollectorClient $collector) { parent::__construct(); }

    protected function configure(): void
    {
        $this->setName('campus:aggregation:run-job')->setDescription('Run one queued operator collection job');
    }

    public static function outcome(array $web, array $wechat): string
    {
        $good = ($web['succeeded'] ?? 0) + ($web['partial'] ?? 0) + max(0, ($wechat['accounts'] ?? 0) - ($wechat['failed'] ?? 0));
        $bad = ($web['failed'] ?? 0) + ($web['partial'] ?? 0) + ($wechat['failed'] ?? 0);
        return $bad ? ($good ? 'partial' : 'failed') : 'succeeded';
    }

    protected function fire(): int
    {
        // app and runner share storage. A process exit releases the lease, so
        // the next runner can mark abandoned jobs without a permanent lock.
        $lease = fopen(__DIR__.'/../../../storage/collection-worker.lock', 'c');
        if (!$lease) throw new \RuntimeException('Cannot open collection worker lock');
        if (!flock($lease, LOCK_EX | LOCK_NB)) { fclose($lease); $this->info('已有采集执行器运行中'); return 0; }
        try {
            $this->db->table('campus_collection_jobs')->where('status', 'running')->update([
                'status' => 'failed', 'finished_at' => gmdate('Y-m-d H:i:s'),
                'result' => '上次执行已中断，已有待审内容保留；可以重新发起采集。',
            ]);
            $job = $this->db->transaction(function () {
                $next = $this->db->table('campus_collection_jobs')->where('status', 'queued')->orderBy('id')->lockForUpdate()->first();
                if ($next) $this->db->table('campus_collection_jobs')->where('id', $next->id)->update([
                    'status' => 'running', 'started_at' => gmdate('Y-m-d H:i:s'),
                ]);
                return $next;
            });
            if (!$job) { $this->info('没有待运行的采集任务'); return 0; }
            $output = new BufferedOutput();
            try {
                $args = ['--limit' => '2'];
                if ($job->source_id) $args['--source'] = (string) $job->source_id;
                $this->poll->run(new ArrayInput($args), $output);
                $web = $this->poll->result();
                $wechat = ['added' => 0, 'failed' => 0, 'accounts' => 0];
                if ($job->include_wechat && !$job->source_id) {
                    $state = $this->collector->state();
                    $this->db->table('settings')->updateOrInsert(['key' => 'ucasser.aggregation.collector_status'],
                        ['value' => json_encode(['state' => $state['state'], 'checked_at' => gmdate('Y-m-d H:i:s')])]);
                    if ($state['state'] === 'ready') {
                        try { $wechat = $this->wechat->run(5); }
                        catch (\Throwable $e) { $wechat['failed'] = 1; }
                        $output->writeln('公众号：处理 '.$wechat['accounts'].' 个订阅，新增 '.$wechat['added'].' 条待审，失败 '.$wechat['failed'].' 个');
                    } elseif ($state['state'] === 'unconfigured') {
                        $output->writeln('公众号：尚未配置，已跳过；官网任务不受影响');
                    } else {
                        $wechat['failed'] = 1;
                        $output->writeln('公众号：'.($state['state'] === 'authorization_required' ? '需要重新授权，未请求新二维码' : '连接校验失败，请检查订阅服务和网络'));
                    }
                }
                $status = self::outcome($web, $wechat);
                $result = mb_substr($output->fetch(), 0, 4000);
                $this->db->table('campus_collection_jobs')->where('id', $job->id)->update([
                    'status' => $status, 'result' => $result, 'finished_at' => gmdate('Y-m-d H:i:s'),
                ]);
                $this->info('采集任务 #'.$job->id.'：'.['succeeded' => '已完成', 'partial' => '部分来源失败', 'failed' => '采集失败'][$status]);
                return $status === 'succeeded' ? 0 : 1;
            } catch (\Throwable $error) {
                $this->db->table('campus_collection_jobs')->where('id', $job->id)->update([
                    'status' => 'failed', 'result' => '采集执行失败；已有待审内容保留，请检查服务和来源状态。',
                    'finished_at' => gmdate('Y-m-d H:i:s'),
                ]);
                $this->error('采集任务 #'.$job->id.' 失败');
                return 1;
            }
        } finally { flock($lease, LOCK_UN); fclose($lease); }
    }
}
