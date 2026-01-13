<?php
/**
 * 运营统计脚本
 * 统计签到金额、赠送金额、消费金等数据
 * 使用方法: php operation_stats.php [日期]  (默认今天，格式: Y-m-d)
 */

require __DIR__ . '/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use think\facade\Db;

// 获取日期参数（默认今天）
$date = $argv[1] ?? date('Y-m-d');
$startTime = strtotime($date . ' 00:00:00');
$endTime = strtotime($date . ' 23:59:59');

echo "\n";
echo "===========================================\n";
echo "          运营统计数据报表\n";
echo "===========================================\n";
echo "统计日期: {$date}\n";
echo "时间范围: " . date('Y-m-d H:i:s', $startTime) . " ~ " . date('Y-m-d H:i:s', $endTime) . "\n";
echo "===========================================\n\n";

// ========================================
// 1. 签到金额统计
// ========================================
echo "📊 【签到金额统计】\n";
echo "-------------------------------------------\n";

$signInLogs = Db::name('user_money_log')
    ->where('biz_type', 'sign_in')
    ->where('create_time', '>=', $startTime)
    ->where('create_time', '<=', $endTime)
    ->select()
    ->toArray();

$signInStats = [
    'count' => count($signInLogs),
    'withdrawable_money' => 0,  // 可提现金额（签到奖励）
    'score' => 0,               // 消费金（签到奖励）
    'total_amount' => 0,
];

foreach ($signInLogs as $log) {
    $fieldType = $log['field_type'];
    $amount = (float)$log['money'];
    
    if ($fieldType === 'withdrawable_money') {
        $signInStats['withdrawable_money'] += $amount;
    } elseif ($fieldType === 'score') {
        $signInStats['score'] += $amount;
    }
    $signInStats['total_amount'] += $amount;
}

// 同时统计签到记录数（从user_sign_in表）
$signInRecords = Db::name('user_sign_in')
    ->where('sign_date', $date)
    ->count();

echo "签到记录数: {$signInRecords} 条\n";
echo "签到奖励流水: {$signInStats['count']} 条\n";
echo "  - 可提现金额奖励: ¥" . number_format($signInStats['withdrawable_money'], 2) . "\n";
echo "  - 消费金奖励: " . number_format($signInStats['score'], 0) . " 分\n";
echo "  - 签到总奖励: ¥" . number_format($signInStats['total_amount'], 2) . "\n";

// ========================================
// 2. 赠送金额统计
// ========================================
echo "\n📊 【赠送金额统计】\n";
echo "-------------------------------------------\n";

// 2.1 注册赠送
$registerRewards = Db::name('user_money_log')
    ->where('biz_type', 'register_reward')
    ->where('create_time', '>=', $startTime)
    ->where('create_time', '<=', $endTime)
    ->select()
    ->toArray();

$registerStats = [
    'count' => count($registerRewards),
    'balance_available' => 0,
    'withdrawable_money' => 0,
    'service_fee_balance' => 0,
    'score' => 0,
    'total_amount' => 0,
];

foreach ($registerRewards as $record) {
    $fieldType = $record['field_type'];
    $amount = (float)$record['money'];
    
    if (isset($registerStats[$fieldType])) {
        $registerStats[$fieldType] += $amount;
    }
    $registerStats['total_amount'] += $amount;
}

echo "【注册赠送】\n";
echo "  记录数: {$registerStats['count']} 条\n";
echo "  - 专项金赠送: ¥" . number_format($registerStats['balance_available'], 2) . "\n";
echo "  - 可提现金额: ¥" . number_format($registerStats['withdrawable_money'], 2) . "\n";
echo "  - 确权金赠送: ¥" . number_format($registerStats['service_fee_balance'], 2) . "\n";
echo "  - 消费金赠送: " . number_format($registerStats['score'], 0) . " 分\n";
echo "  - 总赠送金额: ¥" . number_format($registerStats['total_amount'], 2) . "\n";

// 2.2 邀请奖励
$inviteRewards = Db::name('user_money_log')
    ->where('biz_type', 'invite_reward')
    ->where('create_time', '>=', $startTime)
    ->where('create_time', '<=', $endTime)
    ->select()
    ->toArray();

$inviteStats = [
    'count' => count($inviteRewards),
    'balance_available' => 0,
    'withdrawable_money' => 0,
    'service_fee_balance' => 0,
    'score' => 0,
    'total_amount' => 0,
];

foreach ($inviteRewards as $record) {
    $fieldType = $record['field_type'];
    $amount = (float)$record['money'];
    
    if (isset($inviteStats[$fieldType])) {
        $inviteStats[$fieldType] += $amount;
    }
    $inviteStats['total_amount'] += $amount;
}

echo "\n【邀请奖励】\n";
echo "  记录数: {$inviteStats['count']} 条\n";
echo "  - 专项金赠送: ¥" . number_format($inviteStats['balance_available'], 2) . "\n";
echo "  - 可提现金额: ¥" . number_format($inviteStats['withdrawable_money'], 2) . "\n";
echo "  - 确权金赠送: ¥" . number_format($inviteStats['service_fee_balance'], 2) . "\n";
echo "  - 消费金赠送: " . number_format($inviteStats['score'], 0) . " 分\n";
echo "  - 总奖励金额: ¥" . number_format($inviteStats['total_amount'], 2) . "\n";

// 2.3 其他赠送类型（补偿、活动奖励等）
$otherRewards = Db::name('user_money_log')
    ->where('create_time', '>=', $startTime)
    ->where('create_time', '<=', $endTime)
    ->whereIn('biz_type', [
        'compensation',
        'activity_reward',
        'compensate_jan7_register',
        'first_trade_reward',
        'invite_reward_power',
        'invite_reward_power_retro',
    ])
    ->select()
    ->toArray();

$otherStats = [
    'count' => count($otherRewards),
    'withdrawable_money' => 0,
    'score' => 0,
    'green_power' => 0,
    'total_amount' => 0,
];

foreach ($otherRewards as $record) {
    $fieldType = $record['field_type'];
    $amount = (float)$record['money'];
    
    if ($fieldType === 'withdrawable_money') {
        $otherStats['withdrawable_money'] += $amount;
    } elseif ($fieldType === 'score') {
        $otherStats['score'] += $amount;
    } elseif ($fieldType === 'green_power') {
        $otherStats['green_power'] += $amount;
    }
    $otherStats['total_amount'] += $amount;
}

echo "\n【其他赠送（补偿/活动等）】\n";
echo "  记录数: {$otherStats['count']} 条\n";
echo "  - 可提现金额: ¥" . number_format($otherStats['withdrawable_money'], 2) . "\n";
echo "  - 消费金: " . number_format($otherStats['score'], 0) . " 分\n";
echo "  - 算力: " . number_format($otherStats['green_power'], 2) . "\n";
echo "  - 总赠送金额: ¥" . number_format($otherStats['total_amount'], 2) . "\n";

// 赠送总额汇总
$totalGiftAmount = $registerStats['total_amount'] + $inviteStats['total_amount'] + $otherStats['total_amount'];
echo "\n【赠送总额汇总】\n";
echo "  - 注册赠送: ¥" . number_format($registerStats['total_amount'], 2) . "\n";
echo "  - 邀请奖励: ¥" . number_format($inviteStats['total_amount'], 2) . "\n";
echo "  - 其他赠送: ¥" . number_format($otherStats['total_amount'], 2) . "\n";
echo "  - 总计: ¥" . number_format($totalGiftAmount, 2) . "\n";

// ========================================
// 3. 消费金统计
// ========================================
echo "\n📊 【消费金统计】\n";
echo "-------------------------------------------\n";

// 3.1 消费金收入（赠送、签到等）
$scoreIncome = Db::name('user_money_log')
    ->where('field_type', 'score')
    ->where('money', '>', 0)  // 只统计增加
    ->where('create_time', '>=', $startTime)
    ->where('create_time', '<=', $endTime)
    ->sum('money');

$scoreIncome = (int)$scoreIncome;  // 消费金以分为单位

// 3.2 消费金支出（消费、兑换等）
$scoreExpense = Db::name('user_money_log')
    ->where('field_type', 'score')
    ->where('money', '<', 0)  // 只统计减少
    ->where('create_time', '>=', $startTime)
    ->where('create_time', '<=', $endTime)
    ->sum('money');

$scoreExpense = abs((int)$scoreExpense);  // 转为正数

// 3.3 消费金净流入
$scoreNetFlow = $scoreIncome - $scoreExpense;

// 3.4 按业务类型统计消费金收入
$scoreIncomeByType = Db::name('user_money_log')
    ->where('field_type', 'score')
    ->where('money', '>', 0)
    ->where('create_time', '>=', $startTime)
    ->where('create_time', '<=', $endTime)
    ->field('biz_type, SUM(money) as total')
    ->group('biz_type')
    ->select()
    ->toArray();

echo "【消费金收入】\n";
echo "  总收入: " . number_format($scoreIncome, 0) . " 分 (¥" . number_format($scoreIncome / 100, 2) . ")\n";
if (!empty($scoreIncomeByType)) {
    echo "  按业务类型:\n";
    foreach ($scoreIncomeByType as $item) {
        $typeName = $item['biz_type'] ?: '未知';
        $amount = (int)$item['total'];
        echo "    - {$typeName}: " . number_format($amount, 0) . " 分\n";
    }
}

// 3.5 按业务类型统计消费金支出
$scoreExpenseByType = Db::name('user_money_log')
    ->where('field_type', 'score')
    ->where('money', '<', 0)
    ->where('create_time', '>=', $startTime)
    ->where('create_time', '<=', $endTime)
    ->field('biz_type, SUM(ABS(money)) as total')
    ->group('biz_type')
    ->select()
    ->toArray();

echo "\n【消费金支出】\n";
echo "  总支出: " . number_format($scoreExpense, 0) . " 分 (¥" . number_format($scoreExpense / 100, 2) . ")\n";
if (!empty($scoreExpenseByType)) {
    echo "  按业务类型:\n";
    foreach ($scoreExpenseByType as $item) {
        $typeName = $item['biz_type'] ?: '未知';
        $amount = (int)$item['total'];
        echo "    - {$typeName}: " . number_format($amount, 0) . " 分\n";
    }
}

echo "\n【消费金净流入】\n";
echo "  净流入: " . number_format($scoreNetFlow, 0) . " 分 (¥" . number_format($scoreNetFlow / 100, 2) . ")\n";
echo "  (收入 - 支出 = " . number_format($scoreIncome, 0) . " - " . number_format($scoreExpense, 0) . ")\n";

// ========================================
// 4. 综合汇总
// ========================================
echo "\n📊 【综合汇总】\n";
echo "===========================================\n";

echo "【签到统计】\n";
echo "  签到人数: {$signInRecords} 人\n";
echo "  签到奖励总额: ¥" . number_format($signInStats['total_amount'], 2) . "\n";
echo "    - 可提现金额: ¥" . number_format($signInStats['withdrawable_money'], 2) . "\n";
echo "    - 消费金: " . number_format($signInStats['score'], 0) . " 分\n";

echo "\n【赠送统计】\n";
echo "  总赠送金额: ¥" . number_format($totalGiftAmount, 2) . "\n";
echo "    - 注册赠送: ¥" . number_format($registerStats['total_amount'], 2) . "\n";
echo "    - 邀请奖励: ¥" . number_format($inviteStats['total_amount'], 2) . "\n";
echo "    - 其他赠送: ¥" . number_format($otherStats['total_amount'], 2) . "\n";

echo "\n【消费金统计】\n";
echo "  总收入: " . number_format($scoreIncome, 0) . " 分 (¥" . number_format($scoreIncome / 100, 2) . ")\n";
echo "  总支出: " . number_format($scoreExpense, 0) . " 分 (¥" . number_format($scoreExpense / 100, 2) . ")\n";
echo "  净流入: " . number_format($scoreNetFlow, 0) . " 分 (¥" . number_format($scoreNetFlow / 100, 2) . ")\n";

echo "\n===========================================\n";
echo "统计完成！\n\n";
