<?php
namespace UCASSer\Campus;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class OperatorGrantCommand extends AbstractCommand
{
    public function __construct(private ConnectionInterface $db) { parent::__construct(); }

    protected function configure(): void
    {
        $this->setName('campus:operator')->setDescription('Grant or revoke the separate site-operator role')
            ->addArgument('username', InputArgument::REQUIRED)
            ->addOption('revoke', null, InputOption::VALUE_NONE);
    }

    protected function fire(): int
    {
        $username = trim((string) $this->input->getArgument('username'));
        $user = $this->db->table('users')->where('username', $username)->first(['id', 'is_email_confirmed']);
        if (!$user || !$user->is_email_confirmed) {
            $this->error('账号不存在或尚未完成邮箱验证');
            return 2;
        }
        if ($this->input->getOption('revoke')) {
            $this->db->table('campus_operators')->where('user_id', $user->id)->delete();
            $this->info('已撤销操作员权限：'.$username);
        } else {
            $this->db->table('campus_operators')->updateOrInsert(
                ['user_id' => $user->id], ['granted_at' => gmdate('Y-m-d H:i:s')]
            );
            $this->info('已授予后台运营权限：'.$username);
        }
        return 0;
    }
}
