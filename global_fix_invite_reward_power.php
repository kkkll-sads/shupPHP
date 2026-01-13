<?php
/**
 * 全局补发邀请奖励算力脚本
 * 用于检查所有已实名认证通过的用户，补发邀请人算力奖励
 * 
 * 用法: php global_fix_invite_reward_power.php [开始日期，格式：Y-m-d] [结束日期，格式：Y-m-d]
 * 如果不提供日期，则检查所有已实名认证通过的用户
 */

require __DIR__ . '/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use think\facade\Db;

// 解析命令行参数
$startDateStr = $argv[1] ?? '';
$endDateStr = $argv[2] ?? '';

$startTime = null;
$endTime = null;

if ($startDateStr) {
    $startTime = strtotime($startDateStr . ' 00:00:00');
    if ($startTime === false) {
        echo "错误: 开始日期格式错误，请使用 Y-m-d 格式，如：2026-01-12\n";
        exit(1);
    }
}

if ($endDateStr) {
    $endTime = strtotime($endDateStr . ' 23:59:59');
    if ($endTime === false) {
        echo "错误: 结束日期格式错误，请使用 Y-m-d 格式，如：2026-01-13\n";
        exit(1);
    }
}

echo "=== 全局补发邀请奖励算力 ===\n";
if ($startTime && $endTime) {
    echo "日期范围: " . date('Y-m-d H:i:s', $startTime) . " 至 " . date('Y-m-d H:i:s', $endTime) . "\n\n";
} else {
    echo "检查所有已实名认证通过的用户\n\n";
}

// 获取邀请奖励算力配置
function get_sys_config($name, $default = '') {
    $value = Db::name('config')->where('name', $name)->value('value');
    return $value !== null ? $value : $default;
}

function generateSJSFlowNo($userId) {
    // 使用微秒时间戳 + 用户ID + 随机数，确保唯一性
    $microtime = (int)(microtime(true) * 1000000);
    return 'SJS' . $microtime . str_pad($userId, 6, '0', STR_PAD_LEFT) . rand(1000, 9999);
}

function generateBatchNo($prefix, $id) {
    return $prefix . date('Ymd') . $id;
}

$inviteRewardPower = (float)get_sys_config('invite_reward_power', 0);

if ($inviteRewardPower <= 0) {
    echo "警告: 邀请奖励算力配置为0或未配置，将使用默认值5\n";
    $inviteRewardPower = 5;
}

echo "邀请奖励算力: " . $inviteRewardPower . "\n\n";

// 查询已实名认证通过的用户
$query = Db::name('user')
    ->where('real_name_status', 2) // 已通过
    ->where('inviter_id', '>', 0); // 必须有邀请人

if ($startTime) {
    $query->where('audit_time', '>=', $startTime);
}

if ($endTime) {
    $query->where('audit_time', '<=', $endTime);
}

$users = $query->order('id asc')->select()->toArray();

if (empty($users)) {
    echo "没有找到符合条件的用户\n";
    exit(0);
}

echo "找到 " . count($users) . " 个已实名认证通过且有邀请人的用户\n\n";

$needRetroactive = [];
$alreadyRewarded = [];
$noInviter = [];

// 检查哪些需要补发
foreach ($users as $user) {
    $userId = (int)$user['id'];
    $inviterId = (int)$user['inviter_id'];
    
    if ($inviterId <= 0) {
        $noInviter[] = $user;
        continue;
    }
    
    // 检查邀请人是否已经收到该用户的算力奖励
    // 检查 user_money_log 中是否有邀请奖励算力记录
    $hasPowerLog = Db::name('user_money_log')
        ->where('user_id', $inviterId)
        ->where('biz_id', $userId)
        ->where('field_type', 'green_power')
        ->where('biz_type', 'invite_reward')
        ->find();
    
    if (!$hasPowerLog) {
        $needRetroactive[] = $user;
    } else {
        $alreadyRewarded[] = $user;
    }
}

echo "已有算力奖励的用户: " . count($alreadyRewarded) . " 个\n";
echo "需要补发的用户: " . count($needRetroactive) . " 个\n";
echo "没有邀请人的用户: " . count($noInviter) . " 个\n\n";

if (empty($needRetroactive)) {
    echo "所有用户都已发放算力奖励，无需补发\n";
    exit(0);
}

// 开始补发
$successCount = 0;
$failCount = 0;
$totalRewardPower = 0;

echo "开始补发...\n\n";

foreach ($needRetroactive as $user) {
    try {
        Db::startTrans();
        
        $newUserId = (int)$user['id'];
        $inviterId = (int)$user['inviter_id'];
        $mobile = $user['mobile'] ?? 'N/A';
        
        // 再次检查是否已发放（防止并发）
        $hasPowerLog = Db::name('user_money_log')
            ->where('user_id', $inviterId)
            ->where('biz_id', $newUserId)
            ->where('field_type', 'green_power')
            ->where('biz_type', 'invite_reward')
            ->lock(true)
            ->find();
        
        if ($hasPowerLog) {
            Db::rollback();
            echo "  用户 {$mobile} (ID: {$newUserId}) 的邀请人已收到算力奖励，跳过\n";
            continue;
        }
        
        // 获取邀请人信息
        $inviter = Db::name('user')->where('id', $inviterId)->lock(true)->find();
        if (!$inviter) {
            throw new \Exception("邀请人ID {$inviterId} 不存在");
        }
        
        $beforeGreenPower = (float)($inviter['green_power'] ?? 0);
        $afterGreenPower = round($beforeGreenPower + $inviteRewardPower, 2);
        
        // 更新邀请人算力
        Db::name('user')->where('id', $inviterId)->update([
            'green_power' => $afterGreenPower,
            'update_time' => time(),
        ]);
        
        // 记录算力变动日志
        Db::name('user_money_log')->insert([
            'user_id' => $inviterId,
            'field_type' => 'green_power',
            'money' => $inviteRewardPower,
            'before' => $beforeGreenPower,
            'after' => $afterGreenPower,
            'memo' => sprintf('邀请好友获得算力（补发）：%.2f（被邀请用户ID：%d）', $inviteRewardPower, $newUserId),
            'flow_no' => generateSJSFlowNo($inviterId),
            'batch_no' => generateBatchNo('INV_PWR', $newUserId),
            'biz_type' => 'invite_reward',
            'biz_id' => $newUserId,
            'create_time' => time(),
        ]);
        
        // 记录活动日志
        Db::name('user_activity_log')->insert([
            'user_id' => $inviterId,
            'related_user_id' => $newUserId,
            'action_type' => 'invite_reward',
            'change_field' => 'green_power',
            'change_value' => (string)$inviteRewardPower,
            'before_value' => (string)$beforeGreenPower,
            'after_value' => (string)$afterGreenPower,
            'remark' => sprintf('邀请好友获得算力（补发）：%.2f（被邀请用户ID：%d）', $inviteRewardPower, $newUserId),
            'extra' => json_encode([
                'invite_reward_power' => $inviteRewardPower,
                'invited_user_id' => $newUserId,
                'is_retroactive' => true,
                'original_audit_time' => $user['audit_time'] ? date('Y-m-d H:i:s', $user['audit_time']) : '',
            ], JSON_UNESCAPED_UNICODE),
            'create_time' => time(),
        ]);
        
        Db::commit();
        $successCount++;
        $totalRewardPower += $inviteRewardPower;
        echo "✓ 用户 {$mobile} (ID: {$newUserId}) 的邀请人 {$inviter['mobile']} (ID: {$inviterId}) 补发成功：+{$inviteRewardPower} 算力\n";
    } catch (\Throwable $e) {
        Db::rollback();
        $failCount++;
        echo "  ✗ 用户 {$mobile} (ID: {$newUserId}) 的邀请人补发失败: " . $e->getMessage() . "\n";
    }
}

echo "\n=== 补发完成 ===\n";
echo "成功: {$successCount} 个\n";
echo "失败: {$failCount} 个\n";
echo "总补发算力: " . number_format($totalRewardPower, 2) . "\n";
echo "\n";
