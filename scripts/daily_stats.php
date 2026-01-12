<?php
/**
 * 每日统计数据查询
 * 查询指定日期的注册、赠送和订单数据
 * php scripts/daily_stats.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use think\facade\Db;

// 初始化应用
$app = new think\App(dirname(__DIR__));
$app->initialize();

// 获取日期参数（默认今天）
$date = $argv[1] ?? date('Y-m-d');
$startTime = strtotime($date . ' 00:00:00');
$endTime = strtotime($date . ' 23:59:59');

echo "\n";
echo "===========================================\n";
echo "          每日数据统计报表\n";
echo "===========================================\n";
echo "统计日期: $date\n";
echo "时间范围: " . date('Y-m-d H:i:s', $startTime) . " ~ " . date('Y-m-d H:i:s', $endTime) . "\n";
echo "-------------------------------------------\n\n";

// ========================================
// 1. 注册相关统计
// ========================================
echo "📊 【注册统计】\n";
echo "-------------------------------------------\n";

// 统计今天注册的用户数
$registerCount = Db::name('user')
    ->where('create_time', '>=', $startTime)
    ->where('create_time', '<=', $endTime)
    ->count();

echo "新增注册用户: {$registerCount} 人\n";

// 查询注册赠送的金额（从资金流水表）
$registerRewards = Db::name('user_money_log')
    ->where('biz_type', 'register_reward')
    ->where('create_time', '>=', $startTime)
    ->where('create_time', '<=', $endTime)
    ->select()
    ->toArray();

$registerRewardStats = [
    'count' => count($registerRewards),
    'balance_available' => 0,
    'withdrawable_money' => 0,
    'service_fee_balance' => 0,
    'score' => 0,
    'total_amount' => 0,
];

foreach ($registerRewards as $record) {
    $fieldType = $record['field_type'];
    $amount = floatval($record['money']);
    
    if (isset($registerRewardStats[$fieldType])) {
        $registerRewardStats[$fieldType] += $amount;
    }
    $registerRewardStats['total_amount'] += $amount;
}


echo "注册赠送记录: {$registerRewardStats['count']} 条\n";
echo "  - 专项金赠送: " . number_format($registerRewardStats['balance_available'], 2) . " 元\n";
echo "  - 可提现金额: " . number_format($registerRewardStats['withdrawable_money'], 2) . " 元\n";
echo "  - 确权金赠送: " . number_format($registerRewardStats['service_fee_balance'], 2) . " 元\n";
echo "  - 消费金赠送: " . number_format($registerRewardStats['score'], 2) . " 元\n";
echo "  - 总赠送金额: " . number_format($registerRewardStats['total_amount'], 2) . " 元\n";

// ========================================
// 2. 邀请奖励统计
// ========================================
echo "\n📊 【邀请奖励统计】\n";
echo "-------------------------------------------\n";

$inviteRewards = Db::name('user_money_log')
    ->where('biz_type', 'invite_reward')
    ->where('create_time', '>=', $startTime)
    ->where('create_time', '<=', $endTime)
    ->select()
    ->toArray();

$inviteRewardStats = [
    'count' => count($inviteRewards),
    'balance_available' => 0,
    'withdrawable_money' => 0,
    'service_fee_balance' => 0,
    'score' => 0,
    'total_amount' => 0,
];

foreach ($inviteRewards as $record) {
    $fieldType = $record['field_type'];
    $amount = floatval($record['money']);
    
    if (isset($inviteRewardStats[$fieldType])) {
        $inviteRewardStats[$fieldType] += $amount;
    }
    $inviteRewardStats['total_amount'] += $amount;
}


echo "邀请奖励记录: {$inviteRewardStats['count']} 条\n";
echo "  - 专项金赠送: " . number_format($inviteRewardStats['balance_available'], 2) . " 元\n";
echo "  - 可提现金额: " . number_format($inviteRewardStats['withdrawable_money'], 2) . " 元\n";
echo "  - 确权金赠送: " . number_format($inviteRewardStats['service_fee_balance'], 2) . " 元\n";
echo "  - 消费金赠送: " . number_format($inviteRewardStats['score'], 2) . " 元\n";
echo "  - 总奖励金额: " . number_format($inviteRewardStats['total_amount'], 2) . " 元\n";

// ========================================
// 3. 藏品订单统计
// ========================================
echo "\n📊 【藏品订单统计】\n";
echo "-------------------------------------------\n";

// 统计今天的藏品订单
$orders = Db::name('collection_order')
    ->where('create_time', '>=', $startTime)
    ->where('create_time', '<=', $endTime)
    ->select()
    ->toArray();

$orderStats = [
    'total_count' => count($orders),
    'paid_count' => 0,
    'balance_payment' => 0,
    'score_payment' => 0,
    'total_amount' => 0,
];

foreach ($orders as $order) {
    $amount = floatval($order['total_amount']);
    $payType = $order['pay_type'] ?? '';
    
    if (in_array($order['status'], ['paid', 'completed'])) {
        $orderStats['paid_count']++;
        $orderStats['total_amount'] += $amount;
        
        if ($payType === 'money' || $payType === 'balance') {
            $orderStats['balance_payment'] += $amount;
        } elseif ($payType === 'score') {
            $orderStats['score_payment'] += $amount;
        }
    }
}

echo "订单总数: {$orderStats['total_count']} 单\n";
echo "已支付订单: {$orderStats['paid_count']} 单\n";
echo "  - 余额支付总额: " . number_format($orderStats['balance_payment'], 2) . " 元\n";
echo "  - 消费金支付总额: " . number_format($orderStats['score_payment'], 2) . " 元\n";
echo "  - 订单总金额: " . number_format($orderStats['total_amount'], 2) . " 元\n";

// ========================================
// 4. 综合汇总
// ========================================
echo "\n📊 【综合汇总】\n";
echo "===========================================\n";

$totalRewards = $registerRewardStats['total_amount'] + $inviteRewardStats['total_amount'];

echo "总注册人数: {$registerCount} 人\n";
echo "总赠送金额: " . number_format($totalRewards, 2) . " 元\n";
echo "  └─ 注册赠送: " . number_format($registerRewardStats['total_amount'], 2) . " 元\n";
echo "  └─ 邀请奖励: " . number_format($inviteRewardStats['total_amount'], 2) . " 元\n";
echo "\n";
echo "藏品订单数: {$orderStats['paid_count']} 单\n";
echo "订单总金额: " . number_format($orderStats['total_amount'], 2) . " 元\n";
echo "  └─ 余额支付: " . number_format($orderStats['balance_payment'], 2) . " 元\n";
echo "  └─ 消费金支付: " . number_format($orderStats['score_payment'], 2) . " 元\n";
echo "\n";

// 计算收支情况
$netFlow = $orderStats['balance_payment'] - $totalRewards;
echo "余额收支情况:\n";
echo "  - 收入(订单): +" . number_format($orderStats['balance_payment'], 2) . " 元\n";
echo "  - 支出(赠送): -" . number_format($totalRewards, 2) . " 元\n";
echo "  - 净流入: " . ($netFlow >= 0 ? '+' : '') . number_format($netFlow, 2) . " 元\n";

echo "===========================================\n\n";
