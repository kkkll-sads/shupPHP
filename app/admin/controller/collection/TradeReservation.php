<?php

namespace app\admin\controller\collection;

use app\common\controller\Backend;

class TradeReservation extends Backend
{
    /**
     * TradeReservation模型对象
     * @var \app\admin\model\TradeReservation
     */
    protected object $model;

    protected array|string $preExcludeFields = ['user_id', 'session_id', 'zone_id', 'package_id', 'match_order_id'];

    /**
     * 快速搜索字段（支持关联表字段）
     */
    protected string|array $quickSearchField = ['user.username', 'user.nickname', 'user.mobile'];

    /**
     * 是否开启关联查询
     */
    protected bool|string $relationSearch = true;

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new \app\admin\model\TradeReservation;
    }

    /**
     * 查看
     */
    public function index(): void
    {
        // 设置关联查询
        $this->relationSearch = true;
        if ($this->request->param('select')) {
            $this->select();
        }

        list($where, $alias, $limit, $order) = $this->queryBuilder();
        
        $list = $this->model
            ->withJoin(['user' => ['username', 'nickname', 'mobile'], 'session' => ['title'], 'zone' => ['name'], 'package' => ['name']], 'LEFT')
            ->alias($alias)
            ->where($where)
            ->order($order)
            ->paginate($limit);

        // 🆕 处理列表数据，添加状态文本和相关字段
        $statusMap = [
            0 => '待处理',
            1 => '已中签',
            2 => '未中签',
            3 => '已取消'
        ];
        
        $listItems = $list->items();
        foreach ($listItems as &$item) {
            // 🔍 临时调试：记录状态值和类型
            \think\facade\Log::info('TradeReservation status debug', [
                'id' => $item['id'],
                'status' => $item['status'],
                'status_type' => gettype($item['status']),
                'status_in_array' => isset($statusMap[$item['status']]),
            ]);
            
            // 添加状态文本
            $item['status_text'] = $statusMap[$item['status']] ?? '未知';
            
            // 添加场次信息
            $item['session_title'] = $item['session']['title'] ?? '';
            $item['session_start_time'] = $item['session']['start_time'] ?? '';
            $item['session_end_time'] = $item['session']['end_time'] ?? '';
            
            // 添加分区信息
            $item['zone_name'] = $item['zone']['name'] ?? '';
            $item['zone_min_price'] = (float)($item['zone']['min_price'] ?? 0);
            $item['zone_max_price'] = (float)($item['zone']['max_price'] ?? 0);
            
            // 初始化商品信息
            $item['item_title'] = '';
            $item['item_image'] = '';
            $item['item_price'] = 0;
            $item['actual_buy_price'] = 0;
            $item['refund_diff'] = 0;
            
            // 如果已中签，获取实际购买信息
            if ($item['status'] == 1 && !empty($item['match_order_id'])) {
                // collection_order表没有item_id字段，需要通过collection_order_item表查询
                $orderItem = \think\facade\Db::name('collection_order_item')
                    ->where('order_id', $item['match_order_id'])
                    ->field('item_id, price')
                    ->find();
                
                if ($orderItem) {
                    $item['actual_buy_price'] = (float)$orderItem['price'];
                    $item['refund_diff'] = max(0, $item['freeze_amount'] - $item['actual_buy_price']);
                    
                    // 获取商品信息
                    $itemInfo = \think\facade\Db::name('collection_item')
                        ->where('id', $orderItem['item_id'])
                        ->field('title, image, price')
                        ->find();
                    
                    if ($itemInfo) {
                        $item['item_title'] = $itemInfo['title'];
                        $item['item_image'] = full_url($itemInfo['image'], false);
                        $item['item_price'] = (float)$itemInfo['price'];
                    }
                }
            }
        }

        $this->success('', [
            'list' => $listItems,
            'total' => $list->total()
        ]);
    }

    /**
     * 取消预约并退款
     */
    public function cancel(): void
    {
        $ids = $this->request->param('ids/a', []);
        if (empty($ids)) {
            $this->error('参数错误');
        }

        $count = 0;
        $error = '';

        \think\facade\Db::startTrans();
        try {
            $list = $this->model
                ->where('id', 'in', $ids)
                ->where('status', 0) // 仅处理待处理状态
                ->select();

            foreach ($list as $item) {
                // 1. 更新状态为已取消
                $item->status = 3;
                $item->save();

                // 2. 退还冻结资金
                $freezeAmount = (float)$item->freeze_amount;
                if ($freezeAmount > 0) {
                    $userId = $item->user_id;

                    // 增加用户余额
                    \app\admin\model\User::where('id', $userId)
                        ->inc('balance_available', $freezeAmount)
                        ->update();

                    // 记录资金变动日志
                    $now = time();
                    \think\facade\Db::name('user_money_log')->insert([
                        'user_id' => $userId,
                        'flow_no' => 'REFUND' . date('YmdHis') . $item->id, // 简易流水号
                        'batch_no' => 'CANCEL_RESERVATION_' . $item->id,
                        'biz_type' => 'reservation_refund',
                        'biz_id' => $item->id,
                        'field_type' => 'balance_available',
                        'money' => $freezeAmount,
                        'before' => 0, // 无法精确获取，暂填0或需额外查询
                        'after' => 0,
                        'memo' => '取消盲盒预约，退还冻结资金',
                        'create_time' => $now,
                    ]);
                }

                $count++;
            }

            \think\facade\Db::commit();
        } catch (\Throwable $e) {
            \think\facade\Db::rollback();
            $this->error('取消失败：' . $e->getMessage());
        }

        if ($count > 0) {
            $this->success("成功取消 {$count} 条预约并退款");
        } else {
            $this->error('没有符合条件的记录（仅限“待处理”状态）');
        }
    }

    /**
     * 统计接口
     */
    public function stats(): void
    {
        // 按状态统计
        $statusStats = \think\facade\Db::name('trade_reservations')
            ->field('status, COUNT(*) as count, SUM(freeze_amount) as total_amount')
            ->group('status')
            ->select()
            ->toArray();

        $statusMap = [
            0 => '待处理',
            1 => '已中签',
            2 => '未中签',
            3 => '已取消',
        ];

        $stats = [];
        // 初始化所有状态为0
        foreach ($statusMap as $key => $name) {
            $stats['status'][$key] = [
                'name' => $name,
                'count' => 0,
                'total_amount' => 0,
            ];
        }
        // 填充实际数据
        foreach ($statusStats as $item) {
            $stats['status'][$item['status']] = [
                'name' => $statusMap[$item['status']] ?? '未知',
                'count' => (int)$item['count'],
                'total_amount' => round((float)($item['total_amount'] ?? 0), 2),
            ];
        }

        // 总记录数
        $stats['total'] = \think\facade\Db::name('trade_reservations')->count();

        // 当前冻结金额（仅待处理状态）
        $stats['current_freeze_amount'] = (float)\think\facade\Db::name('trade_reservations')
            ->where('status', 0)
            ->sum('freeze_amount');

        // 历史总冻结金额
        $stats['total_freeze_amount'] = (float)\think\facade\Db::name('trade_reservations')
            ->sum('freeze_amount');

        // 今日新增
        $stats['today_new'] = \think\facade\Db::name('trade_reservations')
            ->where('create_time', '>=', strtotime('today'))
            ->count();

        // 今日中签
        $stats['today_win'] = \think\facade\Db::name('trade_reservations')
            ->where('status', 1)
            ->where('update_time', '>=', strtotime('today'))
            ->count();

        // 按资产包+分区组合统计 (TOP 30)
        $packageZoneStats = \think\facade\Db::name('trade_reservations')
            ->alias('tr')
            ->leftJoin('asset_package ap', 'tr.package_id = ap.id')
            ->leftJoin('price_zone_config pz', 'tr.zone_id = pz.id')
            ->field('ap.id as package_id, pz.id as zone_id, CONCAT(IFNULL(ap.name, "未分类"), " ", IFNULL(pz.name, "未分区")) as name, COUNT(*) as count, SUM(CASE WHEN tr.status = 0 THEN 1 ELSE 0 END) as pending_count, SUM(CASE WHEN tr.status = 1 THEN 1 ELSE 0 END) as win_count, SUM(CASE WHEN tr.status = 2 THEN 1 ELSE 0 END) as lose_count, SUM(tr.freeze_amount) as total_amount')
            ->group('tr.package_id, tr.zone_id')
            ->order('count desc')
            ->limit(30)
            ->select()
            ->toArray();

        // 格式化资产包分区统计
        foreach ($packageZoneStats as &$s) {
            $s['pending_count'] = (int)($s['pending_count'] ?? 0);
            $s['win_count'] = (int)($s['win_count'] ?? 0);
            $s['lose_count'] = (int)($s['lose_count'] ?? 0);
            $s['total_amount'] = round((float)($s['total_amount'] ?? 0), 2);
            $s['win_rate'] = $s['count'] > 0 ? round(($s['win_count'] / $s['count']) * 100, 1) : 0;
        }

        $stats['package_zones'] = $packageZoneStats;

        // 获取专场选项（用于筛选）
        $sessionOptions = \think\facade\Db::name('trade_reservations')
            ->alias('tr')
            ->leftJoin('collection_session cs', 'tr.session_id = cs.id')
            ->field('DISTINCT cs.id as session_id, cs.title as session_title')
            ->whereNotNull('cs.id')
            ->order('cs.id desc')
            ->select()
            ->toArray();

        $stats['session_options'] = $sessionOptions;

        // 按资产包统计 (TOP 20)
        $packageStats = \think\facade\Db::name('trade_reservations')
            ->alias('tr')
            ->leftJoin('asset_package ap', 'tr.package_id = ap.id')
            ->field('ap.id as package_id, ap.name as package_name, 
                COUNT(*) as count,
                SUM(CASE WHEN tr.status = 0 THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN tr.status = 1 THEN 1 ELSE 0 END) as win_count,
                SUM(CASE WHEN tr.status = 2 THEN 1 ELSE 0 END) as lose_count,
                SUM(tr.freeze_amount) as total_amount')
            ->where('tr.package_id', '>', 0)
            ->group('ap.id')
            ->order('count desc')
            ->limit(20)
            ->select()
            ->toArray();

        // 格式化资产包统计
        foreach ($packageStats as &$p) {
            $p['pending_count'] = (int)($p['pending_count'] ?? 0);
            $p['win_count'] = (int)($p['win_count'] ?? 0);
            $p['lose_count'] = (int)($p['lose_count'] ?? 0);
            $p['total_amount'] = round((float)($p['total_amount'] ?? 0), 2);
            $p['win_rate'] = $p['count'] > 0 ? round(($p['win_count'] / $p['count']) * 100, 1) : 0;
        }

        $stats['packages'] = $packageStats;

        $this->success('', [
            'stats' => $stats,
        ]);
    }
}
