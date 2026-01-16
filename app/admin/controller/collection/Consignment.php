<?php

namespace app\admin\controller\collection;

use Throwable;
use think\facade\Db;
use app\common\controller\Backend;
use app\admin\model\CollectionConsignment as CollectionConsignmentModel;

/**
 * 寄售记录管理
 */
class Consignment extends Backend
{
    /**
     * @var CollectionConsignmentModel
     */
    protected object $model;

    protected string|array $quickSearchField = ['id', 'package_name', 'user.mobile', 'user.username'];

    protected array $withJoinTable = ['user', 'item'];

    protected string|array $defaultSortField = 'collection_consignment.id,desc';

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new CollectionConsignmentModel();
    }

    /**
     * 列表接口
     * @throws Throwable
     */
    public function index(): void
    {
        if ($this->request->param('select')) {
            $this->select();
        }

        [$where, $alias, $limit, $order] = $this->queryBuilder('id desc');

        $res = $this->model
            ->alias($alias)
            ->where($where)
            ->with(['user', 'item', 'package'])
            ->order($order)
            ->paginate($limit);

        $list = $res->items();
        foreach ($list as &$item) {
            // 状态文本
            $item->status_text = $item->status_text_attr;
            
            // 用户信息
            $item->username = $item['user']['username'] ?? '';
            $item->user_mobile = $item['user']['mobile'] ?? '';
            
            // 商品信息
            $item->item_title = $item['item']['title'] ?? '';
            $item->item_image = $item['item']['image'] ?? '';
            
            // 资产包信息
            $item->package_name_display = $item['package_name'] ?? ($item['package']['name'] ?? '');
        }

        $this->success('', [
            'list' => $list,
            'total' => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 详情接口
     * @throws Throwable
     */
    public function detail(): void
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            $this->error('参数错误');
        }

        $row = $this->model
            ->with(['user', 'item', 'package'])
            ->find($id);

        if (!$row) {
            $this->error('记录不存在');
        }

        // 格式化数据
        $data = $row->toArray();
        $data['status_text'] = $row->status_text_attr;
        $data['username'] = $row['user']['username'] ?? '';
        $data['user_mobile'] = $row['user']['mobile'] ?? '';
        $data['item_title'] = $row['item']['title'] ?? '';
        $data['item_image'] = $row['item']['image'] ?? '';
        $data['package_name_display'] = $row['package_name'] ?? ($row['package']['name'] ?? '');

        // 时间格式化
        $data['create_time_text'] = $data['create_time'] ? date('Y-m-d H:i:s', $data['create_time']) : '';
        $data['update_time_text'] = $data['update_time'] ? date('Y-m-d H:i:s', $data['update_time']) : '';
        $data['sold_time_text'] = $data['sold_time'] ? date('Y-m-d H:i:s', $data['sold_time']) : '';
        $data['settle_time_text'] = $data['settle_time'] ? date('Y-m-d H:i:s', $data['settle_time']) : '';

        $this->success('', [
            'data' => $data,
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 编辑接口（仅允许修改状态）
     * @throws Throwable
     */
    public function edit(): void
    {
        $pk = $this->model->getPk();
        $id = $this->request->param($pk);
        $row = $this->model->with(['user', 'item'])->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        if ($this->request->isPost()) {
            $data = $this->request->post();
            
            // 只允许修改状态
            if (!isset($data['status'])) {
                $this->error('只能修改状态');
            }

            $newStatus = (int)$data['status'];
            $oldStatus = (int)$row->status;

            // 状态验证
            if (!in_array($newStatus, [1, 2, 3, 4])) {
                $this->error('状态值不正确');
            }

            // 状态流转验证
            if ($oldStatus == 2 && $newStatus != 2) {
                $this->error('已售出状态不能修改为其他状态');
            }

            $result = false;
            $this->model->startTrans();
            try {
                $updateData = ['status' => $newStatus];
                
                // 如果状态改为已售出，记录成交时间
                if ($newStatus == 2 && $oldStatus != 2 && empty($row->sold_time)) {
                    $updateData['sold_time'] = time();
                    $updateData['sold_price'] = $row->price;
                }

                $result = $row->save($updateData);
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

        // GET 请求：返回表单数据
        $this->success('', [
            'data' => $row,
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 统计接口
     * @throws Throwable
     */
    public function stats(): void
    {
        // 按状态统计
        $statusStats = Db::name('collection_consignment')
            ->field('status, COUNT(*) as count')
            ->group('status')
            ->select()
            ->toArray();

        $statusMap = [
            1 => '寄售中',
            2 => '已售出',
            3 => '流拍',
            4 => '已取消',
        ];

        $stats = [];
        // 初始化所有状态为0
        foreach ($statusMap as $key => $name) {
            $stats['status'][$key] = [
                'name' => $name,
                'count' => 0,
            ];
        }
        // 填充实际数据
        foreach ($statusStats as $item) {
            $stats['status'][$item['status']] = [
                'name' => $statusMap[$item['status']] ?? '未知',
                'count' => (int)$item['count'],
            ];
        }

        // 总记录数
        $stats['total'] = Db::name('collection_consignment')->count();

        // 已售出总金额
        $stats['total_sold_amount'] = (float)Db::name('collection_consignment')
            ->where('status', 2)
            ->sum('sold_price');

        // 今日新增
        $stats['today_new'] = Db::name('collection_consignment')
            ->where('create_time', '>=', strtotime('today'))
            ->count();

        // 今日成交
        $stats['today_sold'] = Db::name('collection_consignment')
            ->where('status', 2)
            ->where('sold_time', '>=', strtotime('today'))
            ->count();

        // 今日成交金额
        $stats['today_sold_amount'] = (float)Db::name('collection_consignment')
            ->where('status', 2)
            ->where('sold_time', '>=', strtotime('today'))
            ->sum('sold_price');

        // 按资产包统计（动态获取，包含详细状态）
        $packageStats = Db::name('collection_consignment')
            ->field('package_name, 
                COUNT(*) as count, 
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as listing_count,
                SUM(CASE WHEN status = 2 THEN 1 ELSE 0 END) as sold_count,
                SUM(CASE WHEN status = 3 THEN 1 ELSE 0 END) as failed_count,
                SUM(CASE WHEN status = 4 THEN 1 ELSE 0 END) as cancelled_count,
                SUM(price) as total_amount,
                SUM(CASE WHEN status = 2 THEN sold_price ELSE 0 END) as sold_amount')
            ->where('package_name', '<>', '')
            ->whereNotNull('package_name')
            ->group('package_name')
            ->order('count desc')
            ->limit(30)
            ->select()
            ->toArray();

        // 格式化数据
        foreach ($packageStats as &$pkg) {
            $pkg['listing_count'] = (int)($pkg['listing_count'] ?? 0);
            $pkg['sold_count'] = (int)($pkg['sold_count'] ?? 0);
            $pkg['failed_count'] = (int)($pkg['failed_count'] ?? 0);
            $pkg['cancelled_count'] = (int)($pkg['cancelled_count'] ?? 0);
            $pkg['total_amount'] = round((float)($pkg['total_amount'] ?? 0), 2);
            $pkg['sold_amount'] = round((float)($pkg['sold_amount'] ?? 0), 2);
            $pkg['success_rate'] = $pkg['count'] > 0 ? round(($pkg['sold_count'] / $pkg['count']) * 100, 1) : 0;
        }

        $stats['packages'] = $packageStats;

        // 获取所有资产包名称（用于前端筛选器）
        $packageOptions = Db::name('collection_consignment')
            ->field('DISTINCT package_name')
            ->where('package_name', '<>', '')
            ->whereNotNull('package_name')
            ->order('package_name asc')
            ->select()
            ->toArray();
        
        $stats['package_options'] = array_column($packageOptions, 'package_name');

        // 按资产包+分区组合统计 (TOP 30)
        $packageZoneStats = Db::name('collection_consignment')
            ->alias('cc')
            ->leftJoin('asset_package ap', 'cc.package_id = ap.id')
            ->leftJoin('price_zone_config pz', 'cc.zone_id = pz.id')
            ->field('ap.id as package_id, pz.id as zone_id, CONCAT(IFNULL(ap.name, "未分类"), " ", IFNULL(pz.name, "未分区")) as name, COUNT(*) as count, SUM(CASE WHEN cc.status = 1 THEN 1 ELSE 0 END) as listing_count, SUM(CASE WHEN cc.status = 2 THEN 1 ELSE 0 END) as sold_count, SUM(cc.price) as total_amount, SUM(CASE WHEN cc.status = 2 THEN cc.sold_price ELSE 0 END) as sold_amount')
            ->group('cc.package_id, cc.zone_id')
            ->order('count desc')
            ->limit(30)
            ->select()
            ->toArray();

        // 格式化资产包分区统计
        foreach ($packageZoneStats as &$s) {
            $s['listing_count'] = (int)($s['listing_count'] ?? 0);
            $s['sold_count'] = (int)($s['sold_count'] ?? 0);
            $s['total_amount'] = round((float)($s['total_amount'] ?? 0), 2);
            $s['sold_amount'] = round((float)($s['sold_amount'] ?? 0), 2);
            $s['success_rate'] = $s['count'] > 0 ? round(($s['sold_count'] / $s['count']) * 100, 1) : 0;
        }

        $stats['package_zones'] = $packageZoneStats;

        $this->success('', [
            'stats' => $stats,
        ]);
    }
}

