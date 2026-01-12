<?php

namespace app\api\controller;

use think\facade\Db;
use app\common\controller\Frontend;
use think\exception\HttpResponseException;
use hg\apidoc\annotation as Apidoc;

#[Apidoc\Title("理财产品管理")]
class FinanceProduct extends Frontend
{
    protected array $noNeedLogin = ['index', 'detail', 'getAgreement'];

    public function initialize(): void
    {
        parent::initialize();
    }

    #[
        Apidoc\Title("理财产品列表"),
        Apidoc\Tag("理财产品,列表"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/financeProduct/index"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量(最大50)", default: "10"),
        Apidoc\Returned("list", type: "array", desc: "产品列表"),
        Apidoc\Returned("list[].id", type: "int", desc: "产品ID"),
        Apidoc\Returned("list[].title", type: "string", desc: "产品标题"),
        Apidoc\Returned("list[].cover_image", type: "string", desc: "封面图完整URL"),
        Apidoc\Returned("list[].summary", type: "string", desc: "产品简介"),
        Apidoc\Returned("list[].progress", type: "float", desc: "项目进度百分比(0-100)"),
        Apidoc\Returned("list[].price", type: "float", desc: "产品价格"),
        Apidoc\Returned("list[].yield_rate", type: "string", desc: "年化收益率(带%)"),
        Apidoc\Returned("list[].cycle_days", type: "int", desc: "收益周期(天)"),
        Apidoc\Returned("list[].total_amount", type: "int", desc: "总额度"),
        Apidoc\Returned("list[].sold_amount", type: "int", desc: "已售额度"),
        Apidoc\Returned("list[].remaining_amount", type: "int", desc: "剩余额度"),
        Apidoc\Returned("list[].min_purchase", type: "int", desc: "起购金额"),
        Apidoc\Returned("total", type: "int", desc: "总记录数"),
        Apidoc\Returned("page", type: "int", desc: "当前页码"),
        Apidoc\Returned("limit", type: "int", desc: "每页数量"),
    ]
    public function index(): void
    {
        $page = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 10);
        $limit = min($limit, 50); // 最大50条

        $list = Db::name('finance_product')
            ->where('status', 1)
            ->field([
                'id',
                'name as title',
                'thumbnail as cover_image',
                'summary',
                'price',
                'yield_rate',
                'cycle_days',
                'total_amount',
                'sold_amount',
                'min_purchase',
            ])
            ->order('sort desc, id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        // 计算项目进度和完整URL
        foreach ($list as &$item) {
            // 封面图完整URL
            $item['cover_image'] = $item['cover_image'] ? full_url($item['cover_image'], false) : '';
            
            // 计算项目进度百分比
            if ($item['total_amount'] > 0) {
                $progress = round(($item['sold_amount'] / $item['total_amount']) * 100, 2);
                $item['progress'] = min($progress, 100); // 最大100%
            } else {
                $item['progress'] = 0;
            }

            // 剩余额度
            $item['remaining_amount'] = max(0, $item['total_amount'] - $item['sold_amount']);

            // 格式化收益率为字符串
            $item['yield_rate'] = number_format($item['yield_rate'], 2) . '%';
        }

        $total = Db::name('finance_product')
            ->where('status', 1)
            ->count();

        $this->success('', [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    #[
        Apidoc\Title("理财产品详情"),
        Apidoc\Tag("理财产品,详情"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/financeProduct/detail"),
        Apidoc\Query(name: "id", type: "int", require: true, desc: "产品ID"),
        Apidoc\Returned("id", type: "int", desc: "产品ID"),
        Apidoc\Returned("title", type: "string", desc: "产品标题"),
        Apidoc\Returned("cover_image", type: "string", desc: "封面图完整URL"),
        Apidoc\Returned("description", type: "string", desc: "产品介绍"),
        Apidoc\Returned("sale_status", type: "string", desc: "销售状态(在售/已下架)"),
        Apidoc\Returned("price", type: "float", desc: "产品价格"),
        Apidoc\Returned("yield_rate", type: "float", desc: "年化收益率(数字)"),
        Apidoc\Returned("yield_rate_desc", type: "string", desc: "年化收益率(带%描述)"),
        Apidoc\Returned("cycle_days", type: "int", desc: "收益周期(天)"),
        Apidoc\Returned("cycle_days_desc", type: "string", desc: "收益周期描述"),
        Apidoc\Returned("daily_income_rate", type: "float", desc: "每日收益率"),
        Apidoc\Returned("daily_income_desc", type: "string", desc: "每日收益描述(每万元日收益)"),
        Apidoc\Returned("min_purchase_amount", type: "int", desc: "起购金额"),
        Apidoc\Returned("min_purchase_desc", type: "string", desc: "起购金额描述"),
        Apidoc\Returned("max_purchase_amount", type: "int", desc: "最大购买金额"),
        Apidoc\Returned("max_purchase_desc", type: "string", desc: "最大购买金额描述"),
        Apidoc\Returned("total_amount", type: "int", desc: "总额度"),
        Apidoc\Returned("total_amount_desc", type: "string", desc: "总额度描述"),
        Apidoc\Returned("sold_amount", type: "int", desc: "已售额度"),
        Apidoc\Returned("sold_amount_desc", type: "string", desc: "已售额度描述"),
        Apidoc\Returned("remaining_amount", type: "int", desc: "剩余额度"),
        Apidoc\Returned("remaining_amount_desc", type: "string", desc: "剩余额度描述"),
        Apidoc\Returned("progress", type: "float", desc: "项目进度百分比(0-100)"),
        Apidoc\Returned("min_total_income", type: "float", desc: "起购预计总收益"),
        Apidoc\Returned("income_desc", type: "string", desc: "预计收益描述"),
        Apidoc\Returned("create_time_text", type: "string", desc: "创建时间(格式化)"),
    ]
    public function detail(): void
    {
        $id = $this->request->param('id/d', 0);
        
        if (!$id) {
            $this->error('参数错误');
        }

        $detail = Db::name('finance_product')
            ->where('id', $id)
            ->where('status', 1)
            ->field([
                'id',
                'name as title',
                'thumbnail as cover_image',
                'summary as description',
                'price',
                'cycle_days',
                'yield_rate',
                'total_amount',
                'sold_amount',
                'min_purchase as min_purchase_amount',
                'max_purchase as max_purchase_amount',
                'status',
                'create_time',
            ])
            ->find();

        if (!$detail) {
            $this->error('产品不存在或已下架');
        }

        // 封面图完整URL
        $detail['cover_image'] = $detail['cover_image'] ? full_url($detail['cover_image'], false) : '';

        // 销售状态文本
        $detail['sale_status'] = $detail['status'] == 1 ? '在售' : '已下架';

        // 计算每日收益（按照年化收益率计算）
        // 每日收益 = (年化收益率 / 365) * 投资金额
        // 这里返回的是每投资1元的每日收益
        $daily_income_rate = round($detail['yield_rate'] / 365, 6);
        $detail['daily_income_rate'] = $daily_income_rate;
        $detail['daily_income_desc'] = '每万元日收益：' . number_format($daily_income_rate * 10000, 2) . '元';

        // 收益周期描述
        $detail['cycle_days_desc'] = $detail['cycle_days'] . '天';

        // 起购金额描述
        $detail['min_purchase_desc'] = number_format($detail['min_purchase_amount'], 2) . '元起';

        // 最大购买金额描述
        if ($detail['max_purchase_amount'] > 0) {
            $detail['max_purchase_desc'] = '最高购买' . number_format($detail['max_purchase_amount'], 2) . '元';
        } else {
            $detail['max_purchase_desc'] = '无上限';
        }

        // 项目进度
        if ($detail['total_amount'] > 0) {
            $progress = round(($detail['sold_amount'] / $detail['total_amount']) * 100, 2);
            $detail['progress'] = min($progress, 100);
        } else {
            $detail['progress'] = 0;
        }

        // 剩余额度
        $detail['remaining_amount'] = max(0, $detail['total_amount'] - $detail['sold_amount']);
        $detail['remaining_amount_desc'] = '剩余额度：' . number_format($detail['remaining_amount'], 2) . '元';

        // 预计总收益（按照起购金额计算示例）
        $min_total_income = round($detail['min_purchase_amount'] * ($detail['yield_rate'] / 100) * ($detail['cycle_days'] / 365), 2);
        $detail['min_total_income'] = $min_total_income;
        $detail['income_desc'] = '起购预计收益：' . number_format($min_total_income, 2) . '元';

        // 格式化收益率
        $detail['yield_rate_desc'] = number_format($detail['yield_rate'], 2) . '%';

        // 格式化金额
        $detail['total_amount_desc'] = number_format($detail['total_amount'], 2) . '元';
        $detail['sold_amount_desc'] = number_format($detail['sold_amount'], 2) . '元';

        // 创建时间格式化
        $detail['create_time_text'] = date('Y-m-d H:i:s', $detail['create_time']);

        $this->success('', $detail);
    }

    #[
        Apidoc\Title("购买理财产品"),
        Apidoc\Tag("理财产品,购买"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/financeProduct/buy"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Query(name: "id", type: "int", require: true, desc: "产品ID"),
        Apidoc\Query(name: "amount", type: "float", require: true, desc: "购买金额"),
        Apidoc\Query(name: "payment_channel", type: "string", require: false, desc: "支付通道: alipay/wechat/union/balance (默认balance)"),
        Apidoc\Returned("order_no", type: "string", desc: "订单号"),
        Apidoc\Returned("order_id", type: "int", desc: "订单ID"),
        Apidoc\Returned("amount", type: "float", desc: "购买金额"),
        Apidoc\Returned("balance", type: "float", desc: "购买后余额"),
        Apidoc\Returned("payment_channel", type: "string", desc: "支付通道"),
    ]
    public function buy(): void
    {
        $id = $this->request->param('id/d', 0);
        $amount = $this->request->param('amount/f', 0);
        $paymentChannel = $this->request->param('payment_channel', 'balance');

        if (!$id || $amount <= 0) {
            $this->error('参数错误');
        }

        // 验证支付通道
        $allowedChannels = ['alipay', 'wechat', 'union', 'balance'];
        if (!in_array($paymentChannel, $allowedChannels)) {
            $this->error('不支持的支付通道');
        }

        // 获取当前登录用户
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $userId = $this->auth->id;

        // 获取产品信息
        $product = Db::name('finance_product')
            ->where('id', $id)
            ->where('status', 1)
            ->lock(true)
            ->find();

        if (!$product) {
            $this->error('产品不存在或已下架');
        }

        // 验证购买金额
        if ($amount < $product['min_purchase']) {
            $this->error('购买金额不能低于起购金额：' . number_format($product['min_purchase'], 2) . '元');
        }

        if ($product['max_purchase'] > 0 && $amount > $product['max_purchase']) {
            $this->error('购买金额不能超过最大限额：' . number_format($product['max_purchase'], 2) . '元');
        }

        // 验证剩余额度
        $remaining = $product['total_amount'] - $product['sold_amount'];
        if ($amount > $remaining) {
            $this->error('购买金额超过剩余额度：' . number_format($remaining, 2) . '元');
        }

        // 验证每人限购
        if ($product['per_user_limit'] > 0) {
            // 查询用户已购买的该产品订单数量（不包括已取消和已退款的）
            $userPurchasedCount = Db::name('finance_order')
                ->where('user_id', $userId)
                ->where('product_id', $id)
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->count();
            
            if ($userPurchasedCount >= $product['per_user_limit']) {
                $this->error('该产品每人限购' . $product['per_user_limit'] . '份，您已购买' . $userPurchasedCount . '份');
            }
        }

        // 获取用户信息并锁定
        $user = Db::name('user')
            ->where('id', $userId)
            ->lock(true)
            ->find();

        if (!$user) {
            $this->error('用户不存在');
        }

        // 1. 检查用户可用余额
        if ($user['balance_available'] < $amount) {
            $this->error('可用余额不足，当前余额：' . number_format($user['balance_available'], 2) . '元');
        }

        // 开启事务
        Db::startTrans();
        try {
            // 2. 扣除用户余额（只扣除balance_available，money是派生值会自动计算）
            $beforeBalance = $user['balance_available'];
            $afterBalance = $beforeBalance - $amount;

            $updateUser = Db::name('user')
                ->where('id', $userId)
                ->update([
                    'balance_available' => $afterBalance,
                ]);

            if (!$updateUser) {
                throw new \Exception('扣除余额失败');
            }

            // 3. 创建订单记录
            $orderNo = 'FP' . date('YmdHis') . str_pad($userId, 6, '0', STR_PAD_LEFT) . rand(1000, 9999);
            $expireTime = time() + ($product['cycle_days'] * 86400);
            $expectedIncome = round($amount * ($product['yield_rate'] / 100) * ($product['cycle_days'] / 365), 2);
            
            $orderId = Db::name('finance_order')->insertGetId([
                'order_no' => $orderNo,
                'user_id' => $userId,
                'product_id' => $product['id'],
                'quantity' => 1,
                'unit_price' => $amount,
                'amount' => $amount,
                'payment_channel' => $paymentChannel,
                'yield_rate' => $product['yield_rate'],
                'status' => 'earning',  // 改为收益中状态
                'remark' => '购买理财产品：' . $product['name'],
                'extra' => json_encode([
                    'product_name' => $product['name'],
                    'cycle_days' => $product['cycle_days'],
                    'expected_income' => $expectedIncome,
                    'expire_time' => $expireTime,
                ]),
                'pay_time' => time(),
                'create_time' => time(),
                'update_time' => time(),
            ]);

            if (!$orderId) {
                throw new \Exception('创建订单失败');
            }

            // 4. 更新产品已售金额
            $updateProduct = Db::name('finance_product')
                ->where('id', $product['id'])
                ->inc('sold_amount', $amount)
                ->update();

            if (!$updateProduct) {
                throw new \Exception('更新产品销售额失败');
            }

            // 5. 记录用户活动日志
            Db::name('user_activity_log')->insert([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'finance_purchase',
                'change_field' => 'balance_available',
                'change_value' => -$amount,
                'before_value' => $beforeBalance,
                'after_value' => $afterBalance,
                'remark' => '购买理财产品：' . $product['name'],
                'extra' => json_encode([
                    'order_no' => $orderNo,
                    'order_id' => $orderId,
                    'product_id' => $product['id'],
                    'product_name' => $product['name'],
                    'amount' => $amount,
                    'yield_rate' => $product['yield_rate'],
                    'cycle_days' => $product['cycle_days'],
                ]),
                'create_time' => time(),
                'update_time' => time(),
            ]);

            // 6. 记录余额变动日志
            Db::name('user_money_log')->insert([
                'user_id' => $userId,
                'money' => -$amount,
                'before' => $beforeBalance,
                'after' => $afterBalance,
                'memo' => '购买理财产品：' . $product['name'] . '，订单号：' . $orderNo,
                'create_time' => time(),
            ]);

            // 7. 处理赠送规则
            $giftOrders = [];
            $giftRule = $product['gift_rule'] ? json_decode($product['gift_rule'], true) : null;
            if ($giftRule && isset($giftRule['enabled']) && $giftRule['enabled']) {
                $buyCount = (int)($giftRule['buy'] ?? 1);
                $giftCount = (int)($giftRule['gift'] ?? 1);
                $giftProductId = (int)($giftRule['gift_product_id'] ?? 0);
                
                // 计算应该赠送的数量（按份数计算，这里假设1份=1元，实际应该根据产品价格计算）
                // 简化处理：购买金额达到 buy 份时，赠送 gift 份
                $purchaseCount = floor($amount / $product['price']); // 购买份数
                if ($purchaseCount >= $buyCount) {
                    // 计算赠送份数
                    $giftTimes = floor($purchaseCount / $buyCount); // 满足几次赠送条件
                    $totalGiftCount = $giftTimes * $giftCount; // 总共赠送份数
                    
                    // 确定赠送的产品ID（0表示赠送当前产品）
                    $targetGiftProductId = $giftProductId > 0 ? $giftProductId : $product['id'];
                    
                    // 获取赠送产品信息
                    $giftProduct = Db::name('finance_product')
                        ->where('id', $targetGiftProductId)
                        ->where('status', 1)
                        ->find();
                    
                    if ($giftProduct) {
                        // 计算每份的金额（使用赠送产品的价格）
                        $giftAmountPerUnit = $giftProduct['price'];
                        $totalGiftAmount = $totalGiftCount * $giftAmountPerUnit;
                        
                        // 验证剩余额度
                        $giftRemaining = $giftProduct['total_amount'] - $giftProduct['sold_amount'];
                        if ($totalGiftAmount <= $giftRemaining) {
                            // 创建赠品订单
                            for ($i = 0; $i < $totalGiftCount; $i++) {
                                $giftOrderNo = 'FPG' . date('YmdHis') . str_pad($userId, 6, '0', STR_PAD_LEFT) . rand(1000, 9999);
                                $giftExpireTime = time() + ($giftProduct['cycle_days'] * 86400);
                                $giftExpectedIncome = round($giftAmountPerUnit * ($giftProduct['yield_rate'] / 100) * ($giftProduct['cycle_days'] / 365), 2);
                                
                                $giftOrderId = Db::name('finance_order')->insertGetId([
                                    'order_no' => $giftOrderNo,
                                    'user_id' => $userId,
                                    'product_id' => $targetGiftProductId,
                                    'quantity' => 1,
                                    'unit_price' => $giftAmountPerUnit,
                                    'amount' => $giftAmountPerUnit,
                                    'payment_channel' => 'gift',
                                    'yield_rate' => $giftProduct['yield_rate'],
                                    'status' => 'earning',
                                    'is_gift' => 1,
                                    'parent_order_id' => $orderId,
                                    'remark' => '赠送产品：' . $giftProduct['name'] . '（来自订单：' . $orderNo . '）',
                                    'extra' => json_encode([
                                        'product_name' => $giftProduct['name'],
                                        'cycle_days' => $giftProduct['cycle_days'],
                                        'expected_income' => $giftExpectedIncome,
                                        'expire_time' => $giftExpireTime,
                                        'is_gift' => true,
                                        'parent_order_no' => $orderNo,
                                    ]),
                                    'pay_time' => time(),
                                    'create_time' => time(),
                                    'update_time' => time(),
                                ]);
                                
                                // 更新赠送产品已售金额
                                Db::name('finance_product')
                                    ->where('id', $targetGiftProductId)
                                    ->inc('sold_amount', $giftAmountPerUnit)
                                    ->update();
                                
                                $giftOrders[] = [
                                    'order_no' => $giftOrderNo,
                                    'order_id' => $giftOrderId,
                                    'product_name' => $giftProduct['name'],
                                    'amount' => $giftAmountPerUnit,
                                ];
                            }
                            
                            // 记录赠送日志
                            Db::name('user_activity_log')->insert([
                                'user_id' => $userId,
                                'related_user_id' => 0,
                                'action_type' => 'finance_gift',
                                'change_field' => 'gift',
                                'change_value' => $totalGiftCount,
                                'before_value' => 0,
                                'after_value' => $totalGiftCount,
                                'remark' => '购买理财产品赠送：' . $giftProduct['name'] . '，赠送' . $totalGiftCount . '份',
                                'extra' => json_encode([
                                    'parent_order_no' => $orderNo,
                                    'parent_order_id' => $orderId,
                                    'gift_product_id' => $targetGiftProductId,
                                    'gift_product_name' => $giftProduct['name'],
                                    'gift_count' => $totalGiftCount,
                                    'gift_amount' => $totalGiftAmount,
                                ]),
                                'create_time' => time(),
                                'update_time' => time(),
                            ]);
                        }
                    }
                }
            }

            // 提交事务
            Db::commit();

            $this->success('购买成功', [
                'order_no' => $orderNo,
                'order_id' => $orderId,
                'amount' => $amount,
                'balance' => $afterMoney,
                'payment_channel' => $paymentChannel,
                'expected_income' => $expectedIncome,
                'expire_time' => $expireTime,
                'expire_time_text' => date('Y-m-d H:i:s', $expireTime),
                'gift_orders' => $giftOrders, // 赠品订单列表
            ]);

        } catch (HttpResponseException $e) {
            // success() 和 error() 抛出的 HttpResponseException，直接向上抛出，不做处理
            throw $e;
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            $this->error('购买失败：' . $e->getMessage());
        }
    }

    #[
        Apidoc\Title("我的理财订单列表"),
        Apidoc\Tag("理财产品,订单"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/financeProduct/myOrders"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量", default: "10"),
        Apidoc\Query(name: "status", type: "string", require: false, desc: "订单状态: pending/paid/completed/cancelled/refunded"),
        Apidoc\Returned("list", type: "array", desc: "订单列表"),
        Apidoc\Returned("list[].id", type: "int", desc: "订单ID"),
        Apidoc\Returned("list[].order_no", type: "string", desc: "订单号"),
        Apidoc\Returned("list[].product_name", type: "string", desc: "产品名称"),
        Apidoc\Returned("list[].amount", type: "float", desc: "购买金额"),
        Apidoc\Returned("list[].yield_rate", type: "float", desc: "年化收益率"),
        Apidoc\Returned("list[].cycle_days", type: "int", desc: "收益周期"),
        Apidoc\Returned("list[].expected_income", type: "float", desc: "预计收益"),
        Apidoc\Returned("list[].status", type: "string", desc: "订单状态"),
        Apidoc\Returned("list[].status_text", type: "string", desc: "状态文本"),
        Apidoc\Returned("list[].expire_time_text", type: "string", desc: "到期时间"),
        Apidoc\Returned("list[].pay_time_text", type: "string", desc: "支付时间"),
        Apidoc\Returned("list[].create_time_text", type: "string", desc: "创建时间"),
        Apidoc\Returned("total", type: "int", desc: "总记录数"),
        Apidoc\Returned("page", type: "int", desc: "当前页码"),
        Apidoc\Returned("limit", type: "int", desc: "每页数量"),
    ]
    public function myOrders(): void
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
                'yield_rate',
                'status',
                'remark',
                'extra',
                'pay_time',
                'create_time',
            ])
            ->order('id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        // 格式化数据
        foreach ($list as &$item) {
            $extra = json_decode($item['extra'], true);
            $item['product_name'] = $extra['product_name'] ?? '';
            $item['cycle_days'] = $extra['cycle_days'] ?? 0;
            $item['expected_income'] = $extra['expected_income'] ?? 0;
            $item['expire_time'] = $extra['expire_time'] ?? 0;
            $item['expire_time_text'] = $item['expire_time'] ? date('Y-m-d H:i:s', $item['expire_time']) : '';
            
            // 支付通道文本
            $item['payment_channel_text'] = match($item['payment_channel'] ?? null) {
                'alipay' => '支付宝',
                'wechat' => '微信支付',
                'union' => '银联支付',
                'balance' => '余额支付',
                default => $item['payment_channel'] ?? '未设置',
            };
            
            $item['status_text'] = match($item['status']) {
                'pending' => '待支付',
                'paying' => '支付中',
                'paid' => '支付成功(待收益)',
                'earning' => '收益中',
                'completed' => '已完成',
                'cancelled' => '已取消',
                'refunded' => '已退款',
                default => $item['status'],
            };

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
        Apidoc\Tag("理财产品,订单"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/financeProduct/orderDetail"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Query(name: "id", type: "int", require: true, desc: "订单ID"),
        Apidoc\Returned("id", type: "int", desc: "订单ID"),
        Apidoc\Returned("order_no", type: "string", desc: "订单号"),
        Apidoc\Returned("product_id", type: "int", desc: "产品ID"),
        Apidoc\Returned("product_name", type: "string", desc: "产品名称"),
        Apidoc\Returned("amount", type: "float", desc: "购买金额"),
        Apidoc\Returned("yield_rate", type: "float", desc: "年化收益率"),
        Apidoc\Returned("cycle_days", type: "int", desc: "收益周期"),
        Apidoc\Returned("expected_income", type: "float", desc: "预计收益"),
        Apidoc\Returned("status", type: "string", desc: "订单状态"),
        Apidoc\Returned("status_text", type: "string", desc: "状态文本"),
        Apidoc\Returned("expire_time", type: "int", desc: "到期时间戳"),
        Apidoc\Returned("expire_time_text", type: "string", desc: "到期时间"),
        Apidoc\Returned("pay_time_text", type: "string", desc: "支付时间"),
        Apidoc\Returned("create_time_text", type: "string", desc: "创建时间"),
        Apidoc\Returned("remark", type: "string", desc: "备注"),
    ]
    public function orderDetail(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $id = $this->request->param('id/d', 0);
        if (!$id) {
            $this->error('参数错误');
        }

        $order = Db::name('finance_order')
            ->where('id', $id)
            ->where('user_id', $this->auth->id)
            ->find();

        if (!$order) {
            $this->error('订单不存在');
        }

        // 格式化数据
        $extra = json_decode($order['extra'], true);
        $order['product_name'] = $extra['product_name'] ?? '';
        $order['cycle_days'] = $extra['cycle_days'] ?? 0;
        $order['expected_income'] = $extra['expected_income'] ?? 0;
        $order['expire_time'] = $extra['expire_time'] ?? 0;
        $order['expire_time_text'] = $order['expire_time'] ? date('Y-m-d H:i:s', $order['expire_time']) : '';
        
        // 支付通道文本
        $order['payment_channel_text'] = match($order['payment_channel'] ?? null) {
            'alipay' => '支付宝',
            'wechat' => '微信支付',
            'union' => '银联支付',
            'balance' => '余额支付',
            default => $order['payment_channel'] ?? '未设置',
        };
        
        $order['status_text'] = match($order['status']) {
            'pending' => '待支付',
            'paying' => '支付中',
            'paid' => '支付成功(待收益)',
            'earning' => '收益中',
            'completed' => '已完成',
            'cancelled' => '已取消',
            'refunded' => '已退款',
            default => $order['status'],
        };

        $order['pay_time_text'] = $order['pay_time'] ? date('Y-m-d H:i:s', $order['pay_time']) : '';
        $order['create_time_text'] = date('Y-m-d H:i:s', $order['create_time']);

        $this->success('', $order);
    }

    #[
        Apidoc\Title("获取理财产品委托协议"),
        Apidoc\Tag("理财产品,协议"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/financeProduct/getAgreement"),
        Apidoc\Returned("agreement_content", type: "string", desc: "委托协议内容(支持HTML格式)"),
    ]
    public function getAgreement(): void
    {
        $agreementContent = get_sys_config('finance_agreement_content');
        
        $this->success('', [
            'agreement_content' => $agreementContent ?: '',
        ]);
    }
}

