<?php
namespace UCASSer\Campus;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class CollectionJobCommand extends AbstractCommand
{
    public function __construct(private ConnectionInterface $db, private AggregationPollCommand $poll) { parent::__construct(); }

    protected function configure(): void
    {
        $this->setName('campus:aggregation:run-job')->setDescription('Run one queued operator collection job');
    }

    protected function fire(): int
    {
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
            $exit = $this->poll->run(new ArrayInput($args), $output);
            $result = mb_substr($output->fetch(), 0, 4000);
            $this->db->table('campus_collection_jobs')->where('id', $job->id)->update([
                'status' => $exit === 0 ? 'succeeded' : 'failed', 'result' => $result,
                'finished_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->info('采集任务 #'.$job->id.($exit === 0 ? ' 已完成' : ' 有来源失败'));
            return $exit === 0 ? 0 : 1;
        } catch (\Throwable $error) {
            $this->db->table('campus_collection_jobs')->where('id', $job->id)->update([
                'status' => 'failed', 'result' => mb_substr($error->getMessage(), 0, 4000),
                'finished_at' => gmdate('Y-m-d H:i:s'),
            ]);
            $this->error('采集任务 #'.$job->id.' 失败：'.$error->getMessage());
            return 1;
        }
    }
}
