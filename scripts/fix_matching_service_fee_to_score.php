<?php
/**
 * 修复寄售结算误发确权金（service_fee_balance），改为消费金（score）
 *
 * 输出CSV：exports/matching_service_fee_to_score_20260116.csv
 *
 * 用法：
 *   模拟运行: php scripts/fix_matching_service_fee_to_score.php --dry-run
 *   实际执行: php scripts/fix_matching_service_fee_to_score.php --execute
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

use think\facade\Db;

$dryRun = in_array('--dry-run', $argv, true);
$execute = in_array('--execute', $argv, true);

if (!$dryRun && !$execute) {
    echo "用法：\n";
    echo "  模拟运行: php scripts/fix_matching_service_fee_to_score.php --dry-run\n";
    echo "  实际执行: php scripts/fix_matching_service_fee_to_score.php --execute\n";
    exit(1);
}

$mode = $dryRun ? '【模拟运行】' : '【实际执行】';
$now = time();
$startTime = strtotime('2026-01-16 00:00:00');

echo "========================================\n";
echo "{$mode} 修复确权金 → 消费金\n";
echo "执行时间: " . date('Y-m-d H:i:s', $now) . "\n";
echo "统计范围: " . date('Y-m-d H:i:s', $startTime) . " ~ 现在\n";
echo "========================================\n\n";

// 导出路径
$exportDir = __DIR__ . '/../exports';
if (!is_dir($exportDir)) {
    mkdir($exportDir, 0755, true);
}
$csvPath = $exportDir . '/matching_service_fee_to_score_20260116.csv';

// 找出需要修复的日志（撮合卖家收益里确权金记录）
$logs = Db::query("
    SELECT id, user_id, biz_id, money, `before` as before_value, `after` as after_value, create_time
    FROM ba_user_money_log
    WHERE biz_type = 'consignment_settle'
      AND field_type = 'service_fee_balance'
      AND money > 0
      AND create_time >= ?
", [$startTime]);

echo "待修复记录数: " . count($logs) . "\n";

// 写CSV头
$fp = fopen($csvPath, 'w');
fputcsv($fp, [
    'log_id', 'user_id', 'biz_id', 'amount',
    'service_fee_balance_before', 'service_fee_balance_after',
    'user_score_before', 'user_score_after', 'status'
]);

$fixed = 0;
$skipped = 0;
$failed = 0;
$insufficient = 0;
$allowNegativeServiceFee = true; // 已允许 service_fee_balance 变负

foreach ($logs as $row) {
    $logId = (int)$row['id'];
    $userId = (int)$row['user_id'];
    $bizId = (int)$row['biz_id'];
    $amount = (float)$row['money'];

    if ($amount <= 0) {
        $skipped++;
        continue;
    }

    // 幂等：已修复则跳过
    $exists = Db::name('user_money_log')
        ->where('biz_type', 'fix_matching_income_to_score')
        ->where('biz_id', $logId)
        ->count();
    if ($exists > 0) {
        $skipped++;
        continue;
    }

    $user = Db::name('user')->where('id', $userId)->lock(true)->find();
    $beforeScore = (float)($user['score'] ?? 0);
    $beforeServiceFee = (float)($user['service_fee_balance'] ?? 0);

    if ($beforeServiceFee < $amount) {
        $insufficient++;
        fputcsv($fp, [
            $logId, $userId, $bizId, $amount,
            $beforeServiceFee, $beforeServiceFee, $beforeScore, $beforeScore, 'insufficient'
        ]);
        if (!$execute || !$allowNegativeServiceFee) {
            continue;
        }
    }

    $afterServiceFee = round($beforeServiceFee - $amount, 2);
    $afterScore = round($beforeScore + $amount, 2);

    fputcsv($fp, [
        $logId, $userId, $bizId, $amount,
        $beforeServiceFee, $afterServiceFee, $beforeScore, $afterScore, $execute ? 'fixed' : 'dry'
    ]);

    if ($execute) {
        Db::startTrans();
        try {
            // 更新用户余额
            Db::name('user')->where('id', $userId)->update([
                'service_fee_balance' => $afterServiceFee,
                'score' => $afterScore,
                'update_time' => $now,
            ]);

            // 记录service_fee_balance减少日志
            Db::name('user_money_log')->insert([
                'user_id' => $userId,
                'biz_type' => 'fix_matching_income_to_score',
                'biz_id' => $logId,
                'field_type' => 'service_fee_balance',
                'money' => -$amount,
                'before' => $beforeServiceFee,
                'after' => $afterServiceFee,
                'memo' => '修复确权金转消费金（寄售结算）',
                'create_time' => $now,
            ]);

            // 记录消费金日志
            Db::name('user_score_log')->insert([
                'user_id' => $userId,
                'biz_type' => 'fix_matching_income_to_score',
                'biz_id' => $logId,
                'score' => $amount,
                'before' => $beforeScore,
                'after' => $afterScore,
                'memo' => '修复确权金转消费金（寄售结算）',
                'create_time' => $now,
            ]);

            // 记录活动日志
            Db::name('user_activity_log')->insert([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'fix_matching_income_to_score',
                'change_field' => 'service_fee_balance,score',
                'change_value' => json_encode([
                    'service_fee_balance' => -$amount,
                    'score' => $amount,
                ], JSON_UNESCAPED_UNICODE),
                'before_value' => json_encode([
                    'service_fee_balance' => $beforeServiceFee,
                    'score' => $beforeScore,
                ], JSON_UNESCAPED_UNICODE),
                'after_value' => json_encode([
                    'service_fee_balance' => $afterServiceFee,
                    'score' => $afterScore,
                ], JSON_UNESCAPED_UNICODE),
                'remark' => '修复寄售结算：确权金转消费金',
                'extra' => json_encode([
                    'source_log_id' => $logId,
                    'biz_id' => $bizId,
                ], JSON_UNESCAPED_UNICODE),
                'create_time' => $now,
                'update_time' => $now,
            ]);

            Db::commit();
            $fixed++;
        } catch (\Throwable $e) {
            Db::rollback();
            $failed++;
            echo "❌ 用户{$userId} 日志{$logId} 修复失败: " . $e->getMessage() . "\n";
        }
    }
}

fclose($fp);

echo "\nCSV导出: {$csvPath}\n";
echo "待修复: " . count($logs) . "\n";
echo "修复成功: {$fixed}\n";
echo "跳过: {$skipped}\n";
echo "余额不足: {$insufficient}\n";
echo "失败: {$failed}\n";

if ($dryRun) {
    echo "✅ 模拟完成，未执行修复\n";
}

