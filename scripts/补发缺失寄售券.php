<?php
/**
 * 补发缺失的寄售券
 * 
 * 针对今天（2026-01-16）paid 订单中，交易用户（user_type >= 2）应该收到但没收到寄售券的情况
 * 
 * 使用方法：
 *   模拟运行: php scripts/补发缺失寄售券.php --dry-run
 *   实际执行: php scripts/补发缺失寄售券.php --execute
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
    echo "  模拟运行: php scripts/补发缺失寄售券.php --dry-run\n";
    echo "  实际执行: php scripts/补发缺失寄售券.php --execute\n";
    exit(1);
}

$mode = $dryRun ? '【模拟运行】' : '【实际执行】';
$now = time();
$startTime = strtotime('2026-01-16 00:00:00');

echo "=" . str_repeat("=", 70) . "\n";
echo "$mode 补发缺失的寄售券\n";
echo "执行时间: " . date('Y-m-d H:i:s', $now) . "\n";
echo "=" . str_repeat("=", 70) . "\n\n";

// 查找应该发券但没发的订单
// 条件：今天的 paid 订单，用户是交易用户（user_type >= 2），有对应的 session_id 和 zone_id，但没有对应的券
$missingCoupons = Db::query("
    SELECT 
        co.id as order_id,
        co.user_id,
        u.mobile,
        u.user_type,
        ci.session_id,
        ci.zone_id,
        pz.name as zone_name,
        co.create_time
    FROM ba_collection_order co
    LEFT JOIN ba_collection_order_item coi ON co.id = coi.order_id
    LEFT JOIN ba_collection_item ci ON coi.item_id = ci.id
    LEFT JOIN ba_user u ON co.user_id = u.id
    LEFT JOIN ba_price_zone_config pz ON ci.zone_id = pz.id
    LEFT JOIN ba_user_consignment_coupon ucc ON (
        ucc.user_id = co.user_id 
        AND ucc.session_id = ci.session_id 
        AND ucc.zone_id = ci.zone_id
        AND ucc.create_time >= ?
    )
    WHERE co.create_time >= ?
    AND co.status = 'paid'
    AND u.user_type >= 2
    AND ci.session_id > 0
    AND ci.zone_id > 0
    AND ucc.id IS NULL
    ORDER BY co.user_id, ci.session_id, ci.zone_id
", [$startTime, $startTime]);

echo "需要补发的订单数: " . count($missingCoupons) . "\n\n";

if (count($missingCoupons) == 0) {
    echo "✅ 没有需要补发的寄售券\n";
    exit(0);
}

// 按用户+场次+区域去重（每个组合只发一张券）
$couponMap = [];
foreach ($missingCoupons as $order) {
    $key = $order['user_id'] . '_' . $order['session_id'] . '_' . $order['zone_id'];
    if (!isset($couponMap[$key])) {
        $couponMap[$key] = $order;
    }
}

echo "去重后需要补发的券数: " . count($couponMap) . "\n\n";

$successCount = 0;
$skipCount = 0;
$failCount = 0;

echo "=== 开始补发 ===\n";

foreach ($couponMap as $key => $order) {
    $userId = $order['user_id'];
    $sessionId = $order['session_id'];
    $zoneId = $order['zone_id'];
    $zoneName = $order['zone_name'] ?? "区域#$zoneId";
    
    // 再次检查是否已有券（防止并发）
    $existingCoupon = Db::name('user_consignment_coupon')
        ->where('user_id', $userId)
        ->where('session_id', $sessionId)
        ->where('zone_id', $zoneId)
        ->where('create_time', '>=', $startTime)
        ->find();
    
    if ($existingCoupon) {
        echo "用户 $userId 场次$sessionId/区域$zoneId: 已有券，跳过\n";
        $skipCount++;
        continue;
    }
    
    if ($dryRun) {
        echo "用户 $userId ({$order['mobile']}) 场次$sessionId/区域$zoneId ($zoneName): 将补发券\n";
        $successCount++;
        continue;
    }
    
    // 实际补发
    Db::startTrans();
    try {
        // 再次检查（事务内）
        $checkAgain = Db::name('user_consignment_coupon')
            ->where('user_id', $userId)
            ->where('session_id', $sessionId)
            ->where('zone_id', $zoneId)
            ->where('create_time', '>=', $startTime)
            ->lock(true)
            ->find();
        
        if ($checkAgain) {
            Db::rollback();
            echo "用户 $userId 场次$sessionId/区域$zoneId: 并发检查已有券，跳过\n";
            $skipCount++;
            continue;
        }
        
        // 创建寄售券
        $expireTime = $now + 30 * 86400; // 30天有效期
        
        $couponId = Db::name('user_consignment_coupon')->insertGetId([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'zone_id' => $zoneId,
            'price_zone' => $zoneName,
            'expire_time' => $expireTime,
            'status' => 0, // 未使用
            'create_time' => $now,
            'update_time' => $now,
        ]);
        
        if ($couponId) {
            // 记录活动日志
            Db::name('user_activity_log')->insert([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'consignment_coupon_issued',
                'change_field' => 'consignment_coupon',
                'change_value' => 1,
                'before_value' => 0,
                'after_value' => 1,
                'remark' => "补发寄售券：{$zoneName}（场次#{$sessionId}）",
                'extra' => json_encode([
                    'coupon_id' => $couponId,
                    'session_id' => $sessionId,
                    'zone_id' => $zoneId,
                    'zone_name' => $zoneName,
                    'expire_time' => $expireTime,
                    'trigger' => 'batch_补发',
                    '补发原因' => '订单发放遗漏',
                    '补发时间' => date('Y-m-d H:i:s', $now),
                ], JSON_UNESCAPED_UNICODE),
                'create_time' => $now,
                'update_time' => $now,
            ]);
            
            Db::commit();
            echo "用户 $userId ({$order['mobile']}) 场次$sessionId/区域$zoneId: ✅ 补发成功 (券ID: $couponId)\n";
            $successCount++;
            
            Log::info('补发寄售券', [
                'user_id' => $userId,
                'coupon_id' => $couponId,
                'session_id' => $sessionId,
                'zone_id' => $zoneId,
            ]);
        } else {
            throw new \Exception('创建券失败');
        }
        
    } catch (\Throwable $e) {
        Db::rollback();
        echo "用户 $userId 场次$sessionId/区域$zoneId: ❌ 补发失败 - {$e->getMessage()}\n";
        $failCount++;
        
        Log::error('补发寄售券失败', [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'zone_id' => $zoneId,
            'error' => $e->getMessage(),
        ]);
    }
}

// 输出统计
echo "\n" . str_repeat("=", 70) . "\n";
echo "$mode 执行完成\n";
echo str_repeat("=", 70) . "\n";
echo "需要补发: " . count($couponMap) . "\n";
echo "成功: $successCount\n";
echo "跳过: $skipCount\n";
echo "失败: $failCount\n";
echo str_repeat("=", 70) . "\n";

if ($dryRun) {
    echo "\n⚠️ 这是模拟运行，没有实际执行任何操作\n";
    echo "确认无误后，请使用 --execute 参数执行实际补发\n";
}
