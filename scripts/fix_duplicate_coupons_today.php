<?php
/**
 * 修复今日重复寄售券（同一用户+场次+区间仅保留1张）
 *
 * 输出CSV：exports/duplicate_coupons_today_20260116.csv
 *
 * 用法：
 *   模拟运行: php scripts/fix_duplicate_coupons_today.php --dry-run
 *   实际执行: php scripts/fix_duplicate_coupons_today.php --execute
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

use think\facade\Db;

$dryRun = in_array('--dry-run', $argv, true);
$execute = in_array('--execute', $argv, true);

if (!$dryRun && !$execute) {
    echo "用法：\n";
    echo "  模拟运行: php scripts/fix_duplicate_coupons_today.php --dry-run\n";
    echo "  实际执行: php scripts/fix_duplicate_coupons_today.php --execute\n";
    exit(1);
}

$mode = $dryRun ? '【模拟运行】' : '【实际执行】';
$now = time();
$startTime = strtotime('2026-01-16 00:00:00');

echo "========================================\n";
echo "{$mode} 修复今日重复寄售券\n";
echo "执行时间: " . date('Y-m-d H:i:s', $now) . "\n";
echo "统计范围: " . date('Y-m-d H:i:s', $startTime) . " ~ 现在\n";
echo "========================================\n\n";

// 导出路径
$exportDir = __DIR__ . '/../exports';
if (!is_dir($exportDir)) {
    mkdir($exportDir, 0755, true);
}
$csvPath = $exportDir . '/duplicate_coupons_today_20260116.csv';

// 找出重复券（同一用户+场次+区间）
$dupGroups = Db::query("
    SELECT user_id, session_id, zone_id, COUNT(*) as cnt
    FROM ba_user_consignment_coupon
    WHERE create_time >= ?
    GROUP BY user_id, session_id, zone_id
    HAVING COUNT(*) > 1
", [$startTime]);

echo "重复组数: " . count($dupGroups) . "\n";

// 写CSV头
$fp = fopen($csvPath, 'w');
fputcsv($fp, [
    'user_id', 'mobile', 'session_id', 'zone_id',
    'total_coupons', 'keep_coupon_id', 'remove_coupon_ids', 'removed_count'
]);

$totalRemove = 0;
$totalGroups = 0;
$userTouched = [];

foreach ($dupGroups as $group) {
    $userId = (int)$group['user_id'];
    $sessionId = (int)$group['session_id'];
    $zoneId = (int)$group['zone_id'];

    // 获取该组所有券ID，保留最新一张（ID最大）
    $couponIds = Db::name('user_consignment_coupon')
        ->where('user_id', $userId)
        ->where('session_id', $sessionId)
        ->where('zone_id', $zoneId)
        ->where('create_time', '>=', $startTime)
        ->order('id asc')
        ->column('id');

    if (count($couponIds) <= 1) {
        continue;
    }

    $keepId = max($couponIds);
    $removeIds = array_values(array_diff($couponIds, [$keepId]));
    $removeCount = count($removeIds);

    $mobile = Db::name('user')->where('id', $userId)->value('mobile');

    // 写CSV
    fputcsv($fp, [
        $userId, $mobile, $sessionId, $zoneId,
        count($couponIds), $keepId, implode('|', $removeIds), $removeCount
    ]);

    $totalGroups++;
    $totalRemove += $removeCount;
    $userTouched[$userId] = true;

    if ($execute) {
        Db::startTrans();
        try {
            // 删除多余券
            Db::name('user_consignment_coupon')->whereIn('id', $removeIds)->delete();

            // 重新统计用户有效券数量
            $activeCount = Db::name('user_consignment_coupon')
                ->where('user_id', $userId)
                ->where('status', 1)
                ->where('expire_time', '>', $now)
                ->count();

            Db::name('user')->where('id', $userId)->update([
                'consignment_coupon' => $activeCount,
                'update_time' => $now,
            ]);

            // 记录活动日志（批量修复）
            Db::name('user_activity_log')->insert([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'consignment_coupon_fix',
                'change_field' => 'consignment_coupon',
                'change_value' => -$removeCount,
                'before_value' => $activeCount + $removeCount,
                'after_value' => $activeCount,
                'remark' => "修复重复寄售券：场次#{$sessionId} 区间#{$zoneId}，删除{$removeCount}张",
                'extra' => json_encode([
                    'session_id' => $sessionId,
                    'zone_id' => $zoneId,
                    'keep_id' => $keepId,
                    'remove_ids' => $removeIds,
                ], JSON_UNESCAPED_UNICODE),
                'create_time' => $now,
                'update_time' => $now,
            ]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            echo "❌ 用户{$userId} 场次{$sessionId} 区间{$zoneId} 修复失败: " . $e->getMessage() . "\n";
        }
    }
}

fclose($fp);

echo "\nCSV导出: {$csvPath}\n";
echo "重复组数: {$totalGroups}\n";
echo "删除券数: {$totalRemove}\n";
echo "影响用户数: " . count($userTouched) . "\n";

if ($dryRun) {
    echo "✅ 模拟完成，未执行删除\n";
}

