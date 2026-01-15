<?php
/**
 * 寄售失败用户补偿脚本
 * 功能：给今天9-12场次（场次1）寄售失败的用户补偿10消费金
 * 
 * 使用方法：
 * 1. 模拟运行（不实际修改）：php compensate_failed_consignment.php --dry-run
 * 2. 实际执行补偿：php compensate_failed_consignment.php --execute
 */

require __DIR__ . '/../vendor/autoload.php';

use think\facade\Db;
use think\facade\Log;

$app = new think\App();
$app->initialize();

// 检查参数
$dryRun = in_array('--dry-run', $argv);
$execute = in_array('--execute', $argv);

if (!$dryRun && !$execute) {
    echo "请指定运行模式：\n";
    echo "  --dry-run  : 模拟运行（不实际修改数据）\n";
    echo "  --execute  : 实际执行补偿\n";
    exit(1);
}

$mode = $dryRun ? '【模拟运行】' : '【实际执行】';

echo str_repeat('=', 100) . "\n";
echo "{$mode} 寄售失败用户补偿脚本\n";
echo str_repeat('=', 100) . "\n";
echo "日期: " . date('Y-m-d H:i:s') . "\n";
echo "补偿金额: 10 消费金 (score)\n";
echo "目标用户: 今天场次1（绿色共识池，9:00-12:00）寄售失败的用户\n";
echo str_repeat('=', 100) . "\n\n";

// 今天的日期范围
$today = date('Y-m-d');
$todayStart = strtotime($today . ' 00:00:00');
$todayEnd = strtotime($today . ' 23:59:59');

echo "查询条件:\n";
echo "  日期范围: $today 00:00:00 ~ 23:59:59\n";
echo "  时间戳: $todayStart ~ $todayEnd\n";
echo "  场次ID: 1 (绿色共识池)\n";
echo "  寄售状态: 3 (流拍失败)\n\n";

// 查询今天场次1寄售失败的记录
$failedConsignments = Db::name('collection_consignment')
    ->alias('cc')
    ->leftJoin('user u', 'cc.user_id = u.id')
    ->leftJoin('collection_item ci', 'cc.item_id = ci.id')
    ->where('cc.status', 3) // 3=流拍失败
    ->where('cc.create_time', '>=', $todayStart)
    ->where('cc.create_time', '<=', $todayEnd)
    ->where('ci.session_id', 1) // 场次1
    ->field([
        'cc.id as consignment_id',
        'cc.user_id',
        'cc.item_id',
        'cc.price',
        'cc.create_time',
        'u.mobile',
        'u.nickname',
        'u.score as current_score',
        'ci.title as item_title',
        'ci.session_id',
    ])
    ->order('cc.user_id asc, cc.id asc')
    ->select()
    ->toArray();

echo "=== 查询结果 ===\n";
echo "总寄售失败记录数: " . count($failedConsignments) . "\n\n";

if (empty($failedConsignments)) {
    echo "✓ 没有找到符合条件的寄售失败记录\n";
    exit(0);
}

// 按用户分组统计
$userStats = [];
foreach ($failedConsignments as $fc) {
    $userId = $fc['user_id'];
    if (!isset($userStats[$userId])) {
        $userStats[$userId] = [
            'user_id' => $userId,
            'mobile' => $fc['mobile'],
            'nickname' => $fc['nickname'],
            'current_score' => $fc['current_score'],
            'failed_count' => 0,
            'consignment_ids' => [],
        ];
    }
    $userStats[$userId]['failed_count']++;
    $userStats[$userId]['consignment_ids'][] = $fc['consignment_id'];
}

echo "=== 去重后用户统计 ===\n";
echo "受影响用户数: " . count($userStats) . "\n\n";

echo "=== 用户详情 ===\n";
echo str_repeat('-', 120) . "\n";
printf("%-8s %-15s %-25s %12s %8s %s\n", 
    '用户ID', '手机号', '昵称', '当前消费金', '失败次数', '将补偿');
echo str_repeat('-', 120) . "\n";

$totalCompensation = 0;
$compensateAmount = 10; // 每人补偿10消费金

foreach ($userStats as $stat) {
    printf("%-8d %-15s %-25s %12.2f %8d +%.2f消费金\n",
        $stat['user_id'],
        $stat['mobile'],
        mb_substr($stat['nickname'], 0, 23),
        $stat['current_score'],
        $stat['failed_count'],
        $compensateAmount
    );
    $totalCompensation += $compensateAmount;
}
echo str_repeat('-', 120) . "\n";
echo "总计将补偿: {$totalCompensation} 消费金 (平均每人 {$compensateAmount} 消费金)\n\n";

// 如果是模拟运行，到此结束
if ($dryRun) {
    echo str_repeat('=', 100) . "\n";
    echo "【模拟运行完成】\n";
    echo "如果确认无误，请使用 --execute 参数执行实际补偿:\n";
    echo "  php " . basename(__FILE__) . " --execute\n";
    echo str_repeat('=', 100) . "\n";
    exit(0);
}

// 实际执行补偿
echo str_repeat('=', 100) . "\n";
echo "【开始执行补偿】\n";
echo str_repeat('=', 100) . "\n\n";

$successCount = 0;
$failCount = 0;
$now = time();

Db::startTrans();
try {
    foreach ($userStats as $stat) {
        $userId = $stat['user_id'];
        $mobile = $stat['mobile'];
        $nickname = $stat['nickname'];
        
        echo "处理用户 {$userId} ({$mobile})... ";
        
        try {
            // 1. 更新用户消费金余额
            $user = Db::name('user')->where('id', $userId)->lock(true)->find();
            if (!$user) {
                echo "失败 (用户不存在)\n";
                $failCount++;
                continue;
            }
            
            $beforeScore = $user['score'];
            $afterScore = $beforeScore + $compensateAmount;
            
            Db::name('user')->where('id', $userId)->update([
                'score' => $afterScore,
                'update_time' => $now,
            ]);
            
            // 2. 记录资金流水
            $batchNo = 'COMPENSATE_' . date('YmdHis') . '_' . $userId;
            $flowNo = 'SJS' . date('YmdHis') . str_pad(rand(1, 999999), 6, '0', STR_PAD_LEFT);
            
            Db::name('user_money_log')->insert([
                'user_id' => $userId,
                'flow_no' => $flowNo,
                'batch_no' => $batchNo,
                'biz_type' => 'system_compensation',
                'biz_id' => 0,
                'field_type' => 'score',
                'money' => $compensateAmount,
                'before' => $beforeScore,
                'after' => $afterScore,
                'memo' => "系统补偿：" . date('Y-m-d') . " 寄售失败补偿（场次1，共{$stat['failed_count']}次失败）",
                'create_time' => $now,
            ]);
            
            // 3. 记录活动日志
            Db::name('user_activity_log')->insert([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'system_compensation',
                'change_field' => 'score',
                'change_value' => $compensateAmount,
                'before_value' => $beforeScore,
                'after_value' => $afterScore,
                'remark' => date('Y-m-d') . " 场次1寄售失败补偿 (共{$stat['failed_count']}次)",
                'create_time' => $now,
                'update_time' => $now,
            ]);
            
            echo "成功 (补偿 {$compensateAmount} 消费金，余额: {$beforeScore} → {$afterScore})\n";
            $successCount++;
            
        } catch (\Exception $e) {
            echo "失败 (" . $e->getMessage() . ")\n";
            $failCount++;
            Log::error("补偿失败 - 用户ID: {$userId}, 错误: " . $e->getMessage());
        }
    }
    
    Db::commit();
    
    echo "\n" . str_repeat('=', 100) . "\n";
    echo "【补偿完成】\n";
    echo str_repeat('=', 100) . "\n";
    echo "成功: {$successCount} 人\n";
    echo "失败: {$failCount} 人\n";
    echo "总补偿金额: " . ($successCount * $compensateAmount) . " 消费金\n";
    echo str_repeat('=', 100) . "\n";
    
} catch (\Exception $e) {
    Db::rollback();
    echo "\n错误: 事务回滚 - " . $e->getMessage() . "\n";
    Log::error("补偿脚本执行失败: " . $e->getMessage());
    exit(1);
}
