<?php

namespace app\common\service\core;

use think\facade\Db;
use think\facade\Log;

/**
 * 资金服务类
 * 
 * 负责余额扣款、收益分配、佣金结算等资金流转操作
 * 
 * 账户类型：
 * - balance_available: 专项金（可用于购买）
 * - withdrawable_money: 提现余额
 * - service_fee_balance: 确权金（用于服务费）
 * - score: 消费金/积分
 * 
 * @package app\common\service\core
 * @version 2.0
 * @date 2025-12-28
 */
class FinanceService
{
    // ========================================
    // 常量定义
    // ========================================
    
    /** @var string 账户类型：专项金 */
    const ACCOUNT_BALANCE = 'balance_available';
    /** @var string 账户类型：提现余额 */
    const ACCOUNT_WITHDRAWABLE = 'withdrawable_money';
    /** @var string 账户类型：确权金 */
    const ACCOUNT_SERVICE_FEE = 'service_fee_balance';
    /** @var string 账户类型：消费金 */
    const ACCOUNT_SCORE = 'score';
    
    // ========================================
    // 余额操作方法
    // ========================================
    
    /**
     * 扣除余额
     * 
     * @param int $userId 用户ID
     * @param float $amount 扣除金额（正数）
     * @param string $accountType 账户类型
     * @param string $memo 备注
     * @param array $meta 扩展信息 ['batch_no', 'flow_no', 'biz_type', 'biz_id']
     * @return array ['success' => bool, 'message' => string, 'before' => float, 'after' => float]
     */
    public static function deductBalance(int $userId, float $amount, string $accountType, string $memo, array $meta = []): array
    {
        $now = time();
        
        if ($amount <= 0) {
            return ['success' => false, 'message' => '扣除金额必须大于0'];
        }
        
        Db::startTrans();
        try {
            $user = Db::name('user')
                ->where('id', $userId)
                ->lock(true)
                ->find();
            
            if (!$user) {
                throw new \Exception('用户不存在');
            }
            
            $before = (float)($user[$accountType] ?? 0);
            
            if ($before < $amount) {
                throw new \Exception("余额不足，当前{$accountType}：{$before}");
            }
            
            $after = round($before - $amount, 2);
            
            // 更新余额
            Db::name('user')
                ->where('id', $userId)
                ->update([
                    $accountType => $after,
                    'update_time' => $now,
                ]);
            
            // 记录资金日志
            $logData = [
                'user_id' => $userId,
                'field_type' => $accountType,
                'money' => -$amount,
                'before' => $before,
                'after' => $after,
                'memo' => $memo,
                'create_time' => $now,
            ];
            
            // 填充流水追踪字段
            $logData['flow_no'] = $meta['flow_no'] ?? self::generateFlowNo();
            if (isset($meta['batch_no'])) $logData['batch_no'] = $meta['batch_no'];
            if (isset($meta['biz_type'])) $logData['biz_type'] = $meta['biz_type'];
            if (isset($meta['biz_id'])) $logData['biz_id'] = $meta['biz_id'];
            
            Db::name('user_money_log')->insert($logData);
            
            Db::commit();
            
            return [
                'success' => true,
                'message' => '扣款成功',
                'before' => $before,
                'after' => $after,
            ];
            
        } catch (\Exception $e) {
            Db::rollback();
            Log::error("FinanceService::deductBalance failed: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * 增加余额
     * 
     * @param int $userId 用户ID
     * @param float $amount 增加金额（正数）
     * @param string $accountType 账户类型
     * @param string $memo 备注
     * @param array $meta 扩展信息 ['batch_no', 'flow_no', 'biz_type', 'biz_id']
     * @return array
     */
    public static function addBalance(int $userId, float $amount, string $accountType, string $memo, array $meta = []): array
    {
        $now = time();
        
        if ($amount <= 0) {
            return ['success' => false, 'message' => '增加金额必须大于0'];
        }
        
        Db::startTrans();
        try {
            $user = Db::name('user')
                ->where('id', $userId)
                ->lock(true)
                ->find();
            
            if (!$user) {
                throw new \Exception('用户不存在');
            }
            
            $before = (float)($user[$accountType] ?? 0);
            $after = round($before + $amount, 2);
            
            // 更新余额
            Db::name('user')
                ->where('id', $userId)
                ->update([
                    $accountType => $after,
                    'update_time' => $now,
                ]);
            
            // 记录资金日志
            $logData = [
                'user_id' => $userId,
                'field_type' => $accountType,
                'money' => $amount,
                'before' => $before,
                'after' => $after,
                'memo' => $memo,
                'create_time' => $now,
            ];

            // 填充流水追踪字段
            $logData['flow_no'] = $meta['flow_no'] ?? self::generateFlowNo();
            if (isset($meta['batch_no'])) $logData['batch_no'] = $meta['batch_no'];
            if (isset($meta['biz_type'])) $logData['biz_type'] = $meta['biz_type'];
            if (isset($meta['biz_id'])) $logData['biz_id'] = $meta['biz_id'];
            
            Db::name('user_money_log')->insert($logData);
            
            Db::commit();
            
            return [
                'success' => true,
                'message' => '入账成功',
                'before' => $before,
                'after' => $after,
            ];
            
        } catch (\Exception $e) {
            Db::rollback();
            Log::error("FinanceService::addBalance failed: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    // ========================================
    // 收益分配方法
    // ========================================
    
    /**
     * 分配卖家收益
     * 
     * 规则：
     * 1. 本金全额退回到提现余额
     * 2. 服务费返还
     * 3. 剩余利润对半分配：提现余额 + 消费金
     * 
     * @param int $sellerId 卖家用户ID
     * @param float $sellPrice 卖出价格
     * @param float $originalPrice 原购买价格（本金）
     * @param string $itemTitle 藏品标题
     * @return array
     */
    public static function distributeSellerIncome(int $sellerId, float $sellPrice, float $originalPrice, string $itemTitle = '', ?int $consignmentId = null): array
    {
        $now = time();
        // 生成批次号，串联本次分配的所有资金变动
        $batchNo = self::generateBatchNo();
        $bizType = 'consignment_settle';
        $bizId = $consignmentId ?? 0;
        
        $meta = [
            'batch_no' => $batchNo,
            'biz_type' => $bizType,
            'biz_id' => $bizId
        ];
        
        Db::startTrans();
        try {
            $seller = Db::name('user')
                ->where('id', $sellerId)
                ->lock(true)
                ->find();
            
            if (!$seller) {
                throw new \Exception('卖家用户不存在');
            }
            
            // 🆕 判断是否是旧资产包（旧资产包不返还手续费）
            $isOldAssetPackage = false;
            if ($consignmentId) {
                $consignment = Db::name('collection_consignment')->where('id', $consignmentId)->find();
                if ($consignment && !empty($consignment['user_collection_id'])) {
                    $userCollection = Db::name('user_collection')->where('id', $consignment['user_collection_id'])->find();
                    $isOldAssetPackage = $userCollection && (int)($userCollection['is_old_asset_package'] ?? 0) === 1;
                }
            }
            
            // 计算各项金额
            // 1. 计算实际支付的手续费（基于寄售价格）
            $serviceFeeRate = (float)(get_sys_config('consignment_service_fee_rate') ?? 0.03);
            // 旧资产包不返还手续费
            $feePaid = $isOldAssetPackage ? 0 : round($originalPrice * $serviceFeeRate, 2); 
            
            // 2. 计算净利润 (寄售价格 - 本金 - 已付手续费)
            $netProfit = max(0, round($sellPrice - $originalPrice - $feePaid, 2));
            
            // 3. 计算分红比例（从配置读取）
            $splitRate = (float)(get_sys_config('seller_profit_split_rate') ?? 0.5);
            if ($splitRate < 0 || $splitRate > 1) {
                $splitRate = 0.5;
            }
            
            $profitToWithdrawable = round($netProfit * $splitRate, 2);
            $profitToScore = round($netProfit * (1 - $splitRate), 2);
            
            // 4. 卖家提现余额增加 = 本金 + 手续费返还(全额，旧资产包为0) + 利润分红
            // 注意：这里返还的是 feePaid (用户实际支付的费用)，而不是基于本金计算的费用
            $totalToWithdrawable = $originalPrice + $feePaid + $profitToWithdrawable;
            
            // 更新余额
            $beforeWithdrawable = (float)$seller['withdrawable_money'];
            $beforeScore = (float)$seller['score'];
            
            $afterWithdrawable = round($beforeWithdrawable + $totalToWithdrawable, 2);
            $afterScore = round($beforeScore + $profitToScore, 2);
            
            Db::name('user')->where('id', $sellerId)->update([
                'withdrawable_money' => $afterWithdrawable,
                'score' => $afterScore,
                'update_time' => $now,
            ]);
            
            // 记录本金退回日志
            Db::name('user_money_log')->insert([
                'user_id' => $sellerId,
                'field_type' => 'withdrawable_money',
                'money' => $originalPrice,
                'before' => $beforeWithdrawable,
                'after' => round($beforeWithdrawable + $originalPrice, 2),
                'memo' => '【藏品本金】' . $itemTitle,
                'create_time' => $now,
                'flow_no' => self::generateFlowNo(),
                'batch_no' => $batchNo,
                'biz_type' => $bizType,
                'biz_id' => $bizId,
            ]);
            
            // 记录收益日志 (包含 手续费返还 + 利润分红)
            $totalIncome = $feePaid + $profitToWithdrawable;
            if ($totalIncome > 0) {
                Db::name('user_money_log')->insert([
                    'user_id' => $sellerId,
                    'field_type' => 'withdrawable_money',
                    'money' => $totalIncome,
                    'before' => round($beforeWithdrawable + $originalPrice, 2),
                    'after' => $afterWithdrawable,
                    'memo' => '【增值收益】' . $itemTitle,
                    'create_time' => $now,
                    'flow_no' => self::generateFlowNo(),
                    'batch_no' => $batchNo,
                    'biz_type' => $bizType,
                    'biz_id' => $bizId,
                ]);
            }
            
            // 记录消费金收益
            if ($profitToScore > 0) {
                Db::name('user_score_log')->insert([
                    'user_id' => $sellerId,
                    'score' => $profitToScore,
                    'before' => $beforeScore,
                    'after' => $afterScore,
                    'memo' => '【消费金收益】' . $itemTitle,
                    'create_time' => $now,
                    'flow_no' => self::generateFlowNo(),
                    'batch_no' => $batchNo,
                    'biz_type' => $bizType,
                    'biz_id' => $bizId,
                ]);
            }
            
            // 记录活动日志
            Db::name('user_activity_log')->insert([
                'user_id' => $sellerId,
                'action_type' => 'seller_income',
                'change_field' => 'withdrawable_money,score',
                'change_value' => json_encode([
                    'withdrawable_money' => $totalToWithdrawable,
                    'score' => $profitToScore,
                ], JSON_UNESCAPED_UNICODE),
                'before_value' => json_encode([
                    'withdrawable_money' => $beforeWithdrawable,
                    'score' => $beforeScore,
                ], JSON_UNESCAPED_UNICODE),
                'after_value' => json_encode([
                    'withdrawable_money' => $afterWithdrawable,
                    'score' => $afterScore,
                ], JSON_UNESCAPED_UNICODE),
                'remark' => sprintf('卖出:%s. 本金:%.2f. 提现收益:%.2f. 消费金收益:%.2f', 
                    $itemTitle, $originalPrice, round($feePaid + $profitToWithdrawable, 2), $profitToScore),
                'create_time' => $now,
            ]);
            
            Db::commit();
            
            // 更新寄售记录的结算快照字段（如果有 consignmentId）
            if ($consignmentId && $consignmentId > 0) {
                try {
                    $consignment = Db::name('collection_consignment')
                        ->where('id', $consignmentId)
                        ->find();
                    
                    if ($consignment) {
                        $serviceFee = (float)($consignment['service_fee'] ?? 0);
                        $serviceFeePaidAtApply = true; // 默认在申请时已扣
                        
                        // 调用更新结算快照方法
                        \app\common\service\ConsignmentService::updateConsignmentSettlement(
                            $consignmentId,
                            $sellPrice,
                            $originalPrice,
                            $serviceFee,
                            $serviceFeePaidAtApply,
                            [
                                'principal_amount' => $originalPrice,
                                'profit_amount' => $netProfit,
                                'payout_principal_withdrawable' => $originalPrice,
                                'payout_principal_consume' => 0.00,
                                'payout_profit_withdrawable' => $profitToWithdrawable,
                            'payout_profit_consume' => $profitToScore,
                            ]
                        );
                    }
                } catch (\Exception $e) {
                    // 快照更新失败不影响主流程，只记录日志
                    Log::error("FinanceService: 更新结算快照失败 - " . $e->getMessage(), [
                        'consignment_id' => $consignmentId,
                    ]);
                }
            }
            
            Log::info("FinanceService::distributeSellerIncome success", [
                'seller_id' => $sellerId,
                'original_price' => $originalPrice,
                'sell_price' => $sellPrice,
                'net_profit' => $netProfit,
                'to_withdrawable' => $totalToWithdrawable,
                'to_score' => $profitToScore,
            ]);
            
            return [
                'success' => true,
                'original_price' => $originalPrice,
                'sell_price' => $sellPrice,
                'net_profit' => $netProfit,
                'fee_paid' => $feePaid,
                'to_withdrawable' => $totalToWithdrawable,
                'to_service_fee' => $profitToServiceFee,
            ];
            
        } catch (\Exception $e) {
            Db::rollback();
            Log::error("FinanceService::distributeSellerIncome failed: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * 分配代理佣金
     * 
     * @param int $sellerId 卖家ID（佣金从其上级链路分配）
     * @param float $profit 利润（佣金计算基数）
     * @param array $orderInfo 订单信息 ['item_title', 'order_no', 'order_id', 'consignment_id']
     * @return array
     */
    public static function distributeAgentCommission(int $sellerId, float $profit, array $orderInfo = []): array
    {
        $now = time();
        $results = [];
        
        if ($profit <= 0) {
            return ['success' => true, 'message' => '无利润，跳过佣金分配'];
        }
        
        try {
            $seller = Db::name('user')->where('id', $sellerId)->find();
            if (!$seller) {
                return ['success' => false, 'message' => '卖家不存在'];
            }
            
            // 获取上级链路
            $inviteChain = [];
            $currentUserId = $sellerId;
            $maxLevel = 3; // 最多向上查3级
            
            for ($level = 1; $level <= $maxLevel; $level++) {
                $currentUser = Db::name('user')->where('id', $currentUserId)->find();
                if (!$currentUser || empty($currentUser['inviter_id'])) {
                    break;
                }

                $parentId = (int)$currentUser['inviter_id'];
                $parent = Db::name('user')->where('id', $parentId)->find();
                if (!$parent) {
                    break;
                }
                
                $inviteChain[] = [
                    'user_id' => $parentId,
                    'level' => $level,
                    'user_type' => (int)$parent['user_type'],
                ];
                $currentUserId = $parentId;
            }
            
            // 分配佣金（根据代理等级）
            foreach ($inviteChain as $agent) {
                $agentId = $agent['user_id'];
                $agentLevel = $agent['level'];
                $agentType = $agent['user_type'];
                
                // 获取佣金比例配置
                $commissionRate = self::getAgentCommissionRate($agentType, $agentLevel);
                if ($commissionRate <= 0) {
                    continue;
                }
                
                $commission = round($profit * $commissionRate, 2);
                if ($commission <= 0) {
                    continue;
                }
                
                // 发放佣金到提现余额
                $result = self::addBalance($agentId, $commission, self::ACCOUNT_WITHDRAWABLE, 
                    sprintf('代理佣金（%s - L%d）', $orderInfo['item_title'] ?? '', $agentLevel),
                    // 传递原始订单的元信息，但每一笔佣金单独算作一个子流水，暂不强制共用seller的batch_no，
                    // 但为了追踪可以复用batch_no，或者生成新的。这里复用传入的batch_no如果需要在上层支持。
                    // 暂时这里不传meta，后续可扩展
                );
                
                $results[] = [
                    'agent_id' => $agentId,
                    'level' => $agentLevel,
                    'commission' => $commission,
                    'success' => $result['success'],
                ];
                
                // 记录活动日志
                Db::name('user_activity_log')->insert([
                    'user_id' => $agentId,
                    'related_user_id' => $sellerId,
                    'action_type' => 'agent_commission',
                    'change_field' => 'withdrawable_money',
                    'change_value' => (string)$commission,
                    'remark' => sprintf('代理佣金 L%d：%s', $agentLevel, $orderInfo['item_title'] ?? ''),
                    'extra' => json_encode([
                        'seller_id' => $sellerId,
                        'order_no' => $orderInfo['order_no'] ?? '',
                        'order_id' => $orderInfo['order_id'] ?? 0,
                        'profit' => $profit,
                        'rate' => $commissionRate,
                    ], JSON_UNESCAPED_UNICODE),
                    'create_time' => $now,
                ]);
            }
            
            return [
                'success' => true,
                'distributions' => $results,
            ];
            
        } catch (\Exception $e) {
            Log::error("FinanceService::distributeAgentCommission failed: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * 获取代理佣金比例
     * 
     * @param int $agentType 代理类型
     * @param int $level 层级（1=直推，2=间推，3=三级）
     * @return float 佣金比例
     */
    private static function getAgentCommissionRate(int $agentType, int $level): float
    {
        // 从配置读取佣金比例（优先使用特定配置）
        $configKey = "agent_commission_l{$level}_type{$agentType}";
        $rate = get_sys_config($configKey);
        
        if ($rate !== null && is_numeric($rate)) {
            return (float)$rate;
        }
        
        // 使用通用配置（从后台配置读取）
        $defaults = [
            1 => (float)(get_sys_config('agent_direct_rate') ?? 0.10),   // 直推佣金比例
            2 => (float)(get_sys_config('agent_indirect_rate') ?? 0.05), // 间推佣金比例
            3 => 0.02, // 三级暂无配置，使用默认值
        ];
        
        return $defaults[$level] ?? 0;
    }
    
    /**
     * 生成唯一流水号 (34位: FN + UUID去横杠)
     */
    public static function generateFlowNo(): string
    {
        return 'FN' . str_replace('-', '', uuid());
    }
    
    /**
     * 生成唯一批次号 (34位: BN + UUID去横杠)
     */
    public static function generateBatchNo(): string
    {
        return 'BN' . str_replace('-', '', uuid());
    }
}
