<?php

namespace app\admin\controller\user;

use app\admin\model\UserConsignmentCoupon as ConsignmentCouponModel;
use app\common\controller\Backend;

class ConsignmentCoupon extends Backend
{
    /**
     * @var object
     * @phpstan-var ConsignmentCouponModel
     */
    protected object $model;

    protected array $withJoinTable = ['user', 'session', 'zone'];

    // 排除字段
    protected string|array $preExcludeFields = ['create_time', 'update_time'];

    protected string|array $quickSearchField = ['user.username', 'user.nickname', 'user.mobile'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new ConsignmentCouponModel();
    }

    /**
     * 编辑
     */
    public function edit($ids = null): void
    {
        if ($this->request->isPost()) {
            $data = $this->request->post();
            
            // 如果更新了 zone_id，同步更新 price_zone 名称
            if (isset($data['zone_id'])) {
                $zone = \app\admin\model\PriceZoneConfig::where('id', $data['zone_id'])->find();
                if ($zone) {
                    $data['price_zone'] = $zone['name'];
                }
            }
            
            $this->request->withPost($data);
        }
        parent::edit($ids);
    }

    /**
     * 查看
     */
    public function index(): void
    {
        if ($this->request->param('select')) {
            $this->select();
        }

        list($where, $alias, $limit, $order) = $this->queryBuilder();
        $res = $this->model
            ->withJoin($this->withJoinTable, $this->withJoinType)
            ->alias($alias)
            ->where($where)
            ->order($order)
            ->paginate($limit);

        // 添加状态文字和格式化时间
        $list = $res->items();
        $now = time();
        foreach ($list as &$item) {
            // 计算状态文字
            $status = (int)($item['status'] ?? 0);
            $expireTime = isset($item['expire_time']) ? strtotime($item['expire_time']) : 0;
            
            if ($status === 0) {
                $item['status_text'] = '已使用';
            } elseif ($expireTime > 0 && $expireTime < $now) {
                $item['status_text'] = '已过期';
            } else {
                $item['status_text'] = '可用';
            }
        }
        unset($item);

        $this->success('', [
            'list'   => $list,
            'total'  => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }
}
