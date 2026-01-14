<?php

namespace app\admin\controller\collection;

use Throwable;
use think\facade\Db;
use app\common\controller\Backend;

/**
 * 交易大厅
 * 显示当前场次的预约和寄售统计
 */
class TradeHall extends Backend
{
    /**
     * 交易大厅首页
     * @throws Throwable
     */
    public function index(): void
    {
        // 获取当前场次ID（可选参数，不传则显示所有场次）
        $sessionId = $this->request->param('session_id/d', 0);
        
        // 获取所有场次列表（用于筛选）
        $sessions = Db::name('collection_session')
            ->where('status', 1)
            ->field('id, title')
            ->order('id desc')
            ->select()
            ->toArray();
        
        // 如果没有指定场次，使用第一个场次作为默认
        if ($sessionId <= 0 && !empty($sessions)) {
            $sessionId = $sessions[0]['id'] ?? 0;
        }
        
        // 统计预约数据（按场次+资产包+分区）
        $reservationStats = $this->getReservationStats($sessionId);
        
        // 统计寄售数据（按场次+资产包+分区）
        $consignmentStats = $this->getConsignmentStats($sessionId);
        
        // ✨ 计算汇总统计数据（当前场次）
        $summaryStats = $this->calculateSummaryStats($sessionId, $reservationStats, $consignmentStats);
        
        $this->success('', [
            'session_id' => $sessionId,
            'sessions' => $sessions,
            'reservation_stats' => $reservationStats,
            'consignment_stats' => $consignmentStats,
            'summary_stats' => $summaryStats, // ✨ 汇总统计数据
            'remark' => get_route_remark(),
        ]);
    }
    
    /**
     * 获取预约统计（按场次+资产包+分区）
     * @param int $sessionId 场次ID，0表示所有场次
     * @return array
     */
    private function getReservationStats(int $sessionId = 0): array
    {
        $query = Db::name('trade_reservations')
            ->alias('tr')
            ->leftJoin('asset_package ap', 'tr.package_id = ap.id')
            ->leftJoin('price_zone_config pz', 'tr.zone_id = pz.id')
            ->leftJoin('collection_session cs', 'tr.session_id = cs.id')
            ->field('
                tr.session_id,
                cs.title as session_title,
                tr.package_id,
                ap.name as package_name,
                tr.zone_id,
                pz.name as zone_name,
                COUNT(DISTINCT tr.user_id) as user_count,
                COUNT(*) as reservation_count,
                SUM(tr.freeze_amount) as total_freeze_amount
            ')
            ->where('tr.status', 0) // 只显示待处理的
            ->group('tr.session_id, tr.package_id, tr.zone_id');
        
        if ($sessionId > 0) {
            $query->where('tr.session_id', $sessionId);
        }
        
        $stats = $query->order('tr.session_id desc, tr.package_id asc, tr.zone_id asc')
            ->select()
            ->toArray();
        
        // 格式化数据并统计可用藏品数量（官方库存 + 寄售数量）
        foreach ($stats as &$item) {
            $item['user_count'] = (int)$item['user_count'];
            $item['reservation_count'] = (int)$item['reservation_count'];
            $item['total_freeze_amount'] = round((float)($item['total_freeze_amount'] ?? 0), 2);
            $item['package_name'] = $item['package_name'] ?? '未分类';
            $item['zone_name'] = $item['zone_name'] ?? '未分区';
            
            // 统计该资产包+分区下的可用藏品数量（官方库存 + 寄售数量）
            // 1. 官方库存：上架且有库存的藏品
            $officialStock = Db::name('collection_item')
                ->where('session_id', $item['session_id'])
                ->where('package_id', $item['package_id'])
                ->where('zone_id', $item['zone_id'])
                ->where('status', 1) // 上架
                ->where('stock', '>', 0) // 有库存
                ->sum('stock');
            $officialStock = (int)($officialStock ?? 0);
            
            // 2. 寄售统计：在售的寄售记录（属于该资产包+分区）
            $consignmentData = Db::name('collection_consignment')
                ->alias('cc')
                ->leftJoin('collection_item ci', 'cc.item_id = ci.id')
                ->where('cc.status', 1) // 在售
                ->where('cc.session_id', $item['session_id'])
                ->where('cc.package_id', $item['package_id'])
                ->where('cc.zone_id', $item['zone_id'])
                ->field('COUNT(*) as count, SUM(cc.price) as total_price')
                ->find();
            
            $consignmentCount = (int)($consignmentData['count'] ?? 0);
            $consignmentTotalPrice = round((float)($consignmentData['total_price'] ?? 0), 2);
            
            // 总可用数量 = 官方库存 + 寄售数量
            $item['item_count'] = $officialStock + $consignmentCount;
            $item['official_stock'] = $officialStock; // 额外提供官方库存
            $item['consignment_stock'] = $consignmentCount; // 额外提供寄售库存
            $item['consignment_count'] = $consignmentCount; // ✨ 在售数量
            $item['consignment_total_price'] = $consignmentTotalPrice; // ✨ 在售金额
        }
        
        return $stats;
    }
    
    /**
     * 计算汇总统计数据
     * @param int $sessionId 场次ID
     * @param array $reservationStats 预约统计数据
     * @param array $consignmentStats 寄售统计数据
     * @return array
     */
    private function calculateSummaryStats(int $sessionId, array $reservationStats, array $consignmentStats): array
    {
        // 从预约统计数据计算汇总
        $totalReservationCount = 0; // 预约总数
        $totalFreezeAmount = 0; // 预约冻结总额
        $totalConsignmentCount = 0; // 在售数量总额（从预约统计中获取）
        $totalConsignmentPrice = 0; // 在售金额总额（从预约统计中获取）
        
        foreach ($reservationStats as $item) {
            $totalReservationCount += (int)($item['reservation_count'] ?? 0);
            $totalFreezeAmount += (float)($item['total_freeze_amount'] ?? 0);
            $totalConsignmentCount += (int)($item['consignment_count'] ?? 0);
            $totalConsignmentPrice += (float)($item['consignment_total_price'] ?? 0);
        }
        
        // 从寄售统计数据计算汇总（用于验证）
        $totalConsignmentCountFromSale = 0;
        $totalConsignmentPriceFromSale = 0;
        
        foreach ($consignmentStats as $item) {
            $totalConsignmentCountFromSale += (int)($item['consignment_count'] ?? 0);
            $totalConsignmentPriceFromSale += (float)($item['total_price'] ?? 0);
        }
        
        return [
            'reservation_count' => $totalReservationCount, // 预约总数
            'freeze_amount' => round($totalFreezeAmount, 2), // 预约冻结总额
            'consignment_count' => $totalConsignmentCount, // 在售数量总额
            'consignment_total_price' => round($totalConsignmentPrice, 2), // 在售金额总额
            'consignment_count_from_sale' => $totalConsignmentCountFromSale, // 从寄售统计获取的在售总数（验证用）
            'consignment_price_from_sale' => round($totalConsignmentPriceFromSale, 2), // 从寄售统计获取的在售总价（验证用）
        ];
    }
    
    /**
     * 获取寄售统计（按场次+资产包+分区）
     * @param int $sessionId 场次ID，0表示所有场次
     * @return array
     */
    private function getConsignmentStats(int $sessionId = 0): array
    {
        $query = Db::name('collection_consignment')
            ->alias('cc')
            ->leftJoin('asset_package ap', 'cc.package_id = ap.id')
            ->leftJoin('price_zone_config pz', 'cc.zone_id = pz.id')
            ->leftJoin('collection_session cs', 'cc.session_id = cs.id')
            ->field('
                cc.session_id,
                cs.title as session_title,
                cc.package_id,
                ap.name as package_name,
                cc.zone_id,
                pz.name as zone_name,
                COUNT(DISTINCT cc.user_id) as user_count,
                COUNT(*) as consignment_count,
                SUM(cc.price) as total_price
            ')
            ->where('cc.status', 1) // 只显示在售的
            ->group('cc.session_id, cc.package_id, cc.zone_id');
        
        if ($sessionId > 0) {
            $query->where('cc.session_id', $sessionId);
        }
        
        $stats = $query->order('cc.session_id desc, cc.package_id asc, cc.zone_id asc')
            ->select()
            ->toArray();
        
        // 格式化数据并统计可用藏品数量（官方库存 + 寄售数量）
        foreach ($stats as &$item) {
            $item['user_count'] = (int)$item['user_count'];
            $item['consignment_count'] = (int)$item['consignment_count'];
            $item['total_price'] = round((float)($item['total_price'] ?? 0), 2);
            $item['package_name'] = $item['package_name'] ?? '未分类';
            $item['zone_name'] = $item['zone_name'] ?? '未分区';
            
            // 统计该资产包+分区下的可用藏品数量（官方库存 + 寄售数量）
            // 1. 官方库存：上架且有库存的藏品
            $officialStock = Db::name('collection_item')
                ->where('session_id', $item['session_id'])
                ->where('package_id', $item['package_id'])
                ->where('zone_id', $item['zone_id'])
                ->where('status', 1) // 上架
                ->where('stock', '>', 0) // 有库存
                ->sum('stock');
            $officialStock = (int)($officialStock ?? 0);
            
            // 2. 寄售数量：在售的寄售记录（属于该资产包+分区）
            // 注意：使用 collection_item 的 zone_id（动态分区），而不是寄售记录的 zone_id
            // 寄售统计中的 item_count 主要表示该资产包+分区下还有多少可用库存（官方+寄售）
            $consignmentCount = Db::name('collection_consignment')
                ->alias('cc')
                ->leftJoin('collection_item ci', 'cc.item_id = ci.id')
                ->where('cc.status', 1) // 在售
                ->where('cc.session_id', $item['session_id'])
                ->where('cc.package_id', $item['package_id'])
                ->where('ci.zone_id', $item['zone_id']) // 使用藏品表的 zone_id（动态分区）
                ->count();
            $consignmentCount = (int)$consignmentCount;
            
            // 总可用数量 = 官方库存 + 寄售数量
            $item['item_count'] = $officialStock + $consignmentCount;
            $item['official_stock'] = $officialStock; // 额外提供官方库存
            $item['consignment_stock'] = $consignmentCount; // 额外提供寄售库存
        }
        
        return $stats;
    }
}
