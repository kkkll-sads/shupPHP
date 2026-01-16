<?php
/**
 * 未中签盲盒申购补偿脚本
 * 
 * 补偿规则：
 * 1. 算力补偿：返还每次申购消耗的算力（power_used）
 * 2. 消费金补偿：每人固定10消费金
 * 3. 避免重复补偿
 * 
 * 使用方法：
 * php scripts/compensate_failed_blindbox.php --dry-run  # 模拟运行
 * php scripts/compensate_failed_blindbox.php --execute  # 实际执行
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use think\facade\Db;
use app\common\model\User;
use app\common\model\UserMoneyLog;
use app\common\model\UserActivityLog;

// 初始化应用
$app = new think\App();
$app->initialize();

// 检查参数
$mode = $argv[1] ?? '--dry-run';
$isDryRun = ($mode === '--dry-run');

// 今天的时间范围
$today = date('Y-m-d');
$startTime = strtotime($today . ' 00:00:00');
$endTime = strtotime($today . ' 23:59:59');

echo str_repeat('=', 80) . PHP_EOL;
echo "未中签盲盒申购补偿脚本" . PHP_EOL;
echo str_repeat('=', 80) . PHP_EOL;
echo "执行时间: " . date('Y-m-d H:i:s') . PHP_EOL;
echo "模式: " . ($isDryRun ? '【模拟运行】' : '【实际执行】') . PHP_EOL;
echo "日期范围: {$today} 00:00:00 ~ 23:59:59" . PHP_EOL;
echo str_repeat('=', 80) . PHP_EOL;
echo PHP_EOL;

// 1. 查询今天所有未中签的申购记录（status=2表示未中签）
echo "[1/5] 查询今天未中签申购记录..." . PHP_EOL;
$failedReservations = Db::name('trade_reservations')
    ->where('update_time', '>=', $startTime)
    ->where('update_time', '<=', $endTime)
    ->where('status', 2) // 未中签
    ->field('id,user_id,session_id,power_used,freeze_amount,create_time,update_time')
    ->select()
    ->toArray();

echo "  未中签记录总数: " . count($failedReservations) . PHP_EOL;

if (empty($failedReservations)) {
    echo PHP_EOL;
    echo "✅ 今天没有未中签记录，无需补偿" . PHP_EOL;
    exit(0);
}

// 2. 按用户统计未中签次数和消耗的算力
echo PHP_EOL;
echo "[2/5] 统计用户未中签次数和消耗算力..." . PHP_EOL;

$userStats = [];
foreach ($failedReservations as $record) {
    $userId = $record['user_id'];
    if (!isset($userStats[$userId])) {
        $userStats[$userId] = [
            'fail_count' => 0,
            'total_power_used' => 0,
            'sessions' => [],
            'reservation_ids' => []
        ];
    }
    $userStats[$userId]['fail_count']++;
    $userStats[$userId]['total_power_used'] += floatval($record['power_used']);
    $userStats[$userId]['sessions'][] = $record['session_id'];
    $userStats[$userId]['reservation_ids'][] = $record['id'];
}

echo "  涉及用户总数: " . count($userStats) . PHP_EOL;

// 3. 检查是否已经补偿过（避免重复）
echo PHP_EOL;
echo "[3/5] 检查是否存在重复补偿..." . PHP_EOL;

$batchNo = 'COMP_BLINDBOX_' . date('Ymd');
$alreadyCompensated = [];

foreach ($userStats as $userId => $stat) {
    // 检查是否已经有今天的补偿记录
    $existingLog = Db::name('user_activity_log')
        ->where('user_id', $userId)
        ->where('action_type', 'system_compensation')
        ->where('extra', 'like', '%' . $batchNo . '%')
        ->where('create_time', '>=', $startTime)
        ->find();
    
    if ($existingLog) {
        $alreadyCompensated[$userId] = true;
        echo "  ⚠️  用户ID {$userId} 今天已经补偿过，跳过" . PHP_EOL;
    }
}

// 过滤掉已补偿的用户
$userStats = array_filter($userStats, function($userId) use ($alreadyCompensated) {
    return !isset($alreadyCompensated[$userId]);
}, ARRAY_FILTER_USE_KEY);

echo "  需要补偿的用户数: " . count($userStats) . PHP_EOL;

if (empty($userStats)) {
    echo PHP_EOL;
    echo "✅ 所有用户都已补偿过，无需重复执行" . PHP_EOL;
    exit(0);
}

// 4. 生成补偿报告
echo PHP_EOL;
echo "[4/5] 生成补偿报告..." . PHP_EOL;
echo str_repeat('-', 80) . PHP_EOL;
echo sprintf("%-10s %-15s %-10s %-12s %-12s %-15s",
    "用户ID", "手机号", "未中签次数", "消耗算力", "补偿消费金", "场次"
) . PHP_EOL;
echo str_repeat('-', 80) . PHP_EOL;

$totalPowerToCompensate = 0;
$totalScoreToCompensate = 0;
$compensationPlans = [];

foreach ($userStats as $userId => $stat) {
    $user = Db::name('user')
        ->where('id', $userId)
        ->field('id,username,mobile,green_power,score')
        ->find();
    
    if (!$user) {
        echo "  ⚠️  用户ID {$userId} 不存在，跳过" . PHP_EOL;
        continue;
    }
    
    $powerCompensation = $stat['total_power_used']; // 补偿消耗的算力
    $scoreCompensation = 10.00; // 每人固定10消费金
    
    echo sprintf("%-10d %-15s %-10d %-12.2f %-12.2f %-15s",
        $userId,
        $user['mobile'],
        $stat['fail_count'],
        $powerCompensation,
        $scoreCompensation,
        implode(',', array_unique($stat['sessions']))
    ) . PHP_EOL;
    
    $totalPowerToCompensate += $powerCompensation;
    $totalScoreToCompensate += $scoreCompensation;
    
    $compensationPlans[] = [
        'user_id' => $userId,
        'mobile' => $user['mobile'],
        'fail_count' => $stat['fail_count'],
        'power_compensation' => $powerCompensation,
        'score_compensation' => $scoreCompensation,
        'sessions' => array_unique($stat['sessions']),
        'current_power' => $user['green_power'],
        'current_score' => $user['score'],
    ];
}

echo str_repeat('-', 80) . PHP_EOL;
echo "总计:" . PHP_EOL;
echo "  补偿用户数: " . count($compensationPlans) . PHP_EOL;
echo "  补偿算力总计: {$totalPowerToCompensate}" . PHP_EOL;
echo "  补偿消费金总计: {$totalScoreToCompensate}" . PHP_EOL;
echo str_repeat('-', 80) . PHP_EOL;

// 5. 执行补偿
echo PHP_EOL;
echo "[5/5] 执行补偿..." . PHP_EOL;

if ($isDryRun) {
    echo PHP_EOL;
    echo "⚠️  【模拟运行】未实际执行补偿" . PHP_EOL;
    echo "如需实际执行，请运行: php scripts/compensate_failed_blindbox.php --execute" . PHP_EOL;
    exit(0);
}

// 实际执行补偿
$successCount = 0;
$failCount = 0;
$now = time();

foreach ($compensationPlans as $plan) {
    $userId = $plan['user_id'];
    $mobile = $plan['mobile'];
    
    try {
        Db::startTrans();
        
        // 获取用户当前数据
        $user = User::find($userId);
        if (!$user) {
            throw new Exception("用户不存在");
        }
        
        $beforePower = $user->green_power;
        $beforeScore = $user->score;
        $afterPower = $beforePower + $plan['power_compensation'];
        $afterScore = $beforeScore + $plan['score_compensation'];
        
        // 更新用户算力和消费金
        $user->green_power = $afterPower;
        $user->score = $afterScore;
        $user->save();
        
        // 记录算力变更日志（user_activity_log）
        UserActivityLog::create([
            'user_id' => $userId,
            'related_user_id' => 0, // 系统操作
            'action_type' => 'system_compensation',
            'change_field' => 'green_power',
            'change_value' => (string)$plan['power_compensation'],
            'before_value' => (string)$beforePower,
            'after_value' => (string)$afterPower,
            'remark' => "{$today} 未中签申购算力补偿（{$plan['fail_count']}次）",
            'extra' => json_encode([
                'compensation_type' => 'green_power',
                'fail_count' => $plan['fail_count'],
                'sessions' => $plan['sessions'],
                'batch_no' => $batchNo,
            ], JSON_UNESCAPED_UNICODE),
            'create_time' => $now,
            'update_time' => $now,
        ]);
        
        // 记录消费金变更日志（user_activity_log）
        UserActivityLog::create([
            'user_id' => $userId,
            'related_user_id' => 0, // 系统操作
            'action_type' => 'system_compensation',
            'change_field' => 'score',
            'change_value' => (string)$plan['score_compensation'],
            'before_value' => (string)$beforeScore,
            'after_value' => (string)$afterScore,
            'remark' => "{$today} 未中签申购消费金补偿",
            'extra' => json_encode([
                'compensation_type' => 'score',
                'fail_count' => $plan['fail_count'],
                'sessions' => $plan['sessions'],
                'batch_no' => $batchNo,
            ], JSON_UNESCAPED_UNICODE),
            'create_time' => $now,
            'update_time' => $now,
        ]);
        
        // 记录资金流水（user_money_log）
        UserMoneyLog::create([
            'user_id' => $userId,
            'field_type' => 'score', // 消费金
            'money' => $plan['score_compensation'],
            'before' => $beforeScore,
            'after' => $afterScore,
            'memo' => "{$today} 未中签申购补偿10消费金",
            'create_time' => $now,
        ]);
        
        Db::commit();
        
        echo "  ✅ 用户ID {$userId} ({$mobile}) 补偿成功: 算力+{$plan['power_compensation']} 消费金+{$plan['score_compensation']}" . PHP_EOL;
        $successCount++;
        
    } catch (Exception $e) {
        Db::rollback();
        echo "  ❌ 用户ID {$userId} ({$mobile}) 补偿失败: " . $e->getMessage() . PHP_EOL;
        $failCount++;
    }
}

// 最终统计
echo PHP_EOL;
echo str_repeat('=', 80) . PHP_EOL;
echo "✅ 补偿任务完成" . PHP_EOL;
echo str_repeat('=', 80) . PHP_EOL;
echo "执行时间: " . date('Y-m-d H:i:s') . PHP_EOL;
echo "补偿成功: {$successCount} 人" . PHP_EOL;
echo "补偿失败: {$failCount} 人" . PHP_EOL;
echo "批次号: {$batchNo}" . PHP_EOL;
echo str_repeat('=', 80) . PHP_EOL;
