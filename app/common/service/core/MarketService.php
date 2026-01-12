<?php

namespace app\common\service\core;

use think\facade\Db;

/**
 * 市场基础服务
 * 
 * 管理场次、资产包、价格分区
 * 
 * @package app\common\service\core
 * @version 2.0
 * @date 2025-12-28
 */
class MarketService
{
    // ========================================
    // 场次相关方法
    // ========================================
    
    /**
     * 获取当前开放的交易场次
     * 
     * @return array
     */
    public static function getActiveSessions(): array
    {
        $now = date('H:i');
        return Db::name('collection_session')
            ->where('status', 1)
            ->where('start_time', '<=', $now)
            ->where('end_time', '>=', $now)
            ->select()
            ->toArray();
    }
    
    /**
     * 判断场次是否在交易时间内
     * 
     * @param int $sessionId 场次ID
     * @return bool
     */
    public static function isSessionActive(int $sessionId): bool
    {
        $session = Db::name('collection_session')
            ->where('id', $sessionId)
            ->where('status', 1)
            ->find();
        
        if (!$session) {
            return false;
        }
        
        $currentTime = date('H:i');
        $startTime = $session['start_time'] ?? '';
        $endTime = $session['end_time'] ?? '';
        
        return self::isTimeInRange($currentTime, $startTime, $endTime);
    }
    
    /**
     * 判断场次是否已结束（用于撮合判断）
     * 
     * @param int $sessionId 场次ID
     * @return bool
     */
    public static function isSessionEnded(int $sessionId): bool
    {
        $session = Db::name('collection_session')
            ->where('id', $sessionId)
            ->where('status', 1)
            ->find();
        
        if (!$session) {
            return true; // 不存在或已禁用视为已结束
        }
        
        $currentTime = date('H:i');
        $endTime = $session['end_time'] ?? '';
        
        return $currentTime > $endTime;
    }
    
    /**
     * 获取场次信息
     * 
     * @param int $sessionId
     * @return array|null
     */
    public static function getSession(int $sessionId): ?array
    {
        return Db::name('collection_session')
            ->where('id', $sessionId)
            ->find();
    }

    // ========================================
    // 资产包相关方法
    // ========================================
    
    /**
     * 获取资产包列表
     * 
     * @param int|null $sessionId 场次ID（可选）
     * @return array
     */
    public static function getAssetPackages(?int $sessionId = null): array
    {
        $query = Db::name('asset_package')
            ->where('status', 1);
        
        if ($sessionId !== null) {
            $query->where('session_id', $sessionId);
        }
        
        return $query->field('id, name, session_id, zone_id, image, total_count')
            ->select()
            ->toArray();
    }
    
    // ========================================
    // 价格分区相关方法
    // ========================================
    
    /**
     * 获取指定资产包下的价格分区
     * 
     * @param int $packageId
     * @return array
     */
    public static function getPriceZones(int $packageId): array
    {
        return Db::name('price_zone_config')
            ->where('package_id', $packageId)
            ->order('min_price', 'asc')
            ->select()
            ->toArray();
    }
    
    /**
     * 根据价格自动匹配分区ID
     * 
     * @param int $packageId
     * @param float $price
     * @return int ZoneId
     */
    public static function getZoneIdByPrice(int $packageId, float $price): int
    {
        $zone = Db::name('price_zone_config')
            ->where('package_id', $packageId)
            ->where('min_price', '<=', $price)
            ->where('max_price', '>=', $price)
            ->find();
            
        return $zone ? (int)$zone['id'] : 0;
    }
    
    /**
     * 根据价格获取或创建分区
     * 
     * 如果价格超过现有最高分区，自动创建新分区（每500元一个分区）
     * 
     * @param float $price 商品价格
     * @return array 分区配置数组 ['id' => int, 'name' => string, 'min_price' => float, 'max_price' => float]
     */
    public static function getOrCreateZoneByPrice(float $price): array
    {
        // 价格分区基础配置（每500元一个分区）
        $zoneStep = 500;
        $zoneIndex = (int)floor($price / $zoneStep);
        $minPrice = $zoneIndex * $zoneStep;
        $maxPrice = ($zoneIndex + 1) * $zoneStep;
        
        // 生成分区名称
        if ($minPrice < 1000) {
            $zoneName = $minPrice . '元区';
        } else {
            $zoneName = ($minPrice / 1000) . 'K区';
        }
        
        // 查找现有分区
        $zone = Db::name('price_zone_config')
            ->where('min_price', '<=', $price)
            ->where('max_price', '>', $price)
            ->find();
        
        if ($zone) {
            return [
                'id' => (int)$zone['id'],
                'name' => $zone['name'],
                'min_price' => (float)$zone['min_price'],
                'max_price' => (float)$zone['max_price'],
            ];
        }
        
        // 创建新分区
        $now = time();
        $zoneId = Db::name('price_zone_config')->insertGetId([
            'name' => $zoneName,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'status' => 1,
            'create_time' => $now,
            'update_time' => $now,
        ]);
        
        return [
            'id' => $zoneId,
            'name' => $zoneName,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
        ];
    }
    
    /**
     * 根据价格获取分区名称（简化版）
     * 
     * @param float $price
     * @return string
     */
    public static function getPriceZoneName(float $price): string
    {
        $zoneStep = 500;
        $zoneIndex = (int)floor($price / $zoneStep);
        $minPrice = $zoneIndex * $zoneStep;
        
        if ($minPrice < 1000) {
            return $minPrice . '元区';
        }
        return ($minPrice / 1000) . 'K区';
    }
    
    // ========================================
    // 辅助方法
    // ========================================
    
    /**
     * 判断时间是否在范围内（支持跨天）
     * 
     * @param string $currentTime
     * @param string $startTime
     * @param string $endTime
     * @return bool
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

