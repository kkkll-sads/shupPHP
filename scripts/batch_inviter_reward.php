<?php
/**
 * 批量补发下级首次交易奖励脚本
 * 
 * 用于修复 pid 字段 bug 导致的邀请人奖励未发放问题
 * 
 * 使用方法：
 *   模拟运行（不实际执行）: php scripts/batch_inviter_reward.php --dry-run
 *   实际执行: php scripts/batch_inviter_reward.php --execute
 * 
 * 安全特性：
 *   1. 幂等性检查 - 跳过已有奖励记录的邀请人（按下级用户检查）
 *   2. 事务处理 - 确保每个奖励的原子性
 *   3. 详细日志 - 记录所有操作
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

use think\facade\Db;
use think\facade\Log;

// 解析命令行参数
$dryRun = in_array('--dry-run', $argv);
$execute = in_array('--execute', $argv);

if (!$dryRun && !$execute) {
    echo "用法：\n";
    echo "  模拟运行: php scripts/batch_inviter_reward.php --dry-run\n";
    echo "  实际执行: php scripts/batch_inviter_reward.php --execute\n";
    exit(1);
}

$mode = $dryRun ? '【模拟运行】' : '【实际执行】';
$now = time();

echo "=" . str_repeat("=", 70) . "\n";
echo "$mode 批量补发下级首次交易奖励（邀请人奖励）\n";
echo "执行时间: " . date('Y-m-d H:i:s', $now) . "\n";
echo "=" . str_repeat("=", 70) . "\n\n";

// 获取奖励配置
$rewardScore = (float)Db::name('config')->where('name', 'sub_trade_reward_score')->value('value');
$rewardPower = (float)Db::name('config')->where('name', 'sub_trade_reward_power')->value('value');

echo "=== 奖励配置 ===\n";
echo "下级首次交易奖励 - 消费金: $rewardScore\n";
echo "下级首次交易奖励 - 算力: $rewardPower\n\n";

if ($rewardScore <= 0 && $rewardPower <= 0) {
    echo "❌ 奖励配置为0，无需补发\n";
    exit(0);
}

// Step 1: 获取所有已升级为交易用户且有邀请人的用户
$usersWithInviter = Db::name('user')
    ->where('user_type', '>=', 1)
    ->where('inviter_id', '>', 0)
    ->field('id, mobile, inviter_id')
    ->select()
    ->toArray();

echo "有邀请人且已升级的用户数: " . count($usersWithInviter) . "\n";

// Step 2: 获取已发放过奖励的记录（按下级用户ID检查，防止重复）
// 使用 extra 字段中的 invited_user_id 来检查
$existingRewardSubordinates = [];
$existingLogs = Db::name('user_activity_log')
    ->where('action_type', 'invite_reward')
    ->where('remark', 'like', '%下级首次交易%')
    ->field('id, user_id, related_user_id, extra')
    ->select()
    ->toArray();

foreach ($existingLogs as $log) {
    // related_user_id 就是下级用户ID
    if ($log['related_user_id'] > 0) {
        $existingRewardSubordinates[$log['related_user_id']] = true;
    }
    // 也检查 extra 中的 invited_user_id
    if (!empty($log['extra'])) {
        $extra = json_decode($log['extra'], true);
        if (isset($extra['invited_user_id']) && $extra['invited_user_id'] > 0) {
            $existingRewardSubordinates[$extra['invited_user_id']] = true;
        }
    }
}

echo "已发放下级首次交易奖励的下级用户数: " . count($existingRewardSubordinates) . "\n";

// Step 3: 筛选需要补发的记录
$needReward = [];
foreach ($usersWithInviter as $user) {
    if (!isset($existingRewardSubordinates[$user['id']])) {
        $needReward[] = $user;
    }
}

echo "需要补发奖励的下级用户数: " . count($needReward) . "\n\n";

if (count($needReward) == 0) {
    echo "✅ 所有邀请人都已收到下级首次交易奖励，无需补发\n";
    exit(0);
}

// 统计变量
$successCount = 0;
$skipCount = 0;
$failCount = 0;
$totalScoreAdded = 0;
$totalPowerAdded = 0;

// 按邀请人分组统计
$inviterRewardCount = [];

echo "=== 开始处理 ===\n";

foreach ($needReward as $index => $subordinate) {
    $progress = $index + 1;
    $total = count($needReward);
    $subordinateId = $subordinate['id'];
    $inviterId = $subordinate['inviter_id'];
    
    // 再次检查是否已有奖励记录（双重检查）
    $existingReward = Db::name('user_activity_log')
        ->where('action_type', 'invite_reward')
        ->where('remark', 'like', '%下级首次交易%')
        ->where('related_user_id', $subordinateId)
        ->find();
    
    if ($existingReward) {
        echo "[$progress/$total] 下级 $subordinateId -> 邀请人 $inviterId: 已有奖励记录，跳过\n";
        $skipCount++;
        continue;
    }
    
    // 获取邀请人信息
    $inviter = Db::name('user')->where('id', $inviterId)->find();
    if (!$inviter) {
        echo "[$progress/$total] 下级 $subordinateId -> 邀请人 $inviterId: 邀请人不存在，跳过\n";
        $skipCount++;
        continue;
    }
    
    if ($dryRun) {
        // 模拟运行，只输出不执行
        echo "[$progress/$total] 下级 $subordinateId ({$subordinate['mobile']}) -> 邀请人 $inviterId ({$inviter['mobile']}): 将补发 消费金+$rewardScore, 算力+$rewardPower\n";
        $successCount++;
        $totalScoreAdded += $rewardScore;
        $totalPowerAdded += $rewardPower;
        
        if (!isset($inviterRewardCount[$inviterId])) {
            $inviterRewardCount[$inviterId] = 0;
        }
        $inviterRewardCount[$inviterId]++;
        continue;
    }
    
    // 实际执行 - 使用事务确保原子性
    Db::startTrans();
    try {
        // 再次检查（事务内）防止并发
        $checkAgain = Db::name('user_activity_log')
            ->where('action_type', 'invite_reward')
            ->where('remark', 'like', '%下级首次交易%')
            ->where('related_user_id', $subordinateId)
            ->lock(true)
            ->find();
        
        if ($checkAgain) {
            Db::rollback();
            echo "[$progress/$total] 下级 $subordinateId: 并发检查发现已有记录，跳过\n";
            $skipCount++;
            continue;
        }
        
        // 重新获取邀请人最新数据
        $inviter = Db::name('user')->where('id', $inviterId)->lock(true)->find();
        
        // 发放消费金
        if ($rewardScore > 0) {
            Db::name('user')
                ->where('id', $inviterId)
                ->inc('score', $rewardScore)
                ->update();

            Db::name('user_score_log')->insert([
                'user_id' => $inviterId,
                'score' => $rewardScore,
                'before' => $inviter['score'],
                'after' => $inviter['score'] + $rewardScore,
                'memo' => '下级首次交易奖励（系统补发）',
                'create_time' => $now,
            ]);
            
            Db::name('user_activity_log')->insert([
                'user_id' => $inviterId,
                'related_user_id' => $subordinateId,
                'action_type' => 'invite_reward',
                'change_field' => 'score',
                'change_value' => $rewardScore,
                'before_value' => (float)$inviter['score'],
                'after_value' => (float)$inviter['score'] + $rewardScore,
                'remark' => '下级首次交易奖励消费金（系统补发）',
                'extra' => json_encode([
                    'invite_reward' => $rewardScore,
                    'reward_score' => $rewardScore,
                    'invited_user_id' => $subordinateId,
                    'batch_补发' => true,
                    '补发原因' => 'inviter_id字段bug修复',
                    '补发时间' => date('Y-m-d H:i:s', $now),
                ], JSON_UNESCAPED_UNICODE),
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }

        // 发放算力
        if ($rewardPower > 0) {
            Db::name('user')
                ->where('id', $inviterId)
                ->inc('green_power', $rewardPower)
                ->update();

            Db::name('user_activity_log')->insert([
                'user_id' => $inviterId,
                'related_user_id' => $subordinateId,
                'action_type' => 'invite_reward', 
                'change_field' => 'green_power',
                'change_value' => $rewardPower,
                'before_value' => (float)$inviter['green_power'],
                'after_value' => (float)$inviter['green_power'] + $rewardPower,
                'remark' => '下级首次交易奖励算力（系统补发）',
                'extra' => json_encode([
                    'invite_reward' => $rewardPower,
                    'reward_green_power' => $rewardPower,
                    'invited_user_id' => $subordinateId,
                    'batch_补发' => true,
                    '补发原因' => 'inviter_id字段bug修复',
                    '补发时间' => date('Y-m-d H:i:s', $now),
                ], JSON_UNESCAPED_UNICODE),
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }
        
        Db::commit();
        
        echo "[$progress/$total] 下级 $subordinateId ({$subordinate['mobile']}) -> 邀请人 $inviterId ({$inviter['mobile']}): ✅ 补发成功\n";
        $successCount++;
        $totalScoreAdded += $rewardScore;
        $totalPowerAdded += $rewardPower;
        
        if (!isset($inviterRewardCount[$inviterId])) {
            $inviterRewardCount[$inviterId] = 0;
        }
        $inviterRewardCount[$inviterId]++;
        
        // 记录到系统日志
        Log::info('下级首次交易奖励补发', [
            'inviter_id' => $inviterId,
            'inviter_mobile' => $inviter['mobile'],
            'subordinate_id' => $subordinateId,
            'subordinate_mobile' => $subordinate['mobile'],
            'score' => $rewardScore,
            'green_power' => $rewardPower,
        ]);
        
    } catch (\Throwable $e) {
        Db::rollback();
        echo "[$progress/$total] 下级 $subordinateId -> 邀请人 $inviterId: ❌ 补发失败 - {$e->getMessage()}\n";
        $failCount++;
        
        Log::error('下级首次交易奖励补发失败', [
            'inviter_id' => $inviterId,
            'subordinate_id' => $subordinateId,
            'error' => $e->getMessage(),
        ]);
    }
}

// 输出统计结果
echo "\n" . str_repeat("=", 70) . "\n";
echo "$mode 执行完成\n";
echo str_repeat("=", 70) . "\n";
echo "处理记录数: " . count($needReward) . "\n";
echo "成功: $successCount\n";
echo "跳过: $skipCount\n";
echo "失败: $failCount\n";
echo "补发消费金总计: $totalScoreAdded\n";
echo "补发算力总计: $totalPowerAdded\n";
echo "受益邀请人数: " . count($inviterRewardCount) . "\n";
echo str_repeat("=", 70) . "\n";

if ($dryRun) {
    echo "\n⚠️ 这是模拟运行，没有实际执行任何操作\n";
    echo "确认无误后，请使用 --execute 参数执行实际补发\n";
}
