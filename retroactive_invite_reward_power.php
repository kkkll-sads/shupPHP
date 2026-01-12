<?php
/**
 * 补发邀请奖励算力脚本
 * 用于补发今天实名认证通过但未发放算力奖励的邀请人
 * 
 * 用法: php retroactive_invite_reward_power.php [日期，格式：Y-m-d，默认今天]
 */

require __DIR__ . '/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use think\facade\Db;

$dateStr = $argv[1] ?? '';
if ($dateStr) {
    $dateStart = strtotime($dateStr);
    if ($dateStart === false) {
        echo "错误: 日期格式错误，请使用 Y-m-d 格式，如：2026-01-12\n";
        exit(1);
    }
    $dateEnd = $dateStart + 86400 - 1;
} else {
    $dateStart = strtotime('today');
    $dateEnd = strtotime('tomorrow') - 1;
}

echo "=== 开始补发邀请奖励算力 ===\n";
echo "日期范围: " . date('Y-m-d H:i:s', $dateStart) . " 至 " . date('Y-m-d H:i:s', $dateEnd) . "\n\n";

// 获取邀请奖励算力配置
$inviteRewardPower = (float)Db::name('config')
    ->where('name', 'invite_reward_power')
    ->where('group', 'activity_reward')
    ->value('value', 0);

if ($inviteRewardPower <= 0) {
    echo "警告: 邀请奖励算力配置为0或未配置，将使用默认值5\n";
    $inviteRewardPower = 5;
}

echo "邀请奖励算力: " . $inviteRewardPower . "\n\n";

// 查询今天实名认证通过的用户
$users = Db::name('user')
    ->where('real_name_status', 2)
    ->where('audit_time', '>=', $dateStart)
    ->where('audit_time', '<=', $dateEnd)
    ->where('inviter_id', '>', 0)
    ->order('id asc')
    ->select()
    ->toArray();

if (empty($users)) {
    echo "没有找到符合条件的用户\n";
    exit(0);
}

echo "找到 " . count($users) . " 个今天实名认证通过且有邀请人的用户\n\n";

$needRetroactive = [];
$alreadyRewarded = [];

// 检查哪些需要补发
foreach ($users as $user) {
    $userId = $user['id'];
    $inviterId = (int)$user['inviter_id'];
    
    // 检查邀请人是否已经收到该用户的算力奖励
    $hasPowerLog = Db::name('user_money_log')
        ->where('user_id', $inviterId)
        ->where('biz_id', $userId)
        ->where('field_type', 'green_power')
        ->where('memo', 'like', '%邀请%')
        ->find();
    
    if (!$hasPowerLog) {
        $needRetroactive[] = $user;
    } else {
        $alreadyRewarded[] = $user;
    }
}

echo "已有算力奖励的用户: " . count($alreadyRewarded) . " 个\n";
echo "需要补发的用户: " . count($needRetroactive) . " 个\n\n";

if (empty($needRetroactive)) {
    echo "所有用户都已发放算力奖励，无需补发\n";
    exit(0);
}

// 开始补发
$successCount = 0;
$failCount = 0;
$totalRewardPower = 0;

foreach ($needRetroactive as $user) {
    try {
        Db::startTrans();
        
        $userId = $user['id'];
        $inviterId = (int)$user['inviter_id'];
        
        // 获取邀请人信息
        $inviter = Db::name('user')->where('id', $inviterId)->lock(true)->find();
        if (!$inviter) {
            Db::rollback();
            echo "错误: 用户 " . ($user['mobile'] ?? 'ID:' . $userId) . " 的邀请人(ID: {$inviterId})不存在，跳过\n";
            $failCount++;
            continue;
        }
        
        $beforeGreenPower = (float)($inviter['green_power'] ?? 0);
        $afterGreenPower = round($beforeGreenPower + $inviteRewardPower, 2);
        
        // 更新邀请人算力
        Db::name('user')
            ->where('id', $inviterId)
            ->update([
                'green_power' => $afterGreenPower,
                'update_time' => time(),
            ]);
        
        $now = time();
        $flowNo = generateSJSFlowNo($inviterId);
        $batchNo = generateBatchNo('INVITE_REWARD_RETRO', $userId);
        
        // 记录算力变动日志
        Db::name('user_money_log')->insert([
            'user_id' => $inviterId,
            'field_type' => 'green_power',
            'money' => $inviteRewardPower,
            'before' => $beforeGreenPower,
            'after' => $afterGreenPower,
            'memo' => sprintf('邀请好友获得算力（补发）：%.2f（被邀请用户ID：%d）', $inviteRewardPower, $userId),
            'flow_no' => $flowNo,
            'batch_no' => $batchNo,
            'biz_type' => 'invite_reward',
            'biz_id' => $userId,
            'create_time' => $now,
        ]);
        
        // 记录活动日志
        Db::name('user_activity_log')->insert([
            'user_id' => $inviterId,
            'related_user_id' => $userId,
            'action_type' => 'invite_reward',
            'change_field' => 'green_power',
            'change_value' => (string)$inviteRewardPower,
            'before_value' => (string)$beforeGreenPower,
            'after_value' => (string)$afterGreenPower,
            'remark' => sprintf('邀请好友获得算力（补发）：%.2f（被邀请用户ID：%d）', $inviteRewardPower, $userId),
            'extra' => json_encode([
                'invite_reward_power' => (string)$inviteRewardPower,
                'invited_user_id' => $userId,
                'retroactive' => true,
            ], JSON_UNESCAPED_UNICODE),
            'create_time' => $now,
            'update_time' => $now,
        ]);
        
        Db::commit();
        
        $successCount++;
        $totalRewardPower += $inviteRewardPower;
        $inviterMobile = $inviter['mobile'] ?? $inviter['username'] ?? 'ID:' . $inviterId;
        $userMobile = $user['mobile'] ?? 'ID:' . $userId;
        echo "✓ 用户 {$userMobile} 的邀请人 {$inviterMobile} 补发成功：+" . $inviteRewardPower . " 算力\n";
        
    } catch (\Throwable $e) {
        Db::rollback();
        $failCount++;
        $userMobile = $user['mobile'] ?? 'ID:' . $user['id'];
        echo "✗ 用户 {$userMobile} 补发失败：" . $e->getMessage() . "\n";
    }
}

echo "\n=== 补发完成 ===\n";
echo "成功: " . $successCount . " 个邀请人\n";
echo "失败: " . $failCount . " 个邀请人\n";
echo "总补发算力: " . round($totalRewardPower, 2) . "\n";
