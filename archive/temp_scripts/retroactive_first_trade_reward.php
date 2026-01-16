<?php
/**
 * 首次交易奖励补发脚本
 * 
 * 逻辑：
 * 1. 找出所有有 user_collection 记录但 user_type < 1 的用户（应该升级但未升级）
 * 2. 或者找出所有有 user_collection 记录但没有首次交易奖励记录的用户
 * 3. 补发首次交易奖励（消费金和算力）
 * 4. 同时补发下级首次交易奖励（如果有邀请人）
 * 
 * 用法: php retroactive_first_trade_reward.php [--dry-run] [--date-start=YYYY-MM-DD] [--date-end=YYYY-MM-DD]
 */

require __DIR__ . '/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use think\facade\Db;

// 简单的输出函数
function output($message) {
    echo $message . PHP_EOL;
}

// 解析参数
$args = $_SERVER['argv'];
$dryRun = in_array('--dry-run', $args);
$dateStart = null;
$dateEnd = null;

foreach ($args as $arg) {
    if (strpos($arg, '--date-start=') === 0) {
        $dateStart = str_replace('--date-start=', '', $arg);
    }
    if (strpos($arg, '--date-end=') === 0) {
        $dateEnd = str_replace('--date-end=', '', $arg);
    }
}

output("=== 首次交易奖励补发脚本 ===");
output("模式: " . ($dryRun ? "【预览模式，不会实际修改数据】" : "【执行模式】"));
output("");

// 获取奖励配置
$firstTradeRewardScore = (float)Db::name('config')->where('name', 'first_trade_reward_score')->value('value');
$firstTradeRewardPower = (float)Db::name('config')->where('name', 'first_trade_reward_power')->value('value');
$subTradeRewardScore = (float)Db::name('config')->where('name', 'sub_trade_reward_score')->value('value');
$subTradeRewardPower = (float)Db::name('config')->where('name', 'sub_trade_reward_power')->value('value');

output("奖励配置:");
output("  首次交易奖励消费金: {$firstTradeRewardScore}");
output("  首次交易奖励算力: {$firstTradeRewardPower}");
output("  下级首次交易奖励消费金: {$subTradeRewardScore}");
output("  下级首次交易奖励算力: {$subTradeRewardPower}");
output("");

if ($firstTradeRewardScore <= 0 && $firstTradeRewardPower <= 0) {
    output("⚠️  首次交易奖励配置为0，无需补发");
    exit(0);
}

// 构建查询条件：找出所有有 user_collection 记录的用户
$query = Db::name('user_collection')
    ->alias('uc')
    ->leftJoin('user u', 'uc.user_id = u.id')
    ->field('uc.user_id, MIN(uc.buy_time) as first_buy_time, COUNT(*) as purchase_count')
    ->group('uc.user_id')
    ->having('COUNT(*) > 0');

// 如果指定了日期范围，只处理该范围内的首次购买
if ($dateStart) {
    $startTime = strtotime($dateStart . ' 00:00:00');
    $query->where('uc.buy_time', '>=', $startTime);
}
if ($dateEnd) {
    $endTime = strtotime($dateEnd . ' 23:59:59');
    $query->where('uc.buy_time', '<=', $endTime);
}

$usersWithPurchases = $query->select()->toArray();

output("找到 " . count($usersWithPurchases) . " 个有购买记录的用户");
output("");

$needRewardUsers = [];
$needInviterRewardUsers = [];

foreach ($usersWithPurchases as $row) {
    $userId = (int)$row['user_id'];
    $firstBuyTime = (int)$row['first_buy_time'];
    $purchaseCount = (int)$row['purchase_count'];
    
    // 获取用户信息
    $user = Db::name('user')->where('id', $userId)->find();
    if (!$user) {
        continue;
    }
    
    $userType = (int)($user['user_type'] ?? 0);
    $inviterId = (int)($user['inviter_id'] ?? 0);
    
    // 检查是否已经获得首次交易奖励
    // 方式1：检查 user_type 是否 >= 1（首次交易应该升级到 user_type = 1）
    // 方式2：检查是否有首次交易奖励记录
    $hasFirstTradeReward = false;
    
    // 检查是否有首次交易奖励的活动日志
    $existingReward = Db::name('user_activity_log')
        ->where('user_id', $userId)
        ->where('action_type', 'first_trade_reward')
        ->find();
    
    if ($existingReward) {
        $hasFirstTradeReward = true;
    }
    
    // 如果 user_type >= 1 且没有奖励记录，可能是历史数据，需要检查
    // 如果 user_type < 1，肯定没有获得奖励
    if ($userType < 1 || (!$hasFirstTradeReward && $purchaseCount >= 1)) {
        $needRewardUsers[] = [
            'user_id' => $userId,
            'mobile' => $user['mobile'] ?? '',
            'user_type' => $userType,
            'purchase_count' => $purchaseCount,
            'first_buy_time' => $firstBuyTime,
            'inviter_id' => $inviterId,
        ];
    }
    
    // 🔧 修复：无论首次交易奖励是否已补发，都要检查下级首次交易奖励
    // 如果有邀请人，检查邀请人是否获得下级首次交易奖励
    if ($inviterId > 0) {
        $inviterHasReward = Db::name('user_activity_log')
            ->where('user_id', $inviterId)
            ->where('action_type', 'subordinate_first_trade_reward')
            ->where('related_user_id', $userId)
            ->find();
        
        if (!$inviterHasReward) {
            // 检查是否已经添加到列表中（避免重复）
            $exists = false;
            foreach ($needInviterRewardUsers as $existing) {
                if ($existing['user_id'] == $userId && $existing['inviter_id'] == $inviterId) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $needInviterRewardUsers[] = [
                    'user_id' => $userId,
                    'mobile' => $user['mobile'] ?? '',
                    'inviter_id' => $inviterId,
                    'first_buy_time' => $firstBuyTime,
                ];
            }
        }
    }
}

output("需要补发首次交易奖励的用户: " . count($needRewardUsers) . " 个");
output("需要补发下级首次交易奖励的邀请人: " . count($needInviterRewardUsers) . " 个");
output("");

if (empty($needRewardUsers) && empty($needInviterRewardUsers)) {
    output("✓ 所有用户都已获得首次交易奖励，无需补发");
    exit(0);
}

// 显示需要补发的用户列表
if (!empty($needRewardUsers)) {
    output("需要补发首次交易奖励的用户列表:");
    foreach ($needRewardUsers as $item) {
        output("  - 用户ID: {$item['user_id']}, 手机号: {$item['mobile']}, 当前等级: {$item['user_type']}, 购买次数: {$item['purchase_count']}");
    }
    output("");
}

if (!empty($needInviterRewardUsers)) {
    output("需要补发下级首次交易奖励的邀请人列表:");
    foreach ($needInviterRewardUsers as $item) {
        $inviter = Db::name('user')->where('id', $item['inviter_id'])->find();
        $inviterMobile = $inviter ? ($inviter['mobile'] ?? '') : '未知';
        output("  - 下级用户ID: {$item['user_id']} ({$item['mobile']}), 邀请人ID: {$item['inviter_id']} ({$inviterMobile})");
    }
    output("");
}

if ($dryRun) {
    output("【预览模式】如需执行补发，请移除 --dry-run 参数");
    exit(0);
}

// 开始补发
output("开始补发...");
output("");

$successCount = 0;
$failCount = 0;
$totalScoreRewarded = 0;
$totalPowerRewarded = 0;
$inviterSuccessCount = 0;
$inviterFailCount = 0;
$inviterTotalScoreRewarded = 0;
$inviterTotalPowerRewarded = 0;

// 补发首次交易奖励
foreach ($needRewardUsers as $item) {
    $userId = $item['user_id'];
    $mobile = $item['mobile'];
    $firstBuyTime = $item['first_buy_time'];
    
    try {
        Db::startTrans();
        
        $user = Db::name('user')->where('id', $userId)->lock(true)->find();
        if (!$user) {
            throw new \Exception("用户不存在");
        }
        
        $beforeScore = (float)($user['score'] ?? 0);
        $beforePower = (float)($user['green_power'] ?? 0);
        
        // 发放消费金
        if ($firstTradeRewardScore > 0) {
            $afterScore = round($beforeScore + $firstTradeRewardScore, 2);
            Db::name('user')->where('id', $userId)->update([
                'score' => $afterScore,
                'update_time' => time(),
            ]);
            
            Db::name('user_score_log')->insert([
                'user_id' => $userId,
                'score' => $firstTradeRewardScore,
                'before' => $beforeScore,
                'after' => $afterScore,
                'memo' => '首次交易奖励（补发）',
                'create_time' => $firstBuyTime,
            ]);
            
            Db::name('user_activity_log')->insert([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'first_trade_reward',
                'change_field' => 'score',
                'change_value' => $firstTradeRewardScore,
                'before_value' => $beforeScore,
                'after_value' => $afterScore,
                'remark' => '首次交易奖励消费金（补发）',
                'extra' => json_encode(['reward_score' => $firstTradeRewardScore, 'retroactive' => true], JSON_UNESCAPED_UNICODE),
                'create_time' => $firstBuyTime,
                'update_time' => $firstBuyTime,
            ]);
            
            $totalScoreRewarded += $firstTradeRewardScore;
        }
        
        // 发放算力
        if ($firstTradeRewardPower > 0) {
            $afterPower = round($beforePower + $firstTradeRewardPower, 2);
            Db::name('user')->where('id', $userId)->update([
                'green_power' => $afterPower,
                'update_time' => time(),
            ]);
            
            // 记录算力变动到 user_money_log
            Db::name('user_money_log')->insert([
                'user_id' => $userId,
                'field_type' => 'green_power',
                'money' => $firstTradeRewardPower,
                'before' => $beforePower,
                'after' => $afterPower,
                'memo' => '首次交易奖励算力（补发）',
                'flow_no' => generateSJSFlowNo($userId),
                'batch_no' => generateBatchNo('FIRST_TRADE_RETRO', $userId),
                'biz_type' => 'first_trade_reward_retro',
                'biz_id' => $userId,
                'create_time' => $firstBuyTime,
            ]);
            
            Db::name('user_activity_log')->insert([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'first_trade_reward',
                'change_field' => 'green_power',
                'change_value' => $firstTradeRewardPower,
                'before_value' => $beforePower,
                'after_value' => $afterPower,
                'remark' => '首次交易奖励算力（补发）',
                'extra' => json_encode(['reward_green_power' => $firstTradeRewardPower, 'retroactive' => true], JSON_UNESCAPED_UNICODE),
                'create_time' => $firstBuyTime,
                'update_time' => $firstBuyTime,
            ]);
            
            $totalPowerRewarded += $firstTradeRewardPower;
        }
        
        // 如果 user_type < 1，升级为 1
        $currentUserType = (int)($user['user_type'] ?? 0);
        if ($currentUserType < 1) {
            Db::name('user')->where('id', $userId)->update([
                'user_type' => 1,
                'update_time' => time(),
            ]);
            
            Db::name('user_activity_log')->insert([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'user_type_upgrade',
                'change_field' => 'user_type',
                'change_value' => 1,
                'before_value' => $currentUserType,
                'after_value' => 1,
                'remark' => '首次购买藏品，升级为普通用户（补发）',
                'extra' => json_encode(['purchase_count' => $item['purchase_count'], 'trigger' => 'collection_purchase', 'retroactive' => true], JSON_UNESCAPED_UNICODE),
                'create_time' => $firstBuyTime,
                'update_time' => $firstBuyTime,
            ]);
        }
        
        Db::commit();
        $successCount++;
        output("✓ 用户 {$mobile} (ID: {$userId}) 首次交易奖励补发成功");
    } catch (\Throwable $e) {
        Db::rollback();
        $failCount++;
        output("✗ 用户 {$mobile} (ID: {$userId}) 首次交易奖励补发失败: " . $e->getMessage());
    }
}

// 补发下级首次交易奖励
foreach ($needInviterRewardUsers as $item) {
    $subordinateId = $item['user_id'];
    $subordinateMobile = $item['mobile'];
    $inviterId = $item['inviter_id'];
    $firstBuyTime = $item['first_buy_time'];
    
    try {
        Db::startTrans();
        
        $inviter = Db::name('user')->where('id', $inviterId)->lock(true)->find();
        if (!$inviter) {
            throw new \Exception("邀请人不存在");
        }
        
        $beforeScore = (float)($inviter['score'] ?? 0);
        $beforePower = (float)($inviter['green_power'] ?? 0);
        
        // 发放消费金
        if ($subTradeRewardScore > 0) {
            $afterScore = round($beforeScore + $subTradeRewardScore, 2);
            Db::name('user')->where('id', $inviterId)->update([
                'score' => $afterScore,
                'update_time' => time(),
            ]);
            
            Db::name('user_score_log')->insert([
                'user_id' => $inviterId,
                'score' => $subTradeRewardScore,
                'before' => $beforeScore,
                'after' => $afterScore,
                'memo' => '下级首次交易奖励（补发）',
                'create_time' => $firstBuyTime,
            ]);
            
            Db::name('user_activity_log')->insert([
                'user_id' => $inviterId,
                'related_user_id' => $subordinateId,
                'action_type' => 'subordinate_first_trade_reward',
                'change_field' => 'score',
                'change_value' => $subTradeRewardScore,
                'before_value' => $beforeScore,
                'after_value' => $afterScore,
                'remark' => '下级首次交易奖励消费金（补发）',
                'extra' => json_encode(['reward_score' => $subTradeRewardScore, 'subordinate_id' => $subordinateId, 'retroactive' => true], JSON_UNESCAPED_UNICODE),
                'create_time' => $firstBuyTime,
                'update_time' => $firstBuyTime,
            ]);
            
            $inviterTotalScoreRewarded += $subTradeRewardScore;
        }
        
        // 发放算力
        if ($subTradeRewardPower > 0) {
            $afterPower = round($beforePower + $subTradeRewardPower, 2);
            Db::name('user')->where('id', $inviterId)->update([
                'green_power' => $afterPower,
                'update_time' => time(),
            ]);
            
            // 记录算力变动到 user_money_log
            Db::name('user_money_log')->insert([
                'user_id' => $inviterId,
                'field_type' => 'green_power',
                'money' => $subTradeRewardPower,
                'before' => $beforePower,
                'after' => $afterPower,
                'memo' => '下级首次交易奖励算力（补发）',
                'flow_no' => generateSJSFlowNo($inviterId),
                'batch_no' => generateBatchNo('SUB_TRADE_RETRO', $subordinateId),
                'biz_type' => 'sub_trade_retro',
                'biz_id' => $subordinateId,
                'create_time' => $firstBuyTime,
            ]);
            
            Db::name('user_activity_log')->insert([
                'user_id' => $inviterId,
                'related_user_id' => $subordinateId,
                'action_type' => 'subordinate_first_trade_reward',
                'change_field' => 'green_power',
                'change_value' => $subTradeRewardPower,
                'before_value' => $beforePower,
                'after_value' => $afterPower,
                'remark' => '下级首次交易奖励算力（补发）',
                'extra' => json_encode(['reward_green_power' => $subTradeRewardPower, 'subordinate_id' => $subordinateId, 'retroactive' => true], JSON_UNESCAPED_UNICODE),
                'create_time' => $firstBuyTime,
                'update_time' => $firstBuyTime,
            ]);
            
            $inviterTotalPowerRewarded += $subTradeRewardPower;
        }
        
        Db::commit();
        $inviterSuccessCount++;
        $inviterMobile = $inviter['mobile'] ?? '';
        output("✓ 邀请人 {$inviterMobile} (ID: {$inviterId}) 的下级 {$subordinateMobile} (ID: {$subordinateId}) 首次交易奖励补发成功");
    } catch (\Throwable $e) {
        Db::rollback();
        $inviterFailCount++;
        output("✗ 邀请人 ID: {$inviterId} 的下级 {$subordinateMobile} (ID: {$subordinateId}) 首次交易奖励补发失败: " . $e->getMessage());
    }
}

output("");
output("=== 补发完成 ===");
output("首次交易奖励:");
output("  成功: {$successCount} 个");
output("  失败: {$failCount} 个");
output("  补发消费金: " . number_format($totalScoreRewarded, 2));
output("  补发算力: " . number_format($totalPowerRewarded, 2));
output("");
output("下级首次交易奖励:");
output("  成功: {$inviterSuccessCount} 个");
output("  失败: {$inviterFailCount} 个");
output("  补发消费金: " . number_format($inviterTotalScoreRewarded, 2));
output("  补发算力: " . number_format($inviterTotalPowerRewarded, 2));
output("");
