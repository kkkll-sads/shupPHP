<?php

namespace app\api\controller;

use think\facade\Db;
use app\common\controller\Frontend;
use hg\apidoc\annotation as Apidoc;
use think\exception\HttpResponseException;

#[Apidoc\Title("商城订单管理")]
class ShopOrder extends Frontend
{
    protected array $noNeedLogin = [];

    public function initialize(): void
    {
        parent::initialize();
    }

    #[
        Apidoc\Title("创建订单"),
        Apidoc\Tag("商城,下单"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/shopOrder/create"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Param(name: "items", type: "array", require: true, desc: "商品列表"),
        Apidoc\Param(name: "items[].product_id", type: "int", require: true, desc: "商品ID"),
        Apidoc\Param(name: "items[].quantity", type: "int", require: true, desc: "购买数量"),
        Apidoc\Param(name: "pay_type", type: "string", require: false, desc: "支付方式（已废弃，系统自动计算价格）"),
        Apidoc\Param(name: "address_id", type: "int", require: false, desc: "收货地址ID（实物商品必填）"),
        Apidoc\Param(name: "remark", type: "string", require: false, desc: "订单备注"),
        Apidoc\Returned("order_no", type: "string", desc: "订单号"),
        Apidoc\Returned("order_id", type: "int", desc: "订单ID"),
        Apidoc\Returned("total_amount", type: "float", desc: "订单总金额"),
        Apidoc\Returned("total_score", type: "int", desc: "订单总消费金"),
        Apidoc\Returned("items", type: "array", desc: "订单商品列表"),
        Apidoc\Returned("items[].price", type: "float", desc: "商品价格"),
        Apidoc\Returned("items[].score_price", type: "float", desc: "商品积分价格"),
        Apidoc\Returned("balance_available", type: "string", desc: "用户可用余额"),
        Apidoc\Returned("score", type: "string", desc: "用户消费金"),
    ]
    public function create(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $items = $this->request->param('items/a', []);
        $payType = $this->request->param('pay_type', ''); // 向后兼容，但不再使用
        $addressId = $this->request->param('address_id/d', 0);
        $remark = $this->request->param('remark', '');

        if (empty($items)) {
            $this->error('购物车不能为空');
        }

        $userId = $this->auth->id;

        Db::startTrans();
        try {
            // 1. 验证商品并计算总价
            $totalAmount = 0;
            $totalScore = 0;
            $orderItems = [];
            $hasPhysical = false;

            foreach ($items as $item) {
                if (!isset($item['product_id']) || !isset($item['quantity'])) {
                    throw new \Exception('商品参数不完整');
                }

                $productId = (int)$item['product_id'];
                $quantity = (int)$item['quantity'];

                if ($quantity <= 0) {
                    throw new \Exception('购买数量必须大于0');
                }

                // 查询商品信息并锁定
                $product = Db::name('shop_product')
                    ->where('id', $productId)
                    ->where('status', '1')
                    ->lock(true)
                    ->find();

                if (!$product) {
                    throw new \Exception('商品不存在或已下架');
                }


                // 验证库存
                if ($product['stock'] < $quantity) {
                    throw new \Exception('商品【' . $product['name'] . '】库存不足');
                }

                // 检查是否有实物商品
                if ($product['is_physical'] == '1') {
                    $hasPhysical = true;
                }

                // 计算小计：根据商品的价格自动计费
                $subtotal = 0;
                $subtotalScore = 0;

                // 如果商品有人民币价格，就计入总金额
                if ($product['price'] > 0) {
                    $subtotal = $product['price'] * $quantity;
                    $totalAmount += $subtotal;
                }

                // 如果商品有消费金价格，就计入总消费金
                if ($product['score_price'] > 0) {
                    $subtotalScore = $product['score_price'] * $quantity;
                    $totalScore += $subtotalScore;
                }

                $orderItems[] = [
                    'product_id' => $productId,
                    'product_name' => $product['name'],
                    'product_thumbnail' => $product['thumbnail'],
                    'price' => $product['price'],
                    'score_price' => $product['score_price'],
                    'quantity' => $quantity,
                    'subtotal' => $subtotal,
                    'subtotal_score' => $subtotalScore,
                    'product' => $product,
                ];
            }

            // 2. 如果有实物商品，验证收货地址
            $address = null;
            if ($hasPhysical) {
                if (!$addressId) {
                    throw new \Exception('实物商品必须填写收货地址');
                }

                $address = Db::name('shop_address')
                    ->where('id', $addressId)
                    ->where('user_id', $userId)
                    ->find();

                if (!$address) {
                    throw new \Exception('收货地址不存在');
                }
            }

            // 3. 创建订单（待付款状态）
            $orderNo = 'SO' . date('YmdHis') . str_pad($userId, 6, '0', STR_PAD_LEFT) . rand(1000, 9999);
            
            // 判断是否有卡密商品
            $hasCardProduct = false;
            foreach ($orderItems as $item) {
                // 查询商品信息判断是否为卡密商品
                $product = Db::name('shop_product')
                    ->where('id', $item['product_id'])
                    ->field('is_card_product')
                    ->find();
                if ($product && isset($product['is_card_product']) && $product['is_card_product'] == '1') {
                    $hasCardProduct = true;
                    break;
                }
            }
            
            // 根据订单金额确定支付类型
            $payTypeValue = 'score'; // 默认
            if ($totalAmount > 0 && $totalScore > 0) {
                $payTypeValue = 'combined'; // 同时需要人民币和消费金
            } elseif ($totalAmount > 0) {
                $payTypeValue = 'money'; // 只需要人民币
            } elseif ($totalScore > 0) {
                $payTypeValue = 'score'; // 只需要消费金
            }

            // 订单初始状态为待付款
            $orderData = [
                'order_no' => $orderNo,
                'user_id' => $userId,
                'total_amount' => $totalAmount,
                'total_score' => $totalScore,
                'pay_type' => $payTypeValue,
                'status' => 'pending', // 待付款
                'remark' => $remark,
                'pay_time' => 0,
                'complete_time' => 0,
                'create_time' => time(),
                'update_time' => time(),
            ];

            // 如果有收货地址，添加收货信息
            if ($address) {
                $fullAddress = ($address['province'] ?? '') . ($address['city'] ?? '') . ($address['district'] ?? '') . $address['address'];
                $orderData['recipient_name'] = $address['name'];
                $orderData['recipient_phone'] = $address['phone'];
                $orderData['recipient_address'] = $fullAddress;
            }

            $orderId = Db::name('shop_order')->insertGetId($orderData);

            if (!$orderId) {
                throw new \Exception('创建订单失败');
            }

            // 4. 创建订单明细（暂不扣减库存，等支付成功后再扣减）
            foreach ($orderItems as $item) {
                Db::name('shop_order_item')->insert([
                    'order_id' => $orderId,
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'product_thumbnail' => $item['product_thumbnail'],
                    'price' => $item['price'],
                    'score_price' => $item['score_price'],
                    'quantity' => $item['quantity'],
                    'subtotal' => $item['subtotal'],
                    'subtotal_score' => $item['subtotal_score'],
                    'create_time' => time(),
                ]);
            }

            Db::commit();

            // 记录用户活动日志
            $productNames = array_map(function($item) {
                return $item['product_name'] . ' x' . $item['quantity'];
            }, $orderItems);
            $productNamesStr = implode('、', $productNames);

            Db::name('user_activity_log')->insert([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'shop_order_create',
                'change_field' => 'order_status',
                'change_value' => 'pending',
                'before_value' => '',
                'after_value' => 'pending',
                'remark' => '创建商城订单，等待支付',
                'extra' => json_encode([
                    'order_no' => $orderNo,
                    'order_id' => $orderId,
                    'item_count' => count($orderItems),
                    'pay_type' => $payTypeValue,
                    'total_amount' => $totalAmount,
                    'total_score' => $totalScore,
                    'products' => $productNamesStr,
                ], JSON_UNESCAPED_UNICODE),
                'create_time' => time(),
                'update_time' => time(),
            ]);

            // 获取用户信息
            $userInfo = $this->auth->getUserInfo();

            // 构建返回的商品信息
            $returnItems = [];
            foreach ($orderItems as $item) {
                $returnItems[] = [
                    'price' => $item['price'],
                    'score_price' => $item['score_price'],
                ];
            }

            // 返回订单信息，提示用户去支付
            $this->success('订单创建成功，请完成支付', [
                'order_no' => $orderNo,
                'order_id' => $orderId,
                'total_amount' => $totalAmount,
                'total_score' => $totalScore,
                'status' => 'pending',
                'pay_type' => $payTypeValue,
                'items' => $returnItems,
                'balance_available' => $userInfo['balance_available'] ?? '0.00',
                'score' => $userInfo['score'] ?? '0.00',
            ]);

        } catch (HttpResponseException $e) {
            // success() 和 error() 抛出的 HttpResponseException，直接向上抛出，不做处理
            throw $e;
        } catch (\Exception $e) {
            Db::rollback();
            // 添加日志记录异常信息
            \think\facade\Log::error('商城订单创建失败', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'user_id' => $userId ?? 0,
                'items' => $items ?? [],
                'pay_type' => $payType ?? '',
            ]);
            $this->error($e->getMessage());
        }
    }

    #[
        Apidoc\Title("支付订单"),
        Apidoc\Tag("商城,订单,支付"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/shopOrder/pay"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Param(name: "order_id", type: "int", require: true, desc: "订单ID"),
        Apidoc\Param(name: "pay_money", type: "float", require: false, desc: "使用可用金额支付的金额（不传则根据订单类型自动支付）", default: "0"),
        Apidoc\Param(name: "pay_score", type: "float", require: false, desc: "使用消费金支付的金额（不传则根据订单类型自动支付）", default: "0"),
        Apidoc\Returned("order_no", type: "string", desc: "订单号"),
        Apidoc\Returned("order_id", type: "int", desc: "订单ID"),
        Apidoc\Returned("status", type: "string", desc: "订单状态"),
        Apidoc\Returned("pay_money", type: "float", desc: "实际使用可用金额支付的金额"),
        Apidoc\Returned("pay_score", type: "float", desc: "实际使用消费金支付的金额"),
    ]
    public function pay(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $orderId = $this->request->param('order_id/d', 0);
        $payMoney = $this->request->param('pay_money/f', 0);
        $payScore = $this->request->param('pay_score/f', 0);

        if (!$orderId) {
            $this->error('参数错误');
        }

        // 验证支付金额参数
        if ($payMoney < 0 || $payScore < 0) {
            $this->error('支付金额不能为负数');
        }

        // 如果没有指定支付金额，则根据订单的原始支付类型自动设置支付金额
        $autoPayMode = ($payMoney == 0 && $payScore == 0);
        if ($autoPayMode) {
            // 标记为自动支付模式，后续会根据订单信息设置支付金额
        }

        $userId = $this->auth->id;

        Db::startTrans();
        try {
            // 1. 查询订单并锁定
            $order = Db::name('shop_order')
                ->where('id', $orderId)
                ->where('user_id', $userId)
                ->lock(true)
                ->find();

            if (!$order) {
                throw new \Exception('订单不存在');
            }

            if ($order['status'] != 'pending') {
                throw new \Exception('订单状态不正确，无法支付');
            }

            // 如果是自动支付模式，根据订单的原始支付类型设置支付金额
            if ($autoPayMode) {
                if ($order['pay_type'] == 'money') {
                    $payMoney = $order['total_amount'];
                    $payScore = 0;
                } elseif ($order['pay_type'] == 'score') {
                    $payMoney = 0;
                    $payScore = $order['total_score'];
                } elseif ($order['pay_type'] == 'combined') {
                    // 组合支付：同时扣除人民币和消费金
                    $payMoney = $order['total_amount'];
                    $payScore = $order['total_score'];
                } else {
                    throw new \Exception('订单支付类型不支持自动支付');
                }
            }

            // 2. 查询用户并锁定
            $user = Db::name('user')
                ->where('id', $userId)
                ->lock(true)
                ->find();

            if (!$user) {
                throw new \Exception('用户不存在');
            }

            // 3. 验证用户余额和消费金
            if ($user['balance_available'] < $payMoney) {
                throw new \Exception('可用金额不足，当前可用金额：' . number_format($user['balance_available'], 2) . '元');
            }

            if ($user['score'] < $payScore) {
                throw new \Exception('消费金不足，当前消费金：' . $user['score']);
            }

            // 验证支付金额是否与订单总价值匹配（可用金额价值 + 消费金价值）
            $orderTotalValue = $order['total_amount'] + $order['total_score'];
            $totalPayAmount = $payMoney + $payScore;
            if ($totalPayAmount != $orderTotalValue) {
                throw new \Exception('支付金额与订单总价值不匹配，订单总价值：' . number_format($orderTotalValue, 2));
            }

            // 4. 扣除可用金额和消费金
            $beforeMoney = $user['balance_available'];
            $beforeScore = $user['score'];
            $afterMoney = $beforeMoney - $payMoney;
            $afterScore = $beforeScore - $payScore;

            $updateData = [];
            if ($payMoney > 0) {
                $updateData['balance_available'] = $afterMoney;
            }
            if ($payScore > 0) {
                $updateData['score'] = $afterScore;
            }

            if (!empty($updateData)) {
                Db::name('user')->where('id', $userId)->update($updateData);
            }

            // 记录可用金额日志
            if ($payMoney > 0) {
                Db::name('user_money_log')->insert([
                    'user_id' => $userId,
                    'field_type' => 'balance_available',
                    'money' => -$payMoney,
                    'before' => $beforeMoney,
                    'after' => $afterMoney,
                    'memo' => '商城购物消费（可用金额）',
                    'create_time' => time(),
                ]);
            }

            // 记录消费金日志
            if ($payScore > 0) {
                $flowNo = generateSJSFlowNo($userId);
                $batchNo = generateBatchNo('SHOP_ORDER', $orderId);
                Db::name('user_score_log')->insert([
                    'user_id' => $userId,
                    'flow_no' => $flowNo,
                    'batch_no' => $batchNo,
                    'biz_type' => 'shop_order',
                    'biz_id' => $orderId,
                    'score' => -$payScore,
                    'before' => $beforeScore,
                    'after' => $afterScore,
                    'memo' => '商城消费金支付',
                    'create_time' => time(),
                ]);
            }

            // 5. 查询订单商品明细，判断订单类型
            $orderItems = Db::name('shop_order_item')
                ->where('order_id', $orderId)
                ->select()
                ->toArray();

            $hasPhysical = false;
            $hasCardProduct = false;
            foreach ($orderItems as $item) {
                $product = Db::name('shop_product')
                    ->where('id', $item['product_id'])
                    ->field('is_physical, is_card_product')
                    ->find();
                if ($product) {
                    if ($product['is_physical'] == '1') {
                        $hasPhysical = true;
                    }
                    if (isset($product['is_card_product']) && $product['is_card_product'] == '1') {
                        $hasCardProduct = true;
                    }
                }
            }

            // 6. 扣减库存，增加销量
            foreach ($orderItems as $item) {
                // 先查询当前库存
                $product = Db::name('shop_product')
                    ->where('id', $item['product_id'])
                    ->field('stock')
                    ->find();
                
                // 扣减库存并增加销量，若扣减后库存 <= 0 则自动下架（status=0）
                Db::name('shop_product')
                    ->where('id', $item['product_id'])
                    ->dec('stock', $item['quantity'])
                    ->inc('sales', $item['quantity'])
                    ->update();
                
                if ($product) {
                    $newStock = (int)$product['stock'] - (int)$item['quantity'];
                    if ($newStock <= 0) {
                        Db::name('shop_product')->where('id', $item['product_id'])->update(['status' => '0', 'update_time' => time()]);
                    }
                }
            }

            // 7. 更新订单状态
            // 订单状态判断：
            // 1. 实物商品：已支付（等待发货）
            // 2. 卡密商品：已支付（等待管理员填写卡密）
            // 3. 纯虚拟商品（非卡密）：已完成（自动完成）
            $orderStatus = 'paid';
            $completeTime = 0;
            if (!$hasPhysical && !$hasCardProduct) {
                // 只有纯虚拟商品（非实物、非卡密）才自动完成
                $orderStatus = 'completed';
                $completeTime = time();
            }

            Db::name('shop_order')
                ->where('id', $orderId)
                ->update([
                    'status' => $orderStatus,
                    'balance_available_amount' => $payMoney,
                    'pay_time' => time(),
                    'complete_time' => $completeTime,
                    'update_time' => time(),
                ]);

            // 8. 记录用户活动日志
            $productNames = array_map(function($item) {
                return $item['product_name'] . ' x' . $item['quantity'];
            }, $orderItems);
            $productNamesStr = implode('、', $productNames);
            
            $productsDetail = array_map(function($item) {
                return [
                    'id' => $item['product_id'],
                    'name' => $item['product_name'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'score_price' => $item['score_price'],
                    'subtotal' => $item['subtotal'],
                    'subtotal_score' => $item['subtotal_score'],
                ];
            }, $orderItems);
            
            // 记录用户活动日志
            $changeField = 'combined';
            $changeValue = 0;
            if ($order['total_amount'] > 0 && $order['total_score'] > 0) {
                $changeField = 'combined';
                $changeValue = -$order['total_amount'] - $order['total_score'];
            } elseif ($order['total_amount'] > 0) {
                $changeField = 'balance_available';
                $changeValue = -$order['total_amount'];
            } elseif ($order['total_score'] > 0) {
                $changeField = 'score';
                $changeValue = -$order['total_score'];
            }

            Db::name('user_activity_log')->insert([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'shop_purchase',
                'change_field' => $changeField,
                'change_value' => $changeValue,
                'before_value' => $order['total_amount'] > 0 ? $beforeMoney : $beforeScore,
                'after_value' => $order['total_amount'] > 0 ? $afterMoney : $afterScore,
                'remark' => '商城购物：' . $productNamesStr,
                'extra' => json_encode([
                    'order_no' => $order['order_no'],
                    'order_id' => $orderId,
                    'item_count' => count($orderItems),
                    'pay_type' => $order['pay_type'],
                    'pay_type_text' => $order['pay_type'] == 'combined' ? '组合支付' : ($order['pay_type'] == 'money' ? '余额支付' : '消费金支付'),
                    'products' => $productsDetail,
                ], JSON_UNESCAPED_UNICODE),
                'create_time' => time(),
                'update_time' => time(),
            ]);

            Db::commit();

            // 返回消息判断
            $message = '支付成功';
            if ($hasPhysical) {
                $message = '支付成功，请等待发货';
            } elseif ($hasCardProduct) {
                $message = '支付成功，管理员将尽快为您发放卡密';
            } else {
                $message = '支付成功，虚拟商品已自动完成';
            }
            
            $this->success($message, [
                'order_no' => $order['order_no'],
                'order_id' => $orderId,
                'status' => $orderStatus,
                'pay_money' => $payMoney,
                'pay_score' => $payScore,
            ]);

        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Db::rollback();
            \think\facade\Log::error('商城订单支付失败', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'user_id' => $userId ?? 0,
                'order_id' => $orderId ?? 0,
            ]);
            $this->error($e->getMessage());
        }
    }

    #[
        Apidoc\Title("购买商品（一步到位：创建订单并支付）"),
        Apidoc\Tag("商城,购买"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/shopOrder/buy"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Param(name: "items", type: "array", require: true, desc: "商品列表"),
        Apidoc\Param(name: "items[].product_id", type: "int", require: true, desc: "商品ID"),
        Apidoc\Param(name: "items[].quantity", type: "int", require: true, desc: "购买数量"),
        Apidoc\Param(name: "address_id", type: "int", require: false, desc: "收货地址ID（实物商品必填）"),
        Apidoc\Param(name: "remark", type: "string", require: false, desc: "订单备注"),
        Apidoc\Returned("order_no", type: "string", desc: "订单号"),
        Apidoc\Returned("order_id", type: "int", desc: "订单ID"),
        Apidoc\Returned("total_amount", type: "float", desc: "订单总金额"),
        Apidoc\Returned("total_score", type: "int", desc: "订单总消费金"),
        Apidoc\Returned("status", type: "string", desc: "订单状态"),
    ]
    public function buy(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $items = $this->request->param('items/a', []);
        $addressId = $this->request->param('address_id/d', 0);
        $remark = $this->request->param('remark', '');

        if (empty($items)) {
            $this->error('购物车不能为空');
        }

        $userId = $this->auth->id;

        Db::startTrans();
        try {
            // 1. 验证商品并计算总价
            $totalAmount = 0;
            $totalScore = 0;
            $orderItems = [];
            $hasPhysical = false;

            foreach ($items as $item) {
                if (!isset($item['product_id']) || !isset($item['quantity'])) {
                    throw new \Exception('商品参数不完整');
                }

                $productId = (int)$item['product_id'];
                $quantity = (int)$item['quantity'];

                if ($quantity <= 0) {
                    throw new \Exception('购买数量必须大于0');
                }

                // 查询商品信息并锁定
                $product = Db::name('shop_product')
                    ->where('id', $productId)
                    ->where('status', '1')
                    ->lock(true)
                    ->find();

                if (!$product) {
                    throw new \Exception('商品不存在或已下架');
                }


                // 验证库存
                if ($product['stock'] < $quantity) {
                    throw new \Exception('商品【' . $product['name'] . '】库存不足');
                }

                // 检查是否有实物商品
                if ($product['is_physical'] == '1') {
                    $hasPhysical = true;
                }

                // 计算小计：根据商品的价格自动计费
                $subtotal = 0;
                $subtotalScore = 0;

                // 如果商品有人民币价格，就计入总金额
                if ($product['price'] > 0) {
                    $subtotal = $product['price'] * $quantity;
                    $totalAmount += $subtotal;
                }

                // 如果商品有消费金价格，就计入总消费金
                if ($product['score_price'] > 0) {
                    $subtotalScore = $product['score_price'] * $quantity;
                    $totalScore += $subtotalScore;
                }

                $orderItems[] = [
                    'product_id' => $productId,
                    'product_name' => $product['name'],
                    'product_thumbnail' => $product['thumbnail'],
                    'price' => $product['price'],
                    'score_price' => $product['score_price'],
                    'quantity' => $quantity,
                    'subtotal' => $subtotal,
                    'subtotal_score' => $subtotalScore,
                    'product' => $product,
                ];
            }

            // 2. 如果有实物商品，验证收货地址
            $address = null;
            if ($hasPhysical) {
                if (!$addressId) {
                    throw new \Exception('实物商品必须填写收货地址');
                }

                $address = Db::name('shop_address')
                    ->where('id', $addressId)
                    ->where('user_id', $userId)
                    ->find();

                if (!$address) {
                    throw new \Exception('收货地址不存在');
                }
            }

            // 3. 验证用户余额或积分
            $user = Db::name('user')
                ->where('id', $userId)
                ->lock(true)
                ->find();

            if (!$user) {
                throw new \Exception('用户不存在');
            }

            // 检查用户是否有足够的余额和消费金
            if ($totalAmount > 0 && $user['balance_available'] < $totalAmount) {
                throw new \Exception('可用金额不足，当前可用金额：' . number_format($user['balance_available'], 2) . '元');
            }

            if ($totalScore > 0 && $user['score'] < $totalScore) {
                throw new \Exception('消费金不足，当前消费金：' . $user['score']);
            }

            // 4. 先创建订单（待支付状态，稍后更新为已支付）
            $orderNo = 'SO' . date('YmdHis') . str_pad($userId, 6, '0', STR_PAD_LEFT) . rand(1000, 9999);
            
            // 判断是否有卡密商品
            $hasCardProduct = false;
            foreach ($orderItems as $item) {
                // 查询商品信息判断是否为卡密商品
                $product = Db::name('shop_product')
                    ->where('id', $item['product_id'])
                    ->field('is_card_product')
                    ->find();
                if ($product && isset($product['is_card_product']) && $product['is_card_product'] == '1') {
                    $hasCardProduct = true;
                    break;
                }
            }
            
            // 订单状态判断：
            // 1. 实物商品：已支付（等待发货）
            // 2. 卡密商品：已支付（等待管理员填写卡密）
            // 3. 纯虚拟商品（非卡密）：已完成（自动完成）
            $orderStatus = 'paid';
            $completeTime = 0;
            if (!$hasPhysical && !$hasCardProduct) {
                // 只有纯虚拟商品（非实物、非卡密）才自动完成
                $orderStatus = 'completed';
                $completeTime = time();
            }
            
            // 根据订单金额确定支付类型
            $payTypeValue = 'score'; // 默认
            if ($totalAmount > 0 && $totalScore > 0) {
                $payTypeValue = 'combined'; // 同时需要人民币和消费金
            } elseif ($totalAmount > 0) {
                $payTypeValue = 'money'; // 只需要人民币
            } elseif ($totalScore > 0) {
                $payTypeValue = 'score'; // 只需要消费金
            }

            $orderData = [
                'order_no' => $orderNo,
                'user_id' => $userId,
                'total_amount' => $totalAmount,
                'total_score' => $totalScore,
                'pay_type' => $payTypeValue,
                'status' => $orderStatus,
                'remark' => $remark,
                'pay_time' => time(),
                'complete_time' => $completeTime,
                'create_time' => time(),
                'update_time' => time(),
            ];

            // 如果有收货地址，添加收货信息
            if ($address) {
                $fullAddress = ($address['province'] ?? '') . ($address['city'] ?? '') . ($address['district'] ?? '') . $address['address'];
                $orderData['recipient_name'] = $address['name'];
                $orderData['recipient_phone'] = $address['phone'];
                $orderData['recipient_address'] = $fullAddress;
            }

            $orderId = Db::name('shop_order')->insertGetId($orderData);

            if (!$orderId) {
                throw new \Exception('创建订单失败');
            }

            // 5. 扣除用户余额和消费金
            $beforeMoney = $user['balance_available'];
            $beforeScore = $user['score'];
            $afterMoney = $beforeMoney;
            $afterScore = $beforeScore;

            $updateData = [];
            if ($totalAmount > 0) {
                $afterMoney = $beforeMoney - $totalAmount;
                $updateData['balance_available'] = $afterMoney;
            }
            if ($totalScore > 0) {
                $afterScore = $beforeScore - $totalScore;
                $updateData['score'] = $afterScore;
            }

            if (!empty($updateData)) {
                Db::name('user')->where('id', $userId)->update($updateData);
            }

            // 记录余额日志
            if ($totalAmount > 0) {
                $flowNo = generateSJSFlowNo($userId);
                $batchNo = generateBatchNo('SHOP_ORDER', $orderId);
                Db::name('user_money_log')->insert([
                    'user_id' => $userId,
                    'flow_no' => $flowNo,
                    'batch_no' => $batchNo,
                    'biz_type' => 'shop_order_pay',
                    'biz_id' => $orderId,
                    'field_type' => 'balance_available',
                    'money' => -$totalAmount,
                    'before' => $beforeMoney,
                    'after' => $afterMoney,
                    'memo' => '商城购物消费（可用金额）',
                    'create_time' => time(),
                ]);
            }

            // 记录消费金日志
            if ($totalScore > 0) {
                $flowNo = generateSJSFlowNo($userId);
                $batchNo = generateBatchNo('SHOP_ORDER', $orderId);
                Db::name('user_score_log')->insert([
                    'user_id' => $userId,
                    'flow_no' => $flowNo,
                    'batch_no' => $batchNo,
                    'biz_type' => 'shop_order_pay',
                    'biz_id' => $orderId,
                    'score' => -$totalScore,
                    'before' => $beforeScore,
                    'after' => $afterScore,
                    'memo' => '商城消费金支付',
                    'create_time' => time(),
                ]);
            }

            // 6. 创建订单明细并扣减库存
            foreach ($orderItems as $item) {
                Db::name('shop_order_item')->insert([
                    'order_id' => $orderId,
                    'product_id' => $item['product_id'],
                    'product_name' => $item['product_name'],
                    'product_thumbnail' => $item['product_thumbnail'],
                    'price' => $item['price'],
                    'score_price' => $item['score_price'],
                    'quantity' => $item['quantity'],
                    'subtotal' => $item['subtotal'],
                    'subtotal_score' => $item['subtotal_score'],
                    'create_time' => time(),
                ]);

                // 扣减库存，增加销量
                // 先查询当前库存
                $product = Db::name('shop_product')
                    ->where('id', $item['product_id'])
                    ->field('stock')
                    ->find();
                
                // 扣减库存并增加销量，若扣减后库存 <= 0 则自动下架（status=0）
                Db::name('shop_product')
                    ->where('id', $item['product_id'])
                    ->dec('stock', $item['quantity'])
                    ->inc('sales', $item['quantity'])
                    ->update();
                
                if ($product) {
                    $newStock = (int)$product['stock'] - (int)$item['quantity'];
                    if ($newStock <= 0) {
                        Db::name('shop_product')->where('id', $item['product_id'])->update(['status' => '0', 'update_time' => time()]);
                    }
                }
            }

            // 7. 记录用户活动日志
            $productNames = array_map(function($item) {
                return $item['product_name'] . ' x' . $item['quantity'];
            }, $orderItems);
            $productNamesStr = implode('、', $productNames);
            
            $productsDetail = array_map(function($item) {
                return [
                    'id' => $item['product_id'],
                    'name' => $item['product_name'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'score_price' => $item['score_price'],
                    'subtotal' => $item['subtotal'],
                    'subtotal_score' => $item['subtotal_score'],
                ];
            }, $orderItems);
            
            // 记录用户活动日志
            $changeField = 'combined';
            $changeValue = 0;
            if ($totalAmount > 0 && $totalScore > 0) {
                $changeField = 'combined';
                $changeValue = -$totalAmount - $totalScore;
            } elseif ($totalAmount > 0) {
                $changeField = 'balance_available';
                $changeValue = -$totalAmount;
            } elseif ($totalScore > 0) {
                $changeField = 'score';
                $changeValue = -$totalScore;
            }

            Db::name('user_activity_log')->insert([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'shop_purchase',
                'change_field' => $changeField,
                'change_value' => $changeValue,
                'before_value' => $totalAmount > 0 ? $beforeMoney : $beforeScore,
                'after_value' => $totalAmount > 0 ? $afterMoney : $afterScore,
                'remark' => '商城购物：' . $productNamesStr,
                'extra' => json_encode([
                    'order_no' => $orderNo,
                    'order_id' => $orderId,
                    'item_count' => count($orderItems),
                    'pay_type' => $payTypeValue,
                    'pay_type_text' => $payTypeValue == 'combined' ? '组合支付' : ($payTypeValue == 'money' ? '余额支付' : '消费金支付'),
                    'products' => $productsDetail,
                ], JSON_UNESCAPED_UNICODE),
                'create_time' => time(),
                'update_time' => time(),
            ]);

            Db::commit();

            // 返回消息判断
            $message = '购买成功';
            if ($hasPhysical) {
                $message = '购买成功，请等待发货';
            } elseif ($hasCardProduct) {
                $message = '购买成功，管理员将尽快为您发放卡密';
            } else {
                $message = '购买成功，虚拟商品已自动完成';
            }
            
            $this->success($message, [
                'order_no' => $orderNo,
                'order_id' => $orderId,
                'total_amount' => $totalAmount,
                'total_score' => $totalScore,
                'status' => $orderStatus,
                'is_virtual_only' => !$hasPhysical,
                'has_card_product' => $hasCardProduct,
            ]);

        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Db::rollback();
            \think\facade\Log::error('商城商品购买失败', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'user_id' => $userId ?? 0,
                'items' => $items ?? [],
                'pay_type' => $payType ?? '',
            ]);
            $this->error($e->getMessage());
        }
    }

    #[
        Apidoc\Title("取消订单"),
        Apidoc\Tag("商城,订单,取消"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/shopOrder/cancel"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Param(name: "order_id", type: "int", require: true, desc: "订单ID"),
        Apidoc\Returned("order_no", type: "string", desc: "订单号"),
        Apidoc\Returned("order_id", type: "int", desc: "订单ID"),
        Apidoc\Returned("status", type: "string", desc: "订单状态"),
    ]
    public function cancel(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $orderId = $this->request->param('order_id/d', 0);
        if (!$orderId) {
            $this->error('参数错误');
        }

        $userId = $this->auth->id;

        Db::startTrans();
        try {
            // 1. 查询订单并锁定
            $order = Db::name('shop_order')
                ->where('id', $orderId)
                ->where('user_id', $userId)
                ->lock(true)
                ->find();

            if (!$order) {
                throw new \Exception('订单不存在');
            }

            // 2. 可以取消待支付和已支付但未发货的订单
            if (!in_array($order['status'], ['pending', 'paid'])) {
                throw new \Exception('订单状态不允许取消');
            }

            // 3. 处理不同状态的订单
            if ($order['status'] == 'pending') {
                // 记录用户活动日志
                Db::name('user_activity_log')->insert([
                    'user_id' => $userId,
                    'related_user_id' => 0,
                    'action_type' => 'shop_order_cancel',
                    'change_field' => 'order_status',
                    'change_value' => 'cancelled',
                    'before_value' => 'pending',
                    'after_value' => 'deleted',
                    'remark' => '取消待支付订单，删除订单记录',
                    'extra' => json_encode([
                        'order_no' => $order['order_no'],
                        'order_id' => $orderId,
                        'total_amount' => $order['total_amount'],
                        'total_score' => $order['total_score'],
                        'pay_type' => $order['pay_type'],
                        'operation' => 'delete_pending_order',
                    ], JSON_UNESCAPED_UNICODE),
                    'create_time' => time(),
                    'update_time' => time(),
                ]);

                // 待支付订单：直接删除记录
                // 删除订单明细
                Db::name('shop_order_item')
                    ->where('order_id', $orderId)
                    ->delete();

                // 删除订单
                Db::name('shop_order')
                    ->where('id', $orderId)
                    ->delete();

                Db::commit();

                $this->success('订单取消成功', [
                    'order_no' => $order['order_no'],
                    'order_id' => $orderId,
                    'status' => 'deleted',
                ]);
            } else {
                // 已支付订单：退款并更新状态
                // 查询用户并锁定
                $user = Db::name('user')
                    ->where('id', $userId)
                    ->lock(true)
                    ->find();

                if (!$user) {
                    throw new \Exception('用户不存在');
                }

                // 退还积分或余额
                if ($order['pay_type'] == 'money') {
                    $beforeMoney = $user['balance_available'];
                    $afterMoney = $beforeMoney + $order['total_amount'];
                    Db::name('user')->where('id', $userId)->update(['balance_available' => $afterMoney]);

                    // 记录余额日志
                    $flowNo = generateSJSFlowNo($userId);
                    $batchNo = generateBatchNo('SHOP_ORDER_CANCEL', $orderId);
                    Db::name('user_money_log')->insert([
                        'user_id' => $userId,
                        'flow_no' => $flowNo,
                        'batch_no' => $batchNo,
                        'biz_type' => 'shop_order_cancel',
                        'biz_id' => $orderId,
                        'field_type' => 'balance_available',
                        'money' => $order['total_amount'],
                        'before' => $beforeMoney,
                        'after' => $afterMoney,
                        'memo' => '商城订单取消退款',
                        'create_time' => time(),
                    ]);
                } else {
                    $beforeScore = $user['score'];
                    $afterScore = $beforeScore + $order['total_score'];
                    Db::name('user')->where('id', $userId)->update(['score' => $afterScore]);

                    // 记录积分日志
                    $flowNo = generateSJSFlowNo($userId);
                    $batchNo = generateBatchNo('SHOP_ORDER_CANCEL', $orderId);
                    Db::name('user_score_log')->insert([
                        'user_id' => $userId,
                        'flow_no' => $flowNo,
                        'batch_no' => $batchNo,
                        'biz_type' => 'shop_order_cancel',
                        'biz_id' => $orderId,
                        'score' => $order['total_score'],
                        'before' => $beforeScore,
                        'after' => $afterScore,
                        'memo' => '商城订单取消退款',
                        'create_time' => time(),
                    ]);
                }

                // 更新订单状态为已取消
                Db::name('shop_order')
                    ->where('id', $orderId)
                    ->update([
                        'status' => 'cancelled',
                        'update_time' => time(),
                    ]);

                // 记录用户活动日志
                Db::name('user_activity_log')->insert([
                    'user_id' => $userId,
                    'related_user_id' => 0,
                    'action_type' => 'shop_order_cancel',
                    'change_field' => $order['pay_type'] == 'money' ? 'money' : 'score',
                    'change_value' => $order['pay_type'] == 'money' ? $order['total_amount'] : $order['total_score'],
                    'before_value' => $order['pay_type'] == 'money' ? $beforeMoney : $beforeScore,
                    'after_value' => $order['pay_type'] == 'money' ? $afterMoney : $afterScore,
                    'remark' => '取消商城订单，退款到账户',
                    'extra' => json_encode([
                        'order_no' => $order['order_no'],
                        'order_id' => $orderId,
                        'pay_type' => $order['pay_type'],
                        'refund_amount' => $order['pay_type'] == 'money' ? $order['total_amount'] : $order['total_score'],
                    ], JSON_UNESCAPED_UNICODE),
                    'create_time' => time(),
                    'update_time' => time(),
                ]);

                Db::commit();

                $this->success('订单取消成功，已退款到您的账户', [
                    'order_no' => $order['order_no'],
                    'order_id' => $orderId,
                    'status' => 'cancelled',
                ]);
            }

        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Db::rollback();
            \think\facade\Log::error('取消订单失败', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'user_id' => $userId ?? 0,
                'order_id' => $orderId ?? 0,
            ]);
            $this->error($e->getMessage());
        }
    }

    #[
        Apidoc\Title("我的订单列表"),
        Apidoc\Tag("商城,订单"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/shopOrder/myOrders"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量", default: "10"),
        Apidoc\Query(name: "status", type: "string", require: false, desc: "订单状态"),
        Apidoc\Returned("list", type: "array", desc: "订单列表"),
        Apidoc\Returned("balance_available", type: "string", desc: "用户可用余额"),
        Apidoc\Returned("score", type: "string", desc: "用户消费金"),
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
            ['remark', 'not like', '藏品提货：%'], // 排除藏品提货订单
        ];

        if ($status) {
            $where[] = ['status', '=', $status];
        }

        $list = Db::name('shop_order')
            ->where($where)
            ->order('id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        // 查询订单商品明细
        foreach ($list as &$order) {
            $order['items'] = Db::name('shop_order_item')
                ->where('order_id', $order['id'])
                ->select()
                ->toArray();

            // 处理图片URL和添加商品类型信息
            $hasPhysical = false;
            $hasVirtual = false;
            $hasCardProduct = false;
            
            foreach ($order['items'] as &$item) {
                $item['product_thumbnail'] = $item['product_thumbnail'] ? full_url($item['product_thumbnail'], false) : '';
                $item['price'] = (float)$item['price'];
                $item['subtotal'] = (float)$item['subtotal'];
                
                // 获取商品类型
                $product = Db::name('shop_product')
                    ->where('id', $item['product_id'])
                    ->field('is_physical, is_card_product')
                    ->find();
                if ($product) {
                    $item['is_physical'] = $product['is_physical'];
                    $item['is_card_product'] = $product['is_card_product'] ?? '0';
                    
                    if ($product['is_physical'] == '1') {
                        $hasPhysical = true;
                    } else {
                        $hasVirtual = true;
                        if (isset($product['is_card_product']) && $product['is_card_product'] == '1') {
                            $hasCardProduct = true;
                        }
                    }
                }
            }

            // 设置订单的商品类型标识
            if ($hasPhysical && $hasVirtual) {
                $order['product_type'] = 'mixed'; // 混合订单
                $order['product_type_text'] = '混合订单';
            } elseif ($hasPhysical) {
                $order['product_type'] = 'physical'; // 实物商品
                $order['product_type_text'] = '实物商品';
            } elseif ($hasCardProduct) {
                $order['product_type'] = 'card'; // 卡密商品
                $order['product_type_text'] = '卡密商品';
            } else {
                $order['product_type'] = 'virtual'; // 虚拟商品
                $order['product_type_text'] = '虚拟商品';
            }

            $order['total_amount'] = (float)$order['total_amount'];
            
            // 状态文本
            $statusMap = [
                'pending' => '待支付',
                'paid' => '已支付',
                'shipped' => '已发货',
                'completed' => '已完成',
                'cancelled' => '已取消',
                'refunded' => '已退款',
            ];
            $order['status_text'] = $statusMap[$order['status']] ?? $order['status'];

            $payTypeMap = [
                'money' => '余额支付',
                'score' => '消费金支付',
                'combined' => '组合支付',
            ];
            $order['pay_type_text'] = $payTypeMap[$order['pay_type']] ?? $order['pay_type'];
        }

        $total = Db::name('shop_order')
            ->where($where)
            ->count();

        // 获取用户信息
        $userInfo = $this->auth->getUserInfo();

        $this->success('', [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'balance_available' => $userInfo['balance_available'] ?? '0.00',
            'score' => $userInfo['score'] ?? '0.00',
        ]);
    }

    #[
        Apidoc\Title("订单统计"),
        Apidoc\Tag("商城,订单"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/shopOrder/statistics"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Returned("all_count", type: "int", desc: "全部订单数"),
        Apidoc\Returned("pending_count", type: "int", desc: "待支付订单数"),
        Apidoc\Returned("paid_count", type: "int", desc: "待发货订单数"),
        Apidoc\Returned("shipped_count", type: "int", desc: "已发货订单数"),
        Apidoc\Returned("completed_count", type: "int", desc: "已完成订单数"),
        Apidoc\Returned("cancelled_count", type: "int", desc: "已取消订单数"),
        Apidoc\Returned("refunded_count", type: "int", desc: "已退款订单数"),
    ]
    public function statistics(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $userId = $this->auth->id;

        // 统计各状态订单数量（排除藏品提货订单）
        $baseWhere = [
            ['user_id', '=', $userId],
            ['remark', 'not like', '藏品提货：%'],
        ];
        
        $allCount = Db::name('shop_order')->where($baseWhere)->count();
        $pendingCount = Db::name('shop_order')->where($baseWhere)->where('status', 'pending')->count();
        $paidCount = Db::name('shop_order')->where($baseWhere)->where('status', 'paid')->count();
        $shippedCount = Db::name('shop_order')->where($baseWhere)->where('status', 'shipped')->count();
        $completedCount = Db::name('shop_order')->where($baseWhere)->where('status', 'completed')->count();
        $cancelledCount = Db::name('shop_order')->where($baseWhere)->where('status', 'cancelled')->count();
        $refundedCount = Db::name('shop_order')->where($baseWhere)->where('status', 'refunded')->count();

        $this->success('', [
            'all_count' => $allCount,
            'pending_count' => $pendingCount,
            'paid_count' => $paidCount,
            'shipped_count' => $shippedCount,
            'completed_count' => $completedCount,
            'cancelled_count' => $cancelledCount,
            'refunded_count' => $refundedCount,
            'status_list' => [
                ['status' => 'all', 'text' => '全部', 'count' => $allCount],
                ['status' => 'pending', 'text' => '待支付', 'count' => $pendingCount],
                ['status' => 'paid', 'text' => '待发货', 'count' => $paidCount],
                ['status' => 'shipped', 'text' => '已发货', 'count' => $shippedCount],
                ['status' => 'completed', 'text' => '已完成', 'count' => $completedCount],
                ['status' => 'cancelled', 'text' => '已取消', 'count' => $cancelledCount],
                ['status' => 'refunded', 'text' => '已退款', 'count' => $refundedCount],
            ]
        ]);
    }

    #[
        Apidoc\Title("订单详情"),
        Apidoc\Tag("商城,订单"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/shopOrder/detail"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Query(name: "id", type: "int", require: true, desc: "订单ID"),
        Apidoc\Returned("balance_available", type: "string", desc: "用户可用余额"),
        Apidoc\Returned("score", type: "string", desc: "用户消费金"),
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

        $order = Db::name('shop_order')
            ->where('id', $id)
            ->where('user_id', $this->auth->id)
            ->where('remark', 'not like', '藏品提货：%') // 排除藏品提货订单
            ->find();

        if (!$order) {
            $this->error('订单不存在');
        }

        // 查询订单商品明细
        $order['items'] = Db::name('shop_order_item')
            ->where('order_id', $order['id'])
            ->select()
            ->toArray();

        // 处理图片URL和添加商品类型信息
        $hasPhysical = false;
        $hasVirtual = false;
        $hasCardProduct = false;
        
        foreach ($order['items'] as &$item) {
            $item['product_thumbnail'] = $item['product_thumbnail'] ? full_url($item['product_thumbnail'], false) : '';
            $item['price'] = (float)$item['price'];
            $item['subtotal'] = (float)$item['subtotal'];
            
            // 获取商品类型
            $product = Db::name('shop_product')
                ->where('id', $item['product_id'])
                ->field('is_physical, is_card_product')
                ->find();
            if ($product) {
                $item['is_physical'] = $product['is_physical'];
                $item['is_card_product'] = $product['is_card_product'] ?? '0';
                
                if ($product['is_physical'] == '1') {
                    $hasPhysical = true;
                } else {
                    $hasVirtual = true;
                    if (isset($product['is_card_product']) && $product['is_card_product'] == '1') {
                        $hasCardProduct = true;
                    }
                }
            }
        }

        // 设置订单的商品类型标识
        if ($hasPhysical && $hasVirtual) {
            $order['product_type'] = 'mixed'; // 混合订单
            $order['product_type_text'] = '混合订单';
        } elseif ($hasPhysical) {
            $order['product_type'] = 'physical'; // 实物商品
            $order['product_type_text'] = '实物商品';
        } elseif ($hasCardProduct) {
            $order['product_type'] = 'card'; // 卡密商品
            $order['product_type_text'] = '卡密商品';
        } else {
            $order['product_type'] = 'virtual'; // 虚拟商品
            $order['product_type_text'] = '虚拟商品';
        }

        $order['total_amount'] = (float)$order['total_amount'];
        
        // 状态文本
        $statusMap = [
            'pending' => '待支付',
            'paid' => '已支付',
            'shipped' => '已发货',
            'completed' => '已完成',
            'cancelled' => '已取消',
            'refunded' => '已退款',
        ];
        $order['status_text'] = $statusMap[$order['status']] ?? $order['status'];

        $payTypeMap = [
            'money' => '余额支付',
            'score' => '消费金支付',
            'combined' => '组合支付',
        ];
        $order['pay_type_text'] = $payTypeMap[$order['pay_type']] ?? $order['pay_type'];

        // 获取用户信息
        $userInfo = $this->auth->getUserInfo();

        $this->success('', array_merge($order, [
            'balance_available' => $userInfo['balance_available'] ?? '0.00',
            'score' => $userInfo['score'] ?? '0.00',
        ]));
    }

    #[
        Apidoc\Title("待发货订单列表"),
        Apidoc\Tag("商城,订单"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/shopOrder/pendingShip"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量", default: "10"),
        Apidoc\Returned("list", type: "array", desc: "待发货订单列表"),
        Apidoc\Returned("balance_available", type: "string", desc: "用户可用余额"),
        Apidoc\Returned("score", type: "string", desc: "用户消费金"),
    ]
    public function pendingShip(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $page = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 10);

        $where = [
            ['user_id', '=', $this->auth->id],
            ['status', 'in', ['paid', 'cancelled']], // 包含已支付和已取消的订单
            ['remark', 'not like', '藏品提货：%'], // 排除藏品提货订单
        ];

        $list = Db::name('shop_order')
            ->where($where)
            ->order('id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        foreach ($list as &$order) {
            $order['items'] = Db::name('shop_order_item')
                ->where('order_id', $order['id'])
                ->select()
                ->toArray();

            $hasPhysical = false;
            $hasVirtual = false;
            $hasCardProduct = false;
            
            foreach ($order['items'] as &$item) {
                $item['product_thumbnail'] = $item['product_thumbnail'] ? full_url($item['product_thumbnail'], false) : '';
                $item['price'] = (float)$item['price'];
                $item['subtotal'] = (float)$item['subtotal'];
                
                $product = Db::name('shop_product')
                    ->where('id', $item['product_id'])
                    ->field('is_physical, is_card_product')
                    ->find();
                if ($product) {
                    $item['is_physical'] = $product['is_physical'];
                    $item['is_card_product'] = $product['is_card_product'] ?? '0';
                    
                    if ($product['is_physical'] == '1') {
                        $hasPhysical = true;
                    } else {
                        $hasVirtual = true;
                        if (isset($product['is_card_product']) && $product['is_card_product'] == '1') {
                            $hasCardProduct = true;
                        }
                    }
                }
            }

            if ($hasPhysical && $hasVirtual) {
                $order['product_type'] = 'mixed';
                $order['product_type_text'] = '混合订单';
            } elseif ($hasPhysical) {
                $order['product_type'] = 'physical';
                $order['product_type_text'] = '实物商品';
            } elseif ($hasCardProduct) {
                $order['product_type'] = 'card';
                $order['product_type_text'] = '卡密商品';
            } else {
                $order['product_type'] = 'virtual';
                $order['product_type_text'] = '虚拟商品';
            }

            $order['total_amount'] = (float)$order['total_amount'];

            // 状态文本映射
            $statusMap = [
                'paid' => '待发货',
                'cancelled' => '用户已取消',
            ];
            $order['status_text'] = $statusMap[$order['status']] ?? $order['status'];

            $payTypeMap = [
                'money' => '余额支付',
                'score' => '消费金支付',
                'combined' => '组合支付',
            ];
            $order['pay_type_text'] = $payTypeMap[$order['pay_type']] ?? $order['pay_type'];
        }

        $total = Db::name('shop_order')
            ->where($where)
            ->count();

        // 获取用户信息
        $userInfo = $this->auth->getUserInfo();

        $this->success('', [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'balance_available' => $userInfo['balance_available'] ?? '0.00',
            'score' => $userInfo['score'] ?? '0.00',
        ]);
    }

    #[
        Apidoc\Title("待确认收货订单列表"),
        Apidoc\Tag("商城,订单"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/shopOrder/pendingConfirm"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量", default: "10"),
        Apidoc\Returned("list", type: "array", desc: "待确认收货订单列表"),
        Apidoc\Returned("balance_available", type: "string", desc: "用户可用余额"),
        Apidoc\Returned("score", type: "string", desc: "用户消费金"),
    ]
    public function pendingConfirm(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $page = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 10);

        $where = [
            ['user_id', '=', $this->auth->id],
            ['status', '=', 'shipped'],
            ['remark', 'not like', '藏品提货：%'], // 排除藏品提货订单
        ];

        $list = Db::name('shop_order')
            ->where($where)
            ->order('id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        foreach ($list as &$order) {
            $order['items'] = Db::name('shop_order_item')
                ->where('order_id', $order['id'])
                ->select()
                ->toArray();

            $hasPhysical = false;
            $hasVirtual = false;
            $hasCardProduct = false;
            
            foreach ($order['items'] as &$item) {
                $item['product_thumbnail'] = $item['product_thumbnail'] ? full_url($item['product_thumbnail'], false) : '';
                $item['price'] = (float)$item['price'];
                $item['subtotal'] = (float)$item['subtotal'];
                
                $product = Db::name('shop_product')
                    ->where('id', $item['product_id'])
                    ->field('is_physical, is_card_product')
                    ->find();
                if ($product) {
                    $item['is_physical'] = $product['is_physical'];
                    $item['is_card_product'] = $product['is_card_product'] ?? '0';
                    
                    if ($product['is_physical'] == '1') {
                        $hasPhysical = true;
                    } else {
                        $hasVirtual = true;
                        if (isset($product['is_card_product']) && $product['is_card_product'] == '1') {
                            $hasCardProduct = true;
                        }
                    }
                }
            }

            if ($hasPhysical && $hasVirtual) {
                $order['product_type'] = 'mixed';
                $order['product_type_text'] = '混合订单';
            } elseif ($hasPhysical) {
                $order['product_type'] = 'physical';
                $order['product_type_text'] = '实物商品';
            } elseif ($hasCardProduct) {
                $order['product_type'] = 'card';
                $order['product_type_text'] = '卡密商品';
            } else {
                $order['product_type'] = 'virtual';
                $order['product_type_text'] = '虚拟商品';
            }

            $order['total_amount'] = (float)$order['total_amount'];
            $order['status_text'] = '待确认收货';

            $payTypeMap = [
                'money' => '余额支付',
                'score' => '消费金支付',
                'combined' => '组合支付',
            ];
            $order['pay_type_text'] = $payTypeMap[$order['pay_type']] ?? $order['pay_type'];
        }

        $total = Db::name('shop_order')
            ->where($where)
            ->count();

        // 获取用户信息
        $userInfo = $this->auth->getUserInfo();

        $this->success('', [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'balance_available' => $userInfo['balance_available'] ?? '0.00',
            'score' => $userInfo['score'] ?? '0.00',
        ]);
    }

    #[
        Apidoc\Title("确认收货"),
        Apidoc\Tag("商城,订单"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/shopOrder/confirm"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Param(name: "id", type: "int", require: true, desc: "订单ID"),
    ]
    public function confirm(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $id = $this->request->param('id/d', 0);
        if (!$id) {
            $this->error('参数错误');
        }

        Db::startTrans();
        try {
            $order = Db::name('shop_order')
                ->where('id', $id)
                ->where('user_id', $this->auth->id)
                ->lock(true)
                ->find();

            if (!$order) {
                throw new \Exception('订单不存在');
            }

            if ($order['status'] != 'shipped') {
                throw new \Exception('只有已发货的订单才能确认收货');
            }

            Db::name('shop_order')
                ->where('id', $id)
                ->update([
                    'status' => 'completed',
                    'complete_time' => time(),
                    'update_time' => time(),
                ]);

            // 记录用户活动日志
            Db::name('user_activity_log')->insert([
                'user_id' => $this->auth->id,
                'related_user_id' => 0,
                'action_type' => 'shop_order_confirm',
                'change_field' => 'order_status',
                'change_value' => 'completed',
                'before_value' => 'shipped',
                'after_value' => 'completed',
                'remark' => '确认收货，订单完成',
                'extra' => json_encode([
                    'order_no' => $order['order_no'],
                    'order_id' => $id,
                    'complete_time' => time(),
                ], JSON_UNESCAPED_UNICODE),
                'create_time' => time(),
                'update_time' => time(),
            ]);

            Db::commit();
            $this->success('确认收货成功');
        } catch (HttpResponseException $e) {
            // success() 和 error() 抛出的 HttpResponseException，直接向上抛出，不做处理
            throw $e;
        } catch (\Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
    }

    #[
        Apidoc\Title("待付款订单列表"),
        Apidoc\Tag("商城,订单"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/shopOrder/pendingPay"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量", default: "10"),
        Apidoc\Returned("list", type: "array", desc: "待付款订单列表"),
    ]
    public function pendingPay(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $page = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 10);

        $where = [
            ['user_id', '=', $this->auth->id],
            ['status', '=', 'pending'],
            ['remark', 'not like', '藏品提货：%'], // 排除藏品提货订单
        ];

        $list = Db::name('shop_order')
            ->where($where)
            ->order('id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        foreach ($list as &$order) {
            $order['items'] = Db::name('shop_order_item')
                ->where('order_id', $order['id'])
                ->select()
                ->toArray();

            $hasPhysical = false;
            $hasVirtual = false;
            $hasCardProduct = false;
            
            foreach ($order['items'] as &$item) {
                $item['product_thumbnail'] = $item['product_thumbnail'] ? full_url($item['product_thumbnail'], false) : '';
                $item['price'] = (float)$item['price'];
                $item['subtotal'] = (float)$item['subtotal'];
                
                $product = Db::name('shop_product')
                    ->where('id', $item['product_id'])
                    ->field('is_physical, is_card_product')
                    ->find();
                if ($product) {
                    $item['is_physical'] = $product['is_physical'];
                    $item['is_card_product'] = $product['is_card_product'] ?? '0';
                    
                    if ($product['is_physical'] == '1') {
                        $hasPhysical = true;
                    } else {
                        $hasVirtual = true;
                        if (isset($product['is_card_product']) && $product['is_card_product'] == '1') {
                            $hasCardProduct = true;
                        }
                    }
                }
            }

            if ($hasPhysical && $hasVirtual) {
                $order['product_type'] = 'mixed';
                $order['product_type_text'] = '混合订单';
            } elseif ($hasPhysical) {
                $order['product_type'] = 'physical';
                $order['product_type_text'] = '实物商品';
            } elseif ($hasCardProduct) {
                $order['product_type'] = 'card';
                $order['product_type_text'] = '卡密商品';
            } else {
                $order['product_type'] = 'virtual';
                $order['product_type_text'] = '虚拟商品';
            }

            $order['total_amount'] = (float)$order['total_amount'];
            $order['status_text'] = '待付款';

            $payTypeMap = [
                'money' => '余额支付',
                'score' => '消费金支付',
                'combined' => '组合支付',
            ];
            $order['pay_type_text'] = $payTypeMap[$order['pay_type']] ?? $order['pay_type'];
        }

        $total = Db::name('shop_order')
            ->where($where)
            ->count();

        // 获取用户信息
        $userInfo = $this->auth->getUserInfo();

        $this->success('', [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'balance_available' => $userInfo['balance_available'] ?? '0.00',
            'score' => $userInfo['score'] ?? '0.00',
        ]);
    }

    #[
        Apidoc\Title("已完成订单列表"),
        Apidoc\Tag("商城,订单"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/shopOrder/completed"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name: "limit", type: "int", require: false, desc: "每页数量", default: "10"),
        Apidoc\Returned("list", type: "array", desc: "已完成订单列表"),
        Apidoc\Returned("balance_available", type: "string", desc: "用户可用余额"),
        Apidoc\Returned("score", type: "string", desc: "用户消费金"),
    ]
    public function completed(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $page = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 10);

        $where = [
            ['user_id', '=', $this->auth->id],
            ['status', '=', 'completed'],
        ];

        $list = Db::name('shop_order')
            ->where($where)
            ->order('id desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        foreach ($list as &$order) {
            $order['items'] = Db::name('shop_order_item')
                ->where('order_id', $order['id'])
                ->select()
                ->toArray();

            $hasPhysical = false;
            $hasVirtual = false;
            $hasCardProduct = false;
            
            foreach ($order['items'] as &$item) {
                $item['product_thumbnail'] = $item['product_thumbnail'] ? full_url($item['product_thumbnail'], false) : '';
                $item['price'] = (float)$item['price'];
                $item['subtotal'] = (float)$item['subtotal'];
                
                $product = Db::name('shop_product')
                    ->where('id', $item['product_id'])
                    ->field('is_physical, is_card_product')
                    ->find();
                if ($product) {
                    $item['is_physical'] = $product['is_physical'];
                    $item['is_card_product'] = $product['is_card_product'] ?? '0';
                    
                    if ($product['is_physical'] == '1') {
                        $hasPhysical = true;
                    } else {
                        $hasVirtual = true;
                        if (isset($product['is_card_product']) && $product['is_card_product'] == '1') {
                            $hasCardProduct = true;
                        }
                    }
                }
            }

            if ($hasPhysical && $hasVirtual) {
                $order['product_type'] = 'mixed';
                $order['product_type_text'] = '混合订单';
            } elseif ($hasPhysical) {
                $order['product_type'] = 'physical';
                $order['product_type_text'] = '实物商品';
            } elseif ($hasCardProduct) {
                $order['product_type'] = 'card';
                $order['product_type_text'] = '卡密商品';
            } else {
                $order['product_type'] = 'virtual';
                $order['product_type_text'] = '虚拟商品';
            }

            $order['total_amount'] = (float)$order['total_amount'];
            $order['status_text'] = '已完成';
            $order['complete_time_text'] = $order['complete_time'] ? date('Y-m-d H:i:s', (int)$order['complete_time']) : '';

            $payTypeMap = [
                'money' => '余额支付',
                'score' => '消费金支付',
                'combined' => '组合支付',
            ];
            $order['pay_type_text'] = $payTypeMap[$order['pay_type']] ?? $order['pay_type'];
        }

        $total = Db::name('shop_order')
            ->where($where)
            ->count();

        // 获取用户信息
        $userInfo = $this->auth->getUserInfo();

        $this->success('', [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'balance_available' => $userInfo['balance_available'] ?? '0.00',
            'score' => $userInfo['score'] ?? '0.00',
        ]);
    }

    #[
        Apidoc\Title("删除待支付订单"),
        Apidoc\Tag("商城,订单"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/shopOrder/delete"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Param(name: "order_id", type: "int", require: true, desc: "订单ID"),
        Apidoc\Returned("order_id", type: "int", desc: "已删除的订单ID"),
    ]
    public function delete(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $orderId = $this->request->param('order_id/d', 0);
        if (!$orderId) {
            $this->error('订单ID不能为空');
        }

        $userId = $this->auth->id;

        Db::startTrans();
        try {
            // 1. 查询订单并锁定
            $order = Db::name('shop_order')
                ->where('id', $orderId)
                ->where('user_id', $userId)
                ->lock(true)
                ->find();

            if (!$order) {
                throw new \Exception('订单不存在或无权操作');
            }

            // 2. 只能删除待支付状态的订单
            if ($order['status'] != 'pending') {
                throw new \Exception('只能删除待支付状态的订单');
            }

            // 记录用户活动日志
            Db::name('user_activity_log')->insert([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'shop_order_delete',
                'change_field' => 'order_status',
                'change_value' => 'deleted',
                'before_value' => 'pending',
                'after_value' => 'deleted',
                'remark' => '删除待支付订单',
                'extra' => json_encode([
                    'order_no' => $order['order_no'],
                    'order_id' => $orderId,
                    'total_amount' => $order['total_amount'],
                    'total_score' => $order['total_score'],
                    'pay_type' => $order['pay_type'],
                ], JSON_UNESCAPED_UNICODE),
                'create_time' => time(),
                'update_time' => time(),
            ]);

            // 3. 删除订单明细
            Db::name('shop_order_item')
                ->where('order_id', $orderId)
                ->delete();

            // 4. 删除订单
            Db::name('shop_order')
                ->where('id', $orderId)
                ->delete();

            Db::commit();

            $this->success('订单删除成功', [
                'order_id' => $orderId,
            ]);

        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Db::rollback();
            \think\facade\Log::error('删除订单失败', [
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'user_id' => $userId ?? 0,
                'order_id' => $orderId ?? 0,
            ]);
            $this->error($e->getMessage());
        }
    }
}

