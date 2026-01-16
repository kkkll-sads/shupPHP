<?php
/**
 * 统计所有用户的可提现金额和专项金
 */

require __DIR__ . '/vendor/autoload.php';

use think\facade\Db;

// 引导 ThinkPHP 应用
$app = new think\App();
$app->initialize();

echo "=== 用户资金统计 ===\n";
echo "查询时间: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // 统计所有用户
    $totalUsers = Db::name('user')->count();
    echo "总用户数: {$totalUsers}\n\n";
    
    // 统计用户资金
    $stats = Db::name('user')
        ->field([
            'COUNT(*) as total_users',
            'SUM(withdrawable_money) as total_withdrawable',
            'SUM(money) as total_money',
            'SUM(balance_available) as total_balance_available',
            'SUM(service_fee_balance) as total_service_fee',
            'SUM(score) as total_score',
            'SUM(green_power) as total_green_power',
        ])
        ->find();
    
    echo "=== 资金统计汇总 ===\n";
    echo "可提现余额总额: ¥" . number_format($stats['total_withdrawable'] ?? 0, 2) . "\n";
    echo "总余额(money)总额: ¥" . number_format($stats['total_money'] ?? 0, 2) . "\n";
    echo "可用余额总额: ¥" . number_format($stats['total_balance_available'] ?? 0, 2) . "\n";
    echo "服务费余额总额: ¥" . number_format($stats['total_service_fee'] ?? 0, 2) . "\n";
    echo "消费金总额: " . number_format($stats['total_score'] ?? 0, 2) . " 分\n";
    echo "绿色算力总额: " . number_format($stats['total_green_power'] ?? 0, 2) . "\n";
    
    // 总资金
    $totalMoney = ($stats['total_withdrawable'] ?? 0) + 
                  ($stats['total_money'] ?? 0) + 
                  ($stats['total_balance_available'] ?? 0) + 
                  ($stats['total_service_fee'] ?? 0);
    
    echo "\n总资金余额: ¥" . number_format($totalMoney, 2) . "\n";
    
    // 统计有余额的用户数量
    echo "\n=== 用户分布统计 ===\n";
    
    $usersWithWithdrawable = Db::name('user')
        ->where('withdrawable_money', '>', 0)
        ->count();
    echo "有可提现余额的用户数: {$usersWithWithdrawable}\n";
    
    $usersWithServiceFee = Db::name('user')
        ->where('service_fee_balance', '>', 0)
        ->count();
    echo "有服务费余额的用户数: {$usersWithServiceFee}\n";
    
    $usersWithBalance = Db::name('user')
        ->where('balance_available', '>', 0)
        ->count();
    echo "有可用余额的用户数: {$usersWithBalance}\n";
    
    $usersWithScore = Db::name('user')
        ->where('score', '>', 0)
        ->count();
    echo "有消费金的用户数: {$usersWithScore}\n";
    
    // 可提现余额TOP 10
    echo "\n=== 可提现余额 TOP 10 ===\n";
    $topWithdrawable = Db::name('user')
        ->where('withdrawable_money', '>', 0)
        ->field(['id', 'mobile', 'nickname', 'withdrawable_money'])
        ->order('withdrawable_money', 'desc')
        ->limit(10)
        ->select()
        ->toArray();
    
    foreach ($topWithdrawable as $index => $user) {
        echo ($index + 1) . ". 用户: {$user['mobile']} ({$user['nickname']}), 可提现: ¥" . number_format($user['withdrawable_money'], 2) . "\n";
    }
    
    // 服务费余额 TOP 10
    echo "\n=== 服务费余额 TOP 10 ===\n";
    $topServiceFee = Db::name('user')
        ->where('service_fee_balance', '>', 0)
        ->field(['id', 'mobile', 'nickname', 'service_fee_balance'])
        ->order('service_fee_balance', 'desc')
        ->limit(10)
        ->select()
        ->toArray();
    
    foreach ($topServiceFee as $index => $user) {
        echo ($index + 1) . ". 用户: {$user['mobile']} ({$user['nickname']}), 服务费余额: ¥" . number_format($user['service_fee_balance'], 2) . "\n";
    }
    
    // 消费金 TOP 10
    echo "\n=== 消费金 TOP 10 ===\n";
    $topScore = Db::name('user')
        ->where('score', '>', 0)
        ->field(['id', 'mobile', 'nickname', 'score'])
        ->order('score', 'desc')
        ->limit(10)
        ->select()
        ->toArray();
    
    foreach ($topScore as $index => $user) {
        echo ($index + 1) . ". 用户: {$user['mobile']} ({$user['nickname']}), 消费金: " . number_format($user['score'], 2) . " 分\n";
    }
    
    // 导出详细报告（可选）
    echo "\n是否导出详细CSV报告？(y/n): ";
    $exportChoice = trim(fgets(STDIN));
    
    if (strtolower($exportChoice) === 'y') {
        // 查询所有有余额的用户
        $usersWithBalance = Db::name('user')
            ->where(function($query) {
                $query->where('withdrawable_money', '>', 0)
                      ->whereOr('service_fee_balance', '>', 0)
                      ->whereOr('balance_available', '>', 0)
                      ->whereOr('score', '>', 0);
            })
            ->field([
                'id',
                'mobile',
                'nickname',
                'withdrawable_money',
                'service_fee_balance',
                'balance_available',
                'money',
                'score',
                'green_power',
                'create_time',
            ])
            ->order('withdrawable_money', 'desc')
            ->select()
            ->toArray();
        
        $csvFile = __DIR__ . '/用户资金统计_' . date('YmdHis') . '.csv';
        $fp = fopen($csvFile, 'w');
        fwrite($fp, "\xEF\xBB\xBF"); // BOM
        
        // 写入表头
        fputcsv($fp, [
            '用户ID',
            '手机号',
            '昵称',
            '可提现余额',
            '服务费余额',
            '可用余额',
            '总余额(money)',
            '消费金',
            '绿色算力',
            '注册时间',
        ]);
        
        // 写入数据
        foreach ($usersWithBalance as $user) {
            fputcsv($fp, [
                $user['id'],
                $user['mobile'],
                $user['nickname'],
                $user['withdrawable_money'],
                $user['service_fee_balance'],
                $user['balance_available'],
                $user['money'],
                $user['score'],
                $user['green_power'],
                date('Y-m-d H:i:s', $user['create_time']),
            ]);
        }
        
        fclose($fp);
        echo "\n✓ 详细报告已导出: {$csvFile}\n";
        echo "包含 " . count($usersWithBalance) . " 个有余额的用户\n";
    }
    
} catch (\Exception $e) {
    echo "❌ 查询失败: " . $e->getMessage() . "\n";
    echo "文件: " . $e->getFile() . "\n";
    echo "行号: " . $e->getLine() . "\n";
    exit(1);
}

echo "\n✓ 统计完成\n";
