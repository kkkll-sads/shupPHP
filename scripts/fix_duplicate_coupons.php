<?php
/**
 * 修复重复寄售券脚本
 * 
 * 处理重复订单导致的寄售券问题：
 * 1. 删除重复的寄售券（每个用户每个场次+区域只保留一张）
 * 2. 恢复被错误标记为"已使用"但实际未关联寄售的券状态
 * 
 * 使用方法：
 *   模拟运行: php scripts/fix_duplicate_coupons.php --dry-run
 *   实际执行: php scripts/fix_duplicate_coupons.php --execute
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
    echo "  模拟运行: php scripts/fix_duplicate_coupons.php --dry-run\n";
    echo "  实际执行: php scripts/fix_duplicate_coupons.php --execute\n";
    exit(1);
}

$mode = $dryRun ? '【模拟运行】' : '【实际执行】';
$now = time();

echo "=" . str_repeat("=", 70) . "\n";
echo "$mode 修复重复寄售券\n";
echo "执行时间: " . date('Y-m-d H:i:s', $now) . "\n";
echo "=" . str_repeat("=", 70) . "\n\n";

// 时间范围：今天早上重复订单发生的时间段
$startTime = strtotime('2026-01-16 00:00:00');
$endTime = strtotime('2026-01-16 12:10:00');

// ========================================
// Step 1: 修复被错误标记为"已使用"的券
// ========================================
echo "=== Step 1: 修复被错误标记为已使用的券 ===\n";

// 获取今天发放的所有券
$todayCoupons = Db::name('user_consignment_coupon')
    ->where('create_time', '>=', $startTime)
    ->where('create_time', '<=', $endTime)
    ->select()
    ->toArray();

echo "今天发放的券总数: " . count($todayCoupons) . "\n";

// 获取实际被使用的券ID（关联到寄售订单）
$usedCouponIds = Db::name('collection_consignment')
    ->where('coupon_id', '>', 0)
    ->column('coupon_id');

$usedCouponIds = array_flip($usedCouponIds);

// 找出需要恢复的券（状态=1 但未关联寄售）
$needRestore = [];
foreach ($todayCoupons as $coupon) {
    if ($coupon['status'] == 1 && !isset($usedCouponIds[$coupon['id']])) {
        $needRestore[] = $coupon['id'];
    }
}

echo "状态为已使用但未关联寄售的券: " . count($needRestore) . "\n";

if (count($needRestore) > 0 && !$dryRun) {
    $restoreCount = Db::name('user_consignment_coupon')
        ->whereIn('id', $needRestore)
        ->update([
            'status' => 0,
            'update_time' => $now
        ]);
    echo "✅ 已恢复 $restoreCount 张券的状态为未使用\n";
} elseif ($dryRun) {
    echo "将恢复 " . count($needRestore) . " 张券的状态为未使用\n";
}

// ========================================
// Step 2: 删除重复的寄售券
// ========================================
echo "\n=== Step 2: 删除重复的寄售券 ===\n";

// 查找重复的寄售券组合（同一用户、同一场次、同一区域）
$duplicateGroups = Db::name('user_consignment_coupon')
    ->where('create_time', '>=', $startTime)
    ->where('create_time', '<=', $endTime)
    ->field('user_id, session_id, zone_id, COUNT(*) as cnt, MIN(id) as keep_id')
    ->group('user_id, session_id, zone_id')
    ->having('cnt > 1')
    ->select()
    ->toArray();

echo "有重复券的组合数: " . count($duplicateGroups) . "\n";

$totalToDelete = 0;
$deleteIds = [];

foreach ($duplicateGroups as $group) {
    // 获取该组合的所有券ID
    $couponIds = Db::name('user_consignment_coupon')
        ->where('user_id', $group['user_id'])
        ->where('session_id', $group['session_id'])
        ->where('zone_id', $group['zone_id'])
        ->where('create_time', '>=', $startTime)
        ->where('create_time', '<=', $endTime)
        ->order('id asc')
        ->column('id');
    
    // 保留第一张，其余删除
    $toDelete = array_slice($couponIds, 1);
    
    // 检查是否有被实际使用的券
    foreach ($toDelete as $key => $id) {
        if (isset($usedCouponIds[$id])) {
            // 这张券被实际使用了，不能删除，改为删除第一张
            // 实际上这种情况很少，因为我们已经知道只有2张券被实际使用
            unset($toDelete[$key]);
            echo "⚠️ 券ID $id 已被寄售订单使用，跳过删除\n";
        }
    }
    
    $deleteIds = array_merge($deleteIds, $toDelete);
    $totalToDelete += count($toDelete);
}

echo "需要删除的重复券数: $totalToDelete\n";

if ($totalToDelete > 0 && !$dryRun) {
    $deleteCount = Db::name('user_consignment_coupon')
        ->whereIn('id', $deleteIds)
        ->delete();
    echo "✅ 已删除 $deleteCount 张重复券\n";
} elseif ($dryRun) {
    echo "将删除 $totalToDelete 张重复券\n";
}

// ========================================
// Step 3: 验证结果
// ========================================
echo "\n=== 验证结果 ===\n";

if (!$dryRun) {
    // 重新统计
    $remainingCoupons = Db::name('user_consignment_coupon')
        ->where('create_time', '>=', $startTime)
        ->where('create_time', '<=', $endTime)
        ->count();
    
    $remainingDuplicates = Db::name('user_consignment_coupon')
        ->where('create_time', '>=', $startTime)
        ->where('create_time', '<=', $endTime)
        ->field('user_id, session_id, zone_id, COUNT(*) as cnt')
        ->group('user_id, session_id, zone_id')
        ->having('cnt > 1')
        ->count();
    
    $unusedCoupons = Db::name('user_consignment_coupon')
        ->where('create_time', '>=', $startTime)
        ->where('create_time', '<=', $endTime)
        ->where('status', 0)
        ->count();
    
    echo "修复后今天的券总数: $remainingCoupons\n";
    echo "修复后重复组合数: $remainingDuplicates\n";
    echo "修复后未使用的券: $unusedCoupons\n";
}

// 输出统计
echo "\n" . str_repeat("=", 70) . "\n";
echo "$mode 执行完成\n";
echo str_repeat("=", 70) . "\n";
echo "恢复状态的券: " . count($needRestore) . "\n";
echo "删除的重复券: $totalToDelete\n";
echo str_repeat("=", 70) . "\n";

if ($dryRun) {
    echo "\n⚠️ 这是模拟运行，没有实际执行任何操作\n";
    echo "确认无误后，请使用 --execute 参数执行实际修复\n";
}

// 记录日志
if (!$dryRun) {
    Log::info('重复寄售券修复', [
        'restored_count' => count($needRestore),
        'deleted_count' => $totalToDelete,
        'execute_time' => date('Y-m-d H:i:s', $now),
    ]);
}
