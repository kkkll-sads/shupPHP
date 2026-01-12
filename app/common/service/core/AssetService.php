<?php

namespace app\common\service\core;

use think\facade\Db;
use think\facade\Log;
use app\common\service\UserService;
use app\common\service\ConsignmentService;

/**
 * 资产服务类
 * 
 * 负责用户持仓、寄售上架、下架、资产交割等核心业务
 * 
 * @package app\common\service\core
 * @version 2.0
 * @date 2025-12-28
 */
class AssetService
{
    // ========================================
    // 常量定义
    // ========================================
    
    /** @var int 寄售状态：未寄售 */
    const CONSIGN_STATUS_NONE = 0;
    /** @var int 寄售状态：寄售中 */
    const CONSIGN_STATUS_PENDING = 1;
    /** @var int 寄售状态：已售出 */
    const CONSIGN_STATUS_SOLD = 2;
    /** @var int 寄售状态：已下架/流拍 */
    const CONSIGN_STATUS_OFF_SHELF = 3;
    
    // ========================================
    // 寄售相关方法
    // ========================================
    
    /**
     * 用户发起寄售
     * 
     * 验证归属权 -> 检查解锁时间 -> 检查场次 -> 扣除费用 -> 创建寄售记录
     * 
     * @param int $userId 用户ID
     * @param int $userCollectionId 用户藏品记录ID
     * @param array $options 可选参数 ['skip_fee' => bool 是否跳过费用扣除（免费重发）]
     * @return array ['success' => bool, 'message' => string, 'data' => array]
     */
    public static function consignItem(int $userId, int $userCollectionId, array $options = []): array
    {
        $now = time();
        $skipFee = $options['skip_fee'] ?? false;
        
        Db::startTrans();
        try {
            // 1. 校验用户
            $user = Db::name('user')
                ->where('id', $userId)
                ->lock(true)
                ->find();
            if (!$user) {
                throw new \Exception('用户不存在');
            }
            
            // 2. 校验用户藏品记录
            $collection = Db::name('user_collection')
                ->where('id', $userCollectionId)
                ->where('user_id', $userId)
                ->lock(true)
                ->find();
            
            if (!$collection) {
                throw new \Exception('藏品记录不存在');
            }
            
            // 3. 检查寄售状态
            $consStatus = (int)$collection['consignment_status'];
            if ($consStatus !== 0) {
                $statusMap = [
                    1 => '该藏品当前正在寄售中，无法再次寄售',
                    2 => '该藏品已售出，无法寄售',
                ];
                throw new \Exception($statusMap[$consStatus] ?? "该藏品当前状态不允许寄售（状态码：{$consStatus}）");
            }
            
            // 4. 检查提货状态
            if ((int)$collection['delivery_status'] !== 0) {
                throw new \Exception('已提货的藏品不能寄售');
            }
            
            // 5. 检查权益交割状态
            $rightsDistributed = Db::name('user_activity_log')
                ->where('user_id', $userId)
                ->where('action_type', 'rights_distribute')
                ->where('extra', 'like', '%"user_collection_id":' . $userCollectionId . '%')
                ->find();
            if ($rightsDistributed) {
                throw new \Exception('该藏品已进行权益交割，无法寄售');
            }
            
            // 6. 检查解锁时间
            $buyTime = (int)$collection['buy_time'];
            $unlockHoursRaw = get_sys_config('consignment_unlock_hours');
            if ($unlockHoursRaw === null || $unlockHoursRaw === '' || !is_numeric($unlockHoursRaw)) {
                throw new \Exception('系统未配置寄售解锁小时数，请在后台寄售配置中设置');
            }
            $unlockHours = (int)$unlockHoursRaw;
            if ($unlockHours > 0 && $buyTime) {
                $unlockTime = $buyTime + $unlockHours * 3600;
                if (time() < $unlockTime) {
                    $remain = $unlockTime - time();
                    $hours = ceil($remain / 3600);
                    throw new \Exception("购买{$unlockHours}小时后才允许寄售，剩余约{$hours}小时");
                }
            }
            
            // 7. 获取商品信息
            $item = Db::name('collection_item')->where('id', $collection['item_id'])->find();
            if (!$item) {
                // 使用 user_collection 中的快照信息作为兜底
                $item = [
                    'id' => $collection['item_id'],
                    'title' => $collection['title'],
                    'image' => $collection['image'],
                    'price' => $collection['price'],
                    'session_id' => 0,
                    'zone_id' => 0,
                ];
            }
            
            // 8. 确定寄售价格
            $consignmentPrice = (float)($item['price'] ?? $collection['price']);
            if ($consignmentPrice <= 0) {
                throw new \Exception('该藏品未配置售价，无法寄售');
            }
            
            // 9. 检查交易场次
            $sessionId = (int)($item['session_id'] ?? 0);
            if ($sessionId > 0) {
                $session = Db::name('collection_session')
                    ->where('id', $sessionId)
                    ->where('status', '1')
                    ->find();
                
                if ($session) {
                    $currentTime = date('H:i');
                    $startTime = $session['start_time'] ?? '';
                    $endTime = $session['end_time'] ?? '';
                    
                    if (!self::isTimeInRange($currentTime, $startTime, $endTime)) {
                        $sessionName = $session['title'] ?? '该专场';
                        throw new \Exception("交易场次未开启，{$sessionName}交易时间为 {$startTime} - {$endTime}");
                    }
                } else {
                    throw new \Exception('交易场次未开启或不存在');
                }
            } else {
                throw new \Exception('该藏品未关联交易场次，无法寄售');
            }
            
            $serviceFee = 0;
            $usedCouponId = null;
            $itemTitle = $collection['title'] ?? $item['title'] ?? '藏品寄售';
            
            // 10. 处理费用（非免费重发情况）
            if (!$skipFee) {
                // 计算服务费
                $serviceFeeRate = (float)(get_sys_config('consignment_service_fee_rate') ?? 0.03);
                if ($serviceFeeRate <= 0 || $serviceFeeRate > 1) {
                    $serviceFeeRate = 0.03;
                }
                $serviceFee = round((float)$collection['price'] * $serviceFeeRate, 2);
                
                // 代理折扣
                $userType = (int)$user['user_type'];
                if ($userType >= 3) {
                    $discount = (float)(get_sys_config('agent_service_discount') ?? 1.0);
                    if ($discount >= 0 && $discount <= 1) {
                        $serviceFee = round($serviceFee * $discount, 2);
                    }
                }
                
                // 检查确权金余额
                if ($user['service_fee_balance'] < $serviceFee) {
                    throw new \Exception(sprintf(
                        '确权金不足，无法支付寄售手续费（%.2f元），当前确权金：%.2f元',
                        $serviceFee, $user['service_fee_balance']
                    ));
                }
                
                // 检查寄售券
                $itemZoneId = (int)($item['zone_id'] ?? 0);
                $validCoupon = UserService::getAvailableCouponForConsignment($userId, $sessionId, $itemZoneId);
                if (!$validCoupon) {
                    throw new \Exception("没有适用于该场次(#{$sessionId})的寄售券");
                }
                $usedCouponId = $validCoupon['id'];
                
                // 扣除服务费
                FinanceService::deductBalance($userId, $serviceFee, 'service_fee_balance', 
                    "寄售手续费（{$serviceFeeRate}%）：{$itemTitle}");
                
                // 扣除寄售券
                UserService::useCoupon($usedCouponId, $userId);
                
                // 记录活动日志
                Db::name('user_activity_log')->insert([
                    'user_id' => $userId,
                    'action_type' => 'consignment_fee',
                    'change_field' => 'service_fee_balance',
                    'change_value' => (string)(-$serviceFee),
                    'remark' => "寄售：{$itemTitle}",
                    'extra' => json_encode([
                        'consignment_price' => $consignmentPrice,
                        'service_fee' => $serviceFee,
                        'coupon_id' => $usedCouponId,
                    ], JSON_UNESCAPED_UNICODE),
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
            }
            
            // 11. 获取价格分区
            $zone = MarketService::getOrCreateZoneByPrice($consignmentPrice);
            $zoneId = $zone['id'] ?? 0;
            
            // 12. 获取资产包
            $package = self::findOrCreateAssetPackage($sessionId, $zoneId, $collection, $item);
            
            // 13. 创建寄售记录
            $consignmentData = [
                'user_id' => $userId,
                'user_collection_id' => $userCollectionId,
                'item_id' => $collection['item_id'],
                'package_id' => $package['id'] ?? 0,
                'zone_id' => $zoneId,
                'price' => $consignmentPrice,
                'original_price' => (float)$collection['price'],
                'service_fee' => $serviceFee,
                'status' => 1, // 寄售中
                'create_time' => $now,
                'update_time' => $now,
            ];
            
            $consignmentId = ConsignmentService::createConsignment($userCollectionId, $consignmentData);
            if (!$consignmentId) {
                throw new \Exception('创建寄售记录失败');
            }
            
            Db::commit();
            
            Log::info("AssetService::consignItem success", [
                'user_id' => $userId,
                'user_collection_id' => $userCollectionId,
                'consignment_id' => $consignmentId,
                'price' => $consignmentPrice,
            ]);
            
            return [
                'success' => true,
                'message' => '寄售成功',
                'data' => [
                    'consignment_id' => $consignmentId,
                    'price' => $consignmentPrice,
                    'service_fee' => $serviceFee,
                ]
            ];
            
        } catch (\Exception $e) {
            Db::rollback();
            Log::error("AssetService::consignItem failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => []
            ];
        }
    }
    
    /**
     * 取消寄售
     * 
     * @param int $consignmentId 寄售记录ID
     * @param int $userId 用户ID（用于权限验证）
     * @return array
     */
    public static function cancelConsignment(int $consignmentId, int $userId): array
    {
        try {
            // 验证寄售记录归属
            $consignment = Db::name('collection_consignment')
                ->where('id', $consignmentId)
                ->where('user_id', $userId)
                ->where('status', 1)
                ->find();
            
            if (!$consignment) {
                throw new \Exception('寄售记录不存在或无法取消');
            }
            
            $result = ConsignmentService::cancelConsignment($consignmentId);
            if (!$result) {
                throw new \Exception('取消寄售失败');
            }
            
            return ['success' => true, 'message' => '取消成功'];
            
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * 下架寄售（系统/管理员操作）
     * 
     * @param int $consignmentId 寄售记录ID
     * @param string $reason 下架原因
     * @param bool $addFreeAttempt 是否补偿免费寄售次数
     * @return bool
     */
    public static function offShelfItem(int $consignmentId, string $reason = '', bool $addFreeAttempt = true): bool
    {
        return ConsignmentService::offShelfConsignment($consignmentId, $addFreeAttempt, $reason);
    }
    
    /**
     * 资产交割
     * 
     * 将资产从卖家转移到买家名下
     * 
     * @param int $buyerId 买家用户ID
     * @param int $itemId 藏品ID
     * @param float $price 交易价格
     * @param int $orderId 订单ID
     * @param array $sellerInfo 卖家信息 ['user_id' => int, 'consignment_id' => int, 'original_price' => float]
     * @return array
     */
    public static function deliverAsset(int $buyerId, int $itemId, float $price, int $orderId, array $sellerInfo = []): array
    {
        $now = time();
        
        Db::startTrans();
        try {
            // 1. 获取藏品信息
            $item = Db::name('collection_item')
                ->where('id', $itemId)
                ->lock(true)
                ->find();
            
            if (!$item) {
                throw new \Exception('藏品不存在');
            }
            
            // 2. 创建买家藏品记录
            $userCollectionId = Db::name('user_collection')->insertGetId([
                'user_id' => $buyerId,
                'order_id' => $orderId,
                'item_id' => $itemId,
                'title' => $item['title'] ?? '',
                'image' => $item['image'] ?? '',
                'price' => $price, // 使用实际购买价格
                'buy_time' => $now,
                'delivery_status' => 0,
                'consignment_status' => 0,
                'auto_relist_next_day' => (int)(get_sys_config('auto_relist_default', 0)),
                'create_time' => $now,
                'update_time' => $now,
            ]);
            
            // 3. 更新卖家藏品状态（如果有）
            if (!empty($sellerInfo['consignment_id'])) {
                ConsignmentService::markAsSold((int)$sellerInfo['consignment_id']);
            }
            
            // 4. 触发增值（交易完成后）
            $newPrice = AppreciationService::checkAndAppreciate($itemId, $price);
            
            // 5. 更新藏品价格和分区
            if ($newPrice > $price) {
                $zone = MarketService::getOrCreateZoneByPrice($newPrice);
                Db::name('collection_item')
                    ->where('id', $itemId)
                    ->update([
                        'price' => $newPrice,
                        'zone_id' => $zone['id'] ?? 0,
                        'price_zone' => $zone['name'] ?? '',
                        'update_time' => $now,
                    ]);
            }
            
            // 6. 更新库存和销量
            Db::name('collection_item')
                ->where('id', $itemId)
                ->dec('stock', 1)
                ->inc('sales', 1)
                ->update(['update_time' => $now]);
            
            // 自动下架：库存降为0
            $newStock = (int)Db::name('collection_item')->where('id', $itemId)->value('stock');
            if ($newStock <= 0) {
                Db::name('collection_item')->where('id', $itemId)->update([
                    'status' => '0',
                    'update_time' => $now
                ]);
            }
            
            Db::commit();
            
            Log::info("AssetService::deliverAsset success", [
                'buyer_id' => $buyerId,
                'item_id' => $itemId,
                'price' => $price,
                'new_price' => $newPrice,
                'user_collection_id' => $userCollectionId,
            ]);
            
            return [
                'success' => true,
                'user_collection_id' => $userCollectionId,
                'new_price' => $newPrice,
            ];
            
        } catch (\Exception $e) {
            Db::rollback();
            Log::error("AssetService::deliverAsset failed: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
    
    // ========================================
    // 辅助方法
    // ========================================
    
    /**
     * 查找或创建资产包
     */
    private static function findOrCreateAssetPackage(int $sessionId, int $zoneId, array $collection, array $item): array
    {
        $now = time();
        $isOldAsset = (int)($collection['is_old_asset_package'] ?? 0);
        
        if ($isOldAsset === 1) {
            // 旧资产：随机混入当前场次的资产包
            $packages = Db::name('asset_package')
                ->where('session_id', $sessionId)
                ->where('status', 1)
                ->select()
                ->toArray();
            
            if (!empty($packages)) {
                return $packages[array_rand($packages)];
            }
        }
        
        // 优先使用藏品绑定的资产包
        $packageId = (int)($item['package_id'] ?? 0);
        if ($packageId > 0) {
            $package = Db::name('asset_package')
                ->where('id', $packageId)
                ->where('status', 1)
                ->find();
            if ($package) {
                return $package;
            }
        }
        
        // 按场次查找
        $package = Db::name('asset_package')
            ->where('session_id', $sessionId)
            ->where('status', 1)
            ->order('is_default desc, total_count asc')
            ->find();
        
        if ($package) {
            return $package;
        }
        
        // 创建新资产包（统一设置为通用包，因为每个资产包都会有多个价格分区的商品）
        $session = Db::name('collection_session')->where('id', $sessionId)->find();
        $zone = Db::name('price_zone_config')->where('id', $zoneId)->find();
        
        $newPackageId = Db::name('asset_package')->insertGetId([
            'session_id' => $sessionId,
            'zone_id' => 0,  // 统一设置为通用包
            'name' => ($session['title'] ?? "场次{$sessionId}") . '-' . ($zone['name'] ?? "分区{$zoneId}"),
            'description' => '自动创建',
            'status' => 1,
            'is_default' => 1,
            'total_count' => 0,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        
        return Db::name('asset_package')->where('id', $newPackageId)->find() ?? [];
    }
    
    /**
     * 判断时间是否在范围内
     */
    private static function isTimeInRange(string $currentTime, string $startTime, string $endTime): bool
    {
        if ($endTime < $startTime) {
            // 跨天情况
            return $currentTime >= $startTime || $currentTime <= $endTime;
        }
        return $currentTime >= $startTime && $currentTime <= $endTime;
    }
}
