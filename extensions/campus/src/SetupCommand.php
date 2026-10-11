<?php
namespace UCASSer\Campus;
use Flarum\Console\AbstractCommand;
class SetupCommand extends AbstractCommand
{
    public function __construct(private Sections $sections, private \Flarum\Settings\SettingsRepositoryInterface $settings, private Identity $identity) { parent::__construct(); }
    protected function configure(): void { $this->setName('campus:setup')->setDescription('Create missing campus sections without overwriting existing configuration'); }
    protected function fire(): int {
        $this->sections->installDefaults();
        $this->identity->setup();
        resolve(Records::class)->setup();
        // Choose a blue baseline once; retain administrators' custom colors.
        if (!$this->settings->get('ucasser-campus.theme_initialized')) {
            if (in_array($this->settings->get('theme_primary_color'),[null,'','#536F90','#536f90','#4d698e','#4D698E'],true)) $this->settings->set('theme_primary_color','#2563eb');
            $this->settings->set('ucasser-campus.theme_initialized','1');
        }
        if (!$this->settings->get('ucasser-campus.tag_theme_initialized')) {
            \Flarum\Tags\Tag::query()->whereIn('id',$this->sections->db->table('campus_sections')->select('tag_id'))->where('color','#176d72')->update(['color'=>'#2563eb']);
            $this->settings->set('ucasser-campus.tag_theme_initialized','1');
        }
        if (!$this->settings->get('ucasser-campus.tag_icons_initialized')) {
            foreach ($this->sections->rows() as $section) {
                if (isset(Sections::ICONS[$section->key])) \Flarum\Tags\Tag::query()->where('id',$section->tag_id)->where('icon','fas fa-graduation-cap')->update(['icon'=>'fas fa-'.Sections::ICONS[$section->key]]);
            }
            $this->settings->set('ucasser-campus.tag_icons_initialized','1');
        }
        if (!$this->settings->get('ucasser-campus.locale_initialized')) {
            if (in_array($this->settings->get('default_locale'),[null,'','en'],true)) $this->settings->set('default_locale','zh-Hans');
            $this->settings->set('ucasser-campus.locale_initialized','1');
        }
        if (!$this->settings->get('ucasser-campus.general_translation_initialized')) {
            \Flarum\Tags\Tag::query()->where('slug','general')->where('name','General')->update(['name'=>'综合讨论']);
            $this->settings->set('ucasser-campus.general_translation_initialized','1');
        }
        $db=$this->sections->db;
        if (!$db->table('campus_governance')->where('type','rule')->exists()) $db->table('campus_governance')->insert(['type'=>'rule','title'=>'社区公约（初始版本）','body'=>"平台以公益、校园互助和公共知识共享为目的，不设付费内容墙，不出售用户数据。\n欢迎有出处的信息、合法资料索引、提问与基于事实的学习体验。禁止人身攻击、歧视、骚扰、垃圾广告、虚假指控及泄露个人隐私。\n转载应注明来源与使用授权，受限制的讲义、考试原题和私人资料未经授权不得公开。联系方式须经本人允许，资料侵权可通过举报提出。\n课程评价使用正常账号；匿名功能当前关闭。评分表达个人体验，不对教师进行绝对排名。\n商业推广须事先获得明确许可并标注，不得以公益名义隐瞒利益关系。\n举报材料仅本人和被授权处理人员可见。举报不自动等于违规，可要求补充证据、提醒、要求修改、隐藏或恢复。严重处置由管理员执行并记录理由。\n当事人可以申诉，由不同于原处理人的获授权人员复核。公开治理不公开举报者身份或敏感证据。\n志愿者只维护获授权分区；管理员管理全站设置与高风险操作。人员交接应撤销旧授权，不共用管理员账号。\n规则修改必须发布新版本及生效时间，保留历史版本。",'effective_at'=>gmdate('Y-m-d H:i:s'),'created_at'=>gmdate('Y-m-d H:i:s')]);
        if ($this->settings->get('default_route') === '/all') $this->settings->set('default_route', '/campus');
        $this->info('Campus sections ready; existing data retained'); return 0;
    }
}
