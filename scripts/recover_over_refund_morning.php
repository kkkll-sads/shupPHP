<?php
/**
 * 上午场多退回退款扣回脚本（场次1，00:00-14:00）
 *
 * 用法：
 *   模拟运行: php scripts/recover_over_refund_morning.php --dry-run
 *   实际执行: php scripts/recover_over_refund_morning.php --execute
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

use think\facade\Db;

$dryRun = in_array('--dry-run', $argv, true);
$execute = in_array('--execute', $argv, true);

if (!$dryRun && !$execute) {
    echo "用法：\n";
    echo "  模拟运行: php scripts/recover_over_refund_morning.php --dry-run\n";
    echo "  实际执行: php scripts/recover_over_refund_morning.php --execute\n";
    exit(1);
}

$mode = $dryRun ? '【模拟运行】' : '【实际执行】';
$now = time();
$startTime = strtotime('2026-01-16 00:00:00');
$endTime = strtotime('2026-01-16 14:00:00');

echo "========================================\n";
echo "{$mode} 上午场多退回扣回\n";
echo "执行时间: " . date('Y-m-d H:i:s', $now) . "\n";
echo "时间范围: " . date('Y-m-d H:i:s', $startTime) . " ~ " . date('Y-m-d H:i:s', $endTime) . "\n";
echo "========================================\n\n";

// 导出路径
$exportDir = __DIR__ . '/../exports';
if (!is_dir($exportDir)) {
    mkdir($exportDir, 0755, true);
}
$csvPath = $exportDir . '/over_refund_morning_20260116.csv';

// 查找多退回列表（按预约记录汇总）
$rows = Db::query("
    SELECT
        r.id AS reservation_id,
        r.user_id,
        u.mobile,
        r.status,
        r.freeze_amount,
        IFNULL(SUM(CASE WHEN uml.biz_type IN ('blind_box_refund','blind_box_diff_refund','matching_refund') THEN uml.money ELSE 0 END),0) AS refund_sum,
        CASE
            WHEN r.status = 2 THEN r.freeze_amount
            WHEN r.status = 1 THEN GREATEST(r.freeze_amount - IFNULL(SUM(oi.price * oi.quantity),0), 0)
            ELSE 0
        END AS expected_refund
    FROM ba_trade_reservations r
    LEFT JOIN ba_user u ON u.id = r.user_id
    LEFT JOIN ba_collection_order co ON co.id = r.match_order_id
    LEFT JOIN ba_collection_order_item oi ON oi.order_id = co.id
    LEFT JOIN ba_user_money_log uml ON uml.biz_id = r.id
        AND uml.user_id = r.user_id
        AND uml.biz_type IN ('blind_box_refund','blind_box_diff_refund','matching_refund')
        AND uml.create_time >= ?
        AND uml.create_time < ?
    WHERE r.session_id = 1
    AND r.update_time >= ?
    AND r.update_time < ?
    GROUP BY r.id
    HAVING refund_sum > expected_refund + 0.01
    ORDER BY refund_sum - expected_refund DESC
", [$startTime, $endTime, $startTime, $endTime]);

$totalCount = count($rows);
echo "多退回记录数: {$totalCount}\n";

// 导出CSV
$fp = fopen($csvPath, 'w');
fputcsv($fp, ['reservation_id', 'user_id', 'mobile', 'status', 'freeze_amount', 'refund_sum', 'expected_refund', 'over_amount']);
foreach ($rows as $row) {
    $overAmount = round((float)$row['refund_sum'] - (float)$row['expected_refund'], 2);
    fputcsv($fp, [
        $row['reservation_id'],
        $row['user_id'],
        $row['mobile'],
        $row['status'],
        $row['freeze_amount'],
        $row['refund_sum'],
        $row['expected_refund'],
        $overAmount,
    ]);
}
fclose($fp);
echo "导出完成: {$csvPath}\n\n";

if ($dryRun) {
    echo "✅ 模拟完成，未执行扣回\n";
    exit(0);
}

$success = 0;
$skipped = 0;
$failed = 0;
$totalRecovered = 0.0;

foreach ($rows as $row) {
    $reservationId = (int)$row['reservation_id'];
    $userId = (int)$row['user_id'];
    $overAmount = round((float)$row['refund_sum'] - (float)$row['expected_refund'], 2);
    if ($overAmount <= 0) {
        $skipped++;
        continue;
    }

    // 幂等检查：避免重复扣回
    $exists = Db::name('user_money_log')
        ->where('user_id', $userId)
        ->where('biz_type', 'blind_box_over_refund_recover')
        ->where('biz_id', $reservationId)
        ->count();
    if ($exists > 0) {
        $skipped++;
        continue;
    }

    Db::startTrans();
    try {
        $user = Db::name('user')->where('id', $userId)->lock(true)->find();
        if (!$user) {
            throw new \Exception('用户不存在');
        }

        $beforeBalance = (float)($user['balance_available'] ?? 0);
        $afterBalance = round($beforeBalance - $overAmount, 2);

        Db::name('user')
            ->where('id', $userId)
            ->update([
                'balance_available' => $afterBalance,
                'update_time' => $now,
            ]);

        $flowNo = generateSJSFlowNo($userId);
        $batchNo = generateBatchNo('OVER_REFUND_RECOVER', $reservationId);

        Db::name('user_money_log')->insert([
            'user_id' => $userId,
            'flow_no' => $flowNo,
            'batch_no' => $batchNo,
            'biz_type' => 'blind_box_over_refund_recover',
            'biz_id' => $reservationId,
            'field_type' => 'balance_available',
            'money' => -$overAmount,
            'before' => $beforeBalance,
            'after' => $afterBalance,
            'memo' => '上午场多退回扣回',
            'create_time' => $now,
        ]);

        Db::name('user_activity_log')->insert([
            'user_id' => $userId,
            'related_user_id' => 0,
            'action_type' => 'refund_recover',
            'change_field' => 'balance_available',
            'change_value' => (string)(-$overAmount),
            'before_value' => (string)$beforeBalance,
            'after_value' => (string)$afterBalance,
            'remark' => '上午场多退回扣回',
            'extra' => json_encode([
                'reservation_id' => $reservationId,
                'over_amount' => $overAmount,
            ], JSON_UNESCAPED_UNICODE),
            'create_time' => $now,
            'update_time' => $now,
        ]);

        Db::commit();
        $success++;
        $totalRecovered += $overAmount;
    } catch (\Throwable $e) {
        Db::rollback();
        $failed++;
        echo "❌ 预约{$reservationId} 用户{$userId} 失败: " . $e->getMessage() . "\n";
    }
}

echo "\n=== 扣回完成 ===\n";
echo "成功: {$success}\n";
echo "跳过(已处理/无金额): {$skipped}\n";
echo "失败: {$failed}\n";
echo "累计扣回金额: " . number_format($totalRecovered, 2) . "\n";

