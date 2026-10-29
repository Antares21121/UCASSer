<?php
namespace UCASSer\Campus;

use Flarum\Console\AbstractCommand;
use Flarum\Foundation\ValidationException;
use Symfony\Component\Console\Input\InputOption;

/**
 * Subscribe many registered accounts at once from a `name,article_url` CSV.
 * Each row is verified individually by the collector, so a wrong link only
 * fails that row.
 */
class CollectorSubscribeCommand extends AbstractCommand
{
    public function __construct(private CollectorClient $collector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('campus:collector:subscribe')
            ->setDescription('Subscribe registered WeChat accounts from a name,article_url CSV')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'CSV path (relative to the forum root)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only validate the file');
    }

    protected function fire(): int
    {
        $file = (string) $this->input->getOption('file');
        if ($file === '' || !is_file($file)) {
            $this->error('请用 --file 指向 name,article_url 的 CSV');
            return 1;
        }
        $known = array_column(OfficialSourceCatalog::WECHAT, 0);
        $rows = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $parts = str_getcsv($line);
            if (count($parts) < 2) continue;
            // Excel-authored CSVs often start with a UTF-8 BOM on the first cell.
            $rows[] = [trim(ltrim($parts[0], "\xEF\xBB\xBF")), trim($parts[1])];
        }
        $this->info('共 '.count($rows).' 行；已订阅：'.count(array_intersect(array_column($rows, 0), $this->collector->subscribed())).' 个');
        if ($this->input->getOption('dry-run')) return 0;
        $ok = $fail = 0;
        foreach ($rows as [$name, $url]) {
            if (!in_array($name, $known, true)) { $this->info('[跳过] 未登记账号：'.$name); $fail++; continue; }
            try {
                $result = $this->collector->subscribe($name, $url);
                $this->info('[成功] '.$name.'（'.$result['status'].'）');
                $ok++;
            } catch (ValidationException $error) {
                $this->info('[失败] '.$name.'：'.implode('；', array_map(fn ($v) => is_array($v) ? implode('、', $v) : (string) $v, $error->getAttributes())));
                $fail++;
            } catch (\Throwable $error) {
                $this->info('[失败] '.$name.'：'.mb_substr($error->getMessage(), 0, 120));
                $fail++;
            }
        }
        $this->info('完成：成功 '.$ok.'，失败 '.$fail);
        return $fail ? 1 : 0;
    }
}
