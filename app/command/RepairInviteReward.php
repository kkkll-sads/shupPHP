<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use app\listener\UserRegisterSuccess;

/**
 * 补发邀请奖励命令
 * 用于补发实名认证通过但未发放邀请奖励的用户
 * 
 * 使用方法：
 * php think repair:invite-reward
 */
class RepairInviteReward extends Command
{
    protected function configure()
    {
        $this->setName('repair:invite-reward')
            ->setDescription('补发实名认证通过但未发放的邀请奖励');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('[' . date('Y-m-d H:i:s') . '] 开始检查需要补发邀请奖励的用户...');
        
        try {
            // 查询所有实名通过但未发放邀请奖励的用户
            $needRepairUsers = Db::query("
                SELECT 
                    u.id as invited_user_id,
                    u.username as invited_username,
                    u.inviter_id,
                    ui.username as inviter_username,
                    u.real_name_status
                FROM ba_user u
                LEFT JOIN ba_user ui ON u.inviter_id = ui.id
                LEFT JOIN ba_user_activity_log ual ON u.inviter_id = ual.user_id AND u.id = ual.related_user_id AND ual.action_type = 'invite_reward'
                WHERE u.inviter_id > 0 
                  AND u.real_name_status = 2
                  AND ual.id IS NULL
                ORDER BY u.id
            ");
            
            if (empty($needRepairUsers)) {
                $output->writeln('✓ 没有需要补发的邀请奖励');
                return;
            }
            
            $output->writeln('找到 ' . count($needRepairUsers) . ' 个需要补发邀请奖励的用户：');
            
            $successCount = 0;
            $failCount = 0;
            $listener = new UserRegisterSuccess();
            
            foreach ($needRepairUsers as $user) {
                $inviterId = (int)$user['inviter_id'];
                $invitedUserId = (int)$user['invited_user_id'];
                $inviterUsername = $user['inviter_username'] ?? '未知';
                $invitedUsername = $user['invited_username'] ?? '未知';
                
                try {
                    // 发放邀请奖励
                    $listener->handleInviteReward($inviterId, $invitedUserId);
                    
                    $output->writeln("  ✓ 用户 {$inviterUsername}(ID:{$inviterId}) ← 邀请 {$invitedUsername}(ID:{$invitedUserId}) 奖励补发成功");
                    $successCount++;
                    
                } catch (\Throwable $e) {
                    $output->writeln("  ✗ 用户 {$inviterUsername}(ID:{$inviterId}) 奖励补发失败: " . $e->getMessage());
                    $failCount++;
                }
            }
            
            $output->writeln('');
            $output->writeln("补发完成！成功: {$successCount}, 失败: {$failCount}");
            
        } catch (\Throwable $e) {
            $output->writeln('✗ 补发过程出错: ' . $e->getMessage());
            $output->writeln('文件: ' . $e->getFile() . ':' . $e->getLine());
        }
    }
}

