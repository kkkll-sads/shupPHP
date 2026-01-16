<?php
/**
 * 核查用户 15905225585 的充值和资产情况
 */

require __DIR__ . '/vendor/autoload.php';

use think\facade\Db;

// 初始化 ThinkPHP 应用
$app = new think\App();
$app->initialize();

$mobile = '15905225585';

echo "=== 用户资产核查报告 ===\n";
echo "查询时间: " . date('Y-m-d H:i:s') . "\n";
echo "用户手机: {$mobile}\n\n";

try {
    // 1. 查询用户基本信息
    $user = Db::name('user')
        ->where('mobile', $mobile)
        ->find();
    
    if (!$user) {
        echo "❌ 未找到用户: {$mobile}\n";
        exit(1);
    }
    
    echo "=== 用户基本信息 ===\n";
    echo "用户ID: {$user['id']}\n";
    echo "昵称: {$user['nickname']}\n";
    echo "注册时间: " . date('Y-m-d H:i:s', $user['create_time']) . "\n\n";
    
    // 2. 查询当前余额
    echo "=== 当前账户余额 ===\n";
    echo "可提现余额: ¥" . number_format($user['withdrawable_money'], 2) . "\n";
    echo "总余额(money): ¥" . number_format($user['money'], 2) . "\n";
    echo "可用余额: ¥" . number_format($user['balance_available'], 2) . "\n";
    echo "服务费余额: ¥" . number_format($user['service_fee_balance'], 2) . "\n";
    echo "消费金: " . number_format($user['score'], 2) . " 分\n";
    echo "绿色算力: " . number_format($user['green_power'], 2) . "\n";
    
    $totalBalance = $user['withdrawable_money'] + $user['money'] + $user['balance_available'] + $user['service_fee_balance'];
    echo "账户总余额: ¥" . number_format($totalBalance, 2) . "\n\n";
    
    // 3. 查询充值记录总额
    $rechargeStats = Db::name('recharge_order')
        ->where('user_id', $user['id'])
        ->where('status', 'paid')
        ->field([
            'COUNT(*) as count',
            'SUM(amount) as total_amount',
        ])
        ->find();
    
    echo "=== 充值记录统计 ===\n";
    echo "充值笔数: {$rechargeStats['count']} 笔\n";
    echo "充值总额: ¥" . number_format($rechargeStats['total_amount'] ?? 0, 2) . "\n\n";
    
    // 列出所有充值记录
    $recharges = Db::name('recharge_order')
        ->where('user_id', $user['id'])
        ->where('status', 'paid')
        ->field(['id', 'order_no', 'amount', 'payment_type', 'create_time'])
        ->order('create_time', 'desc')
        ->select();
    
    echo "充值明细：\n";
    foreach ($recharges as $r) {
        echo "  " . date('Y-m-d H:i:s', $r['create_time']) . " | ¥" . $r['amount'] . " | " . $r['payment_type'] . "\n";
    }
    echo "\n";
    
    // 4. 查询藏品价值
    $collections = Db::name('user_collection')
        ->where('user_id', $user['id'])
        ->where('delivery_status', 0) // 未提货
        ->where('consignment_status', '<>', 2) // 未售出
        ->field([
            'COUNT(*) as count',
            'SUM(price) as total_value',
        ])
        ->find();
    
    echo "=== 持有藏品统计 ===\n";
    echo "藏品数量: {$collections['count']} 件\n";
    echo "藏品总价值: ¥" . number_format($collections['total_value'] ?? 0, 2) . "\n\n";
    
    // 5. 查询提现记录
    $withdrawStats = Db::name('user_withdraw')
        ->where('user_id', $user['id'])
        ->whereIn('status', [1, 3]) // 审核通过和已打款
        ->field([
            'COUNT(*) as count',
            'SUM(amount) as total_amount',
            'SUM(actual_amount) as total_actual',
        ])
        ->find();
    
    echo "=== 提现记录统计 ===\n";
    echo "提现笔数: {$withdrawStats['count']} 笔\n";
    echo "提现总额: ¥" . number_format($withdrawStats['total_amount'] ?? 0, 2) . "\n";
    echo "实际到账: ¥" . number_format($withdrawStats['total_actual'] ?? 0, 2) . "\n\n";
    
    // 6. 查询订单支出（商城订单）
    $orderStats = Db::name('shop_order')
        ->where('user_id', $user['id'])
        ->whereIn('status', ['paid', 'shipped', 'completed'])
        ->field([
            'COUNT(*) as count',
            'SUM(total_amount) as total_amount',
            'SUM(total_score) as total_score',
        ])
        ->find();
    
    echo "=== 商城订单统计 ===\n";
    echo "订单笔数: {$orderStats['count']} 笔\n";
    echo "现金支出: ¥" . number_format($orderStats['total_amount'] ?? 0, 2) . "\n";
    echo "消费金支出: " . number_format($orderStats['total_score'] ?? 0, 2) . " 分\n\n";
    
    // 7. 查询藏品购买支出
    $collectionPurchase = Db::name('user_collection')
        ->where('user_id', $user['id'])
        ->field([
            'COUNT(*) as count',
            'SUM(price) as total_spent',
        ])
        ->find();
    
    echo "=== 藏品购买统计 ===\n";
    echo "购买藏品数: {$collectionPurchase['count']} 件\n";
    echo "藏品购买总额: ¥" . number_format($collectionPurchase['total_spent'] ?? 0, 2) . "\n\n";
    
    // 8. 资产汇总
    echo "=== 资产汇总核对 ===\n";
    $currentAssets = $totalBalance + ($collections['total_value'] ?? 0);
    $totalRecharge = $rechargeStats['total_amount'] ?? 0;
    $totalWithdraw = $withdrawStats['total_amount'] ?? 0;
    $totalOrderSpend = $orderStats['total_amount'] ?? 0;
    
    echo "充值总额: ¥" . number_format($totalRecharge, 2) . "\n";
    echo "提现总额: -¥" . number_format($totalWithdraw, 2) . "\n";
    echo "商城消费: -¥" . number_format($totalOrderSpend, 2) . "\n";
    echo "---\n";
    echo "当前账户余额: ¥" . number_format($totalBalance, 2) . "\n";
    echo "当前藏品价值: ¥" . number_format($collections['total_value'] ?? 0, 2) . "\n";
    echo "当前总资产: ¥" . number_format($currentAssets, 2) . "\n\n";
    
    // 计算差异
    $expectedAssets = $totalRecharge - $totalWithdraw - $totalOrderSpend;
    $difference = $currentAssets - $expectedAssets;
    
    echo "=== 差异分析 ===\n";
    echo "理论资产（充值-提现-消费）: ¥" . number_format($expectedAssets, 2) . "\n";
    echo "实际资产（余额+藏品）: ¥" . number_format($currentAssets, 2) . "\n";
    echo "差异: ¥" . number_format($difference, 2) . "\n";
    
    if (abs($difference) > 0.01) {
        echo "\n⚠️  存在差异，需要进一步核查：\n";
        echo "可能原因：\n";
        echo "1. 藏品寄售手续费支出\n";
        echo "2. 其他服务费扣除\n";
        echo "3. 邀请奖励等收入\n";
        echo "4. 系统调整记录\n";
    } else {
        echo "\n✓ 账户核对无误\n";
    }
    
} catch (\Exception $e) {
    echo "❌ 查询失败: " . $e->getMessage() . "\n";
    echo "文件: " . $e->getFile() . "\n";
    echo "行号: " . $e->getLine() . "\n";
    exit(1);
}

echo "\n✓ 核查完成\n";
