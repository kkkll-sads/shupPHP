<?php

namespace app\common\service;

use think\facade\Db;
use think\facade\Log;

/**
 * 寄售状态统一管理服务
 * 
 * 该服务统一管理 collection_consignment 和 user_collection 两个表的寄售状态同步
 * 所有寄售状态变更都应通过该服务进行，以确保数据一致性
 * 
 * 状态定义：
 * - collection_consignment.status: 0=已取消, 1=寄售中, 2=已售出, 3=已下架/流拍
 * - user_collection.consignment_status: 0=未寄售, 1=寄售中, 2=已售出
 */
class ConsignmentService
{
    // 寄售记录状态常量
    const STATUS_CANCELLED = 0;    // 已取消
    const STATUS_SELLING = 1;      // 寄售中
    const STATUS_SOLD = 2;         // 已售出
    const STATUS_OFF_SHELF = 3;    // 已下架/流拍

    // 用户藏品寄售状态常量
    const UC_STATUS_NONE = 0;      // 未寄售
    const UC_STATUS_SELLING = 1;   // 寄售中
    const UC_STATUS_SOLD = 2;      // 已售出

    /**
     * 创建寄售记录（上架）
     * 同时更新 user_collection.consignment_status = 1
     * 
     * @param int $userCollectionId 用户藏品ID
     * @param array $consignmentData 寄售记录数据
     * @return int|false 寄售记录ID或false
     */
    public static function createConsignment(int $userCollectionId, array $consignmentData)
    {
        $now = time();
        
        try {
            Db::startTrans();
            
            // 验证用户藏品存在且未寄售
            $userCollection = Db::name('user_collection')
                ->where('id', $userCollectionId)
                ->lock(true)
                ->find();
            
            if (!$userCollection) {
                throw new \Exception('用户藏品不存在');
            }
            
            if ((int)$userCollection['consignment_status'] !== self::UC_STATUS_NONE) {
                throw new \Exception('该藏品已在寄售中');
            }
            
            // 创建寄售记录
            $consignmentData['user_collection_id'] = $userCollectionId;
            $consignmentData['status'] = self::STATUS_SELLING;
            $consignmentData['create_time'] = $consignmentData['create_time'] ?? $now;
            $consignmentData['update_time'] = $now;
            
            $consignmentId = Db::name('collection_consignment')->insertGetId($consignmentData);
            
            // 同步更新用户藏品状态
            Db::name('user_collection')
                ->where('id', $userCollectionId)
                ->update([
                    'consignment_status' => self::UC_STATUS_SELLING,
                    'update_time' => $now,
                ]);
            
            Db::commit();
            
            Log::info("ConsignmentService: 创建寄售记录成功, consignment_id={$consignmentId}, user_collection_id={$userCollectionId}");
            
            return $consignmentId;
            
        } catch (\Exception $e) {
            Db::rollback();
            Log::error("ConsignmentService: 创建寄售记录失败 - " . $e->getMessage());
            return false;
        }
    }

    /**
     * 更新寄售状态为已售出
     * 同时更新 user_collection.consignment_status
     * 
     * @param int $consignmentId 寄售记录ID
     * @param bool $updateUserCollection 是否更新用户藏品状态（卖家藏品）
     * @return bool
     */
    public static function markAsSold(int $consignmentId, bool $updateUserCollection = true): bool
    {
        $now = time();
        
        try {
            Db::startTrans();
            
            $consignment = Db::name('collection_consignment')
                ->where('id', $consignmentId)
                ->lock(true)
                ->find();
            
            if (!$consignment) {
                throw new \Exception('寄售记录不存在');
            }
            
            // 更新寄售记录状态，同时记录成交时间
            Db::name('collection_consignment')
                ->where('id', $consignmentId)
                ->update([
                    'status' => self::STATUS_SOLD,
                    'sold_time' => $now, // 记录成交时间
                    'update_time' => $now,
                ]);
            
            // 同步更新卖家藏品状态（标记为已售出，实际上藏品已转移给买家）
            if ($updateUserCollection && !empty($consignment['user_collection_id'])) {
                Db::name('user_collection')
                    ->where('id', $consignment['user_collection_id'])
                    ->update([
                        'consignment_status' => self::UC_STATUS_SOLD,
                        'update_time' => $now,
                    ]);
            }
            
            Db::commit();
            
            Log::info("ConsignmentService: 寄售已售出, consignment_id={$consignmentId}");
            
            return true;
            
        } catch (\Exception $e) {
            Db::rollback();
            Log::error("ConsignmentService: 标记已售出失败 - " . $e->getMessage());
            return false;
        }
    }

    /**
     * 更新寄售记录的结算快照字段
     * 在成交结算时调用，写入快照数据避免后续配置变更影响历史记录
     * 
     * @param int $consignmentId 寄售记录ID
     * @param float $soldPrice 成交价
     * @param float $originalPrice 卖家原购买价格（本金）
     * @param float $serviceFee 手续费
     * @param bool $serviceFeePaidAtApply 手续费是否在申请时已扣
     * @param array $settlementData 结算数据 ['principal_amount', 'profit_amount', 'payout_*', 'is_legacy', 'legacy_unlock_price']
     * @return bool
     */
    public static function updateConsignmentSettlement(
        int $consignmentId,
        float $soldPrice,
        float $originalPrice,
        float $serviceFee,
        bool $serviceFeePaidAtApply = true,
        array $settlementData = []
    ): bool {
        $now = time();
        
        try {
            $consignment = Db::name('collection_consignment')
                ->where('id', $consignmentId)
                ->where('status', self::STATUS_SOLD) // 只更新已售出的记录
                ->find();
            
            if (!$consignment) {
                Log::warning("ConsignmentService: 寄售记录不存在或未售出, consignment_id={$consignmentId}");
                return false;
            }
            
            // 判断是否是旧资产
            $isLegacy = false;
            $legacyUnlockPrice = 0.00;
            if (!empty($consignment['user_collection_id'])) {
                $userCollection = Db::name('user_collection')
                    ->where('id', $consignment['user_collection_id'])
                    ->find();
                
                if ($userCollection && (int)($userCollection['is_old_asset_package'] ?? 0) === 1) {
                    $isLegacy = true;
                    // 旧资产的解锁价通常是1000.00，从用户藏品价格获取
                    $legacyUnlockPrice = round((float)($userCollection['price'] ?? 1000.00), 2);
                }
            }
            
            // 从 settlementData 中获取或计算各项金额
            $principalAmount = $settlementData['principal_amount'] ?? $originalPrice;
            $profitAmount = $settlementData['profit_amount'] ?? 0.00;
            
            // 如果是旧资产首次售出
            if ($isLegacy) {
                $principalAmount = $legacyUnlockPrice; // 本金 = 解锁价
                $profitAmount = 0.00; // 利润 = 0
                
                // 旧资产规则：本金按比例拆分到提现余额和消费金（从配置读取）
                $legacySplitRate = (float)(get_sys_config('legacy_principal_split_rate') ?? 0.5);
                if ($legacySplitRate < 0 || $legacySplitRate > 1) {
                    $legacySplitRate = 0.5;
                }
                $payoutPrincipalWithdrawable = round($principalAmount * $legacySplitRate, 2);
                $payoutPrincipalConsume = round($principalAmount * (1 - $legacySplitRate), 2);
                $payoutProfitWithdrawable = 0.00;
                $payoutProfitConsume = 0.00;
                $settleRule = 'legacy_principal_split';
            } else {
                // 普通资产：从 settlementData 获取或计算
                // 计算净利润（成交价 - 本金 - 已付手续费）
                $netProfit = max(0, round($soldPrice - $originalPrice - $serviceFee, 2));
                $profitAmount = $netProfit;
                
                // 获取分红比例（从配置读取）
                $splitRate = (float)(get_sys_config('seller_profit_split_rate') ?? 0.5);
                if ($splitRate < 0 || $splitRate > 1) {
                    $splitRate = 0.5;
                }
                
                // 本金全部到提现余额
                $payoutPrincipalWithdrawable = $principalAmount;
                $payoutPrincipalConsume = 0.00;
                
                // 利润按比例分配
                $payoutProfitWithdrawable = round($profitAmount * $splitRate, 2);
                $payoutProfitConsume = round($profitAmount * (1 - $splitRate), 2);
                $settleRule = 'normal';
            }
            
            // 总计
            $payoutTotalWithdrawable = round($payoutPrincipalWithdrawable + $payoutProfitWithdrawable, 2);
            $payoutTotalConsume = round($payoutPrincipalConsume + $payoutProfitConsume, 2);
            
            // 允许从 settlementData 覆盖
            if (isset($settlementData['payout_principal_withdrawable'])) {
                $payoutPrincipalWithdrawable = round((float)$settlementData['payout_principal_withdrawable'], 2);
            }
            if (isset($settlementData['payout_principal_consume'])) {
                $payoutPrincipalConsume = round((float)$settlementData['payout_principal_consume'], 2);
            }
            if (isset($settlementData['payout_profit_withdrawable'])) {
                $payoutProfitWithdrawable = round((float)$settlementData['payout_profit_withdrawable'], 2);
            }
            if (isset($settlementData['payout_profit_consume'])) {
                $payoutProfitConsume = round((float)$settlementData['payout_profit_consume'], 2);
            }
            if (isset($settlementData['settle_rule'])) {
                $settleRule = (string)$settlementData['settle_rule'];
            }
            
            // 更新快照字段
            $updateData = [
                'settle_status' => 1, // 1=已结算
                'settle_time' => $now,
                'service_fee_paid_at_apply' => $serviceFeePaidAtApply ? 1 : 0,
                'settle_rule' => $settleRule,
                'is_legacy_snapshot' => $isLegacy ? 1 : 0,
                'legacy_unlock_price_snapshot' => round($legacyUnlockPrice, 2),
                'principal_amount' => round($principalAmount, 2),
                'profit_amount' => round($profitAmount, 2),
                'payout_principal_withdrawable' => $payoutPrincipalWithdrawable,
                'payout_principal_consume' => $payoutPrincipalConsume,
                'payout_profit_withdrawable' => $payoutProfitWithdrawable,
                'payout_profit_consume' => $payoutProfitConsume,
                'payout_total_withdrawable' => $payoutTotalWithdrawable,
                'payout_total_consume' => $payoutTotalConsume,
                'update_time' => $now,
            ];
            
            Db::name('collection_consignment')
                ->where('id', $consignmentId)
                ->update($updateData);
            
            Log::info("ConsignmentService: 更新结算快照成功", [
                'consignment_id' => $consignmentId,
                'is_legacy' => $isLegacy,
                'settle_rule' => $settleRule,
                'principal_amount' => $principalAmount,
                'profit_amount' => $profitAmount,
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error("ConsignmentService: 更新结算快照失败 - " . $e->getMessage(), [
                'consignment_id' => $consignmentId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 取消寄售（用户主动取消）
     * 同时更新 user_collection.consignment_status = 0
     * 
     * @param int $consignmentId 寄售记录ID
     * @return bool
     */
    public static function cancelConsignment(int $consignmentId): bool
    {
        $now = time();
        
        try {
            Db::startTrans();
            
            $consignment = Db::name('collection_consignment')
                ->where('id', $consignmentId)
                ->lock(true)
                ->find();
            
            if (!$consignment) {
                throw new \Exception('寄售记录不存在');
            }
            
            if ((int)$consignment['status'] !== self::STATUS_SELLING) {
                throw new \Exception('当前状态不允许取消');
            }
            
            // 更新寄售记录状态
            Db::name('collection_consignment')
                ->where('id', $consignmentId)
                ->update([
                    'status' => self::STATUS_CANCELLED,
                    'update_time' => $now,
                ]);
            
            // 同步更新用户藏品状态（恢复为未寄售）
            if (!empty($consignment['user_collection_id'])) {
                Db::name('user_collection')
                    ->where('id', $consignment['user_collection_id'])
                    ->update([
                        'consignment_status' => self::UC_STATUS_NONE,
                        'update_time' => $now,
                    ]);
            }
            
            Db::commit();
            
            Log::info("ConsignmentService: 寄售已取消, consignment_id={$consignmentId}");
            
            return true;
            
        } catch (\Exception $e) {
            Db::rollback();
            Log::error("ConsignmentService: 取消寄售失败 - " . $e->getMessage());
            return false;
        }
    }

    /**
     * 下架寄售（系统自动下架或管理员下架）
     * 同时更新 user_collection.consignment_status = 0
     * 可选择是否增加免费寄售次数作为补偿
     * 
     * @param int $consignmentId 寄售记录ID
     * @param bool $addFreeAttempt 是否增加免费寄售次数
     * @param string $reason 下架原因
     * @return bool
     */
    public static function offShelfConsignment(int $consignmentId, bool $addFreeAttempt = false, string $reason = ''): bool
    {
        $now = time();
        
        try {
            Db::startTrans();
            
            $consignment = Db::name('collection_consignment')
                ->where('id', $consignmentId)
                ->lock(true)
                ->find();
            
            if (!$consignment) {
                throw new \Exception('寄售记录不存在');
            }
            
            // 更新寄售记录状态
            Db::name('collection_consignment')
                ->where('id', $consignmentId)
                ->update([
                    'status' => self::STATUS_OFF_SHELF,
                    'update_time' => $now,
                ]);
            
            // 同步更新用户藏品状态（恢复为未寄售）
            if (!empty($consignment['user_collection_id'])) {
                $updateData = [
                    'consignment_status' => self::UC_STATUS_NONE,
                    'update_time' => $now,
                ];
                
                // 增加免费寄售次数作为补偿
                if ($addFreeAttempt) {
                    $updateData['free_consign_attempts'] = Db::raw('free_consign_attempts + 1');
                }
                
                Db::name('user_collection')
                    ->where('id', $consignment['user_collection_id'])
                    ->update($updateData);
            }
            
            // 记录活动日志
            if (!empty($consignment['user_id'])) {
                Db::name('user_activity_log')->insert([
                    'user_id' => $consignment['user_id'],
                    'related_user_id' => 0,
                    'action_type' => 'consignment_offshelf',
                    'change_field' => 'consignment_status',
                    'change_value' => '0',
                    'before_value' => '1',
                    'after_value' => '0',
                    'remark' => $reason ?: '寄售已下架',
                    'extra' => json_encode([
                        'consignment_id' => $consignmentId,
                        'add_free_attempt' => $addFreeAttempt,
                    ], JSON_UNESCAPED_UNICODE),
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            }
            
            Db::commit();
            
            Log::info("ConsignmentService: 寄售已下架, consignment_id={$consignmentId}, reason={$reason}");
            
            return true;
            
        } catch (\Exception $e) {
            Db::rollback();
            Log::error("ConsignmentService: 下架寄售失败 - " . $e->getMessage());
            return false;
        }
    }

    /**
     * 批量更新寄售状态
     * 用于批量下架场次结束后的寄售
     * 
     * @param array $consignmentIds 寄售记录ID数组
     * @param int $newStatus 新状态
     * @param bool $addFreeAttempt 是否增加免费寄售次数（仅下架时有效）
     * @param string $reason 原因
     * @return int 成功更新的数量
     */
    public static function batchUpdateStatus(array $consignmentIds, int $newStatus, bool $addFreeAttempt = false, string $reason = ''): int
    {
        $successCount = 0;
        
        foreach ($consignmentIds as $consignmentId) {
            $result = false;
            
            switch ($newStatus) {
                case self::STATUS_SOLD:
                    $result = self::markAsSold($consignmentId);
                    break;
                case self::STATUS_CANCELLED:
                    $result = self::cancelConsignment($consignmentId);
                    break;
                case self::STATUS_OFF_SHELF:
                    $result = self::offShelfConsignment($consignmentId, $addFreeAttempt, $reason);
                    break;
            }
            
            if ($result) {
                $successCount++;
            }
        }
        
        Log::info("ConsignmentService: 批量更新完成, 总数=" . count($consignmentIds) . ", 成功={$successCount}");
        
        return $successCount;
    }

    /**
     * 直接更新寄售状态（无事务，用于已在事务中调用）
     * 注意：调用者需要自行管理事务
     * 
     * @param int $consignmentId 寄售记录ID
     * @param int $newStatus 新状态
     * @param int|null $userCollectionId 用户藏品ID（可选，如果不传则从寄售记录获取）
     * @return bool
     */
    public static function updateStatusDirect(int $consignmentId, int $newStatus, ?int $userCollectionId = null): bool
    {
        $now = time();
        
        // 更新寄售记录状态
        Db::name('collection_consignment')
            ->where('id', $consignmentId)
            ->update([
                'status' => $newStatus,
                'update_time' => $now,
            ]);
        
        // 获取 user_collection_id
        if ($userCollectionId === null) {
            $consignment = Db::name('collection_consignment')
                ->where('id', $consignmentId)
                ->field('user_collection_id')
                ->find();
            $userCollectionId = $consignment['user_collection_id'] ?? 0;
        }
        
        // 同步更新用户藏品状态
        if ($userCollectionId > 0) {
            $ucStatus = self::mapToUserCollectionStatus($newStatus);
            Db::name('user_collection')
                ->where('id', $userCollectionId)
                ->update([
                    'consignment_status' => $ucStatus,
                    'update_time' => $now,
                ]);
        }
        
        return true;
    }

    /**
     * 将寄售记录状态映射为用户藏品寄售状态
     * 
     * @param int $consignmentStatus 寄售记录状态
     * @return int 用户藏品寄售状态
     */
    public static function mapToUserCollectionStatus(int $consignmentStatus): int
    {
        return match ($consignmentStatus) {
            self::STATUS_SELLING => self::UC_STATUS_SELLING,  // 寄售中 -> 寄售中
            self::STATUS_SOLD => self::UC_STATUS_SOLD,        // 已售出 -> 已售出
            default => self::UC_STATUS_NONE,                   // 其他 -> 未寄售
        };
    }

    /**
     * 获取状态文本
     * 
     * @param int $status 寄售记录状态
     * @return string 状态文本
     */
    public static function getStatusText(int $status): string
    {
        return match ($status) {
            self::STATUS_CANCELLED => '已取消',
            self::STATUS_SELLING => '寄售中',
            self::STATUS_SOLD => '已售出',
            self::STATUS_OFF_SHELF => '已下架',
            default => '未知',
        };
    }
}
