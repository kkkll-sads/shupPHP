<?php

namespace app\admin\controller\collection;

use Throwable;
use app\common\controller\Backend;
use app\admin\model\CollectionItem as CollectionItemModel;
use think\facade\Log;

class Item extends Backend
{
    /**
     * @var CollectionItemModel
     */
    protected object $model;

    protected string|array $quickSearchField = ['title', 'id', 'core_enterprise', 'farmer_info'];
    
    // 无需鉴权的方法
    protected array $noNeedPermission = ['globalStats', 'statistics'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new CollectionItemModel();
    }

    /**
     * @throws Throwable
     */
    public function index(): void
    {
        if ($this->request->param('select')) {
            $this->select();
        }

        [$where, $alias, $limit, $order] = $this->queryBuilder('sort desc,id desc');

        $res = $this->model
            ->alias($alias)
            ->with(['package'])  // 🆕 添加资产包关联
            ->where($where)
            ->order($order)
            ->paginate($limit);
        
        // 🆕 处理列表数据，添加资产包名称
        $list = $res->items();
        foreach ($list as &$item) {
            $item['package_name'] = $item['package']['name'] ?? '未分类';
        }

        $this->success('', [
            'list' => $list,
            'total' => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * @throws Throwable
     */
    public function add(): void
    {
        if ($this->request->isPost()) {
            $data = $this->excludeFields($this->request->post());
            if (!$data) {
                $this->error(__('Parameter %s can not be empty', ['']));
            }

            unset($data['create_time'], $data['update_time']);

            // 获取添加数量，默认为1，限制1-100
            $quantity = intval($data['quantity'] ?? 1);
            $quantity = max(1, min($quantity, 100));
            unset($data['quantity']);

            $addedCount = 0;
            $this->model->startTrans();
            try {
                if ($this->modelValidate) {
                    $validate = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                    if (class_exists($validate)) {
                        $validate = new $validate();
                        if ($this->modelSceneValidate) {
                            $validate->scene('add');
                        }
                        $validate->check($data);
                    }
                }

                // 批量创建藏品
                for ($i = 0; $i < $quantity; $i++) {
                    $itemData = $data;
                    // 每个藏品生成唯一的存证指纹
                    // 如果已有确权编号，基于确权编号生成；否则使用随机生成
                    if (!empty($itemData['asset_code'])) {
                        $itemData['tx_hash'] = '0x' . md5($itemData['asset_code']);
                    } else {
                        $itemData['tx_hash'] = $this->generateFingerprint();
                    }
                    
                    $item = new CollectionItemModel();
                    $item->save($itemData);
                    $addedCount++;
                }

                $this->model->commit();
            } catch (Throwable $e) {
                $this->model->rollback();
                Log::error(sprintf(
                    'CollectionItem batch add failed: %s in %s:%d; data=%s; quantity=%d; trace=%s',
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    json_encode($data, JSON_UNESCAPED_UNICODE),
                    $quantity,
                    $e->getTraceAsString()
                ));
                $this->error($e->getMessage());
            }

            if ($addedCount > 0) {
                $this->success(__('Added successfully') . '，共添加 ' . $addedCount . ' 个藏品');
            }
            $this->error(__('No rows were added'));
        }

        // 🆕 获取资产包列表供选择
        $packages = \think\facade\Db::name('asset_package')
            ->where('status', '1')
            ->field('id, name, session_id')
            ->order('id desc')
            ->select()
            ->toArray();

        $this->success('', [
            'remark' => get_route_remark(),
            'packages' => $packages,
        ]);
    }

    /**
     * 生成唯一存证指纹（0x + 32字节十六进制）
     */
    private function generateFingerprint(): string
    {
        $hex = '';
        try {
            $hex = bin2hex(random_bytes(16));
        } catch (\Throwable) {
            $hex = md5(uniqid((string)microtime(true), true));
        }
        return '0x' . $hex;
    }

    /**
     * @throws Throwable
     */
    public function edit(): void
    {
        $pk = $this->model->getPk();
        $id = $this->request->param($pk);
        $row = $this->model->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        if ($this->request->isPost()) {
            $data = $this->excludeFields($this->request->post());
            if (!$data) {
                $this->error(__('Parameter %s can not be empty', ['']));
            }

            unset($data['create_time'], $data['update_time']);

            $result = false;
            $this->model->startTrans();
            try {
                // 快速编辑检测：如果只更新少数字段（如只更新status），跳过完整验证
                $isQuickEdit = count($data) <= 2; // 只有1-2个字段时视为快速编辑
                
                if ($this->modelValidate && !$isQuickEdit) {
                    $validate = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                    if (class_exists($validate)) {
                        $validate = new $validate();
                        if ($this->modelSceneValidate) {
                            $validate->scene('edit');
                        }
                        $validate->check($data);
                    }
                }

                $result = $row->save($data);
                $this->model->commit();
            } catch (Throwable $e) {
                $this->model->rollback();
                $this->error($e->getMessage());
            }

            if ($result !== false) {
                $this->success(__('Updated successfully'));
            }
            $this->error(__('No rows were updated'));
        }

        // 🆕 获取资产包列表供选择
        $packages = \think\facade\Db::name('asset_package')
            ->where('status', '1')
            ->field('id, name, session_id')
            ->order('id desc')
            ->select()
            ->toArray();

        $this->success('', [
            'row' => $row,
            'remark' => get_route_remark(),
            'packages' => $packages,
        ]);
    }

    /**
     * 获取藏品详细统计信息
     * 包括：交易次数、交易用户明细、寄售统计等
     * @throws Throwable
     */
    public function statistics(): void
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            $this->error('藏品ID不能为空');
        }

        $item = $this->model->find($id);
        if (!$item) {
            $this->error('藏品不存在');
        }

        try {
            // 1. 藏品基本信息
            $basicInfo = [
                'id' => $item->id,
                'title' => $item->title,
                'image' => $item->image ? full_url($item->image, false) : '',
                'price' => (float)$item->price,
                'issue_price' => (float)$item->issue_price,
                'stock' => (int)$item->stock,
                'sales' => (int)$item->sales,
                'status' => $item->status,
                'status_text' => $item->status == '1' ? '上架中' : '已下架',
                'session_id' => $item->session_id,
                'zone_id' => $item->zone_id,
                'core_enterprise' => $item->core_enterprise ?? '',
                'farmer_info' => $item->farmer_info ?? '',
                'asset_code' => $item->asset_code ?? '',
                'tx_hash' => $item->tx_hash ?? '',
                'create_time' => $item->create_time ? date('Y-m-d H:i:s', $item->create_time) : '',
            ];

            // 2. 交易统计（从用户藏品表统计）
            $tradeStats = \think\facade\Db::name('user_collection')
                ->where('item_id', $id)
                ->field([
                    'COUNT(*) as total_trades',
                    'COUNT(DISTINCT user_id) as unique_buyers',
                    'SUM(price) as total_amount',
                    'MIN(buy_time) as first_trade_time',
                    'MAX(buy_time) as last_trade_time',
                ])
                ->find();

            $tradeStatistics = [
                'total_trades' => (int)$tradeStats['total_trades'],
                'unique_buyers' => (int)$tradeStats['unique_buyers'],
                'total_amount' => (float)($tradeStats['total_amount'] ?? 0),
                'first_trade_time' => $tradeStats['first_trade_time'] ? date('Y-m-d H:i:s', $tradeStats['first_trade_time']) : '',
                'last_trade_time' => $tradeStats['last_trade_time'] ? date('Y-m-d H:i:s', $tradeStats['last_trade_time']) : '',
            ];

            // 3. 交易用户明细（最近50条）
            $tradeUsers = \think\facade\Db::name('user_collection')
                ->alias('uc')
                ->leftJoin('user u', 'uc.user_id = u.id')
                ->where('uc.item_id', $id)
                ->field([
                    'uc.id as collection_id',
                    'uc.user_id',
                    'u.username',
                    'u.nickname',
                    'u.mobile',
                    'uc.price',
                    'uc.buy_time',
                    'uc.delivery_status',
                    'uc.consignment_status',
                    'uc.is_old_asset_package',
                ])
                ->order('uc.buy_time desc')
                ->limit(50)
                ->select()
                ->toArray();

            foreach ($tradeUsers as &$user) {
                $user['buy_time_text'] = $user['buy_time'] ? date('Y-m-d H:i:s', $user['buy_time']) : '';
                $user['price'] = (float)$user['price'];
                
                // 交付状态
                $deliveryStatusMap = [
                    0 => '已交付',
                    1 => '待交付',
                ];
                $user['delivery_status_text'] = $deliveryStatusMap[$user['delivery_status']] ?? '未知';
                
                // 寄售状态
                $consignmentStatusMap = [
                    0 => '未寄售',
                    1 => '寄售中',
                    2 => '已售出',
                ];
                $user['consignment_status_text'] = $consignmentStatusMap[$user['consignment_status']] ?? '未知';
                
                // 是否旧资产包
                $user['is_old_asset_package_text'] = $user['is_old_asset_package'] == 1 ? '是' : '否';
            }

            // 4. 寄售统计
            $consignmentStats = \think\facade\Db::name('collection_consignment')
                ->alias('c')
                ->leftJoin('user_collection uc', 'c.user_collection_id = uc.id')
                ->where('uc.item_id', $id)
                ->field([
                    'COUNT(*) as total_consignments',
                    'SUM(CASE WHEN c.status = 1 THEN 1 ELSE 0 END) as consigning',
                    'SUM(CASE WHEN c.status = 2 THEN 1 ELSE 0 END) as sold',
                    'SUM(CASE WHEN c.status = 3 THEN 1 ELSE 0 END) as offshelf',
                    'SUM(CASE WHEN c.status = 0 THEN 1 ELSE 0 END) as cancelled',
                    'AVG(c.price) as avg_consignment_price',
                    'MIN(c.price) as min_consignment_price',
                    'MAX(c.price) as max_consignment_price',
                ])
                ->find();

            $consignmentStatistics = [
                'total_consignments' => (int)$consignmentStats['total_consignments'],
                'consigning' => (int)$consignmentStats['consigning'], // 寄售中
                'sold' => (int)$consignmentStats['sold'], // 已售出
                'offshelf' => (int)$consignmentStats['offshelf'], // 已下架
                'cancelled' => (int)$consignmentStats['cancelled'], // 已取消
                'failed' => (int)$consignmentStats['offshelf'] + (int)$consignmentStats['cancelled'], // 失败次数 = 下架 + 取消
                'avg_consignment_price' => (float)($consignmentStats['avg_consignment_price'] ?? 0),
                'min_consignment_price' => (float)($consignmentStats['min_consignment_price'] ?? 0),
                'max_consignment_price' => (float)($consignmentStats['max_consignment_price'] ?? 0),
            ];

            // 5. 寄售明细（最近30条）
            $consignmentList = \think\facade\Db::name('collection_consignment')
                ->alias('c')
                ->leftJoin('user_collection uc', 'c.user_collection_id = uc.id')
                ->leftJoin('user u', 'c.user_id = u.id')
                ->where('uc.item_id', $id)
                ->field([
                    'c.id as consignment_id',
                    'c.user_id',
                    'u.username',
                    'u.nickname',
                    'c.price as consignment_price',
                    'c.service_fee',
                    'c.status',
                    'c.create_time',
                    'c.update_time',
                    'uc.is_old_asset_package',
                ])
                ->order('c.create_time desc')
                ->limit(30)
                ->select()
                ->toArray();

            $statusMap = [
                0 => '已取消',
                1 => '寄售中',
                2 => '已售出',
                3 => '已下架',
            ];

            foreach ($consignmentList as &$consignment) {
                $consignment['consignment_price'] = (float)$consignment['consignment_price'];
                $consignment['service_fee'] = (float)($consignment['service_fee'] ?? 0);
                $consignment['total_cost'] = $consignment['consignment_price'] + $consignment['service_fee'];
                $consignment['status_text'] = $statusMap[$consignment['status']] ?? '未知';
                $consignment['create_time_text'] = $consignment['create_time'] ? date('Y-m-d H:i:s', $consignment['create_time']) : '';
                $consignment['update_time_text'] = $consignment['update_time'] ? date('Y-m-d H:i:s', $consignment['update_time']) : '';
                $consignment['is_old_asset_package_text'] = $consignment['is_old_asset_package'] == 1 ? '是' : '否';
            }

            // 6. 盲盒预约统计（如果有）
            $blindBoxStats = \think\facade\Db::name('trade_reservations')
                ->where('product_id', $id)
                ->field([
                    'COUNT(*) as total_reservations',
                    'SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as won',
                    'SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) as not_won',
                    'SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as pending',
                ])
                ->find();

            $blindBoxStatistics = [
                'total_reservations' => (int)$blindBoxStats['total_reservations'],
                'won' => (int)$blindBoxStats['won'],
                'not_won' => (int)$blindBoxStats['not_won'],
                'pending' => (int)$blindBoxStats['pending'],
            ];

            // 返回完整统计数据
            $this->success('', [
                'basic_info' => $basicInfo,
                'trade_statistics' => $tradeStatistics,
                'trade_users' => $tradeUsers,
                'consignment_statistics' => $consignmentStatistics,
                'consignment_list' => $consignmentList,
                'blind_box_statistics' => $blindBoxStatistics,
            ]);

        } catch (Throwable $e) {
            Log::error('获取藏品统计信息失败: ' . $e->getMessage());
            $this->error('获取统计信息失败：' . $e->getMessage());
        }
    }

    /**
     * @throws Throwable
     */
    public function del(): void
    {
        $where = [];
        $dataLimitAdminIds = $this->getDataLimitAdminIds();
        if ($dataLimitAdminIds) {
            $where[] = [$this->dataLimitField, 'in', $dataLimitAdminIds];
        }

        $ids = $this->request->param('ids/a', []);
        $where[] = [$this->model->getPk(), 'in', $ids];
        $list = $this->model->where($where)->select();

        // 🆕 统计各资产包需要减少的商品数量
        $packageDelCounts = [];
        foreach ($list as $item) {
            if (!empty($item['package_id']) && $item['package_id'] > 0) {
                if (!isset($packageDelCounts[$item['package_id']])) {
                    $packageDelCounts[$item['package_id']] = 0;
                }
                $packageDelCounts[$item['package_id']]++;
            }
        }

        $count = 0;
        $this->model->startTrans();
        try {
            foreach ($list as $item) {
                $count += $item->delete();
            }
            
            // 🆕 同步更新资产包的 generated_count
            foreach ($packageDelCounts as $packageId => $delCount) {
                \think\facade\Db::name('asset_package')
                    ->where('id', $packageId)
                    ->update([
                        'generated_count' => \think\facade\Db::raw('GREATEST(generated_count - ' . $delCount . ', 0)'),
                        'total_count' => \think\facade\Db::raw('GREATEST(total_count - ' . $delCount . ', 0)'),
                        'update_time' => time(),
                    ]);
            }
            
            $this->model->commit();
        } catch (Throwable $e) {
            $this->model->rollback();
            $this->error($e->getMessage());
        }

        if ($count) {
            $this->success(__('Deleted successfully'));
        }
        $this->error(__('No rows were deleted'));
    }

    /**
     * 全局统计接口
     */
    public function globalStats(): void
    {
        Log::info('========== globalStats 方法被调用 ==========');
        
        $stats = [
            'total_items' => 0,
            'total_stock' => 0,
            'total_sales' => 0,
            'total_value' => 0,
            'today_new' => 0,
            'today_sales' => 0,
            'active_items' => 0,
            'inactive_items' => 0,
            'sessions' => [],
            'zones' => [],
        ];
        
        Log::info('Stats array initialized');

        try {
            Log::info('Step 1: 查询总藏品数');
            // 总藏品数
            $stats['total_items'] = \think\facade\Db::name('collection_item')->count();

            Log::info('Step 2: 查询总库存');
            // 总库存
            $stats['total_stock'] = (int)\think\facade\Db::name('collection_item')->sum('stock');

            Log::info('Step 3: 查询总销量');
            // 总销量
            $stats['total_sales'] = (int)\think\facade\Db::name('collection_item')->sum('sales');

            Log::info('Step 4: 查询今日新增');
            // 今日新增藏品
            $stats['today_new'] = \think\facade\Db::name('collection_item')
                ->where('create_time', '>=', strtotime('today'))
                ->count();

            Log::info('Step 5: 查询今日销量');
            // 今日销量
            $stats['today_sales'] = (int)\think\facade\Db::name('user_collection')
                ->where('buy_time', '>=', strtotime('today'))
                ->count();

            Log::info('Step 6: 查询上架数量');
            // 上架中数量
            $stats['active_items'] = \think\facade\Db::name('collection_item')
                ->where('status', 1)
                ->count();
            
            Log::info('Step 7: 查询下架数量');
            // 下架数量
            $stats['inactive_items'] = \think\facade\Db::name('collection_item')
                ->where('status', 0)
                ->count();

            // 按资产包+分区组合统计 (TOP 30)
            try {
                $packageZoneStats = \think\facade\Db::name('collection_item')
                    ->alias('ci')
                    ->leftJoin('asset_package ap', 'ci.package_id = ap.id')
                    ->leftJoin('price_zone_config pz', 'ci.zone_id = pz.id')
                    ->field('ap.id as package_id, ap.name as package_name, pz.id as zone_id, pz.name as zone_name, CONCAT(IFNULL(ap.name, "未分类"), " ", IFNULL(pz.name, "未分区")) as name, COUNT(*) as count, SUM(ci.stock) as stock, SUM(ci.sales) as sales')
                    ->group('ci.package_id, ci.zone_id')
                    ->order('count desc')
                    ->limit(30)
                    ->select()
                    ->toArray();

                foreach ($packageZoneStats as &$s) {
                    $s['stock'] = (int)($s['stock'] ?? 0);
                    $s['sales'] = (int)($s['sales'] ?? 0);
                }
                $stats['package_zones'] = $packageZoneStats;
            } catch (\Throwable $e) {
                Log::warning('资产包分区统计查询失败: ' . $e->getMessage());
                $stats['package_zones'] = [];
            }

            // 按价格分区统计 (TOP 10)
            try {
                $zoneStats = \think\facade\Db::name('collection_item')
                    ->alias('ci')
                    ->leftJoin('price_zone_config pz', 'ci.zone_id = pz.id')
                    ->field('pz.id as zone_id, pz.name as zone_name, COUNT(*) as count, SUM(ci.stock) as stock, SUM(ci.sales) as sales')
                    ->where('ci.zone_id', '>', 0)
                    ->group('ci.zone_id')
                    ->order('count desc')
                    ->limit(10)
                    ->select()
                    ->toArray();

                foreach ($zoneStats as &$z) {
                    $z['stock'] = (int)($z['stock'] ?? 0);
                    $z['sales'] = (int)($z['sales'] ?? 0);
                }
                $stats['zones'] = $zoneStats;
            } catch (\Throwable $e) {
                Log::warning('分区统计查询失败: ' . $e->getMessage());
                $stats['zones'] = [];
            }

            // 按资产包统计 (TOP 20)
            try {
                $packageStats = \think\facade\Db::name('collection_item')
                    ->alias('ci')
                    ->leftJoin('asset_package ap', 'ci.package_id = ap.id')
                    ->field('ap.id as package_id, ap.name as package_name, COUNT(*) as count, SUM(ci.stock) as stock, SUM(ci.sales) as sales')
                    ->where('ci.package_id', '>', 0)
                    ->group('ci.package_id')
                    ->order('count desc')
                    ->limit(20)
                    ->select()
                    ->toArray();

                foreach ($packageStats as &$p) {
                    $p['stock'] = (int)($p['stock'] ?? 0);
                    $p['sales'] = (int)($p['sales'] ?? 0);
                }
                $stats['packages'] = $packageStats;
            } catch (\Throwable $e) {
                Log::warning('资产包统计查询失败: ' . $e->getMessage());
                $stats['packages'] = [];
            }

            $this->success('', ['stats' => $stats]);
        } catch (\think\exception\HttpResponseException $e) {
            // 正常的响应异常，需要重新抛出
            throw $e;
        } catch (Throwable $e) {
            $errorDetail = sprintf(
                "%s: %s at %s:%d",
                get_class($e),
                $e->getMessage() ?: '(no message)',
                basename($e->getFile()),
                $e->getLine()
            );
            Log::error("获取藏品全局统计失败: " . $errorDetail);
            $this->error('查询失败: ' . $errorDetail);
        }
    }
}

