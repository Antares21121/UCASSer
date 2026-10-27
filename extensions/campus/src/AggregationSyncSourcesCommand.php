<?php
namespace UCASSer\Campus;

use Flarum\Console\AbstractCommand;

/** Register the reviewed UCASS source catalog without resetting operator choices. */
class AggregationSyncSourcesCommand extends AbstractCommand
{
    public function __construct(private Aggregation $aggregation) { parent::__construct(); }

    protected function configure(): void
    {
        $this->setName('campus:aggregation:sync-sources')
            ->setDescription('Register reviewed public UCASS notice lists and documented WeChat accounts');
    }

    protected function fire(): int
    {
        $db = $this->aggregation->db;
        $now = gmdate('Y-m-d H:i:s');
        $createdWeb = 0; $connectedWeb = 0; $createdWechat = 0;
        foreach (OfficialSourceCatalog::WEB as [$name, $url]) {
            $existing = $db->table('campus_aggregation_sources')->where('listing_url', $url)->first();
            if ($existing) {
                if ($existing->adapter === 'pending-web') {
                    $db->table('campus_aggregation_sources')->where('id', $existing->id)->update([
                        'adapter' => 'ucass-vsb', 'trust' => 'official', 'enabled' => true, 'updated_at' => $now,
                    ]);
                    $connectedWeb++;
                }
                continue; // Preserve explicitly paused and previously configured sources.
            }
            $db->table('campus_aggregation_sources')->insert([
                'name' => $name, 'listing_url' => $url, 'adapter' => 'ucass-vsb',
                'trust' => 'official', 'visibility' => 'public', 'enabled' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $createdWeb++;
        }
        foreach (OfficialSourceCatalog::EMPLOYMENT as [$name, $url]) {
            $existing = $db->table('campus_aggregation_sources')->where('listing_url', $url)->first();
            if ($existing) {
                if ($existing->adapter === 'pending-web') {
                    $db->table('campus_aggregation_sources')->where('id', $existing->id)->update([
                        'adapter' => 'ucass-employment', 'trust' => 'official', 'enabled' => true, 'updated_at' => $now,
                    ]);
                    $connectedWeb++;
                }
                continue;
            }
            $db->table('campus_aggregation_sources')->insert([
                'name' => $name, 'listing_url' => $url, 'adapter' => 'ucass-employment',
                'trust' => 'official', 'visibility' => 'public', 'enabled' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $createdWeb++;
        }
        foreach (OfficialSourceCatalog::WECHAT as [$name, $evidenceUrl]) {
            if ($db->table('campus_aggregation_sources')->where('name', $name)->whereIn('adapter', ['we-mp-rss-pending', 'we-mp-rss'])->exists()) continue;
            $db->table('campus_aggregation_sources')->insert([
                'name' => $name, 'listing_url' => $evidenceUrl, 'adapter' => 'we-mp-rss-pending',
                'trust' => 'unofficial', 'visibility' => 'public', 'enabled' => false,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $createdWechat++;
        }
        $this->info("官网新增 $createdWeb 个、已登记来源接入 $connectedWeb 个；公众号待授权新增 $createdWechat 个。所有新文章仍需管理员审核。");
        return 0;
    }
}
