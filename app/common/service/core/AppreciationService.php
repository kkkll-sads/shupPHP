<?php

namespace app\common\service\core;

use think\facade\Db;
use think\facade\Log;

/**
 * 增值服务类
 * 
 * 负责价格增长逻辑的判断与执行
 * 
 * 增值规则：
 * 1. 触发时机：用户买入藏品，交易完成后
 * 2. 判断条件：检查该藏品上一次交易的时间
 *    - 如果上一次交易发生在昨天（前一天），则触发增值
 *    - 首单：默认触发增值（找不到上一次交易记录）
 *    - 否则不增值
 * 
 * @package app\common\service\core
 * @version 2.0
 * @date 2025-12-28
 */
class AppreciationService
{
    // ========================================
    // 常量定义
    // ========================================
    
    /** @var float 默认增值率 5% */
    const DEFAULT_RATE = 0.05;
    
    // ========================================
    // 核心方法
    // ========================================
    
    /**
     * 检查并执行增值
     * 
     * 根据上一次交易时间判断是否满足增值条件，满足则更新价格
     * 
     * @param int $itemId 藏品ID
     * @param float $currentPrice 当前交易价格
     * @return float 新价格（如果增值则返回增值后价格，否则返回原价格）
     */
    public static function checkAndAppreciate(int $itemId, float $currentPrice): float
    {
        $now = time();
        
        // 1. 获取增值率配置
        $rate = self::getAppreciationRate();
        
        // 2. 检查是否满足增值条件
        $shouldAppreciate = self::shouldAppreciate($itemId);
        
        if (!$shouldAppreciate) {
            Log::info("AppreciationService: Item {$itemId} does not meet appreciation condition, price unchanged: {$currentPrice}");
            return $currentPrice;
        }
        
        // 3. 计算新价格
        $newPrice = self::calculateNewPrice($currentPrice, $rate);
        
        // 4. 记录增值日志
        Db::name('collection_appreciation_log')->insert([
            'item_id' => $itemId,
            'before_price' => $currentPrice,
            'after_price' => $newPrice,
            'rate' => $rate,
            'reason' => $shouldAppreciate === 'first_trade' ? '首单增值' : '连续交易增值',
            'create_time' => $now,
        ]);
        
        Log::info("AppreciationService: Item {$itemId} appreciated", [
            'before' => $currentPrice,
            'after' => $newPrice,
            'rate' => $rate,
            'reason' => $shouldAppreciate,
        ]);
        
        return $newPrice;
    }
    
    /**
     * 判断是否应该增值
     * 
     * @param int $itemId 藏品ID
     * @return string|false 满足条件返回原因字符串，不满足返回 false
     */
    public static function shouldAppreciate(int $itemId)
    {
        $lastTradeTime = self::getLastTradeTime($itemId);
        
        // 首单：找不到上一次交易记录，默认增值
        if ($lastTradeTime === null) {
            return 'first_trade';
        }
        
        // 判断上一次交易是否在昨天
        $lastTradeDate = date('Y-m-d', $lastTradeTime);
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        
        if ($lastTradeDate === $yesterday) {
            return 'yesterday_trade';
        }
        
        // 不满足增值条件
        return false;
    }
    
    /**
     * 计算新价格
     * 
     * @param float $price 当前价格
     * @param float $rate 增值率（小数形式，如 0.05 表示 5%）
     * @return float 新价格
     */
    public static function calculateNewPrice(float $price, float $rate): float
    {
        return round($price * (1 + $rate), 2);
    }
    
    /**
     * 获取上次交易时间
     * 
     * 从订单历史记录中查找该藏品最近一次交易的时间
     * 
     * @param int $itemId 藏品ID
     * @return int|null 上次交易时间戳，找不到返回 null
     */
    public static function getLastTradeTime(int $itemId): ?int
    {
        // 方式1：从 collection_order_item 表查询
        $lastOrder = Db::name('collection_order_item')
            ->alias('oi')
            ->join('collection_order o', 'oi.order_id = o.id')
            ->where('oi.item_id', $itemId)
            ->where('o.status', 'paid')
            ->order('o.pay_time desc')
            ->field('o.pay_time')
            ->find();
        
        if ($lastOrder && !empty($lastOrder['pay_time'])) {
            return (int)$lastOrder['pay_time'];
        }
        
        // 方式2：从 user_collection 表查询（作为兜底）
        $lastCollection = Db::name('user_collection')
            ->where('item_id', $itemId)
            ->order('buy_time desc')
            ->value('buy_time');
        
        return $lastCollection ? (int)$lastCollection : null;
    }
    
    /**
     * 获取增值率配置
     * 
     * @return float 增值率（小数形式）
     */
    public static function getAppreciationRate(): float
    {
        $rate = get_sys_config('price_increment_rate');
        
        if ($rate === null || !is_numeric($rate)) {
            return self::DEFAULT_RATE;
        }
        
        $rate = (float)$rate;
        
        // 确保增值率在合理范围内（0-100%）
        if ($rate < 0 || $rate > 1) {
            return self::DEFAULT_RATE;
        }
        
        return $rate;
    }
    
    // ========================================
    // 批量处理方法
    // ========================================
    
    /**
     * 批量检查增值条件
     * 
     * 用于预览或报表生成
     * 
     * @param array $itemIds 藏品ID数组
     * @return array [itemId => ['should_appreciate' => bool, 'reason' => string]]
     */
    public static function batchCheckAppreciation(array $itemIds): array
    {
        $result = [];
        
        foreach ($itemIds as $itemId) {
            $reason = self::shouldAppreciate((int)$itemId);
            $result[$itemId] = [
                'should_appreciate' => $reason !== false,
                'reason' => $reason ?: 'no_qualify',
            ];
        }
        
        return $result;
    }
    
    /**
     * 获取藏品增值历史
     * 
     * @param int $itemId 藏品ID
     * @param int $limit 返回记录数
     * @return array
     */
    public static function getAppreciationHistory(int $itemId, int $limit = 10): array
    {
        return Db::name('collection_appreciation_log')
            ->where('item_id', $itemId)
            ->order('create_time desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }
}
