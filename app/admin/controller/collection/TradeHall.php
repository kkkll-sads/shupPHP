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
        
        $this->success('', [
            'session_id' => $sessionId,
            'sessions' => $sessions,
            'reservation_stats' => $reservationStats,
            'consignment_stats' => $consignmentStats,
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
            
            // 2. 寄售数量：在售的寄售记录（属于该资产包+分区）
            $consignmentCount = Db::name('collection_consignment')
                ->alias('cc')
                ->leftJoin('collection_item ci', 'cc.item_id = ci.id')
                ->where('cc.status', 1) // 在售
                ->where('cc.session_id', $item['session_id'])
                ->where('cc.package_id', $item['package_id'])
                ->where('cc.zone_id', $item['zone_id'])
                ->count();
            $consignmentCount = (int)$consignmentCount;
            
            // 总可用数量 = 官方库存 + 寄售数量
            $item['item_count'] = $officialStock + $consignmentCount;
            $item['official_stock'] = $officialStock; // 额外提供官方库存
            $item['consignment_stock'] = $consignmentCount; // 额外提供寄售库存
        }
        
        return $stats;
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
