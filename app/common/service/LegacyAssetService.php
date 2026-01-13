<?php

namespace app\common\service;

use Exception;
use think\facade\Db;
use think\facade\Log;
use app\common\service\UserService;

/**
 * 旧资产解锁服务类
 * 负责处理旧资产解锁的特殊流程：场次选择、SPU创建、用户藏品创建等
 * 注意：不再自动创建寄售单，由用户手动选择寄售或转矿机
 */
class LegacyAssetService
{
    /**
     * 执行旧资产解锁全流程
     * 
     * @param int $userId 用户ID
     * @param float $unlockPrice 解锁价格
     * @param int $newUnlockCount 新的解锁次数
     * @return array
     * @throws Exception
     */
    public static function executeUnlock(int $userId, float $unlockPrice, int $newUnlockCount): array
    {
        // 1. 选择目标场次
        $targetSession = self::selectTargetSession();
        $sessionId = (int)$targetSession['id'];
        
        // 2. 选择或创建目标资产包
        // 这里需要先确定 zoneId，根据解锁价格计算分区
        // 假设规则：1000元对应zoneId，这里需要业务逻辑确认。暂时通过价格查找或默认逻辑。
        // 根据现有逻辑，1000元通常对应特定的 zone，我们先查找 1000 元对应的 zone_id
        $zoneId = self::determineZoneId($unlockPrice);
        
        $targetPackage = self::findOrCreateTargetPackage($sessionId, $zoneId);
        $packageId = (int)$targetPackage['id'];
        
        // 3. 创建解锁后的新 SPU
        $now = time();
        
        // 生成确权编号：37-DATA-{包ID(4位)}-{序号(4位)}，与普通藏品格式一致
        // 获取该资产包下当前最大序号
        $maxSeqRecord = Db::name('collection_item')
            ->where('package_id', $packageId)
            ->where('asset_code', 'like', '37-DATA-%')
            ->order('id desc')
            ->field('asset_code')
            ->find();
        
        $nextSeq = 1;
        if ($maxSeqRecord && !empty($maxSeqRecord['asset_code'])) {
            // 从 37-DATA-0009-0001 格式中提取序号
            $parts = explode('-', $maxSeqRecord['asset_code']);
            if (count($parts) >= 4) {
                $nextSeq = (int)$parts[3] + 1;
            }
        }
        
        $assetCode = sprintf('37-DATA-%04d-%04d', $packageId, $nextSeq);
        $txHash = '0x' . md5($assetCode . $now . $nextSeq . mt_rand());
        
        // 使用资产包名称作为藏品标题
        $packageName = $targetPackage['name'] ?? '旧资产包';
        
        // 查找该资产包下现有商品的图片（保持一致性）
        $existingItem = Db::name('collection_item')
            ->where('package_id', $packageId)
            ->where('image', '<>', '')
            ->limit(1)
            ->find();
        
        // 如果该资产包下没有商品，查找同场次同分区的商品图片
        if (!$existingItem || empty($existingItem['image'])) {
            $existingItem = Db::name('collection_item')
                ->where('session_id', $sessionId)
                ->where('zone_id', $zoneId)
                ->where('image', '<>', '')
                ->limit(1)
                ->find();
        }
        
        // 兜底：使用旧模板图片
        if (!$existingItem || empty($existingItem['image'])) {
            $existingItem = Db::name('collection_item')
                ->where('image', '<>', '')
                ->limit(1)
                ->find();
        }
        $image = $existingItem['image'] ?? '';
        
        $newItemId = Db::name('collection_item')->insertGetId([
            'session_id' => $sessionId,
            'zone_id' => $zoneId,
            'package_id' => $packageId,
            'package_name' => $packageName,
            'title' => $packageName, // 使用资产包名称作为藏品标题
            'image' => $image,
            'images' => $image, // 简单处理
            'price' => $unlockPrice,
            'issue_price' => $unlockPrice,
            'price_zone' => self::getPriceZoneName($unlockPrice), // e.g. "1K区"
            'description' => '旧资产解锁生成',
            'status' => '1',
            'stock' => 1,
            'sales' => 0,
            'is_physical' => 0, // 假设虚拟
            'create_time' => $now,
            'update_time' => $now,
            'asset_code' => $assetCode,
            'tx_hash' => $txHash,
            'owner_id' => $userId,
        ]);
        
        if (!$newItemId) {
            throw new Exception('创建新SPU失败');
        }
        
        // 4. 创建 user_collection 记录
        $userCollectionId = Db::name('user_collection')->insertGetId([
            'user_id' => $userId,
            'order_id' => 0, // 解锁非购买订单
            'order_item_id' => 0,
            'item_id' => $newItemId,
            'title' => $packageName, // 使用资产包名称
            'image' => $image,
            'price' => $unlockPrice,
            'buy_time' => $now,
            'delivery_status' => 0,
            'consignment_status' => 0, // 不自动寄售，由用户手动选择寄售或转矿机
            'is_old_asset_package' => 1, // 修正：标记为1，以便寄售时触发"自动归类"逻辑
            'legacy_unlock_price' => $unlockPrice,
            'legacy_package_id' => $packageId,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        
        if (!$userCollectionId) {
            throw new Exception('创建用户藏品记录失败');
        }
        
        // 5. 赠送寄售券（用于用户后续手动寄售时使用）
        // 修正：每次解锁都发放寄售券，不仅仅是第一次
        $couponId = UserService::issueConsignmentCoupon(
            $userId, 
            $sessionId, 
            $zoneId
        );
        
        // 不再自动创建寄售单，让用户自己选择：
        // 1. 手动寄售（使用赠送的寄售券）
        // 2. 转矿机（获得持续收益）
        
        return [
            'user_collection_id' => $userCollectionId,
            'item_id' => $newItemId,
            'session_id' => $sessionId,
            'consignment_id' => 0, // 不再自动创建寄售单
            'coupon_id' => $couponId, // 返回赠送的寄售券ID
        ];
    }
    
    /**
     * 选择目标场次
     * 选择下一个开启的场次（当前时间之后最早开始的场次）
     */
    public static function selectTargetSession(): array
    {
        $currentHi = date('H:i');
        
        // 查询所有启用的场次，按开始时间排序
        $sessions = Db::name('collection_session')
            ->where('status', '1')
            ->order('start_time', 'asc')
            ->select()
            ->toArray();
            
        if (empty($sessions)) {
            throw new Exception('暂无开放场次');
        }
        
        // 寻找下一个开启的场次（start_time > 当前时间）
        foreach ($sessions as $session) {
            $startTime = $session['start_time'] ?? '';
            if ($startTime > $currentHi) {
                return $session; // 找到今天未开始的场次
            }
        }
        
        // 如果今天所有场次都已开始或结束，选择明天最早开始的场次（即列表第一个）
        // 因为场次是每天重复的，所以明天最早的就是 start_time 最小的
        return $sessions[0];
    }
    
    /**
     * 选择或创建目标资产包
     * 新版逻辑：在该分区下随机找一个资产包，如果没有专用包，在该分区下随机分配一个现有资产包
     * 
     * @param int $sessionId 场次ID
     * @param int $zoneId 价格分区ID
     * @return array 资产包信息
     */
    public static function findOrCreateTargetPackage(int $sessionId, int $zoneId): array
    {
        // 1. 优先在该场次下查找通用包（zone_id = 0），因为所有资产包都是通用包
        $packages = Db::name('asset_package')
            ->where('session_id', $sessionId)
            ->where('zone_id', 0)  // 所有资产包都是通用包
            ->where('status', 1)
            ->select()
            ->toArray();
        
        if (!empty($packages)) {
            // 随机选择一个资产包，确保持仓多样性
            $randomIndex = array_rand($packages);
            return $packages[$randomIndex];
        }
        
        // 2. 如果没有现有资产包，创建新的通用包
        $session = Db::name('collection_session')->where('id', $sessionId)->find();
        $sessionTitle = $session['title'] ?? '未知场次';
        
        // 获取分区信息
        $zone = Db::name('price_zone_config')->where('id', $zoneId)->find();
        $zoneName = $zone ? $zone['name'] : self::getPriceZoneName(1000);
        
        $pkgName = date('m月d日') . $sessionTitle . '-' . $zoneName . '自动包';
        
        // 统一设置为通用包（zone_id = 0），因为每个资产包都会有多个价格分区的商品
        $id = Db::name('asset_package')->insertGetId([
            'session_id' => $sessionId,
            'zone_id' => 0,  // 统一设置为通用包
            'name' => $pkgName,
            'description' => '旧资产解锁自动创建的资产包',
            'min_price' => 0,
            'max_price' => 0,
            'total_count' => 0,
            'status' => 1,
            'is_default' => 0,
            'create_time' => time(),
            'update_time' => time(),
        ]);
        
        return Db::name('asset_package')->where('id', $id)->find();
    }
    
    public static function createConsignmentOrder($userId, $userCollectionId, $itemId, $sessionId, $zoneId, $packageId, $packageName, $price): int
    {
        $data = [
            'user_id' => $userId,
            'user_collection_id' => $userCollectionId,
            'item_id' => $itemId,
            'package_id' => $packageId,
            'package_name' => $packageName,
            'price' => $price,
            'original_price' => $price,
            'service_fee' => 0, // 假设通过券抵扣，或者此流程特有
            'coupon_used' => 0, // 后续更新
            'status' => 1, // 寄售中
            'create_time' => time(),
            'update_time' => time(),
            'session_id' => $sessionId,
            'zone_id' => $zoneId,
            'is_legacy_snapshot' => 1,
            'legacy_unlock_price_snapshot' => $price,
            'settle_rule' => 'legacy_principal_split',
            'principal_amount' => $price, // 本金=解锁价
            'profit_amount' => 0,
        ];
        
        return Db::name('collection_consignment')->insertGetId($data);
    }
    
    /**
     * 根据解锁金额匹配价格分区
     * 新版逻辑：根据解锁金额（例如1000元），匹配该场次下的对应分区（例如1K分区，范围800-1200）
     * 
     * @param float $unlockPrice 解锁金额
     * @return int 价格分区ID
     */
    private static function determineZoneId(float $unlockPrice): int
    {
        // 根据解锁金额查找对应的价格分区
        // 例如：1000元对应1K分区（范围500.01-1000.00）
        $zone = Db::name('price_zone_config')
            ->where('status', 1)
            ->where('min_price', '<=', $unlockPrice)
            ->where('max_price', '>=', $unlockPrice)
            ->order('min_price', 'desc')  // 如果有重叠，选择范围更小的
            ->find();
        
        if ($zone) {
            return (int)$zone['id'];
        }
        
        // 如果没有匹配的分区，查找包含该价格的最大分区
        $maxZone = Db::name('price_zone_config')
            ->where('status', 1)
            ->where('max_price', '>=', $unlockPrice)
            ->order('max_price', 'asc')  // 选择最小的包含该价格的分区
            ->find();
        
        if ($maxZone) {
            return (int)$maxZone['id'];
        }
        
        // 如果仍然没有，查找最大的分区作为兜底
        $fallbackZone = Db::name('price_zone_config')
            ->where('status', 1)
            ->order('max_price', 'desc')
            ->find();
        
        return $fallbackZone ? (int)$fallbackZone['id'] : 1; // 默认返回1（500元区）
    }
    
    private static function getPriceZoneName($price) {
        if ($price >= 1000 && $price < 2000) return '1K区';
        if ($price >= 2000 && $price < 3000) return '2K区';
        if ($price >= 3000 && $price < 4000) return '3K区';
        return '普通区';
    }
}
