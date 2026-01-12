<?php

namespace app\common\service\core;

use think\facade\Db;
use think\facade\Log;
use app\common\service\UserService;
use app\common\service\ConsignmentService;

/**
 * 交易核心服务
 * 
 * 负责池化撮合逻辑
 * 
 * @package app\common\service\core
 * @version 2.0
 * @date 2025-12-28
 */
class TradeService
{
    // ========================================
    // 撮合入口
    // ========================================
    
    /**
     * 执行特定池子的撮合
     * 
     * @param int $sessionId 场次ID
     * @param int $packageId 资产包ID (可选，为0时处理所有)
     * @param int $zoneId 价格分区ID (可选)
     * @return array 撮合结果统计
     */
    public static function matchPool(int $sessionId, int $packageId = 0, int $zoneId = 0): array
    {
        $stats = [
            'matched' => 0,
            'failed' => 0,
            'refunded' => 0,
            'off_shelf' => 0,
        ];

        // 1. 获取该场次所有待撮合的买单
        $buyQuery = Db::name('collection_matching_pool')
            ->where('session_id', $sessionId)
            ->where('status', 'pending');
        
        if ($packageId > 0) {
            $buyQuery->where('package_id', $packageId);
        }
        if ($zoneId > 0) {
            $buyQuery->where('zone_id', $zoneId);
        }
        
        $buyOrders = $buyQuery
            ->order('weight desc, create_time asc')
            ->select()
            ->toArray();

        if (empty($buyOrders)) {
            Log::info("TradeService::matchPool - No pending buy orders for session {$sessionId}");
            return $stats;
        }

        // 2. 按藏品和分区分组买单
        $buyOrdersGroups = [];
        foreach ($buyOrders as $order) {
            $itemId = (int)$order['item_id'];
            $zoneId = (int)($order['zone_id'] ?? 0);
            $key = "{$itemId}_{$zoneId}";
            
            if (!isset($buyOrdersGroups[$key])) {
                $buyOrdersGroups[$key] = [
                    'item_id' => $itemId,
                    'zone_id' => $zoneId,
                    'orders' => []
                ];
            }
            $buyOrdersGroups[$key]['orders'][] = $order;
        }

        // 3. 逐个分组处理撮合
        foreach ($buyOrdersGroups as $group) {
            $itemStats = self::matchItem($group['item_id'], $group['orders'], $sessionId, $group['zone_id']);
            $stats['matched'] += $itemStats['matched'];
            $stats['failed'] += $itemStats['failed'];
            $stats['refunded'] += $itemStats['refunded'];
            $stats['off_shelf'] += $itemStats['off_shelf'];
        }

        Log::info("TradeService::matchPool completed", [
            'session_id' => $sessionId,
            'stats' => $stats,
        ]);

        return $stats;
    }

    /**
     * 针对单个藏品执行撮合
     * 
     * @param int $itemId 藏品ID
     * @param array $buyOrders 该藏品的买单列表
     * @param int $sessionId 场次ID
     * @param int $targetZoneId 目标价格分区ID (0表示不限制)
     * @return array
     */
    private static function matchItem(int $itemId, array $buyOrders, int $sessionId, int $targetZoneId = 0): array
    {
        $stats = ['matched' => 0, 'failed' => 0, 'refunded' => 0, 'off_shelf' => 0];
        $now = time();

        // 获取藏品信息
        $item = Db::name('collection_item')->where('id', $itemId)->find();
        if (!$item) {
            return $stats;
        }

        // 获取可用库存（官方库存 + 寄售数量）
        $stock = (int)$item['stock'];
        
        // 获取寄售中的记录
        $consignmentQuery = Db::name('collection_consignment')
            ->where('item_id', $itemId)
            ->where('status', 1); // 寄售中

        // 如果指定了价格分区，增加价格过滤
        if ($targetZoneId > 0) {
            $zoneConfig = Db::name('price_zone_config')->where('id', $targetZoneId)->find();
            if ($zoneConfig) {
                // 确保寄售价格在该分区范围内：min <= price < max
                $consignmentQuery->where('price', '>=', $zoneConfig['min_price']);
                if ($zoneConfig['max_price'] > 0) {
                    $consignmentQuery->where('price', '<', $zoneConfig['max_price']);
                }
            }
        }
            
        $consignments = $consignmentQuery
            ->order('create_time asc')
            ->select()
            ->toArray();
        
        $totalAvailable = $stock + count($consignments);
        
        if ($totalAvailable <= 0) {
            // 没有可售库存，所有买单退款
            foreach ($buyOrders as $order) {
                self::refundBuyOrder($order);
                $stats['refunded']++;
            }
            return $stats;
        }

        // 使用轮盘赌选择中签者
        $needCount = min(count($buyOrders), $totalAvailable);
        $selectedIds = self::rouletteWheel($buyOrders, $needCount);
        $selectedMap = array_flip($selectedIds);

        // 处理每个买单
        $matchedCount = 0;
        foreach ($buyOrders as $order) {
            $orderId = (int)$order['id'];
            $isSelected = isset($selectedMap[$orderId]);
            
            if ($isSelected && $matchedCount < $totalAvailable) {
                // 中签：执行交易
                $consignment = null;
                if ($matchedCount >= $stock && !empty($consignments)) {
                    // 从寄售中取
                    $consignment = array_shift($consignments);
                }
                
                $result = self::executeTrade($order, $item, $consignment);
                if ($result['success']) {
                    $stats['matched']++;
                    $matchedCount++;
                } else {
                    $stats['failed']++;
                    self::refundBuyOrder($order);
                    $stats['refunded']++;
                }
            } else {
                // 未中签：退款
                self::refundBuyOrder($order);
                $stats['refunded']++;
            }
        }

        // 处理未匹配的寄售记录（流拍下架）
        foreach ($consignments as $unsoldConsignment) {
            AssetService::offShelfItem((int)$unsoldConsignment['id'], '场次结束未匹配', true);
            $stats['off_shelf']++;
        }

        return $stats;
    }

    // ========================================
    // 轮盘赌算法
    // ========================================
    
    /**
     * 轮盘赌算法：根据权重选择中签者
     * 
     * @param array $records 待撮合记录数组，每个记录包含 weight 字段
     * @param int $selectCount 需要选择的数量
     * @return array 中签的记录ID数组
     */
    public static function rouletteWheel(array $records, int $selectCount): array
    {
        if (empty($records) || $selectCount <= 0) {
            return [];
        }

        // 如果数量足够，全部中签
        if ($selectCount >= count($records)) {
            return array_column($records, 'id');
        }

        // 检查是否所有权重相同
        $weights = array_column($records, 'weight');
        $distinctWeights = array_unique($weights);
        
        if (count($distinctWeights) === 1) {
            // 权重相同，根据配置决定：时间优先或随机
            $tieMode = (string)(get_sys_config('matching_tie_breaker', 'time') ?? 'time');
            
            if ($tieMode === 'time') {
                // 时间优先（已按 create_time asc 排序）
                $selectedSlice = array_slice($records, 0, $selectCount);
                return array_column($selectedSlice, 'id');
            } else {
                // 随机选择
                $keys = array_rand($records, $selectCount);
                $keys = is_array($keys) ? $keys : [$keys];
                return array_map(fn($k) => $records[$k]['id'], $keys);
            }
        }

        // 计算总权重
        $totalWeight = array_sum($weights);
        if ($totalWeight <= 0) {
            // 总权重为0，随机选择
            $keys = array_rand($records, min($selectCount, count($records)));
            $keys = is_array($keys) ? $keys : [$keys];
            return array_map(fn($k) => $records[$k]['id'], $keys);
        }

        // 轮盘赌选择
        $selectedIds = [];
        $selectedIndexes = [];

        for ($i = 0; $i < $selectCount; $i++) {
            // 计算剩余权重
            $remainingRecords = [];
            $remainingWeight = 0;
            foreach ($records as $idx => $record) {
                if (!in_array($idx, $selectedIndexes)) {
                    $remainingRecords[] = ['idx' => $idx, 'weight' => (int)$record['weight']];
                    $remainingWeight += (int)$record['weight'];
                }
            }

            if (empty($remainingRecords) || $remainingWeight <= 0) {
                break;
            }

            // 随机选择
            $random = mt_rand(1, $remainingWeight);
            $cumulative = 0;

            foreach ($remainingRecords as $item) {
                $cumulative += $item['weight'];
                if ($random <= $cumulative) {
                    $selectedIdx = $item['idx'];
                    $selectedIds[] = $records[$selectedIdx]['id'];
                    $selectedIndexes[] = $selectedIdx;
                    break;
                }
            }
        }

        return $selectedIds;
    }

    // ========================================
    // 交易执行
    // ========================================
    
    /**
     * 执行单笔交易
     * 
     * @param array $buyOrder 买单记录
     * @param array $item 藏品信息
     * @param array|null $consignment 寄售记录（如果从寄售购买）
     * @return array
     */
    private static function executeTrade(array $buyOrder, array $item, ?array $consignment = null): array
    {
        Db::startTrans();
        try {
            $now = time();
            $buyerId = (int)$buyOrder['user_id'];
            $itemId = (int)$item['id'];
            $itemPrice = (float)$item['price'];

            // 1. 检查买家余额/冻结资金
            $user = Db::name('user')
                ->where('id', $buyerId)
                ->lock(true)
                ->find();
            
            if (!$user) {
                throw new \Exception('买家不存在');
            }

            // 尝试使用预约冻结资金
            $reservation = Db::name('trade_reservations')
                ->where('user_id', $buyerId)
                ->where('session_id', $buyOrder['session_id'])
                ->where('status', 0)
                ->lock(true)
                ->find();

            $usedReservation = false;
            if ($reservation && (float)$reservation['freeze_amount'] >= $itemPrice) {
                Db::name('trade_reservations')
                    ->where('id', $reservation['id'])
                    ->update(['status' => 1, 'update_time' => $now]);
                $usedReservation = true;
            } else {
                // 从余额扣款
                if ((float)$user['balance_available'] < $itemPrice) {
                    throw new \Exception('余额不足');
                }
                
                FinanceService::deductBalance($buyerId, $itemPrice, FinanceService::ACCOUNT_BALANCE,
                    '撮合购买：' . $item['title']);
            }

            // 2. 创建订单
            $orderNo = 'CO' . date('YmdHis') . str_pad($buyerId, 6, '0', STR_PAD_LEFT) . mt_rand(1000, 9999);
            $orderId = Db::name('collection_order')->insertGetId([
                'order_no' => $orderNo,
                'user_id' => $buyerId,
                'total_amount' => $itemPrice,
                'pay_type' => 'balance',
                'status' => 'paid',
                'pay_time' => $now,
                'complete_time' => $now,
                'create_time' => $now,
                'update_time' => $now,
            ]);

            Db::name('collection_order_item')->insert([
                'order_id' => $orderId,
                'item_id' => $itemId,
                'item_title' => $item['title'],
                'item_image' => $item['image'] ?? '',
                'price' => $itemPrice,
                'quantity' => 1,
                'subtotal' => $itemPrice,
                'create_time' => $now,
            ]);

            // 3. 资产交割
            $sellerInfo = [];
            if ($consignment) {
                $sellerInfo = [
                    'user_id' => (int)$consignment['user_id'],
                    'consignment_id' => (int)$consignment['id'],
                    'original_price' => (float)($consignment['original_price'] ?? $consignment['price']),
                ];
            }
            
            $deliverResult = AssetService::deliverAsset($buyerId, $itemId, $itemPrice, $orderId, $sellerInfo);
            if (!$deliverResult['success']) {
                throw new \Exception($deliverResult['message'] ?? '交割失败');
            }

            // 4. 分配卖家收益
            if ($consignment) {
                $sellerId = (int)$consignment['user_id'];
                $originalPrice = (float)($consignment['original_price'] ?? $consignment['price']);
                $consignmentId = (int)$consignment['id'];
                
                FinanceService::distributeSellerIncome($sellerId, $itemPrice, $originalPrice, $item['title'], $consignmentId);
                
                // 分配代理佣金
                $profit = max(0, $itemPrice - $originalPrice);
                if ($profit > 0) {
                    FinanceService::distributeAgentCommission($sellerId, $profit, [
                        'item_title' => $item['title'],
                        'order_no' => $orderNo,
                        'order_id' => $orderId,
                        'consignment_id' => $consignment['id'],
                    ]);
                }
            }

            // 5. 更新买单状态
            Db::name('collection_matching_pool')
                ->where('id', $buyOrder['id'])
                ->update([
                    'status' => 'matched',
                    'match_time' => $now,
                    'match_order_id' => $orderId,
                    'update_time' => $now,
                ]);

            // 6. 买家升级检查
            $sessionId = (int)$buyOrder['session_id'];
            $itemZoneId = (int)($item['zone_id'] ?? 0);
            UserService::checkAndUpgradeUserAfterPurchase($buyerId, $sessionId, $itemZoneId);

            Db::commit();
            
            Log::info("TradeService::executeTrade success", [
                'buyer_id' => $buyerId,
                'item_id' => $itemId,
                'order_no' => $orderNo,
                'is_consignment' => $consignment !== null,
            ]);

            return ['success' => true, 'order_no' => $orderNo, 'order_id' => $orderId];

        } catch (\Exception $e) {
            Db::rollback();
            Log::error("TradeService::executeTrade failed: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 退款买单
     * 
     * @param array $order 买单记录
     * @return bool
     */
    private static function refundBuyOrder(array $order): bool
    {
        $now = time();
        
        try {
            // 更新买单状态
            Db::name('collection_matching_pool')
                ->where('id', $order['id'])
                ->update([
                    'status' => 'refunded',
                    'update_time' => $now,
                ]);

            // 如果有预约冻结，解冻资金
            $reservation = Db::name('trade_reservations')
                ->where('user_id', $order['user_id'])
                ->where('session_id', $order['session_id'])
                ->where('status', 0)
                ->find();

            if ($reservation) {
                $freezeAmount = (float)$reservation['freeze_amount'];
                
                // 解冻资金：返还到 balance_available
                FinanceService::addBalance((int)$order['user_id'], $freezeAmount, 
                    FinanceService::ACCOUNT_BALANCE, '撮合未中签退款');
                
                Db::name('trade_reservations')
                    ->where('id', $reservation['id'])
                    ->update(['status' => 2, 'update_time' => $now]); // 2=已退款
            }

            Log::info("TradeService::refundBuyOrder success", [
                'order_id' => $order['id'],
                'user_id' => $order['user_id'],
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error("TradeService::refundBuyOrder failed: " . $e->getMessage());
            return false;
        }
    }
}

