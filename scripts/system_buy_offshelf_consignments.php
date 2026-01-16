<?php
/**
 * 系统买入已下架的寄售订单
 * 
 * 用法：
 *   模拟运行：php scripts/system_buy_offshelf_consignments.php --dry-run
 *   实际执行：php scripts/system_buy_offshelf_consignments.php --execute
 * 
 * 功能：
 *   1. 将下架(status=3)的寄售订单改为已售出(status=2)
 *   2. 给卖家发放收益（50%可提现，50%消费金）
 *   3. 分配代理佣金（直推、间推、团队奖）
 *   4. 更新用户藏品状态
 *   5. 藏品回归系统库存
 *   6. 完整记录流水日志
 */

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

use think\facade\Db;

// 解析命令行参数
$dryRun = in_array('--dry-run', $argv);
$execute = in_array('--execute', $argv);

if (!$dryRun && !$execute) {
    echo "用法:\n";
    echo "  模拟运行: php scripts/system_buy_offshelf_consignments.php --dry-run\n";
    echo "  实际执行: php scripts/system_buy_offshelf_consignments.php --execute\n";
    exit(1);
}

$now = time();
$mode = $dryRun ? '模拟运行' : '实际执行';

echo "========================================\n";
echo "系统买入已下架寄售订单\n";
echo "模式: {$mode}\n";
echo "执行时间: " . date('Y-m-d H:i:s', $now) . "\n";
echo "========================================\n\n";

// 从配置读取佣金比例
$directRate = (float)(get_sys_config('agent_direct_rate') ?? 0.10);
$indirectRate = (float)(get_sys_config('agent_indirect_rate') ?? 0.05);
$teamRates = [
    1 => (float)(get_sys_config('agent_team_level1') ?? 0.09),
    2 => (float)(get_sys_config('agent_team_level2') ?? 0.12),
    3 => (float)(get_sys_config('agent_team_level3') ?? 0.15),
    4 => (float)(get_sys_config('agent_team_level4') ?? 0.18),
    5 => (float)(get_sys_config('agent_team_level5') ?? 0.21),
];
$sameLevelRate = (float)(get_sys_config('agent_same_level_rate') ?? 0.10);

echo "佣金配置：直推{$directRate}，间推{$indirectRate}\n";
echo "团队奖：" . implode('/', array_values($teamRates)) . "\n\n";

// 获取手续费率配置
$serviceFeeRate = (float)(get_sys_config('consignment_service_fee_rate') ?? 0.03);
$splitRate = (float)(get_sys_config('seller_profit_split_rate') ?? 0.5);
echo "手续费率：{$serviceFeeRate}，利润分割率：{$splitRate}\n\n";

// 查询需要处理的下架寄售订单（场次2，17:04下架的）
$consignments = Db::name('collection_consignment')
    ->alias('c')
    ->leftJoin('user_collection uc', 'c.user_collection_id = uc.id')
    ->leftJoin('collection_item ci', 'c.item_id = ci.id')
    ->where('c.status', 3)  // 已下架
    ->where('c.session_id', 2)
    ->where('c.update_time', '>=', strtotime('2026-01-16 17:04:00'))
    ->field('c.*, uc.price as buy_price, uc.is_old_asset_package, ci.title as item_title, ci.image as item_image')
    ->select()
    ->toArray();

$totalCount = count($consignments);
echo "找到 {$totalCount} 个需要处理的寄售订单\n\n";

if ($totalCount == 0) {
    echo "没有需要处理的订单，退出\n";
    exit(0);
}

// 统计
$stats = [
    'success' => 0,
    'failed' => 0,
    'total_income' => 0,
    'total_withdrawable' => 0,
    'total_score' => 0,
    'total_commission' => 0,
];

// 处理每个寄售订单
foreach ($consignments as $index => $consignment) {
    $consignmentId = (int)$consignment['id'];
    $sellerId = (int)$consignment['user_id'];
    $itemId = (int)$consignment['item_id'];
    $userCollectionId = (int)$consignment['user_collection_id'];
    $sellPrice = (float)$consignment['price'];
    $serviceFee = (float)$consignment['service_fee'];
    $buyPrice = (float)($consignment['buy_price'] ?? 0);
    $itemTitle = $consignment['item_title'] ?? '藏品';
    
    // 判断是否是旧资产包
    $isOldAssetPackage = (int)($consignment['is_old_asset_package'] ?? 0) === 1;
    
    // 计算利润（售价 - 买入价）
    $profit = round($sellPrice - $buyPrice, 2);
    if ($profit < 0) $profit = 0;
    
    // 正确的收益分配逻辑：
    // 新资产：本金 + 手续费返还(本金*3%) + 剩余利润50% → 可提现，剩余利润50% → 消费金
    // 旧资产：本金 + 利润50% → 可提现，利润50% → 消费金（不返还手续费）
    
    // 手续费返还（旧资产包不返还）
    $feeRefund = $isOldAssetPackage ? 0 : round($buyPrice * $serviceFeeRate, 2);
    
    // 剩余利润 = 利润 - 手续费返还
    $remainingProfit = max(0, $profit - $feeRefund);
    
    // 剩余利润拆分
    $profitToWithdrawable = round($remainingProfit * $splitRate, 2);
    $profitToScore = round($remainingProfit * (1 - $splitRate), 2);
    
    // 卖家可提现 = 本金 + 手续费返还 + 剩余利润的一半
    $toWithdrawable = round($buyPrice + $feeRefund + $profitToWithdrawable, 2);
    $toScore = $profitToScore;  // 剩余利润的另一半 → 消费金
    
    $num = $index + 1;
    $assetType = $isOldAssetPackage ? '旧资产' : '新资产';
    echo "[{$num}/{$totalCount}] 寄售ID:{$consignmentId} 卖家:{$sellerId} 售价:{$sellPrice} 成本:{$buyPrice} 利润:{$profit} [{$assetType}]\n";
    echo "  分配: 本金{$buyPrice}+费返{$feeRefund}+利润50%={$toWithdrawable}(可提现), 利润50%={$toScore}(消费金)\n";
    
    if ($dryRun) {
        // 模拟运行只统计
        $stats['total_income'] += ($toWithdrawable + $toScore);
        $stats['total_withdrawable'] += $toWithdrawable;
        $stats['total_score'] += $toScore;
        
        // 计算佣金（模拟）
        if ($profit > 0) {
            $directCommission = round($profit * $directRate, 2);
            $indirectCommission = round($profit * $indirectRate, 2);
            echo "  佣金(预估): 直推={$directCommission}, 间推={$indirectCommission}\n";
            $stats['total_commission'] += $directCommission + $indirectCommission;
        }
        
        echo "  清除: 下架日志+免费寄售次数-1\n";
        
        $stats['success']++;
        continue;
    }
    
    // === 实际执行 ===
    Db::startTrans();
    try {
        // 1. 获取卖家信息（加锁）
        $seller = Db::name('user')->where('id', $sellerId)->lock(true)->find();
        if (!$seller) {
            throw new Exception("卖家不存在");
        }
        
        $beforeWithdrawable = (float)$seller['withdrawable_money'];
        $beforeScore = (float)$seller['score'];
        $afterWithdrawable = round($beforeWithdrawable + $toWithdrawable, 2);
        $afterScore = round($beforeScore + $toScore, 2);
        
        // 2. 更新寄售状态为已售出
        Db::name('collection_consignment')
            ->where('id', $consignmentId)
            ->update([
                'status' => 2, // 已售出
                'sold_price' => $sellPrice,
                'sold_time' => $now,
                'update_time' => $now,
            ]);
        
        // 3. 更新卖家余额
        Db::name('user')
            ->where('id', $sellerId)
            ->update([
                'withdrawable_money' => $afterWithdrawable,
                'score' => $afterScore,
                'update_time' => $now,
            ]);
        
        // 4. 记录资金日志 - 本金退回
        $flowNo1 = generateSJSFlowNo($sellerId);
        $batchNo = generateBatchNo('SYSTEM_BUY', $consignmentId);
        $logBefore = $beforeWithdrawable;
        $logAfter = round($logBefore + $buyPrice, 2);
        
        Db::name('user_money_log')->insert([
            'user_id' => $sellerId,
            'flow_no' => $flowNo1,
            'batch_no' => $batchNo,
            'biz_type' => 'consignment_sold',
            'biz_id' => $consignmentId,
            'field_type' => 'withdrawable_money',
            'money' => $buyPrice,
            'before' => $logBefore,
            'after' => $logAfter,
            'memo' => '交易' . $itemTitle . '成功',
            'create_time' => $now,
        ]);
        
        // 5. 记录交易收益日志（手续费返还 + 利润50%）
        $incomePart = round($feeRefund + $profitToWithdrawable, 2);
        if ($incomePart > 0) {
            $flowNo2 = generateSJSFlowNo($sellerId);
            $logBefore = $logAfter;
            $logAfter = round($logBefore + $incomePart, 2);
            
            Db::name('user_money_log')->insert([
                'user_id' => $sellerId,
                'flow_no' => $flowNo2,
                'batch_no' => $batchNo,
                'biz_type' => 'consignment_sold',
                'biz_id' => $consignmentId,
                'field_type' => 'withdrawable_money',
                'money' => $incomePart,
                'before' => $logBefore,
                'after' => $logAfter,
                'memo' => '【交易收益】' . $itemTitle,
                'create_time' => $now,
            ]);
        }
        
        // 6. 记录消费金日志（利润50%）- 只有有利润时才记录
        if ($toScore > 0) {
            $flowNo3 = generateSJSFlowNo($sellerId);
            Db::name('user_score_log')->insert([
                'user_id' => $sellerId,
                'flow_no' => $flowNo3,
                'batch_no' => $batchNo,
                'biz_type' => 'consignment_sold',
                'biz_id' => $consignmentId,
                'user_collection_id' => $userCollectionId,
                'item_id' => $itemId,
                'title_snapshot' => $itemTitle,
                'image_snapshot' => $consignment['item_image'] ?? '',
                'score' => $toScore,
                'before' => $beforeScore,
                'after' => $afterScore,
                'memo' => '【确权收益】' . $itemTitle,
                'create_time' => $now,
            ]);
        }
        
        // 7. 记录活动日志
        Db::name('user_activity_log')->insert([
            'user_id' => $sellerId,
            'related_user_id' => 0,
            'action_type' => 'consignment_sold',
            'change_field' => 'withdrawable_money,score',
            'change_value' => json_encode([
                'withdrawable_money' => $toWithdrawable,
                'score' => $toScore,
            ], JSON_UNESCAPED_UNICODE),
            'before_value' => json_encode([
                'withdrawable_money' => $beforeWithdrawable,
                'score' => $beforeScore,
            ], JSON_UNESCAPED_UNICODE),
            'after_value' => json_encode([
                'withdrawable_money' => $afterWithdrawable,
                'score' => $afterScore,
            ], JSON_UNESCAPED_UNICODE),
            'remark' => sprintf('卖出:%s. 本金:%.2f. 费返:%.2f. 利润:%.2f', 
                $itemTitle, $buyPrice, $feeRefund, $profit),
            'create_time' => $now,
            'update_time' => $now,
        ]);
        
        // 8. 更新用户藏品状态为已售出，并清除下架时增加的免费寄售次数
        if ($userCollectionId > 0) {
            // 获取当前免费次数
            $currentFreeAttempts = (int)Db::name('user_collection')
                ->where('id', $userCollectionId)
                ->value('free_consign_attempts');
            
            // 减少1次（下架时增加的），最少为0
            $newFreeAttempts = max(0, $currentFreeAttempts - 1);
            
            Db::name('user_collection')
                ->where('id', $userCollectionId)
                ->update([
                    'consignment_status' => 2, // 已售出
                    'free_consign_attempts' => $newFreeAttempts,
                    'update_time' => $now,
                ]);
        }
        
        // 9. 藏品回归系统库存
        Db::name('collection_item')
            ->where('id', $itemId)
            ->update([
                'owner_id' => 0,
                'stock' => 1,
                'update_time' => $now,
            ]);
        
        // 10. 删除下架时记录的活动日志
        Db::name('user_activity_log')
            ->where('user_id', $sellerId)
            ->where('action_type', 'consignment_offshelf')
            ->where('remark', 'like', "%寄售ID#{$consignmentId}%")
            ->where('create_time', '>=', strtotime('2026-01-16 17:04:00'))
            ->delete();
        
        // 11. 分配代理佣金（如果有利润）
        if ($profit > 0) {
            distributeAgentCommission($sellerId, $profit, $itemTitle, $consignmentId, $now, $stats,
                $directRate, $indirectRate, $teamRates, $sameLevelRate);
        }
        
        Db::commit();
        
        $stats['success']++;
        $stats['total_income'] += ($toWithdrawable + $toScore);
        $stats['total_withdrawable'] += $toWithdrawable;
        $stats['total_score'] += $toScore;
        
        echo "  ✅ 成功\n";
        
    } catch (Throwable $e) {
        Db::rollback();
        $stats['failed']++;
        echo "  ❌ 失败: " . $e->getMessage() . "\n";
    }
}

echo "\n========================================\n";
echo "处理完成\n";
echo "成功: {$stats['success']}\n";
echo "失败: {$stats['failed']}\n";
echo "总收入: " . number_format($stats['total_income'], 2) . "\n";
echo "总可提现: " . number_format($stats['total_withdrawable'], 2) . "\n";
echo "总消费金: " . number_format($stats['total_score'], 2) . "\n";
echo "总佣金: " . number_format($stats['total_commission'], 2) . "\n";
echo "========================================\n";

/**
 * 分配代理佣金
 */
function distributeAgentCommission($sellerId, $profit, $itemTitle, $consignmentId, $now, &$stats,
    $directRate, $indirectRate, $teamRates, $sameLevelRate)
{
    // 获取卖家信息
    $seller = Db::name('user')->where('id', $sellerId)->find();
    if (!$seller) {
        return;
    }
    
    // 1. 直推佣金
    $directInviterId = (int)($seller['inviter_id'] ?? 0);
    $directInviter = null;
    if ($directInviterId > 0) {
        $directInviter = Db::name('user')->where('id', $directInviterId)->lock(true)->find();
        
        if ($directInviter) {
            $directCommission = round($profit * $directRate, 2);
            if ($directCommission > 0) {
                $beforeWithdrawable = (float)$directInviter['withdrawable_money'];
                $afterWithdrawable = round($beforeWithdrawable + $directCommission, 2);
                
                Db::name('user')
                    ->where('id', $directInviterId)
                    ->update([
                        'withdrawable_money' => $afterWithdrawable,
                        'update_time' => $now,
                    ]);
                
                $flowNo = generateSJSFlowNo($directInviterId);
                $batchNo = generateBatchNo('SYSTEM_BUY_COMMISSION', $consignmentId);
                
                Db::name('user_money_log')->insert([
                    'user_id' => $directInviterId,
                    'flow_no' => $flowNo,
                    'batch_no' => $batchNo,
                    'biz_type' => 'matching_commission',
                    'biz_id' => $consignmentId,
                    'field_type' => 'withdrawable_money',
                    'money' => $directCommission,
                    'before' => $beforeWithdrawable,
                    'after' => $afterWithdrawable,
                    'memo' => '【一级】直推佣金：' . $itemTitle . '（利润：' . number_format($profit, 2) . '元）',
                    'create_time' => $now,
                ]);
                
                Db::name('user_activity_log')->insert([
                    'user_id' => $directInviterId,
                    'related_user_id' => $sellerId,
                    'action_type' => 'agent_direct_commission',
                    'change_field' => 'withdrawable_money',
                    'change_value' => (string)$directCommission,
                    'before_value' => (string)$beforeWithdrawable,
                    'after_value' => (string)$afterWithdrawable,
                    'remark' => '【一级】直推佣金：' . $itemTitle . '（利润：' . number_format($profit, 2) . '元）',
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
                
                $stats['total_commission'] += $directCommission;
                echo "    直推佣金: 用户{$directInviterId} 获得 {$directCommission}\n";
            }
        }
    }
    
    // 2. 间推佣金
    if ($directInviter && $directInviterId > 0) {
        $indirectInviterId = (int)($directInviter['inviter_id'] ?? 0);
        if ($indirectInviterId > 0) {
            $indirectInviter = Db::name('user')->where('id', $indirectInviterId)->lock(true)->find();
            
            if ($indirectInviter) {
                $indirectCommission = round($profit * $indirectRate, 2);
                if ($indirectCommission > 0) {
                    $beforeWithdrawable = (float)$indirectInviter['withdrawable_money'];
                    $afterWithdrawable = round($beforeWithdrawable + $indirectCommission, 2);
                    
                    Db::name('user')
                        ->where('id', $indirectInviterId)
                        ->update([
                            'withdrawable_money' => $afterWithdrawable,
                            'update_time' => $now,
                        ]);
                    
                    $flowNo = generateSJSFlowNo($indirectInviterId);
                    $batchNo = generateBatchNo('SYSTEM_BUY_COMMISSION', $consignmentId);
                    
                    Db::name('user_money_log')->insert([
                        'user_id' => $indirectInviterId,
                        'flow_no' => $flowNo,
                        'batch_no' => $batchNo,
                        'biz_type' => 'matching_commission',
                        'biz_id' => $consignmentId,
                        'field_type' => 'withdrawable_money',
                        'money' => $indirectCommission,
                        'before' => $beforeWithdrawable,
                        'after' => $afterWithdrawable,
                        'memo' => '【二级】间推佣金：' . $itemTitle . '（利润：' . number_format($profit, 2) . '元）',
                        'create_time' => $now,
                    ]);
                    
                    Db::name('user_activity_log')->insert([
                        'user_id' => $indirectInviterId,
                        'related_user_id' => $sellerId,
                        'action_type' => 'agent_indirect_commission',
                        'change_field' => 'withdrawable_money',
                        'change_value' => (string)$indirectCommission,
                        'before_value' => (string)$beforeWithdrawable,
                        'after_value' => (string)$afterWithdrawable,
                        'remark' => '【二级】间推佣金：' . $itemTitle . '（利润：' . number_format($profit, 2) . '元）',
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);
                    
                    $stats['total_commission'] += $indirectCommission;
                    echo "    间推佣金: 用户{$indirectInviterId} 获得 {$indirectCommission}\n";
                }
            }
        }
    }
    
    // 3. 代理团队奖（向上查找代理）
    $agentChain = [];
    $searchUserId = $sellerId;
    
    for ($searchDepth = 0; $searchDepth < 10; $searchDepth++) {
        $searchUser = Db::name('user')->where('id', $searchUserId)->find();
        if (!$searchUser) break;
        
        $inviterId = (int)($searchUser['inviter_id'] ?? 0);
        if ($inviterId <= 0) break;
        
        $inviter = Db::name('user')->where('id', $inviterId)->find();
        if (!$inviter) break;
        
        $agentLevel = (int)$inviter['user_type'] - 2;
        if ($agentLevel >= 1 && $agentLevel <= 5) {
            $agentChain[] = [
                'user_id' => $inviterId,
                'agent_level' => $agentLevel,
                'user_type' => (int)$inviter['user_type'],
            ];
        }
        
        $searchUserId = $inviterId;
    }
    
    if (empty($agentChain)) {
        return;
    }
    
    $lastPaidLevel = 0;
    $lastPaidAgentLevel = 0;
    
    foreach ($agentChain as $agent) {
        $agentUserId = $agent['user_id'];
        $agentLevel = $agent['agent_level'];
        
        if ($agentLevel <= $lastPaidAgentLevel) {
            continue;
        }
        
        $currentRate = $teamRates[$agentLevel] ?? 0;
        $prevRate = $lastPaidAgentLevel > 0 ? ($teamRates[$lastPaidAgentLevel] ?? 0) : 0;
        $actualRate = $currentRate - $prevRate;
        
        if ($actualRate <= 0) {
            continue;
        }
        
        $teamCommission = round($profit * $actualRate, 2);
        if ($teamCommission <= 0) {
            continue;
        }
        
        $agentUser = Db::name('user')->where('id', $agentUserId)->lock(true)->find();
        if (!$agentUser) {
            continue;
        }
        
        $beforeWithdrawable = (float)$agentUser['withdrawable_money'];
        $afterWithdrawable = round($beforeWithdrawable + $teamCommission, 2);
        
        Db::name('user')
            ->where('id', $agentUserId)
            ->update([
                'withdrawable_money' => $afterWithdrawable,
                'update_time' => $now,
            ]);
        
        $flowNo = generateSJSFlowNo($agentUserId);
        $batchNo = generateBatchNo('SYSTEM_BUY_TEAM', $consignmentId);
        
        Db::name('user_money_log')->insert([
            'user_id' => $agentUserId,
            'flow_no' => $flowNo,
            'batch_no' => $batchNo,
            'biz_type' => 'matching_commission',
            'biz_id' => $consignmentId,
            'field_type' => 'withdrawable_money',
            'money' => $teamCommission,
            'before' => $beforeWithdrawable,
            'after' => $afterWithdrawable,
            'memo' => "【{$agentLevel}级代理】团队奖：{$itemTitle}（利润：" . number_format($profit, 2) . "元）",
            'create_time' => $now,
        ]);
        
        Db::name('user_activity_log')->insert([
            'user_id' => $agentUserId,
            'related_user_id' => $sellerId,
            'action_type' => 'agent_team_commission',
            'change_field' => 'withdrawable_money',
            'change_value' => (string)$teamCommission,
            'before_value' => (string)$beforeWithdrawable,
            'after_value' => (string)$afterWithdrawable,
            'remark' => "【{$agentLevel}级代理】团队奖：{$itemTitle}（利润：" . number_format($profit, 2) . "元）",
            'create_time' => $now,
            'update_time' => $now,
        ]);
        
        $stats['total_commission'] += $teamCommission;
        echo "    团队奖({$agentLevel}级): 用户{$agentUserId} 获得 {$teamCommission}\n";
        
        $lastPaidAgentLevel = $agentLevel;
    }
}
