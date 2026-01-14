<?php

namespace app\admin\controller\shop;

use Throwable;
use think\facade\Db;
use app\common\controller\Backend;
use think\exception\HttpResponseException;

/**
 * 商城订单取消审核管理
 */
class OrderCancelReview extends Backend
{
    protected string|array $quickSearchField = ['order_no', 'id'];
    
    protected array $withJoinTable = ['user', 'shop_order'];
    
    protected string|array $defaultSortField = 'id,desc';
    
    /**
     * 返回select搜索的选项数据
     */
    public function select(): void
    {
        $field = $this->request->get('field', '');
        
        $options = [];
        
        switch ($field) {
            case 'status':
                $options = [
                    ['value' => '0', 'label' => '待审核'],
                    ['value' => '1', 'label' => '已通过'],
                    ['value' => '2', 'label' => '已拒绝'],
                ];
                break;
        }
        
        $this->success('', [
            'options' => $options
        ]);
    }
    
    /**
     * 查看列表
     */
    public function index(): void
    {
        if ($this->request->param('select')) {
            $this->select();
        }
        
        $quickSearch = $this->request->get('quickSearch/s', '');
        $limit       = $this->request->get('limit/d', 10);
        $search      = $this->request->get('search/a', []);
        
        $where = [];
        
        // 快速搜索
        if ($quickSearch) {
            $searchValue = '%' . str_replace('%', '\%', $quickSearch) . '%';
            $where[] = function ($query) use ($searchValue) {
                $query->where('r.id', 'like', $searchValue)
                    ->whereOr('r.order_no', 'like', $searchValue)
                    ->whereOr('u.mobile', 'like', $searchValue)
                    ->whereOr('u.nickname', 'like', $searchValue);
            };
        }
        
        // 通用搜索
        foreach ($search as $field) {
            if (!is_array($field) || !isset($field['operator']) || !isset($field['field']) || !isset($field['val'])) {
                continue;
            }
            
            $operator = $this->getOperatorByAlias($field['operator']);
            
            if ($field['field'] == 'status') {
                $where[] = ['r.status', $operator, $field['val']];
            } elseif ($field['field'] == 'apply_time' && isset($field['render']) && $field['render'] == 'datetime') {
                if ($operator == 'RANGE') {
                    $datetimeArr = explode(',', $field['val']);
                    if (isset($datetimeArr[1])) {
                        $datetimeArr = array_filter(array_map("strtotime", $datetimeArr));
                        $where[] = ['r.apply_time', 'BETWEEN', $datetimeArr];
                    }
                }
            }
        }
        
        // 查询数据
        $res = Db::name('shop_order_cancel_review')
            ->alias('r')
            ->leftJoin('user u', 'r.user_id = u.id')
            ->leftJoin('shop_order o', 'r.order_id = o.id')
            ->leftJoin('admin a', 'r.audit_admin_id = a.id')
            ->where($where)
            ->field([
                'r.*',
                'u.username as user_username',
                'u.nickname as user_nickname',
                'u.mobile as user_mobile',
                'o.total_amount',
                'o.total_score',
                'o.pay_type',
                'o.status as order_status',
                'a.username as audit_admin_username'
            ])
            ->order('r.id', 'desc')
            ->paginate($limit);
        
        $list = $res->items();
        
        // 处理状态文本
        foreach ($list as &$item) {
            $statusMap = [
                0 => '待审核',
                1 => '已通过',
                2 => '已拒绝',
            ];
            $item['status_text'] = $statusMap[$item['status']] ?? $item['status'];
            
            $payTypeMap = [
                'money' => '余额支付',
                'score' => '消费金支付',
            ];
            $item['pay_type_text'] = $payTypeMap[$item['pay_type']] ?? $item['pay_type'];
            
            // 计算订单创建时间到现在的小时数
            $hoursSinceCreate = ($item['apply_time'] - $item['order_create_time']) / 3600;
            $item['hours_since_create'] = round($hoursSinceCreate, 2);
        }
        
        $this->success('', [
            'list' => $list,
            'total' => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }
    
    /**
     * 审核通过
     */
    public function approve(): void
    {
        $id = $this->request->post('id/d', 0);
        if (!$id) {
            $this->error('ID不能为空');
        }
        
        $remark = $this->request->post('audit_remark/s', '');
        $adminId = $this->auth->id;
        $now = time();
        
        Db::startTrans();
        try {
            // 查询审核记录
            $review = Db::name('shop_order_cancel_review')
                ->where('id', $id)
                ->lock(true)
                ->find();
            
            if (!$review) {
                throw new \Exception('审核记录不存在');
            }
            
            if ($review['status'] != 0) {
                throw new \Exception('仅待审核状态可操作');
            }
            
            // 查询订单
            $order = Db::name('shop_order')
                ->where('id', $review['order_id'])
                ->lock(true)
                ->find();
            
            if (!$order) {
                throw new \Exception('订单不存在');
            }
            
            if ($order['status'] != 'paid') {
                throw new \Exception('订单状态不允许取消');
            }
            
            // 查询用户
            $user = Db::name('user')
                ->where('id', $review['user_id'])
                ->lock(true)
                ->find();
            
            if (!$user) {
                throw new \Exception('用户不存在');
            }
            
            // 退款
            if ($order['pay_type'] == 'money') {
                $beforeMoney = $user['balance_available'];
                $afterMoney = $beforeMoney + $order['total_amount'];
                Db::name('user')->where('id', $review['user_id'])->update(['balance_available' => $afterMoney]);
                
                // 记录余额日志
                $flowNo = generateSJSFlowNo($review['user_id']);
                $batchNo = generateBatchNo('SHOP_ORDER_CANCEL_REVIEW', $review['order_id']);
                Db::name('user_money_log')->insert([
                    'user_id' => $review['user_id'],
                    'flow_no' => $flowNo,
                    'batch_no' => $batchNo,
                    'biz_type' => 'shop_order_cancel_review',
                    'biz_id' => $review['order_id'],
                    'field_type' => 'balance_available',
                    'money' => $order['total_amount'],
                    'before' => $beforeMoney,
                    'after' => $afterMoney,
                    'memo' => '商城订单取消审核通过退款',
                    'create_time' => $now,
                ]);
            } else {
                $beforeScore = $user['score'];
                $afterScore = $beforeScore + $order['total_score'];
                Db::name('user')->where('id', $review['user_id'])->update(['score' => $afterScore]);
                
                // 记录积分日志
                $flowNo = generateSJSFlowNo($review['user_id']);
                $batchNo = generateBatchNo('SHOP_ORDER_CANCEL_REVIEW', $review['order_id']);
                Db::name('user_score_log')->insert([
                    'user_id' => $review['user_id'],
                    'flow_no' => $flowNo,
                    'batch_no' => $batchNo,
                    'biz_type' => 'shop_order_cancel_review',
                    'biz_id' => $review['order_id'],
                    'score' => $order['total_score'],
                    'before' => $beforeScore,
                    'after' => $afterScore,
                    'memo' => '商城订单取消审核通过退款',
                    'create_time' => $now,
                ]);
            }
            
            // 更新订单状态
            Db::name('shop_order')
                ->where('id', $review['order_id'])
                ->update([
                    'status' => 'cancelled',
                    'update_time' => $now,
                ]);
            
            // 更新审核记录
            Db::name('shop_order_cancel_review')
                ->where('id', $id)
                ->update([
                    'status' => 1,
                    'audit_time' => $now,
                    'audit_admin_id' => $adminId,
                    'audit_remark' => $remark,
                    'update_time' => $now,
                ]);
            
            // 记录活动日志
            Db::name('user_activity_log')->insert([
                'user_id' => $review['user_id'],
                'related_user_id' => $adminId,
                'action_type' => 'shop_order_cancel_review_approved',
                'change_field' => 'order_status',
                'change_value' => 'cancelled',
                'before_value' => 'paid',
                'after_value' => 'cancelled',
                'remark' => '订单取消审核通过，已退款',
                'extra' => json_encode([
                    'order_no' => $review['order_no'],
                    'order_id' => $review['order_id'],
                    'review_id' => $id,
                    'audit_remark' => $remark,
                ], JSON_UNESCAPED_UNICODE),
                'create_time' => $now,
                'update_time' => $now,
            ]);
            
            Db::commit();
            $this->success('审核通过成功');
            
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
    }
    
    /**
     * 审核拒绝
     */
    public function reject(): void
    {
        $id = $this->request->post('id/d', 0);
        if (!$id) {
            $this->error('ID不能为空');
        }
        
        $remark = $this->request->post('audit_remark/s', '');
        if (empty($remark)) {
            $this->error('请填写拒绝原因');
        }
        
        $adminId = $this->auth->id;
        $now = time();
        
        Db::startTrans();
        try {
            // 查询审核记录
            $review = Db::name('shop_order_cancel_review')
                ->where('id', $id)
                ->lock(true)
                ->find();
            
            if (!$review) {
                throw new \Exception('审核记录不存在');
            }
            
            if ($review['status'] != 0) {
                throw new \Exception('仅待审核状态可操作');
            }
            
            // 更新审核记录
            Db::name('shop_order_cancel_review')
                ->where('id', $id)
                ->update([
                    'status' => 2,
                    'audit_time' => $now,
                    'audit_admin_id' => $adminId,
                    'audit_remark' => $remark,
                    'update_time' => $now,
                ]);
            
            // 记录活动日志
            Db::name('user_activity_log')->insert([
                'user_id' => $review['user_id'],
                'related_user_id' => $adminId,
                'action_type' => 'shop_order_cancel_review_rejected',
                'change_field' => 'review_status',
                'change_value' => 'rejected',
                'before_value' => 'pending',
                'after_value' => 'rejected',
                'remark' => '订单取消审核被拒绝',
                'extra' => json_encode([
                    'order_no' => $review['order_no'],
                    'order_id' => $review['order_id'],
                    'review_id' => $id,
                    'audit_remark' => $remark,
                ], JSON_UNESCAPED_UNICODE),
                'create_time' => $now,
                'update_time' => $now,
            ]);
            
            Db::commit();
            $this->success('审核拒绝成功');
            
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
    }
    
    /**
     * 查看详情
     */
    public function detail(): void
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            $this->error('ID不能为空');
        }
        
        $row = Db::name('shop_order_cancel_review')
            ->alias('r')
            ->leftJoin('user u', 'r.user_id = u.id')
            ->leftJoin('shop_order o', 'r.order_id = o.id')
            ->leftJoin('admin a', 'r.audit_admin_id = a.id')
            ->where('r.id', $id)
            ->field([
                'r.*',
                'u.username as user_username',
                'u.nickname as user_nickname',
                'u.mobile as user_mobile',
                'o.total_amount',
                'o.total_score',
                'o.pay_type',
                'o.status as order_status',
                'o.recipient_name',
                'o.recipient_phone',
                'o.recipient_address',
                'a.username as audit_admin_username'
            ])
            ->find();
        
        if (!$row) {
            $this->error('记录不存在');
        }
        
        // 处理状态文本
        $statusMap = [
            0 => '待审核',
            1 => '已通过',
            2 => '已拒绝',
        ];
        $row['status_text'] = $statusMap[$row['status']] ?? $row['status'];
        
        $payTypeMap = [
            'money' => '余额支付',
            'score' => '消费金支付',
        ];
        $row['pay_type_text'] = $payTypeMap[$row['pay_type']] ?? $row['pay_type'];
        
        // 计算订单创建时间到申请时间的小时数
        $hoursSinceCreate = ($row['apply_time'] - $row['order_create_time']) / 3600;
        $row['hours_since_create'] = round($hoursSinceCreate, 2);
        
        $this->success('', [
            'row' => $row
        ]);
    }
}
