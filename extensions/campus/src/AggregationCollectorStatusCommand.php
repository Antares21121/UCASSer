<?php
namespace UCASSer\Campus;

use Flarum\Console\AbstractCommand;
use Symfony\Component\Console\Input\InputArgument;

/** Persist only the collector's state, never credentials or QR contents. */
class AggregationCollectorStatusCommand extends AbstractCommand
{
    public function __construct(private Aggregation $aggregation) { parent::__construct(); }

    protected function configure(): void
    {
        $this->setName('campus:aggregation:collector-status')
            ->setDescription('Record the local WeChat collector authorization state')
            ->addArgument('state', InputArgument::REQUIRED, 'ready, authorization_required, unavailable, or unconfigured');
    }

    protected function fire(): int
    {
        $state = (string) $this->input->getArgument('state');
        if (!in_array($state, ['ready', 'authorization_required', 'unavailable', 'unconfigured'], true)) {
            $this->error('Unknown collector state');
            return 2;
        }
        $this->aggregation->db->table('settings')->updateOrInsert(
            ['key' => 'ucasser.aggregation.collector_status'],
            ['value' => json_encode(['state' => $state, 'checked_at' => gmdate('Y-m-d H:i:s')])]
        );
        $this->info('Collector status recorded');
        return 0;
    }
}
