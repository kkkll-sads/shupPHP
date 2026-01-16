<?php
/**
 * 1月7号注册用户补偿脚本
 * 补偿内容：8.8元【可调度收益】红包（发放到可提现余额）
 * 
 * 用法: php compensate_jan7_users.php [--dry-run]
 */

require __DIR__ . '/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use think\facade\Db;

$isDryRun = in_array('--dry-run', $argv);

echo "=== 1月7号注册用户补偿发放脚本 ===\n";
if ($isDryRun) {
    echo "⚠️  干运行模式（不会实际发放）\n";
}
echo "补偿金额: 8.8 元（可提现余额）\n";
echo "日期: 2026-01-07\n\n";

// 查询1月7号注册的所有用户
$dateStart = strtotime('2026-01-07 00:00:00');
$dateEnd = strtotime('2026-01-08 00:00:00') - 1;

$users = Db::name('user')
    ->where('create_time', '>=', $dateStart)
    ->where('create_time', '<=', $dateEnd)
    ->order('id asc')
    ->select()
    ->toArray();

if (empty($users)) {
    echo "没有找到1月7号注册的用户\n";
    exit(0);
}

echo "找到 " . count($users) . " 个1月7号注册的用户\n\n";

$compensationAmount = 8.8;
$successCount = 0;
$failCount = 0;
$totalAmount = 0;

foreach ($users as $user) {
    try {
        $userId = $user['id'];
        $mobile = $user['mobile'] ?? $user['username'] ?? 'ID:' . $userId;
        
        if ($isDryRun) {
            echo "✓ [干运行] 用户 {$mobile} (ID: {$userId}) - 应发放 {$compensationAmount} 元\n";
            $successCount++;
            $totalAmount += $compensationAmount;
            continue;
        }
        
        Db::startTrans();
        
        // 获取用户当前可提现余额
        $userInfo = Db::name('user')->where('id', $userId)->lock(true)->find();
        if (!$userInfo) {
            Db::rollback();
            echo "✗ 用户 {$mobile} (ID: {$userId}) 不存在，跳过\n";
            $failCount++;
            continue;
        }
        
        $beforeWithdrawable = (float)($userInfo['withdrawable_money'] ?? 0);
        $afterWithdrawable = round($beforeWithdrawable + $compensationAmount, 2);
        
        // 更新用户可提现余额
        Db::name('user')
            ->where('id', $userId)
            ->update([
                'withdrawable_money' => $afterWithdrawable,
                'update_time' => time(),
            ]);
        
        $now = time();
        $flowNo = generateSJSFlowNo($userId);
        $batchNo = generateBatchNo('COMPENSATE_JAN7', $userId);
        
        // 记录资金变动日志
        Db::name('user_money_log')->insert([
            'user_id' => $userId,
            'field_type' => 'withdrawable_money',
            'money' => $compensationAmount,
            'before' => $beforeWithdrawable,
            'after' => $afterWithdrawable,
            'memo' => '1月7号注册用户补偿【可调度收益】红包：8.8元',
            'flow_no' => $flowNo,
            'batch_no' => $batchNo,
            'biz_type' => 'compensation',
            'biz_id' => 0,
            'create_time' => $now,
        ]);
        
        // 记录活动日志
        Db::name('user_activity_log')->insert([
            'user_id' => $userId,
            'related_user_id' => 0,
            'action_type' => 'compensation',
            'change_field' => 'withdrawable_money',
            'change_value' => (string)$compensationAmount,
            'before_value' => (string)$beforeWithdrawable,
            'after_value' => (string)$afterWithdrawable,
            'remark' => '1月7号注册用户补偿【可调度收益】红包：8.8元',
            'extra' => json_encode([
                'compensation_type' => 'jan7_registration',
                'compensation_amount' => (string)$compensationAmount,
                'registration_date' => '2026-01-07',
            ], JSON_UNESCAPED_UNICODE),
            'create_time' => $now,
            'update_time' => $now,
        ]);
        
        Db::commit();
        
        $successCount++;
        $totalAmount += $compensationAmount;
        echo "✓ 用户 {$mobile} (ID: {$userId}) 补偿发放成功：+{$compensationAmount} 元\n";
        
    } catch (\Throwable $e) {
        if (!$isDryRun) {
            Db::rollback();
        }
        $failCount++;
        $mobile = $user['mobile'] ?? $user['username'] ?? 'ID:' . $user['id'];
        echo "✗ 用户 {$mobile} 补偿发放失败：" . $e->getMessage() . "\n";
    }
}

echo "\n=== 发放完成 ===\n";
echo "成功: {$successCount} 个用户\n";
echo "失败: {$failCount} 个用户\n";
echo "总发放金额: " . round($totalAmount, 2) . " 元\n";

if ($isDryRun) {
    echo "\n⚠️  这是干运行模式，未实际发放。如需实际发放，请去掉 --dry-run 参数。\n";
}
