<?php

namespace app\api\controller;

use think\facade\Db;
use app\common\controller\Frontend;
use hg\apidoc\annotation as Apidoc;

#[Apidoc\Title("理财订单管理")]
class FinanceOrder extends Frontend
{
    protected array $noNeedLogin = [];

    public function initialize(): void
    {
        parent::initialize();
    }

    #[
        Apidoc\Title("理财订单列表（管理员）"),
        Apidoc\Tag("理财订单,列表"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/financeOrder/list"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "管理员Token"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量", default: "10"),
        Apidoc\Query(name: "status", type: "string", require: false, desc: "订单状态: pending/paying/paid/earning/completed/cancelled/refunded"),
        Apidoc\Query(name: "payment_channel", type: "string", require: false, desc: "支付通道: alipay/wechat/union/balance"),
        Apidoc\Query(name: "keyword", type: "string", require: false, desc: "搜索关键词(订单号/用户昵称)"),
        Apidoc\Returned("list", type: "array", desc: "订单列表"),
        Apidoc\Returned("list[].id", type: "int", desc: "订单ID"),
        Apidoc\Returned("list[].order_no", type: "string", desc: "订单号"),
        Apidoc\Returned("list[].user_nickname", type: "string", desc: "用户昵称"),
        Apidoc\Returned("list[].product_name", type: "string", desc: "产品名称"),
        Apidoc\Returned("list[].amount", type: "float", desc: "购买金额"),
        Apidoc\Returned("list[].payment_channel", type: "string", desc: "支付通道"),
        Apidoc\Returned("list[].payment_channel_text", type: "string", desc: "支付通道文本"),
        Apidoc\Returned("list[].yield_rate", type: "float", desc: "收益率"),
        Apidoc\Returned("list[].expected_income", type: "float", desc: "预期收益"),
        Apidoc\Returned("list[].status", type: "string", desc: "订单状态"),
        Apidoc\Returned("list[].status_text", type: "string", desc: "状态文本"),
        Apidoc\Returned("list[].status_color", type: "string", desc: "状态颜色"),
        Apidoc\Returned("list[].expire_time_text", type: "string", desc: "到期时间"),
        Apidoc\Returned("list[].pay_time_text", type: "string", desc: "支付时间"),
        Apidoc\Returned("list[].create_time_text", type: "string", desc: "创建时间"),
        Apidoc\Returned("total", type: "int", desc: "总记录数"),
        Apidoc\Returned("statistics", type: "object", desc: "统计信息"),
    ]
    public function list(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $page = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 10);
        $status = $this->request->param('status', '');
        $paymentChannel = $this->request->param('payment_channel', '');
        $keyword = $this->request->param('keyword', '');

        $where = [];

        if ($status) {
            $where[] = ['o.status', '=', $status];
        }

        if ($paymentChannel) {
            $where[] = ['o.payment_channel', '=', $paymentChannel];
        }

        if ($keyword) {
            $where[] = ['o.order_no|u.nickname', 'like', '%' . $keyword . '%'];
        }

        $list = Db::name('finance_order')
            ->alias('o')
            ->leftJoin('user u', 'o.user_id = u.id')
            ->leftJoin('finance_product p', 'o.product_id = p.id')
            ->where($where)
            ->field([
                'o.id',
                'o.order_no',
                'o.user_id',
                'u.nickname as user_nickname',
                'o.product_id',
                'p.name as product_name',
                'o.amount',
                'o.payment_channel',
                'o.yield_rate',
                'o.status',
                'o.extra',
                'o.pay_time',
                'o.complete_time',
                'o.create_time',
            ])
            ->order('o.id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        // 格式化数据
        foreach ($list as &$item) {
            $extra = json_decode($item['extra'], true);
            $item['expected_income'] = $extra['expected_income'] ?? 0;
            $item['expire_time'] = $extra['expire_time'] ?? 0;
            $item['expire_time_text'] = $item['expire_time'] ? date('Y-m-d H:i:s', $item['expire_time']) : '';

            // 支付通道文本
            $item['payment_channel_text'] = $this->getPaymentChannelText($item['payment_channel']);

            // 订单状态文本和颜色
            list($statusText, $statusColor) = $this->getStatusInfo($item['status']);
            $item['status_text'] = $statusText;
            $item['status_color'] = $statusColor;

            $item['pay_time_text'] = $item['pay_time'] ? date('Y-m-d H:i:s', $item['pay_time']) : '';
            $item['create_time_text'] = date('Y-m-d H:i:s', $item['create_time']);

            unset($item['extra']);
        }

        $total = Db::name('finance_order')
            ->alias('o')
            ->leftJoin('user u', 'o.user_id = u.id')
            ->where($where)
            ->count();

        // 统计信息
        $statistics = $this->getStatistics();

        $this->success('', [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'statistics' => $statistics,
        ]);
    }

    #[
        Apidoc\Title("我的理财订单列表（用户）"),
        Apidoc\Tag("理财订单,我的订单"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/financeOrder/myList"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户Token"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量", default: "10"),
        Apidoc\Query(name: "status", type: "string", require: false, desc: "订单状态"),
        Apidoc\Returned("list", type: "array", desc: "订单列表"),
        Apidoc\Returned("total", type: "int", desc: "总记录数"),
    ]
    public function myList(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $page = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 10);
        $status = $this->request->param('status', '');

        $where = [
            ['user_id', '=', $this->auth->id],
        ];

        if ($status) {
            $where[] = ['status', '=', $status];
        }

        $list = Db::name('finance_order')
            ->where($where)
            ->field([
                'id',
                'order_no',
                'product_id',
                'amount',
                'payment_channel',
                'yield_rate',
                'status',
                'extra',
                'pay_time',
                'create_time',
            ])
            ->order('id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        // 获取产品名称
        $productIds = array_unique(array_column($list, 'product_id'));
        $products = [];
        if ($productIds) {
            $products = Db::name('finance_product')
                ->whereIn('id', $productIds)
                ->column('name', 'id');
        }

        // 格式化数据
        foreach ($list as &$item) {
            $extra = json_decode($item['extra'], true);
            $item['product_name'] = $products[$item['product_id']] ?? '';
            $item['cycle_days'] = $extra['cycle_days'] ?? 0;
            $item['expected_income'] = $extra['expected_income'] ?? 0;
            $item['expire_time'] = $extra['expire_time'] ?? 0;
            $item['expire_time_text'] = $item['expire_time'] ? date('Y-m-d H:i:s', $item['expire_time']) : '';

            // 支付通道文本
            $item['payment_channel_text'] = $this->getPaymentChannelText($item['payment_channel']);

            // 订单状态文本和颜色
            list($statusText, $statusColor) = $this->getStatusInfo($item['status']);
            $item['status_text'] = $statusText;
            $item['status_color'] = $statusColor;

            $item['pay_time_text'] = $item['pay_time'] ? date('Y-m-d H:i:s', $item['pay_time']) : '';
            $item['create_time_text'] = date('Y-m-d H:i:s', $item['create_time']);

            unset($item['extra']);
        }

        $total = Db::name('finance_order')
            ->where($where)
            ->count();

        $this->success('', [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    #[
        Apidoc\Title("订单详情"),
        Apidoc\Tag("理财订单,详情"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/financeOrder/detail"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户Token"),
        Apidoc\Query(name: "id", type: "int", require: true, desc: "订单ID"),
        Apidoc\Returned("detail", type: "object", desc: "订单详情"),
    ]
    public function detail(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $id = $this->request->param('id/d', 0);
        if (!$id) {
            $this->error('参数错误');
        }

        $order = Db::name('finance_order')
            ->alias('o')
            ->leftJoin('user u', 'o.user_id = u.id')
            ->leftJoin('finance_product p', 'o.product_id = p.id')
            ->where('o.id', $id)
            ->where('o.user_id', $this->auth->id)
            ->field([
                'o.*',
                'u.nickname as user_nickname',
                'p.name as product_name',
            ])
            ->find();

        if (!$order) {
            $this->error('订单不存在');
        }

        // 格式化数据
        $extra = json_decode($order['extra'], true);
        $order['cycle_days'] = $extra['cycle_days'] ?? 0;
        $order['expected_income'] = $extra['expected_income'] ?? 0;
        $order['expire_time'] = $extra['expire_time'] ?? 0;
        $order['expire_time_text'] = $order['expire_time'] ? date('Y-m-d H:i:s', $order['expire_time']) : '';

        // 支付通道文本
        $order['payment_channel_text'] = $this->getPaymentChannelText($order['payment_channel']);

        // 订单状态文本和颜色
        list($statusText, $statusColor) = $this->getStatusInfo($order['status']);
        $order['status_text'] = $statusText;
        $order['status_color'] = $statusColor;

        $order['pay_time_text'] = $order['pay_time'] ? date('Y-m-d H:i:s', $order['pay_time']) : '';
        $order['complete_time_text'] = $order['complete_time'] ? date('Y-m-d H:i:s', $order['complete_time']) : '';
        $order['create_time_text'] = date('Y-m-d H:i:s', $order['create_time']);

        $this->success('', $order);
    }

    #[
        Apidoc\Title("订单统计"),
        Apidoc\Tag("理财订单,统计"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/financeOrder/statistics"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "管理员Token"),
        Apidoc\Returned("by_status", type: "array", desc: "按状态统计"),
        Apidoc\Returned("by_channel", type: "array", desc: "按支付通道统计"),
        Apidoc\Returned("total_summary", type: "object", desc: "总计"),
    ]
    public function statistics(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $statistics = $this->getStatistics();

        $this->success('', $statistics);
    }

    #[
        Apidoc\Title("可提现余额划转到可用余额"),
        Apidoc\Tag("用户余额,余额划转"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/financeOrder/transferIncomeToPurchase"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户Token"),
        Apidoc\Param(name: "amount", type: "float", require: true, desc: "划转金额"),
        Apidoc\Param(name: "remark", type: "string", require: false, desc: "备注信息"),
        Apidoc\Returned("transfer_amount", type: "float", desc: "划转金额"),
        Apidoc\Returned("remaining_withdrawable", type: "float", desc: "剩余可提现余额"),
        Apidoc\Returned("new_balance_available", type: "float", desc: "新的可用余额"),
    ]
    public function transferIncomeToPurchase(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        if (!$this->request->isPost()) {
            $this->error('请求方式错误');
        }

        $amount = (float)$this->request->post('amount', 0);
        $remark = $this->request->post('remark', '');

        // 参数验证
        if ($amount <= 0) {
            $this->error('划转金额必须大于0，当前收到: ' . $this->request->post('amount', 'null'));
        }

        $userId = (int)$this->auth->id;

        Db::startTrans();
        try {
            // 获取并锁定用户信息
            $user = Db::name('user')
                ->where('id', $userId)
                ->lock(true)
                ->find();

            if (!$user) {
                throw new \Exception("用户不存在");
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
            'pending' => ['待支付', '#999999'],
            'paying' => ['支付中', '#ff9500'],
            'paid' => ['支付成功(待收益)', '#1989fa'],
            'earning' => ['收益中', '#07c160'],
            'completed' => ['已完成', '#333333'],
            'cancelled' => ['已取消', '#ee0a24'],
            'refunded' => ['已退款', '#ed6a0c'],
            default => [$status, '#999999'],
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

        // 按支付通道统计
        $byChannel = Db::name('finance_order')
            ->field([
                'payment_channel',
                'COUNT(*) as count',
                'SUM(amount) as total_amount',
                'AVG(yield_rate) as avg_yield_rate',
            ])
            ->where('status', '<>', 'cancelled')
            ->group('payment_channel')
            ->select()
            ->toArray();

        foreach ($byChannel as &$item) {
            $item['payment_channel_text'] = $this->getPaymentChannelText($item['payment_channel']);
            $item['total_amount'] = (float)$item['total_amount'];
            $item['avg_yield_rate'] = round((float)$item['avg_yield_rate'], 2);
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

        $totalSummary['total_amount'] = (float)$totalSummary['total_amount'];
        $totalSummary['earning_amount'] = (float)$totalSummary['earning_amount'];
        $totalSummary['completed_amount'] = (float)$totalSummary['completed_amount'];

        return [
            'by_status' => $byStatus,
            'by_channel' => $byChannel,
            'total_summary' => $totalSummary,
        ];
    }
}

