<?php
// fix_duplicate_recharge_rewards_all.php
// 清理所有用户的重复充值奖励补发记录

// 定义应用目录
define('APP_PATH', __DIR__ . '/app/');

// 加载框架引导文件
require __DIR__ . '/vendor/autoload.php';
$app = new think\App();
$app->initialize();

use think\facade\Db;

// 确保 generateSJSFlowNo 和 generateBatchNo 函数可用
if (!function_exists('generateSJSFlowNo')) {
    function generateSJSFlowNo(int $userId): string
    {
        return 'SJS' . date('YmdHis') . str_pad($userId, 6, '0', STR_PAD_LEFT) . mt_rand(1000, 9999);
    }
}

if (!function_exists('generateBatchNo')) {
    function generateBatchNo(string $prefix, int $businessId): string
    {
        return strtoupper($prefix) . '_' . $businessId;
    }
}

echo "=== 开始清理所有用户的重复充值奖励补发记录 ===\n\n";

// 查找所有重复的补发记录（按用户ID和订单号分组）
$duplicateGroups = Db::query("
    SELECT 
        user_id,
        SUBSTRING_INDEX(SUBSTRING_INDEX(memo, '订单号 ', -1), ' ', 1) as order_no,
        memo,
        COUNT(*) as count,
        SUM(money) as total_money,
        MIN(id) as first_id,
        MIN(create_time) as first_time
    FROM ba_user_money_log
    WHERE field_type = 'green_power'
    AND memo LIKE '%充值奖励-绿色算力（补发）%'
    GROUP BY user_id, memo
    HAVING count > 1
    ORDER BY count DESC, user_id ASC
");

if (empty($duplicateGroups)) {
    echo "没有发现重复的补发记录\n";
    exit(0);
}

echo "发现 " . count($duplicateGroups) . " 组重复的补发记录\n\n";

$totalFixedGroups = 0;
$totalDeductedPower = 0;
$totalDeletedLogs = 0;

foreach ($duplicateGroups as $group) {
    $userId = (int)$group['user_id'];
    $orderNo = $group['order_no'];
    $memo = $group['memo'];
    $duplicateCount = (int)$group['count'];
    $totalMoney = (float)$group['total_money'];
    $firstId = (int)$group['first_id'];
    $firstTime = (int)$group['first_time'];
    
    // 获取所有重复的记录
    $duplicateLogs = Db::name('user_money_log')
        ->where('user_id', $userId)
        ->where('field_type', 'green_power')
        ->where('memo', $memo)
        ->order('create_time asc, id asc') // 保留最早的
        ->select()
        ->toArray();
    
    if (count($duplicateLogs) <= 1) {
        continue; // 实际上不是重复的，跳过
    }
    
    // 保留第一条记录，删除其余的
    $firstLog = array_shift($duplicateLogs);
    $logsToDelete = $duplicateLogs;
    
    $deductPower = 0;
    $deletedIds = [];
    
    foreach ($logsToDelete as $log) {
        $deductPower += (float)$log['money'];
        $deletedIds[] = $log['id'];
    }
    
    // 计算应该保留的金额（第一条记录的金额）
    $keepMoney = (float)$firstLog['money'];
    
    if (!empty($deletedIds)) {
        Db::startTrans();
        try {
            // 删除重复的算力变动日志
            $deletedCount = Db::name('user_money_log')->whereIn('id', $deletedIds)->delete();
            
            // 扣除用户多余的算力
            if ($deductPower > 0) {
                $user = Db::name('user')->where('id', $userId)->lock(true)->find();
                if ($user) {
                    $beforeGreenPower = (float)($user['green_power'] ?? 0);
                    $afterGreenPower = round($beforeGreenPower - $deductPower, 2);
                    if ($afterGreenPower < 0) $afterGreenPower = 0; // 避免负数
                    
                    Db::name('user')
                        ->where('id', $userId)
                        ->update([
                            'green_power' => $afterGreenPower,
                            'update_time' => time(),
                        ]);
                    
                    // 记录算力扣除日志（如果需要）
                    // 这里不记录扣除日志，因为只是纠正错误
                    
                    $totalDeductedPower += $deductPower;
                }
            }
            
            // 同时删除对应的活动日志（如果存在重复的）
            // 查找对应的活动日志
            $activityLogs = Db::name('user_activity_log')
                ->where('user_id', $userId)
                ->where('action_type', 'recharge_reward')
                ->where('extra', 'like', '%"order_no": "' . $orderNo . '"%')
                ->where('create_time', '>', $firstTime - 10) // 允许10秒的时间差
                ->where('create_time', '<', $firstTime + 86400) // 24小时内
                ->order('create_time asc, id asc')
                ->select()
                ->toArray();
            
            if (count($activityLogs) > 1) {
                // 保留第一条，删除其余的
                array_shift($activityLogs);
                $activityLogIdsToDelete = array_column($activityLogs, 'id');
                if (!empty($activityLogIdsToDelete)) {
                    Db::name('user_activity_log')->whereIn('id', $activityLogIdsToDelete)->delete();
                }
            }
            
            Db::commit();
            
            $userInfo = Db::name('user')->where('id', $userId)->find();
            $userName = $userInfo['mobile'] ?? $userInfo['username'] ?? 'ID:' . $userId;
            
            echo "用户: {$userName} (ID: {$userId})\n";
            echo "  订单号: {$orderNo}\n";
            echo "  重复记录数: " . count($logsToDelete) + 1 . "\n";
            echo "  保留记录ID: {$firstLog['id']} (时间: " . date('Y-m-d H:i:s', $firstLog['create_time']) . ")\n";
            echo "  删除记录ID: " . implode(', ', $deletedIds) . "\n";
            echo "  扣除算力: {$deductPower} (保留: {$keepMoney})\n";
            echo "  ✓ 修复成功\n\n";
            
            $totalFixedGroups++;
            $totalDeletedLogs += $deletedCount;
            
        } catch (Throwable $e) {
            Db::rollback();
            echo "用户ID: {$userId}, 订单号: {$orderNo} 修复失败：" . $e->getMessage() . "\n\n";
        }
    }
}

echo "\n=== 清理完成 ===\n";
echo "修复组数: {$totalFixedGroups}\n";
echo "删除记录数: {$totalDeletedLogs}\n";
echo "总扣除算力: " . round($totalDeductedPower, 2) . "\n";
