<?php

namespace app\admin\controller\finance;

use Throwable;
use think\facade\Db;
use app\common\controller\Backend;
use app\admin\model\FinanceOrder as FinanceOrderModel;
use think\exception\HttpResponseException;

class Order extends Backend
{
    /**
     * @var FinanceOrderModel
     */
    protected object $model;

    protected string|array $quickSearchField = ['order_no', 'id'];

    protected array $withJoinTable = ['user', 'product'];

    protected string|array $defaultSortField = 'finance_order.id,desc';

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new FinanceOrderModel();
    }

    /**
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
            ->with(['user', 'product'])
            ->order($order)
            ->paginate($limit);

        $list = $res->items();
        foreach ($list as &$item) {
            // 解析extra字段（转换为数组）
            $extra = $item->extra ? (array)$item->extra : [];
            $item->product_name = $extra['product_name'] ?? ($item->product ? $item->product->name : '');
            $item->cycle_days = $extra['cycle_days'] ?? 0;
            $item->expected_income = $extra['expected_income'] ?? 0;
            $item->expire_time = $extra['expire_time'] ?? 0;
            $item->expire_time_text = $item->expire_time ? date('Y-m-d H:i:s', $item->expire_time) : '';
            
            // 支付通道文本
            $item->payment_channel_text = $this->getPaymentChannelText($item->payment_channel);
            
            // 订单状态文本和颜色
            list($statusText, $statusColor) = $this->getStatusInfo($item->status);
            $item->status_text = $statusText;
            $item->status_color = $statusColor;
            
            // 用户昵称
            $item->user_nickname = $item->user ? $item->user->nickname : '';
            
            // 格式化时间
            $item->pay_time_text = $item->pay_time ? date('Y-m-d H:i:s', $item->pay_time) : '';
            $item->complete_time_text = $item->complete_time ? date('Y-m-d H:i:s', $item->complete_time) : '';
            $item->create_time_text = date('Y-m-d H:i:s', $item->create_time);
        }

        // 统计信息
        $statistics = $this->getStatistics();

        $this->success('', [
            'list' => $list,
            'total' => $res->total(),
            'remark' => get_route_remark(),
            'statistics' => $statistics,
        ]);
    }

    /**
     * 查看订单详情
     * @throws Throwable
     */
    public function edit(): void
    {
        $pk = $this->model->getPk();
        $id = $this->request->param($pk);
        $row = $this->model->with(['user', 'product'])->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        // 解析extra字段（转换为数组）
        $extra = $row->extra ? (array)$row->extra : [];
        $row->product_name = $extra['product_name'] ?? ($row->product ? $row->product->name : '');
        $row->cycle_days = $extra['cycle_days'] ?? 0;
        $row->expected_income = $extra['expected_income'] ?? 0;
        $row->expire_time = $extra['expire_time'] ?? 0;
        $row->expire_time_text = $row->expire_time ? date('Y-m-d H:i:s', $row->expire_time) : '';
        
        // 支付通道文本
        $row->payment_channel_text = $this->getPaymentChannelText($row->payment_channel);
        
        // 订单状态文本和颜色
        list($statusText, $statusColor) = $this->getStatusInfo($row->status);
        $row->status_text = $statusText;
        $row->status_color = $statusColor;
        
        // 用户信息
        $row->user_nickname = $row->user ? $row->user->nickname : '';
        $row->user_mobile = $row->user ? $row->user->mobile : '';
        
        // 格式化时间
        $row->pay_time_text = $row->pay_time ? date('Y-m-d H:i:s', $row->pay_time) : '';
        $row->complete_time_text = $row->complete_time ? date('Y-m-d H:i:s', $row->complete_time) : '';
        $row->create_time_text = date('Y-m-d H:i:s', $row->create_time);

        $this->success('', [
            'row' => $row,
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 手动结算订单
     * @throws Throwable
     */
    public function settle(): void
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            $this->error('订单ID不能为空');
        }

        $order = $this->model->find($id);
        if (!$order) {
            $this->error('订单不存在');
        }

        if ($order->status != 'earning') {
            $this->error('只有收益中的订单才能结算');
        }

        Db::startTrans();
        try {
            $userId = $order->user_id;
            $orderNo = $order->order_no;
            $amount = $order->amount;
            
            $extra = $order->extra ? (array)$order->extra : [];
            $expectedIncome = $extra['expected_income'] ?? 0;
            $productName = $extra['product_name'] ?? '理财产品';

            // 计算返还总额（本金 + 收益）
            $totalReturn = $amount + $expectedIncome;

            // 获取用户信息并锁定
            $user = Db::name('user')
                ->where('id', $userId)
                ->lock(true)
                ->find();

            if (!$user) {
                throw new \Exception("用户不存在: {$userId}");
            }

            $beforeMoney = $user['money'];
            $afterMoney = $beforeMoney + $totalReturn;

            // 1. 更新用户余额（返还本金 + 收益）
            Db::name('user')
                ->where('id', $userId)
                ->update([
                    'money' => $afterMoney,
                ]);

            // 2. 更新订单状态为已完成
            $order->status = 'completed';
            $order->complete_time = time();
            $order->save();

            // 3. 记录余额变动日志
            Db::name('user_money_log')->insert([
                'user_id' => $userId,
                'money' => $totalReturn,
                'before' => $beforeMoney,
                'after' => $afterMoney,
                'memo' => "理财产品手动结算：{$productName}，订单号：{$orderNo}，本金：{$amount}元，收益：{$expectedIncome}元",
                'create_time' => time(),
            ]);

            // 4. 记录用户活动日志
            Db::name('user_activity_log')->insert([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'finance_settle',
                'change_field' => 'money',
                'change_value' => $totalReturn,
                'before_value' => $beforeMoney,
                'after_value' => $afterMoney,
                'remark' => "理财产品手动结算：{$productName}（管理员操作）",
                'extra' => json_encode([
                    'order_no' => $orderNo,
                    'order_id' => $order->id,
                    'principal' => $amount,
                    'income' => $expectedIncome,
                    'total_return' => $totalReturn,
                    'settle_type' => 'manual',
                ]),
                'create_time' => time(),
                'update_time' => time(),
            ]);

            Db::commit();
            $this->success('结算成功');
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
    }

    /**
     * 取消订单
     * @throws Throwable
     */
    public function cancel(): void
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            $this->error('订单ID不能为空');
        }

        $order = $this->model->find($id);
        if (!$order) {
            $this->error('订单不存在');
        }

        if (!in_array($order->status, ['pending', 'paying', 'earning'])) {
            $this->error('该状态的订单不能取消');
        }

        Db::startTrans();
        try {
            // 如果是收益中的订单，需要退还本金
            if ($order->status == 'earning') {
                $user = Db::name('user')->where('id', $order->user_id)->lock(true)->find();
                if (!$user) {
                    throw new \Exception('用户不存在');
                }

                // 退还本金
                $beforeMoney = $user['money'];
                $afterMoney = $beforeMoney + $order->amount;
                Db::name('user')->where('id', $order->user_id)->update(['money' => $afterMoney]);

                // 记录余额日志
                Db::name('user_money_log')->insert([
                    'user_id' => $order->user_id,
                    'money' => $order->amount,
                    'before' => $beforeMoney,
                    'after' => $afterMoney,
                    'memo' => '取消理财订单退款（本金），订单号：' . $order->order_no,
                    'create_time' => time(),
                ]);

                // 记录用户活动日志
                Db::name('user_activity_log')->insert([
                    'user_id' => $order->user_id,
                    'related_user_id' => 0,
                    'action_type' => 'finance_cancel',
                    'change_field' => 'money',
                    'change_value' => $order->amount,
                    'before_value' => $beforeMoney,
                    'after_value' => $afterMoney,
                    'remark' => '取消理财订单退款（管理员操作），订单号：' . $order->order_no,
                    'extra' => json_encode([
                        'order_id' => $order->id,
                        'order_no' => $order->order_no,
                        'refund_amount' => (string)$order->amount,
                        'operation' => 'order_cancel',
                    ]),
                    'create_time' => time(),
                    'update_time' => time(),
                ]);

                // 恢复产品已售额度
                if ($order->product_id) {
                    Db::name('finance_product')
                        ->where('id', $order->product_id)
                        ->dec('sold_amount', $order->amount)
                        ->update();
                }
            }

            // 更新订单状态
            $order->status = 'cancelled';
            $order->save();

            Db::commit();
            $this->success('取消订单成功');
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $this->error($e->getMessage() ?: '取消订单失败，请稍后重试');
        }
    }

    /**
     * 可提现余额划转到可用余额申购理财
     * @throws Throwable
     */
    public function transferIncomeToPurchase(): void
    {
        $userId = $this->request->param('user_id/d', 0);
        $amount = $this->request->param('amount/f', 0);
        $remark = $this->request->param('remark', '');

        // 参数验证
        if (!$userId) {
            $this->error('用户ID不能为空');
        }
        if ($amount <= 0) {
            $this->error('划转金额必须大于0');
        }

        Db::startTrans();
        try {
            // 获取并锁定用户信息
            $user = Db::name('user')
                ->where('id', $userId)
                ->lock(true)
                ->find();

            if (!$user) {
                throw new \Exception("用户不存在: {$userId}");
            }

            // 检查可提现余额
            $beforeWithdrawable = (float)$user['withdrawable_money'];
            if ($beforeWithdrawable < $amount) {
                throw new \Exception('可提现余额不足，无法完成划转');
            }

            // 计算划转后的余额
            $currentBalance = (float)$user['balance_available'];
            $afterBalance = $currentBalance + $amount;
            $afterWithdrawable = $beforeWithdrawable - $amount;

            // 生成唯一的流水号
            $flowNo = 'BT' . date('YmdHis') . mt_rand(1000, 9999);

            // 更新用户余额：扣减可提现余额，增加可用余额
            Db::name('user')->where('id', $userId)->update([
                'withdrawable_money' => $afterWithdrawable,
                'balance_available' => $afterBalance,
                'update_time' => time()
            ]);

            // 记录资金变动日志 - 可提现余额扣减
            Db::name('user_money_log')->insert([
                'user_id' => $userId,
                'field_type' => 'withdrawable_money',
                'money' => -$amount,
                'before' => $beforeWithdrawable,
                'after' => $afterWithdrawable,
                'memo' => "可提现余额划转到可用余额：{$amount}元",
                'flow_no' => $flowNo,
                'batch_no' => '',
                'biz_type' => 'balance_transfer',
                'biz_id' => 0,
                'user_collection_id' => 0,
                'item_id' => 0,
                'title_snapshot' => '',
                'image_snapshot' => '',
                'extra_json' => json_encode([
                    'transfer_type' => 'withdrawable_to_balance',
                    'transfer_amount' => $amount,
                    'operation_type' => 'balance_transfer'
                ], JSON_UNESCAPED_UNICODE),
                'create_time' => time()
            ]);

            // 记录用户活动日志（余额划转记录）
            Db::name('user_activity_log')->insert([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'balance_transfer',
                'change_field' => 'withdrawable_money,balance_available',
                'change_value' => -$amount,
                'before_value' => $beforeWithdrawable,
                'after_value' => $afterWithdrawable,
                'remark' => "可提现余额划转到可用余额：{$amount}元",
                'extra' => json_encode([
                    'transfer_type' => 'withdrawable_to_balance',
                    'transfer_amount' => $amount,
                    'before_withdrawable' => $beforeWithdrawable,
                    'after_withdrawable' => $afterWithdrawable,
                    'before_balance' => $currentBalance,
                    'after_balance' => $afterBalance,
                    'operation_type' => 'balance_transfer',
                    'transfer_time' => time()
                ], JSON_UNESCAPED_UNICODE),
                'create_time' => time(),
                'update_time' => time()
            ]);

            Db::commit();

            $this->success('余额划转成功', [
                'transfer_amount' => $amount,
                'remaining_withdrawable' => $afterWithdrawable,
                'new_balance_available' => $afterBalance
            ]);

        } catch (Throwable $e) {
            Db::rollback();
            $this->error($e->getMessage() ?: '收益划转失败，请稍后重试');
        }
    }

    /**
     * 不允许添加订单
     */
    public function add(): void
    {
        $this->error('理财订单只能由用户购买产品时自动创建');
    }

    /**
     * 不允许删除订单
     */
    public function del(): void
    {
        $this->error('理财订单不能删除，只能取消');
    }

    /**
     * 获取支付通道文本
     */
    protected function getPaymentChannelText(?string $channel): string
    {
        return match($channel) {
            'alipay' => '支付宝',
            'wechat' => '微信支付',
            'union' => '银联支付',
            'balance' => '余额支付',
            default => $channel ?: '未设置',
        };
    }

    /**
     * 获取订单状态信息
     * @return array [状态文本, 状态颜色]
     */
    protected function getStatusInfo(string $status): array
    {
        return match($status) {
            'pending' => ['待支付', '#909399'],
            'paying' => ['支付中', '#E6A23C'],
            'paid' => ['支付成功(待收益)', '#409EFF'],
            'earning' => ['收益中', '#67C23A'],
            'completed' => ['已完成', '#303133'],
            'cancelled' => ['已取消', '#F56C6C'],
            'refunded' => ['已退款', '#E6A23C'],
            default => [$status, '#909399'],
        };
    }

    /**
     * 获取统计信息
     */
    protected function getStatistics(): array
    {
        // 按状态统计
        $byStatus = Db::name('finance_order')
            ->field([
                'status',
                'COUNT(*) as count',
                'SUM(amount) as total_amount',
            ])
            ->group('status')
            ->select()
            ->toArray();

        foreach ($byStatus as &$item) {
            list($statusText, $statusColor) = $this->getStatusInfo($item['status']);
            $item['status_text'] = $statusText;
            $item['status_color'] = $statusColor;
            $item['total_amount'] = (float)$item['total_amount'];
        }

        // 总计
        $totalSummary = Db::name('finance_order')
            ->field([
                'COUNT(*) as total_orders',
                'SUM(amount) as total_amount',
                'SUM(CASE WHEN status="earning" THEN amount ELSE 0 END) as earning_amount',
                'SUM(CASE WHEN status="completed" THEN amount ELSE 0 END) as completed_amount',
            ])
            ->find();

        $totalSummary['total_amount'] = (float)($totalSummary['total_amount'] ?? 0);
        $totalSummary['earning_amount'] = (float)($totalSummary['earning_amount'] ?? 0);
        $totalSummary['completed_amount'] = (float)($totalSummary['completed_amount'] ?? 0);

        return [
            'by_status' => $byStatus,
            'total_summary' => $totalSummary,
        ];
    }
}


