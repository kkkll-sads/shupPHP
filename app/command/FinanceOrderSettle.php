<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 理财订单结算定时任务
 * 用于自动处理到期的理财订单，将收益发放给用户
 * 
 * 使用方法：
 * php think finance:settle
 * 
 * Crontab 配置示例（每小时执行一次）：
 * 0 * * * * cd /www/wwwroot/18.166.209.223 && php think finance:settle >> /tmp/finance_settle.log 2>&1
 */
class FinanceOrderSettle extends Command
{
    protected function configure()
    {
        $this->setName('finance:settle')
            ->setDescription('理财订单自动结算');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('开始处理到期理财订单...');
        
        $now = time();
        $processCount = 0;
        $successCount = 0;
        $errorCount = 0;

        try {
            // 查询所有到期且状态为"收益中"的订单（关联产品表以获取复利配置）
            $orders = Db::name('finance_order')
                ->alias('o')
                ->join('finance_product p', 'o.product_id = p.id')
                ->where('o.status', 'earning')
                ->where('JSON_EXTRACT(o.extra, "$.expire_time")', '<=', $now)
                ->field('o.*, p.compound_interest, p.id as product_id, p.name as product_name, p.cycle_days, p.yield_rate, p.status as product_status')
                ->select()
                ->toArray();

            $processCount = count($orders);
            $output->writeln("找到 {$processCount} 个需要结算的订单");

            foreach ($orders as $order) {
                try {
                    // 开启事务
                    Db::startTrans();

                    $userId = $order['user_id'];
                    $orderId = $order['id'];
                    $orderNo = $order['order_no'];
                    $amount = $order['amount'];
                    
                    $extra = json_decode($order['extra'], true);
                    $expectedIncome = $extra['expected_income'] ?? 0;
                    $productName = $order['product_name'] ?? ($extra['product_name'] ?? '理财产品');
                    $compoundInterest = $order['compound_interest'] ?? 0;
                    $productId = $order['product_id'];
                    $productStatus = $order['product_status'] ?? 0;

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

                    // 检查是否启用复利
                    if ($compoundInterest == 1 && $productStatus == 1) {
                        // 复利模式：本金+收益自动再投资
                        // 检查产品剩余额度
                        $product = Db::name('finance_product')
                            ->where('id', $productId)
                            ->lock(true)
                            ->find();
                        
                        if ($product) {
                            $remaining = $product['total_amount'] - $product['sold_amount'];
                            
                            if ($totalReturn <= $remaining) {
                                // 可以再投资，创建新的订单
                                $newOrderNo = 'FP' . date('YmdHis') . str_pad($userId, 6, '0', STR_PAD_LEFT) . rand(1000, 9999);
                                $newExpireTime = time() + ($product['cycle_days'] * 86400);
                                $newExpectedIncome = round($totalReturn * ($product['yield_rate'] / 100) * ($product['cycle_days'] / 365), 2);
                                
                                $newOrderId = Db::name('finance_order')->insertGetId([
                                    'order_no' => $newOrderNo,
                                    'user_id' => $userId,
                                    'product_id' => $productId,
                                    'quantity' => 1,
                                    'unit_price' => $totalReturn,
                                    'amount' => $totalReturn,
                                    'payment_channel' => 'compound',
                                    'yield_rate' => $product['yield_rate'],
                                    'status' => 'earning',
                                    'is_gift' => 0,
                                    'parent_order_id' => $orderId,
                                    'remark' => '复利再投资：' . $productName . '（来自订单：' . $orderNo . '）',
                                    'extra' => json_encode([
                                        'product_name' => $productName,
                                        'cycle_days' => $product['cycle_days'],
                                        'expected_income' => $newExpectedIncome,
                                        'expire_time' => $newExpireTime,
                                        'is_compound' => true,
                                        'parent_order_no' => $orderNo,
                                        'original_principal' => $amount,
                                        'original_income' => $expectedIncome,
                                    ]),
                                    'pay_time' => $now,
                                    'create_time' => $now,
                                    'update_time' => $now,
                                ]);
                                
                                // 更新产品已售金额
                                Db::name('finance_product')
                                    ->where('id', $productId)
                                    ->inc('sold_amount', $totalReturn)
                                    ->update();
                                
                                // 记录复利返息日志
                                Db::name('finance_income_log')->insert([
                                    'order_id' => $orderId,
                                    'user_id' => $userId,
                                    'product_id' => $productId,
                                    'income_type' => 'compound',
                                    'income_amount' => $totalReturn,
                                    'income_date' => date('Y-m-d'),
                                    'status' => 1,
                                    'settle_time' => $now,
                                    'remark' => "复利再投资：{$productName}，本金：{$amount}元，收益：{$expectedIncome}元，再投资金额：{$totalReturn}元",
                                    'create_time' => $now,
                                    'update_time' => $now,
                                ]);
                                
                                // 记录用户活动日志
                                Db::name('user_activity_log')->insert([
                                    'user_id' => $userId,
                                    'related_user_id' => 0,
                                    'action_type' => 'finance_compound',
                                    'change_field' => 'money',
                                    'change_value' => 0, // 余额不变，直接再投资
                                    'before_value' => $beforeMoney,
                                    'after_value' => $beforeMoney,
                                    'remark' => "复利再投资：{$productName}，再投资金额：{$totalReturn}元",
                                    'extra' => json_encode([
                                        'old_order_no' => $orderNo,
                                        'old_order_id' => $orderId,
                                        'new_order_no' => $newOrderNo,
                                        'new_order_id' => $newOrderId,
                                        'original_principal' => $amount,
                                        'original_income' => $expectedIncome,
                                        'compound_amount' => $totalReturn,
                                    ]),
                                    'create_time' => $now,
                                    'update_time' => $now,
                                ]);
                                
                                $output->writeln("✓ 订单 {$orderNo} 复利再投资成功，新订单号：{$newOrderNo}，再投资金额：{$totalReturn}元");
                            } else {
                                // 额度不足，返还本金+收益
                                $afterMoney = $beforeMoney + $totalReturn;
                                Db::name('user')
                                    ->where('id', $userId)
                                    ->update([
                                        'money' => $afterMoney,
                                    ]);
                                
                                $output->writeln("⚠ 订单 {$orderNo} 产品额度不足，已返还本金+收益：{$totalReturn}元");
                            }
                        } else {
                            // 产品不存在或已下架，返还本金+收益
                            $afterMoney = $beforeMoney + $totalReturn;
                            Db::name('user')
                                ->where('id', $userId)
                                ->update([
                                    'money' => $afterMoney,
                                ]);
                            
                            $output->writeln("⚠ 订单 {$orderNo} 产品不存在或已下架，已返还本金+收益：{$totalReturn}元");
                        }
                    } else {
                        // 非复利模式：返还本金+收益
                        $afterMoney = $beforeMoney + $totalReturn;
                        
                        // 1. 更新用户余额（返还本金 + 收益）
                        Db::name('user')
                            ->where('id', $userId)
                            ->update([
                                'money' => $afterMoney,
                            ]);

                        // 2. 记录余额变动日志
                        Db::name('user_money_log')->insert([
                            'user_id' => $userId,
                            'money' => $totalReturn,
                            'before' => $beforeMoney,
                            'after' => $afterMoney,
                            'memo' => "理财产品到期结算：{$productName}，订单号：{$orderNo}，本金：{$amount}元，收益：{$expectedIncome}元",
                            'create_time' => $now,
                        ]);

                        // 3. 记录用户活动日志
                        Db::name('user_activity_log')->insert([
                            'user_id' => $userId,
                            'related_user_id' => 0,
                            'action_type' => 'finance_settle',
                            'change_field' => 'money',
                            'change_value' => $totalReturn,
                            'before_value' => $beforeMoney,
                            'after_value' => $afterMoney,
                            'remark' => "理财产品到期结算：{$productName}",
                            'extra' => json_encode([
                                'order_no' => $orderNo,
                                'order_id' => $orderId,
                                'principal' => $amount,
                                'income' => $expectedIncome,
                                'total_return' => $totalReturn,
                            ]),
                            'create_time' => $now,
                            'update_time' => $now,
                        ]);
                        
                        $output->writeln("✓ 订单 {$orderNo} 结算成功，用户ID：{$userId}，本金：{$amount}，收益：{$expectedIncome}");
                    }

                    // 4. 更新订单状态为已完成
                    Db::name('finance_order')
                        ->where('id', $orderId)
                        ->update([
                            'status' => 'completed',
                            'complete_time' => $now,
                            'update_time' => $now,
                        ]);

                    // 提交事务
                    Db::commit();
                    $successCount++;

                } catch (\Exception $e) {
                    // 回滚事务
                    Db::rollback();
                    $errorCount++;
                    $output->writeln("✗ 订单 {$order['order_no']} 结算失败：" . $e->getMessage());
                }
            }

            $output->writeln("\n结算完成！");
            $output->writeln("总计：{$processCount} 个订单");
            $output->writeln("成功：{$successCount} 个");
            $output->writeln("失败：{$errorCount} 个");
            
            return 0;

        } catch (\Exception $e) {
            $output->writeln("处理异常：" . $e->getMessage());
            return 1;
        }
    }
}

