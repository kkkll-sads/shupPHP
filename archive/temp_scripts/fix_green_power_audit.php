<?php
/**
 * 算力审计和修复脚本
 * 严谨地检查所有用户的算力是否正确，并修复错误
 * 
 * 用法: php fix_green_power_audit.php [--dry-run] [--user-id=用户ID]
 * 
 * --dry-run: 只检查不修复
 * --user-id: 只检查指定用户
 */

require __DIR__ . '/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use think\facade\Db;

// 解析命令行参数
$dryRun = in_array('--dry-run', $argv);
$userIdFilter = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--user-id=') === 0) {
        $userIdFilter = (int)substr($arg, 10);
    }
}

echo "=== 算力审计和修复脚本 ===\n";
echo "模式: " . ($dryRun ? "检查模式（不修复）" : "修复模式") . "\n";
if ($userIdFilter) {
    echo "用户ID过滤: {$userIdFilter}\n";
}
echo "\n";

// 1. 检查所有充值奖励记录，找出重复的
echo "步骤1: 检查重复的充值奖励记录...\n";
$duplicateRewards = [];

// 查询所有充值奖励活动日志
$rewardLogs = Db::name('user_activity_log')
    ->where('action_type', 'recharge_reward')
    ->order('id asc')
    ->select()
    ->toArray();

// 按用户ID和订单ID分组
$rewardGroups = [];
foreach ($rewardLogs as $log) {
    $extra = json_decode($log['extra'], true);
    if (isset($extra['order_id'])) {
        $key = $log['user_id'] . '_' . $extra['order_id'];
        if (!isset($rewardGroups[$key])) {
            $rewardGroups[$key] = [];
        }
        $rewardGroups[$key][] = $log;
    }
}

// 找出重复的
foreach ($rewardGroups as $key => $logs) {
    if (count($logs) > 1) {
        $duplicateRewards[$key] = $logs;
    }
}

echo "发现 " . count($duplicateRewards) . " 个订单有重复奖励记录\n\n";

// 2. 检查所有用户的算力是否正确
echo "步骤2: 检查所有用户的算力是否正确...\n";

// 获取所有用户（或指定用户）
$query = Db::name('user');
if ($userIdFilter) {
    $query->where('id', $userIdFilter);
}
$users = $query->field('id, green_power, mobile, username')->select()->toArray();

echo "检查 " . count($users) . " 个用户\n\n";

$errors = [];
$fixedUsers = [];

foreach ($users as $user) {
    $userId = (int)$user['id'];
    $currentPower = (float)($user['green_power'] ?? 0);
    
    // 计算正确的算力值：从所有算力变动日志计算
    // 🔧 严谨方法：从0开始累加所有变动，不依赖日志中的before/after值
    $powerLogs = Db::name('user_money_log')
        ->where('user_id', $userId)
        ->where('field_type', 'green_power')
        ->order('id asc, create_time asc')
        ->select()
        ->toArray();
    
    $calculatedPower = 0;
    $powerHistory = [];
    $logIssues = [];
    
    foreach ($powerLogs as $log) {
        $change = (float)$log['money'];
        $before = (float)$log['before'];
        $after = (float)$log['after'];
        
        // 验证日志的一致性
        $expectedAfter = round($before + $change, 2);
        $isConsistent = abs($expectedAfter - $after) <= 0.01;
        
        if (!$isConsistent) {
            $logIssues[] = [
                'log_id' => $log['id'],
                'before' => $before,
                'change' => $change,
                'after' => $after,
                'expected_after' => $expectedAfter,
            ];
        }
        
        // 🔧 严谨方法：直接累加变动值，不依赖日志中的before/after
        // 这样可以避免日志数据不一致导致的错误
        $calculatedPower = round($calculatedPower + $change, 2);
        
        $powerHistory[] = [
            'log_id' => $log['id'],
            'change' => $change,
            'before' => $before,
            'after' => $after,
            'memo' => $log['memo'] ?? '',
            'create_time' => $log['create_time'] ?? 0,
            'is_consistent' => $isConsistent,
        ];
    }
    
    // 记录日志不一致的问题
    if (!empty($logIssues)) {
        foreach ($logIssues as $issue) {
            $errors[] = [
                'type' => 'log_inconsistent',
                'user_id' => $userId,
                'log_id' => $issue['log_id'],
                'message' => "日志ID {$issue['log_id']} 数据不一致: before={$issue['before']}, change={$issue['change']}, after={$issue['after']}, 期望after={$issue['expected_after']}",
            ];
        }
    }
    
    // 检查算力是否一致
    $diff = abs($currentPower - $calculatedPower);
    if ($diff > 0.01) {
        $errors[] = [
            'type' => 'power_mismatch',
            'user_id' => $userId,
            'current_power' => $currentPower,
            'calculated_power' => $calculatedPower,
            'diff' => $diff,
            'mobile' => $user['mobile'] ?? '',
            'username' => $user['username'] ?? '',
        ];
        
        // 如果不是 dry-run 模式，修复算力
        if (!$dryRun) {
            try {
                Db::startTrans();
                
                // 更新用户算力
                Db::name('user')
                    ->where('id', $userId)
                    ->update([
                        'green_power' => $calculatedPower,
                        'update_time' => time(),
                    ]);
                
                Db::commit();
                
                $fixedUsers[] = [
                    'user_id' => $userId,
                    'old_power' => $currentPower,
                    'new_power' => $calculatedPower,
                    'diff' => $diff,
                ];
                
                echo "✓ 用户 {$userId} ({$user['mobile']}) 算力已修复: {$currentPower} -> {$calculatedPower} (差异: {$diff})\n";
                
            } catch (\Throwable $e) {
                Db::rollback();
                echo "✗ 用户 {$userId} 算力修复失败: " . $e->getMessage() . "\n";
            }
        } else {
            echo "⚠ 用户 {$userId} ({$user['mobile']}) 算力不一致: 当前={$currentPower}, 计算={$calculatedPower}, 差异={$diff}\n";
        }
    }
}

echo "\n";

// 3. 清理重复的充值奖励记录
if (!empty($duplicateRewards) && !$dryRun) {
    echo "步骤3: 清理重复的充值奖励记录...\n";
    
    $cleanedCount = 0;
    $deductedPower = 0;
    
    foreach ($duplicateRewards as $key => $logs) {
        // 按时间排序，保留最早的
        usort($logs, function($a, $b) {
            return ($a['create_time'] ?? 0) - ($b['create_time'] ?? 0);
        });
        
        $keepLog = $logs[0];
        $deleteLogs = array_slice($logs, 1);
        
        $userId = (int)$keepLog['user_id'];
        $extra = json_decode($keepLog['extra'], true);
        $orderId = $extra['order_id'] ?? 0;
        $orderNo = $extra['order_no'] ?? '';
        
        // 计算需要扣除的算力
        $deductPower = 0;
        $deleteIds = [];
        foreach ($deleteLogs as $log) {
            $deductPower += (float)($log['change_value'] ?? 0);
            $deleteIds[] = $log['id'];
        }
        
        if ($deductPower > 0) {
            try {
                Db::startTrans();
                
                // 删除重复的活动日志
                Db::name('user_activity_log')->whereIn('id', $deleteIds)->delete();
                
                // 查找对应的算力变动日志并删除
                $moneyLogs = Db::name('user_money_log')
                    ->where('user_id', $userId)
                    ->where('field_type', 'green_power')
                    ->where('biz_type', 'recharge_reward')
                    ->where('biz_id', $orderId)
                    ->where('memo', 'like', '%订单号 ' . $orderNo . '%')
                    ->order('id asc')
                    ->select()
                    ->toArray();
                
                if (count($moneyLogs) > 1) {
                    // 保留第一条，删除其余的
                    $firstMoneyLog = array_shift($moneyLogs);
                    $moneyLogIdsToDelete = array_column($moneyLogs, 'id');
                    
                    if (!empty($moneyLogIdsToDelete)) {
                        Db::name('user_money_log')->whereIn('id', $moneyLogIdsToDelete)->delete();
                    }
                }
                
                // 扣除用户多余的算力
                $user = Db::name('user')->where('id', $userId)->lock(true)->find();
                if ($user) {
                    $beforePower = (float)($user['green_power'] ?? 0);
                    $afterPower = round($beforePower - $deductPower, 2);
                    if ($afterPower < 0) $afterPower = 0;
                    
                    Db::name('user')
                        ->where('id', $userId)
                        ->update([
                            'green_power' => $afterPower,
                            'update_time' => time(),
                        ]);
                }
                
                Db::commit();
                
                $cleanedCount++;
                $deductedPower += $deductPower;
                
                echo "✓ 用户 {$userId} 订单 {$orderNo} 重复记录已清理，扣除算力: {$deductPower}\n";
                
            } catch (\Throwable $e) {
                Db::rollback();
                echo "✗ 用户 {$userId} 订单 {$orderNo} 清理失败: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "清理完成: {$cleanedCount} 个重复记录，总扣除算力: " . round($deductedPower, 2) . "\n\n";
}

// 4. 输出总结
echo "=== 审计总结 ===\n";
echo "检查用户数: " . count($users) . "\n";
echo "发现错误数: " . count($errors) . "\n";
echo "修复用户数: " . count($fixedUsers) . "\n";

if (!empty($errors)) {
    echo "\n错误详情:\n";
    foreach ($errors as $error) {
        if ($error['type'] === 'power_mismatch') {
            echo "  用户 {$error['user_id']} ({$error['mobile']}): 算力不一致，当前={$error['current_power']}, 计算={$error['calculated_power']}, 差异={$error['diff']}\n";
        } else {
            echo "  {$error['message']}\n";
        }
    }
}

if (!empty($fixedUsers)) {
    echo "\n修复详情:\n";
    foreach ($fixedUsers as $fixed) {
        echo "  用户 {$fixed['user_id']}: {$fixed['old_power']} -> {$fixed['new_power']} (差异: {$fixed['diff']})\n";
    }
}

echo "\n=== 完成 ===\n";
