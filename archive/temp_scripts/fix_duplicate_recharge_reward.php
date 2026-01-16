<?php
/**
 * 修复重复的充值奖励记录
 * 用法: php fix_duplicate_recharge_reward.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use think\facade\Db;

echo "=== 检查重复的充值奖励记录 ===\n\n";

// 查找所有充值奖励记录，按用户和订单ID分组
$allLogs = Db::name('user_activity_log')
    ->where('action_type', 'recharge_reward')
    ->order('id asc')
    ->select()
    ->toArray();

$grouped = [];
foreach ($allLogs as $log) {
    $extra = json_decode($log['extra'], true);
    $orderId = $extra['order_id'] ?? 0;
    $userId = $log['user_id'];
    
    $key = $userId . '_' . $orderId;
    if (!isset($grouped[$key])) {
        $grouped[$key] = [];
    }
    $grouped[$key][] = $log;
}

$duplicates = [];
foreach ($grouped as $key => $logs) {
    if (count($logs) > 1) {
        $duplicates[$key] = $logs;
    }
}

if (empty($duplicates)) {
    echo "没有发现重复记录\n";
    exit(0);
}

echo "发现 " . count($duplicates) . " 组重复记录\n\n";

$totalFixed = 0;
$totalPowerDeducted = 0;

foreach ($duplicates as $key => $logs) {
    // 保留第一条记录，删除其他重复记录
    $keepLog = $logs[0];
    $deleteLogs = array_slice($logs, 1);
    
    $userId = $keepLog['user_id'];
    $orderId = json_decode($keepLog['extra'], true)['order_id'] ?? 0;
    
    $deletePower = 0;
    $deleteIds = [];
    foreach ($deleteLogs as $deleteLog) {
        $deletePower += (float)$deleteLog['change_value'];
        $deleteIds[] = $deleteLog['id'];
    }
    
    echo "用户ID: {$userId}, 订单ID: {$orderId}, 重复记录数: " . count($logs) . "\n";
    echo "  保留记录ID: {$keepLog['id']}\n";
    echo "  删除记录ID: " . implode(', ', $deleteIds) . "\n";
    echo "  需要扣除算力: {$deletePower}\n";
    
    try {
        Db::startTrans();
        
        // 扣除用户算力
        $user = Db::name('user')->where('id', $userId)->lock(true)->find();
        if ($user) {
            $currentPower = (float)($user['green_power'] ?? 0);
            $newPower = round($currentPower - $deletePower, 2);
            
            Db::name('user')
                ->where('id', $userId)
                ->update([
                    'green_power' => max(0, $newPower), // 确保不为负数
                    'update_time' => time(),
                ]);
            
            echo "  用户算力: {$currentPower} -> " . max(0, $newPower) . "\n";
        }
        
        // 删除重复的活动日志记录
        Db::name('user_activity_log')
            ->where('id', 'in', $deleteIds)
            ->delete();
        
        // 删除对应的资金变动日志（如果有）
        foreach ($deleteIds as $deleteId) {
            // 通过批次号查找对应的资金变动日志
            $deleteLog = Db::name('user_activity_log')->where('id', $deleteId)->find();
            if ($deleteLog) {
                // 注意：这里可能无法精确匹配，因为批次号可能不同
                // 但我们可以通过 biz_id 和 biz_type 来查找
                // 由于资金变动日志的批次号可能不同，这里先不删除，只删除活动日志
            }
        }
        
        Db::commit();
        
        $totalFixed++;
        $totalPowerDeducted += $deletePower;
        echo "  ✓ 修复成功\n\n";
        
    } catch (\Throwable $e) {
        Db::rollback();
        echo "  ✗ 修复失败: " . $e->getMessage() . "\n\n";
    }
}

echo "=== 修复完成 ===\n";
echo "修复组数: {$totalFixed}\n";
echo "总扣除算力: " . round($totalPowerDeducted, 2) . "\n";
