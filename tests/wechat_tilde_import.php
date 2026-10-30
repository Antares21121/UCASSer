<?php
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'development') throw new RuntimeException('Development CLI only');
require __DIR__.'/../vendor/autoload.php';
$c = (require __DIR__.'/../site.php')->bootApp()->getContainer();
$db = $c->make(Illuminate\Database\ConnectionInterface::class);
$command = $c->make(UCASSer\Campus\AggregationImportFeedCommand::class);
$file = '.runtime/wechat-tilde-regression-'.bin2hex(random_bytes(5)).'.json';
$name = 'Wechat tilde regression '.bin2hex(random_bytes(5));
$marker = bin2hex(random_bytes(8));
$rows = [];
for ($i = 0; $i < 6; $i++) $rows[] = ['url' => "https://mp.weixin.qq.com/s/fixture~{$marker}_$i", 'title' => "Tilde article fixture $i", 'summary' => 'Fixture metadata', 'published_at' => null];
$original = [];
foreach (['campus_aggregation_sources', 'campus_aggregation_candidates', 'campus_aggregation_items'] as $t) $original[$t] = $db->table($t)->count();
set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
$db->beginTransaction();
try {
    file_put_contents($file, json_encode($rows));
    $out = new Symfony\Component\Console\Output\BufferedOutput();
    $args = new Symfony\Component\Console\Input\ArrayInput(['file' => $file, 'name' => $name]);
    if ($command->run($args, $out) !== 0) throw new RuntimeException('Six tilde articles did not pass real CLI validation');
    $source = $db->table('campus_aggregation_sources')->where('name', $name)->first();
    if (!$source || $db->table('campus_aggregation_candidates')->where('source_id', $source->id)->where('status', 'pending')->count() !== 6) throw new RuntimeException('Tilde articles were not added to review');
    $command->run($args, $out);
    if ($db->table('campus_aggregation_candidates')->where('source_id', $source->id)->count() !== 6) throw new RuntimeException('Repeat import duplicated candidates');
    if ($db->table('campus_aggregation_items')->count() !== $original['campus_aggregation_items']) throw new RuntimeException('Import published content');
} finally {
    $db->rollBack();
    if (is_file($file)) unlink($file);
    restore_error_handler();
}
foreach ($original as $t => $count) if ($db->table($t)->count() !== $count) throw new RuntimeException('Tilde fixture transaction did not roll back');
echo "Six tilde articles enter pending review, repeat safely and roll back without publication OK\n";
