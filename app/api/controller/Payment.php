<?php

namespace app\api\controller;

use Throwable;
use think\facade\Db;
use think\facade\Log;
use app\common\controller\Frontend;
use app\admin\model\CompanyPaymentAccount as CompanyPaymentAccountModel;
use app\admin\model\UserMoneyLog;
use app\common\model\UserActivityLog;
use think\exception\HttpResponseException;

class Payment extends Frontend
{
    protected array $noNeedLogin = [
        'dakaNotify', 'xiongmaoNotify', 'qipilangNotify', 'daka2Notify', 'zhiyangNotify',
        'shandianNotify', 'sanjianNotify',
    ];
    
    protected static $notifyUrl = 'http://23.248.226.82:5657/api/Payment/';
    
    //company_payment_account.id;  订单id;  订单类型(产品('order')/充值('recharge'))
    public static function startPayment($paymentId, $orderId, $orderType)
    {
        try {
            $payment = $order = $pay_url = null;
            if ($orderId == 'test') {
                $payment = Db::name('company_payment_account')->where('id', $paymentId)->find();
            } else {
                $payment = Db::name('company_payment_account')->where('id', $paymentId)->where('status', 1)->find();
            }
            if ($orderId == 'test') {
                $orderType = 'test';
                $order = [
                    'order_no' => 'RC' . date('YmdHis') . rand(1000, 9999),
                    'amount' => 100
                ];
            }else if ($orderType == 'order') {
                $order = Db::name('shop_order')->where('id', $orderId)->where('status', 'pending')->find();
            } else {
                $order = Db::name('recharge_order')->where('id', $orderId)->where('status', 0)->find();
            }
            if (empty($payment)) {
                throw new \Exception('未找到支付方式');
            }
            if (empty($order)) {
                throw new \Exception('未找到订单');
            }
            $amount = $order['amount'] ?? $order['total_amount'];
            $order_no = $order['order_no'];
            $code = $payment['account_number'];
            if ($payment['bank_name'] == '大咖支付') {
                $pay_url = self::dakaPay($order_no, $amount, $orderType, $code);
            } else if ($payment['bank_name'] == '大咖支付2') {
                $pay_url = self::daka2Pay($order_no, $amount, $orderType, $code);
            } else if ($payment['bank_name'] == '熊猫支付') {
                $pay_url = self::xiongmaoPay($order_no, $amount, $orderType, $code);
            } else if ($payment['bank_name'] == '七匹狼支付') {
                $pay_url = self::qipilangPay($order_no, $amount, $orderType, $code);
            } else if ($payment['bank_name'] == '智阳支付') {
                $pay_url = self::zhiyangPay($order_no, $amount, $orderType, $code);
            } else if ($payment['bank_name'] == '乐乐支付') {
                $pay_url = self::lelePay($order_no, $amount, $orderType, $code);
            } else if ($payment['bank_name'] == '闪电支付') {
                $pay_url = self::shandianPay($order_no, $amount, $orderType, $code);
            } else if ($payment['bank_name'] == '三剑支付') {
                $pay_url = self::sanjianPay($order_no, $amount, $orderType, $code);
            }
            if ($pay_url) {
                return ['code'=> 0, 'data'=> $pay_url, 'order'=> $order, 'orderType' => $orderType, 'cannelId' => $code, 'cannelName' => $payment['bank_name']];
            } else {
                throw new \Exception('未获取到支付地址');
            }
        } catch (\Exception $e) {
            return ['code'=> 1, 'data'=> $e->__toString()];
        }
    }
    
    ////////////////////////////// 三剑支付 //////////////////////////////
    public static function sanjianPay($order_no, $amount, $orderType, $code)
    {
        $signBody = [];
        $signBody['mchid'] = '80372';
        $signBody['version'] = '1.0.1';
        $signBody['out_trade_no'] = $order_no.'-'.$orderType;
        $signBody['amount'] = $amount;
        $signBody['notify_url'] = self::$notifyUrl.'sanjianNotify';
        $signBody['return_url'] = self::$notifyUrl.'sanjianNotify';
        $signBody['time_stamp'] = date('YmdHis');
        $signBody['channel'] = $code;
        $signBody['body'] = '王老吉';
        $signBody['ext_code'] = 10086;
        $signBody['sign'] = self::generateSign($signBody, 'JkRdx03adJMmZKAowJk0tRTb8Rtl4Ot0jm6umnc5', false);
        $result = self::http_curl('https://gateway.ssjpay.com/api/payOrder/v2/create', $signBody, 'form', '三剑支付');
        if (!isset($result['code']) || $result['code'] != 0) {
            return null;
        }
        return $result['data']['request_url'] ?? '';
    }
    
    public function sanjianNotify()
    {
        $params = $this->getInputParam();
        if (!empty($params['order_status']) && ($params['order_status'] == 1 || $params['order_status'] == 8)) {
            $parts = explode('-', $params['out_trade_no']);
            $order_sn = $parts[0];
            $orderType = $parts[1];
            $result = $this->hanldNotify($order_sn, $orderType, 'SUCCESS');
            echo $result;
        } else {
            echo 'fail';
        }
    }
    ////////////////////////////// 三剑支付 END //////////////////////////////
    
    ////////////////////////////// 闪电支付 //////////////////////////////
    public static function shandianPay($order_no, $amount, $orderType, $code)
    {
        $signBody = [];
        $signBody['merchant_no'] = '9476505220';
        $signBody['order_sn'] = $order_no.'-'.$orderType;
        $signBody['order_amount'] = $amount;
        $signBody['notify_url'] = self::$notifyUrl.'shandianNotify';
        $signBody['code'] = $code;
        $signBody['sign'] = self::generateSign($signBody, '68270ac9ce5953f6102aa27c71789865', true);
        $result = self::http_curl('https://admin.okgkuc.top/api/pay', $signBody, 'form', '闪电支付');
        if (!isset($result['code']) || $result['code'] != 0) {
            return null;
        }
        return $result['data']['pay_url'] ?? '';
    }
    
    public function shandianNotify()
    {
        $params = $this->getInputParam();
        if (!empty($params['order_status']) && ($params['order_status'] == 1 || $params['order_status'] == 8)) {
            $parts = explode('-', $params['order_sn']);
            $order_sn = $parts[0];
            $orderType = $parts[1];
            $result = $this->hanldNotify($order_sn, $orderType, 'SUCCESS');
            echo $result;
        } else {
            echo 'fail';
        }
    }
    ////////////////////////////// 闪电支付 END //////////////////////////////
    
    ////////////////////////////// 智阳支付 //////////////////////////////
    public static function zhiyangPay($order_no, $amount, $orderType, $code)
    {
        $signBody = [];
        $signBody['userCode'] = '100040';
        $signBody['orderId'] = $order_no.'-'.$orderType;
        $signBody['channelCode'] = $code;
        $signBody['callbackUrl'] = self::$notifyUrl.'zhiyangNotify';
        $signBody['orderMoney'] = $amount;
        $signBody['sign'] = self::generateSign($signBody, 'cd7beb9cd601c2f6647a632731bcf823', false);
        $result = self::http_curl('http://13.114.244.98/zy/apis/pay/get', $signBody, 'form', '智阳支付');
        if (!isset($result['code']) || $result['code'] != 200) {
            return null;
        }
        return $result['result'] ?? '';
    }
    
    public function zhiyangNotify()
    {
        $params = $this->getInputParam();
        if (!empty($params['orderStatus']) && $params['orderStatus'] == 2) {
            $parts = explode('-', $params['orderId']);
            $order_sn = $parts[0];
            $orderType = $parts[1];
            $result = $this->hanldNotify($order_sn, $orderType, 'success');
            echo $result;
        } else {
            echo 'fail';
        }
    }
    ////////////////////////////// 智阳支付 END //////////////////////////////
    
    ////////////////////////////// 智阳支付2 //////////////////////////////
    public static function lelePay($order_no, $amount, $orderType, $code)
    {
        $signBody = [];
        $signBody['userCode'] = '100072';
        $signBody['orderId'] = $order_no.'-'.$orderType;
        $signBody['channelCode'] = $code;
        $signBody['callbackUrl'] = self::$notifyUrl.'zhiyangNotify';
        $signBody['orderMoney'] = $amount;
        $signBody['sign'] = self::generateSign($signBody, 'd20b24fce10cc84db62daacf6b374d9c', false);
        $result = self::http_curl('http://52.196.205.134/zjdr/apis/pay/get', $signBody, 'form', '乐乐支付');
        if (!isset($result['code']) || $result['code'] != 200) {
            return null;
        }
        return $result['result'] ?? '';
    }
    ////////////////////////////// 智阳支付2 END //////////////////////////////
    
    ////////////////////////////// 大咖支付 //////////////////////////////
    public static function dakaPay($order_no, $amount, $orderType, $code)
    {
        $signBody = [];
        $signBody['mchId'] = '1112';
        $signBody['outTradeNo'] = $order_no.'-'.$orderType;
        $signBody['productId'] = $code;
        $signBody['notifyUrl'] = self::$notifyUrl.'dakaNotify';
        $signBody['returnUrl'] = self::$notifyUrl.'dakaNotify';
        $signBody['amount'] = $amount * 100;
        $signBody['reqTime'] = time() * 1000;
        $signBody['sign'] = self::generateSign($signBody, 'jkkBS6nES3mMNA28Eq23c3q', true);
        $result = self::http_curl('https://jkapi-longzf.deepding.com/api/v1/pay/unifiedOrder', $signBody, 'json', '大咖支付');
        if (!isset($result['code']) || ($result['code'] != 0 && $result['code'] != '0')) {
            return null;
        }
        return $result['data']['payUrl'] ?? '';
    }
    
    public function dakaNotify()
    {
        $params = $this->getInputParam();
        if (!empty($params['state']) && $params['state'] == 1) {
            $parts = explode('-', $params['outTradeNo']);
            $order_sn = $parts[0];
            $orderType = $parts[1];
            $result = $this->hanldNotify($order_sn, $orderType, 'SUCCESS');
            echo $result;
        } else {
            echo 'fail';
        }
    }
    ////////////////////////////// 大咖支付 END //////////////////////////////
    
    ////////////////////////////// 大咖支付2 //////////////////////////////
    public static function daka2Pay($order_no, $amount, $orderType, $code)
    {
        $signBody = [];
        $signBody['appId'] = 'be049c9a294e2040e3564b69ce5ce78f';
        $signBody['tradeType'] = $code;
        $signBody['orderId'] = $order_no;
        $signBody['amount'] = $amount;
        $signBody['remark'] = $orderType;
        $signBody['notifyUrl'] = self::$notifyUrl.'daka2Notify';
        $signBody['random'] = self::get_random_code(32);
        $signBody['sign'] = self::generateSign2($signBody, '2a686b1d3857e97521c8a385591f7234', true);
        $result = self::http_curl('http://cf.casher.top/rest/mall/payment/order', $signBody, 'json', '大咖支付2');
        return $result['qrcode'] ?? '';
    }
    
    public function daka2Notify()
    {
        $params = $this->getInputParam();
        $orderType = $params['remark'];
        $signBody = [
            'appId' => 'be049c9a294e2040e3564b69ce5ce78f',
            'orderId' => $params['orderId'],
            'tradeNo' => $params['tradeNo'],
        ];
        $signBody['random'] = self::get_random_code(32);
        $signBody['sign'] = self::generateSign2($signBody, '2a686b1d3857e97521c8a385591f7234', true);
        $result = self::http_curl('http://cf.casher.top/rest/mall/payment/query', $signBody, 'json', '大咖支付2回调订单验证');
        $result_status = $result['status'] ?? 100;
        if ($result_status == 0) {
            $result = $this->hanldNotify($params['orderId'], $orderType, 'success');
            echo $result;
        } else {
            echo 'fail';
        }
    }
    ////////////////////////////// 大咖支付2 END //////////////////////////////
    
    ////////////////////////////// 熊猫支付 //////////////////////////////
    public static function xiongmaoPay($order_no, $amount, $orderType, $code)
    {
        $signBody = [];
        $signBody['userCode'] = '100480';
        $signBody['channelCode'] = $code;
        $signBody['orderId'] = $order_no.'-'.$orderType;
        $signBody['orderMoney'] = $amount;
        $signBody['callbackUrl'] = self::$notifyUrl.'xiongmaoNotify';
        $signBody['sign'] = self::generateSign($signBody, 'd0f28285cb8e0acfc25a62a3dbc71993');
        $result = self::http_curl('https://xm53mksf233fd.top/agency/apis/pay/get', $signBody, 'form', '熊猫支付');
        if (!isset($result['code']) || ($result['code'] != 200 && $result['code'] != '200')) {
            return null;
        }
        return $result['result'] ?? '';
    }
    
    public function xiongmaoNotify()
    {
        $params = $this->getInputParam();
        if (!empty($params['orderStatus']) && $params['orderStatus'] == 2) {
            $parts = explode('-', $params['orderId']);
            $order_sn = $parts[0];
            $orderType = $parts[1];
            $result = $this->hanldNotify($order_sn, $orderType, 'success');
            echo $result;
        } else {
            echo 'fail';
        }
    }
    ////////////////////////////// 熊猫支付 END //////////////////////////////
    
    ////////////////////////////// 七匹狼支付 //////////////////////////////
    public static function qipilangPay($order_no, $amount, $orderType, $code)
    {
        $signBody = [];
        $signBody['userCode'] = '100542';
        $signBody['channelCode'] = $code;
        $signBody['orderId'] = $order_no.'-'.$orderType;
        $signBody['orderMoney'] = $amount;
        $signBody['callbackUrl'] = self::$notifyUrl.'qipilangNotify';
        $signBody['sign'] = self::generateSign($signBody, '77c27f9c9766abe1ce62163613a5074b');
        $result = self::http_curl('https://qpl3vg6fo4fsd.top/agency/apis/pay/get', $signBody, 'form', '七匹狼支付');
        if (!isset($result['code']) || ($result['code'] != 200 && $result['code'] != '200')) {
            return null;
        }
        return $result['result'] ?? '';
    }
    
    public function qipilangNotify()
    {
        $params = $this->getInputParam();
        if (!empty($params['orderStatus']) && $params['orderStatus'] == 2) {
            $parts = explode('-', $params['orderId']);
            $order_sn = $parts[0];
            $orderType = $parts[1];
            $result = $this->hanldNotify($order_sn, $orderType, 'success');
            echo $result;
        } else {
            echo 'fail';
        }
    }
    ////////////////////////////// 七匹狼支付 END //////////////////////////////
    
    ////////////////////////////// 回调统一处理订单 //////////////////////////////
    public function hanldNotify($order_sn, $orderType, $return)
    {
        Log::info('回调订单:' . json_encode([
            'order_sn'=> $order_sn,
            'orderType'=> $orderType,
        ]) );

        if ($orderType == 'order') {
            // 处理产品订单支付到账
            try {
                Db::startTrans();

                // 查找产品订单
                $order = Db::name('shop_order')
                    ->where('order_no', $order_sn)
                    ->lock(true)
                    ->find();

                if (!$order) {
                    Log::error('产品订单不存在: ' . $order_sn);
                    Db::rollback();
                    return 'fail';
                }

                if ($order['status'] == 'paid' || $order['status'] == 'completed') {
                    Log::info('产品订单已处理: ' . $order_sn);
                    Db::rollback();
                    return $return;
                }

                if ($order['status'] != 'pending') {
                    Log::error('产品订单状态不正确: ' . $order_sn . ' status:' . $order['status']);
                    Db::rollback();
                    return 'fail';
                }

                // 查询订单商品明细，判断订单类型
                $orderItems = Db::name('shop_order_item')
                    ->where('order_id', $order['id'])
                    ->select()
                    ->toArray();

                $hasPhysical = false;
                $hasCardProduct = false;
                foreach ($orderItems as $item) {
                    $product = Db::name('shop_product')
                        ->where('id', $item['product_id'])
                        ->field('is_physical, is_card_product, status')
                        ->find();
                    if ($product) {
                        // 检查商品是否已上架
                        if ($product['status'] != '1') {
                            Log::error('商品未上架: ' . $item['product_id']);
                            Db::rollback();
                            return 'fail';
                        }
                        if ($product['is_physical'] == '1') {
                            $hasPhysical = true;
                        }
                        if (isset($product['is_card_product']) && $product['is_card_product'] == '1') {
                            $hasCardProduct = true;
                        }
                    }
                }

                // 扣减库存，增加销量
                foreach ($orderItems as $item) {
                    // 先查询当前库存
                    $product = Db::name('shop_product')
                        ->where('id', $item['product_id'])
                        ->field('stock')
                        ->find();

                    if (!$product) {
                        Log::error('商品不存在: ' . $item['product_id']);
                        Db::rollback();
                        return 'fail';
                    }

                    if ($product['stock'] < $item['quantity']) {
                        Log::error('商品库存不足: ' . $item['product_id'] . ' 库存:' . $product['stock'] . ' 需要:' . $item['quantity']);
                        Db::rollback();
                        return 'fail';
                    }

                    // 扣减库存并增加销量
                    Db::name('shop_product')
                        ->where('id', $item['product_id'])
                        ->dec('stock', $item['quantity'])
                        ->inc('sales', $item['quantity'])
                        ->update();

                    // 若扣减后库存 <= 0 则自动下架
                    $newStock = (int)$product['stock'] - (int)$item['quantity'];
                    if ($newStock <= 0) {
                        Db::name('shop_product')->where('id', $item['product_id'])->update(['status' => '0', 'update_time' => time()]);
                    }
                }

                // 更新订单状态
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
                    ->where('id', $order['id'])
                    ->update([
                        'status' => $orderStatus,
                        'pay_time' => time(),
                        'complete_time' => $completeTime,
                        'update_time' => time(),
                    ]);

                // 记录用户活动日志
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
                        'total' => $item['total'],
                    ];
                }, $orderItems);

                UserActivityLog::create([
                    'user_id' => $order['user_id'],
                    'related_user_id' => 0,
                    'action_type' => 'shop_order',
                    'change_field' => 'status',
                    'change_value' => $orderStatus,
                    'before_value' => $order['status'],
                    'after_value' => $orderStatus,
                    'remark' => '在线支付完成，订单号：' . $order_sn . '，商品：' . $productNamesStr,
                    'extra' => [
                        'order_id' => $order['id'],
                        'order_no' => $order_sn,
                        'total_amount' => $order['total_amount'],
                        'pay_type' => $order['pay_type'],
                        'products' => $productsDetail,
                        'has_physical' => $hasPhysical,
                        'has_card_product' => $hasCardProduct,
                        'payment_type' => 'online',
                        'payment_method' => 'online_payment_callback',
                        'source' => 'online_payment_callback',
                    ],
                ]);

                Db::commit();
                Log::info('产品订单处理成功: ' . $order_sn);
                return $return;

            } catch (Throwable $e) {
                Db::rollback();
                Log::error('产品订单处理失败: ' . $order_sn . ', 错误: ' . $e->getMessage());
                return 'fail';
            }
        } else if ($orderType == 'recharge') {
            // 处理充值订单到账
            try {
                Db::startTrans();

                // 查找充值订单
                $order = Db::name('recharge_order')
                    ->where('order_no', $order_sn)
                    ->lock(true)
                    ->find();

                if (!$order) {
                    Log::error('充值订单不存在: ' . $order_sn);
                    Db::rollback();
                    return 'fail';
                }

                if ($order['status'] == 1) {
                    Log::info('充值订单已处理: ' . $order_sn);
                    Db::rollback();
                    return $return;
                }

                if ($order['status'] != 0) {
                    Log::error('充值订单状态不正确: ' . $order_sn . ' status:' . $order['status']);
                    Db::rollback();
                    return 'fail';
                }

                // 更新订单状态为已通过
                Db::name('recharge_order')
                    ->where('id', $order['id'])
                    ->update([
                        'status' => 1, // 已通过
                        'audit_admin_id' => 0, // 系统自动审核
                        'audit_time' => time(),
                        'audit_remark' => '线上支付自动到账',
                        'update_time' => time(),
                    ]);

                // 增加用户余额
                $user = Db::name('user')->where('id', $order['user_id'])->lock(true)->find();
                if (!$user) {
                    Log::error('用户不存在: ' . $order['user_id']);
                    Db::rollback();
                    return 'fail';
                }

                $beforeBalance = (float)$user['balance_available'];
                $beforeMoney = (float)$user['money'];
                $amount = (float)$order['amount'];
                $afterBalance = $beforeBalance + $amount;
                $afterMoney = $beforeMoney + $amount;

                $updateData = [
                    'balance_available' => $afterBalance,
                    'money' => $afterMoney,
                    'update_time' => time(),
                ];

                // 检查并赠送算力（根据活动配置）
                $rewardPowerRate = (float)Db::name('config')
                    ->where('name', 'recharge_reward_power_rate')
                    ->where('group', 'activity_reward')
                    ->value('value', 0);
                
                $rewardPower = 0;
                if ($rewardPowerRate > 0) {
                    $rewardPower = round($amount * $rewardPowerRate / 100, 2);
                    if ($rewardPower > 0) {
                        $beforeGreenPower = (float)($user['green_power'] ?? 0);
                        $afterGreenPower = round($beforeGreenPower + $rewardPower, 2);
                        $updateData['green_power'] = $afterGreenPower;
                    }
                }

                // 更新用户余额
                Db::name('user')
                    ->where('id', $order['user_id'])
                    ->update($updateData);

                // 记录资金变动日志
                $moneyLog = new UserMoneyLog();
                $moneyLog->user_id = $order['user_id'];
                $moneyLog->money = $amount;
                $moneyLog->before = $beforeBalance;
                $moneyLog->after = $afterBalance;
                $moneyLog->memo = '线上充值到账：' . $order_sn;
                $moneyLog->save();

                // 记录用户活动日志
                UserActivityLog::create([
                    'user_id' => $order['user_id'],
                    'related_user_id' => 0,
                    'action_type' => 'balance',
                    'change_field' => 'balance_available',
                    'change_value' => (string)$amount,
                    'before_value' => (string)$beforeBalance,
                    'after_value' => (string)$afterBalance,
                    'remark' => '线上充值到账，订单号：' . $order_sn,
                    'extra' => [
                        'order_id' => $order['id'],
                        'order_no' => $order_sn,
                        'amount' => (string)$amount,
                        'payment_type' => 'online',
                        'payment_method' => 'online',
                        'source' => 'online_payment_callback',
                    ],
                ]);

                // 如果赠送了算力，记录算力变动日志和活动日志
                if ($rewardPower > 0) {
                    $beforeGreenPower = (float)($user['green_power'] ?? 0);
                    $afterGreenPower = round($beforeGreenPower + $rewardPower, 2);
                    $now = time();
                    
                    // 记录算力变动日志
                    Db::name('user_money_log')->insert([
                        'user_id' => $order['user_id'],
                        'field_type' => 'green_power',
                        'money' => $rewardPower,
                        'before' => $beforeGreenPower,
                        'after' => $afterGreenPower,
                        'memo' => '充值奖励-绿色算力：订单号 ' . $order_sn,
                        'flow_no' => generateSJSFlowNo($order['user_id']),
                        'batch_no' => generateBatchNo('RECHARGE_REWARD', $order['id']),
                        'biz_type' => 'recharge_reward',
                        'biz_id' => $order['id'],
                        'create_time' => $now,
                    ]);

                    // 记录活动日志
                    UserActivityLog::create([
                        'user_id' => $order['user_id'],
                        'related_user_id' => 0,
                        'action_type' => 'recharge_reward',
                        'change_field' => 'green_power',
                        'change_value' => (string)$rewardPower,
                        'before_value' => (string)$beforeGreenPower,
                        'after_value' => (string)$afterGreenPower,
                        'remark' => '充值奖励-绿色算力：+' . $rewardPower . '（充值金额：' . $amount . '元，奖励比例：' . $rewardPowerRate . '%）',
                        'extra' => [
                            'order_id' => $order['id'],
                            'order_no' => $order_sn,
                            'amount' => (string)$amount,
                            'reward_power_rate' => (string)$rewardPowerRate,
                            'reward_power' => (string)$rewardPower,
                            'source' => 'online_payment_callback',
                        ],
                    ]);
                }

                Db::commit();
                Log::info('充值订单处理成功: ' . $order_sn);
                return $return;

            } catch (Throwable $e) {
                Db::rollback();
                Log::error('充值订单处理失败: ' . $order_sn . ', 错误: ' . $e->getMessage());
                return 'fail';
            }
        } else if ($orderType == 'test') {
            return $return;
        }
        return 'fail';
    }
    ////////////////////////////// 回调统一处理订单 END //////////////////////////////
    
    public function getInputParam($key = null, $default = null)
    {
        $contentType = $this->request->contentType();
        if (stripos($contentType, 'application/json') !== false) {
            $rawData = $this->request->getInput();
            $data = json_decode($rawData, true) ?: [];
        } else {
            $data = $this->request->post();
        }
        return $data;
    }
    
    public static function generateSign($signBody, $signkey, $strtoupper = false, $joinKey = true)
    {
        $data = array_filter($signBody);
        ksort($data);
        $tmp_string = http_build_query($data);
        $tmp_string = urldecode($tmp_string);
        $tmp_string = $tmp_string . ($joinKey ? '&key=' : '&') . $signkey;
        if ($strtoupper) {
            return strtoupper(md5( $tmp_string ));
        } else {
            return md5( $tmp_string );
        }
    }
    
    public static function generateSign2($signBody, $signkey, $strtoupper = false)
    {
        $signBody['key'] = $signkey;
        $data = array_filter($signBody);
        ksort($data);
        $tmp_string = http_build_query($data);
        $tmp_string = urldecode($tmp_string);
        if ($strtoupper) {
            return strtoupper(md5( $tmp_string ));
        } else {
            return md5( $tmp_string );
        }
    }
    
    public static function http_curl($url, $data = [], $type = 'json', $remark='')
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        if ($type === 'json') {
            $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload)
            ]);
        } else { // form
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/x-www-form-urlencoded'
            ]);
        }
        $response = curl_exec($ch);
        $response = json_decode($response, true);
        $error = curl_error($ch);
        curl_close($ch);
        if (!empty($remark)) {
            Log::info($remark.'请求数据:' . json_encode($data));
            Log::info($remark.'返回数据:' . json_encode($response));
        }
        if ($error) {
            return ['error' => $error];
        }
        return $response;
    }
    
    public static function get_random_code($num)
    {
        $codeSeeds = "1234567890";
        $len = strlen($codeSeeds);
        $ban_num = ($num / 2) - 3;
        $code = "";
        for ($i = 0; $i < $num; $i++) {
            $rand = rand(0, $len - 1);
            if ($i == $ban_num) {
                $code .= 'O';
            } else {
                $code .= $codeSeeds[$rand];
            }
        }
        return $code;
    }
    
    
    
    
    
    
    
    
    
    
}