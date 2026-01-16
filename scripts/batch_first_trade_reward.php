<?php
/**
 * 批量补发首次交易奖励脚本
 * 
 * 用于修复 pid 字段 bug 导致的首次交易奖励未发放问题
 * 
 * 使用方法：
 *   模拟运行（不实际执行）: php scripts/batch_first_trade_reward.php --dry-run
 *   实际执行: php scripts/batch_first_trade_reward.php --execute
 * 
 * 安全特性：
 *   1. 幂等性检查 - 跳过已有奖励记录的用户
 *   2. 事务处理 - 确保每个用户的奖励原子性
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
    echo "  模拟运行: php scripts/batch_first_trade_reward.php --dry-run\n";
    echo "  实际执行: php scripts/batch_first_trade_reward.php --execute\n";
    exit(1);
}

$mode = $dryRun ? '【模拟运行】' : '【实际执行】';
$now = time();

echo "=" . str_repeat("=", 70) . "\n";
echo "$mode 批量补发首次交易奖励\n";
echo "执行时间: " . date('Y-m-d H:i:s', $now) . "\n";
echo "=" . str_repeat("=", 70) . "\n\n";

// 获取奖励配置
$rewardScore = (float)Db::name('config')->where('name', 'first_trade_reward_score')->value('value');
$rewardPower = (float)Db::name('config')->where('name', 'first_trade_reward_power')->value('value');

echo "=== 奖励配置 ===\n";
echo "消费金: $rewardScore\n";
echo "算力: $rewardPower\n\n";

if ($rewardScore <= 0 && $rewardPower <= 0) {
    echo "❌ 奖励配置为0，无需补发\n";
    exit(0);
}

// Step 1: 获取所有已升级为交易用户的用户ID（user_type >= 1）
$upgradedUsers = Db::name('user')
    ->where('user_type', '>=', 1)
    ->column('id');

echo "已升级用户总数: " . count($upgradedUsers) . "\n";

// Step 2: 获取所有已收到首次交易奖励的用户ID（去重）
$rewardedUsers = Db::name('user_activity_log')
    ->where('action_type', 'first_trade_reward')
    ->group('user_id')
    ->column('user_id');

echo "已获得首次交易奖励用户数: " . count($rewardedUsers) . "\n";

// Step 3: 找出未收到奖励的用户
$missingRewardUsers = array_diff($upgradedUsers, $rewardedUsers);

echo "需要补发奖励用户数: " . count($missingRewardUsers) . "\n\n";

if (count($missingRewardUsers) == 0) {
    echo "✅ 所有用户都已收到首次交易奖励，无需补发\n";
    exit(0);
}

// 统计变量
$successCount = 0;
$skipCount = 0;
$failCount = 0;
$totalScoreAdded = 0;
$totalPowerAdded = 0;

echo "=== 开始处理 ===\n";

foreach ($missingRewardUsers as $index => $userId) {
    $progress = $index + 1;
    $total = count($missingRewardUsers);
    
    // 再次检查该用户是否已有奖励记录（双重检查，防止并发）
    $existingReward = Db::name('user_activity_log')
        ->where('user_id', $userId)
        ->where('action_type', 'first_trade_reward')
        ->find();
    
    if ($existingReward) {
        echo "[$progress/$total] 用户 $userId: 已有奖励记录，跳过\n";
        $skipCount++;
        continue;
    }
    
    // 获取用户信息
    $user = Db::name('user')->where('id', $userId)->find();
    if (!$user) {
        echo "[$progress/$total] 用户 $userId: 用户不存在，跳过\n";
        $skipCount++;
        continue;
    }
    
    // 检查用户是否真的购买过藏品
    $hasPurchase = Db::name('user_collection')
        ->where('user_id', $userId)
        ->count();
    
    if ($hasPurchase == 0) {
        echo "[$progress/$total] 用户 $userId ({$user['mobile']}): 无购买记录，跳过\n";
        $skipCount++;
        continue;
    }
    
    if ($dryRun) {
        // 模拟运行，只输出不执行
        echo "[$progress/$total] 用户 $userId ({$user['mobile']}): 将补发 消费金+$rewardScore, 算力+$rewardPower\n";
        $successCount++;
        $totalScoreAdded += $rewardScore;
        $totalPowerAdded += $rewardPower;
        continue;
    }
    
    // 实际执行 - 使用事务确保原子性
    Db::startTrans();
    try {
        // 再次检查（事务内）防止并发
        $checkAgain = Db::name('user_activity_log')
            ->where('user_id', $userId)
            ->where('action_type', 'first_trade_reward')
            ->lock(true)
            ->find();
        
        if ($checkAgain) {
            Db::rollback();
            echo "[$progress/$total] 用户 $userId: 并发检查发现已有记录，跳过\n";
            $skipCount++;
            continue;
        }
        
        // 发放消费金
        if ($rewardScore > 0) {
            Db::name('user')
                ->where('id', $userId)
                ->inc('score', $rewardScore)
                ->update();

            Db::name('user_score_log')->insert([
                'user_id' => $userId,
                'score' => $rewardScore,
                'before' => $user['score'],
                'after' => $user['score'] + $rewardScore,
                'memo' => '首次交易奖励（系统补发）',
                'create_time' => $now,
            ]);
            
            Db::name('user_activity_log')->insert([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'first_trade_reward',
                'change_field' => 'score',
                'change_value' => $rewardScore,
                'before_value' => (float)$user['score'],
                'after_value' => (float)$user['score'] + $rewardScore,
                'remark' => '首次交易奖励消费金（系统补发）',
                'extra' => json_encode([
                    'reward_score' => $rewardScore,
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
                ->where('id', $userId)
                ->inc('green_power', $rewardPower)
                ->update();

            Db::name('user_activity_log')->insert([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'first_trade_reward', 
                'change_field' => 'green_power',
                'change_value' => $rewardPower,
                'before_value' => (float)$user['green_power'],
                'after_value' => (float)$user['green_power'] + $rewardPower,
                'remark' => '首次交易奖励算力（系统补发）',
                'extra' => json_encode([
                    'reward_green_power' => $rewardPower,
                    'batch_补发' => true,
                    '补发原因' => 'inviter_id字段bug修复',
                    '补发时间' => date('Y-m-d H:i:s', $now),
                ], JSON_UNESCAPED_UNICODE),
                'create_time' => $now,
                'update_time' => $now,
            ]);
        }
        
        Db::commit();
        
        echo "[$progress/$total] 用户 $userId ({$user['mobile']}): ✅ 补发成功 消费金+$rewardScore, 算力+$rewardPower\n";
        $successCount++;
        $totalScoreAdded += $rewardScore;
        $totalPowerAdded += $rewardPower;
        
        // 记录到系统日志
        Log::info('首次交易奖励补发', [
            'user_id' => $userId,
            'mobile' => $user['mobile'],
            'score' => $rewardScore,
            'green_power' => $rewardPower,
        ]);
        
    } catch (\Throwable $e) {
        Db::rollback();
        echo "[$progress/$total] 用户 $userId ({$user['mobile']}): ❌ 补发失败 - {$e->getMessage()}\n";
        $failCount++;
        
        Log::error('首次交易奖励补发失败', [
            'user_id' => $userId,
            'error' => $e->getMessage(),
        ]);
    }
}

// 输出统计结果
echo "\n" . str_repeat("=", 70) . "\n";
echo "$mode 执行完成\n";
echo str_repeat("=", 70) . "\n";
echo "处理用户数: " . count($missingRewardUsers) . "\n";
echo "成功: $successCount\n";
echo "跳过: $skipCount\n";
echo "失败: $failCount\n";
echo "补发消费金总计: $totalScoreAdded\n";
echo "补发算力总计: $totalPowerAdded\n";
echo str_repeat("=", 70) . "\n";

if ($dryRun) {
    echo "\n⚠️ 这是模拟运行，没有实际执行任何操作\n";
    echo "确认无误后，请使用 --execute 参数执行实际补发\n";
}
