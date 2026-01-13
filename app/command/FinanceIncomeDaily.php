<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 每日返息定时任务
 * 用于自动发放每日返息收益
 * 
 * 使用方法：
 * php think finance:income:daily
 * 
 * Crontab 配置示例（每天凌晨1点执行）：
 * 0 1 * * * cd /www/wwwroot/18.166.209.223 && php think finance:income:daily >> /tmp/finance_income_daily.log 2>&1
 */
class FinanceIncomeDaily extends Command
{
    protected function configure()
    {
        $this->setName('finance:income:daily')
            ->setDescription('每日返息自动发放');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('开始处理每日返息...');
        
        $today = date('Y-m-d');
        $now = time();
        $processCount = 0;
        $successCount = 0;
        $errorCount = 0;

        try {
            // 查询所有收益中且产品为每日返息模式的订单
            $orders = Db::name('finance_order')
                ->alias('o')
                ->join('finance_product p', 'o.product_id = p.id')
                ->where('o.status', 'earning')
                ->where('p.income_mode', 'daily')
                ->where('p.status', 1)
                ->field('o.*, p.name as product_name, p.income_value_type, p.daily_income_value')
                ->select()
                ->toArray();

            $processCount = count($orders);
            $output->writeln("找到 {$processCount} 个需要发放每日返息的订单");

            foreach ($orders as $order) {
                try {
                    // 检查今天是否已经发放过
                    $todayLog = Db::name('finance_income_log')
                        ->where('order_id', $order['id'])
                        ->where('income_type', 'daily')
                        ->where('income_date', $today)
                        ->where('status', 1)
                        ->find();

                    if ($todayLog) {
                        $output->writeln("订单 {$order['order_no']} 今天已发放过，跳过");
                        continue;
                    }

                    // 检查订单是否已到期
                    $extra = json_decode($order['extra'], true);
                    $expireTime = $extra['expire_time'] ?? 0;
                    if ($expireTime > 0 && $expireTime < $now) {
                        $output->writeln("订单 {$order['order_no']} 已到期，跳过");
                        continue;
                    }

                    // 计算返息金额
                    $incomeAmount = $this->calculateIncome($order['amount'], $order['income_value_type'], $order['daily_income_value']);

                    if ($incomeAmount <= 0) {
                        $output->writeln("订单 {$order['order_no']} 返息金额为0，跳过");
                        continue;
                    }

                    // 开启事务
                    Db::startTrans();

                    // 获取用户信息并锁定
                    $user = Db::name('user')
                        ->where('id', $order['user_id'])
                        ->lock(true)
                        ->find();

                    if (!$user) {
                        throw new \Exception("用户不存在: {$order['user_id']}");
                    }

                    // 理财收益进入withdrawable_money（可提现余额）
                    $beforeWithdrawable = $user['withdrawable_money'];
                    $afterWithdrawable = $beforeWithdrawable + $incomeAmount;

                    // 1. 更新用户可提现余额
                    Db::name('user')
                        ->where('id', $order['user_id'])
                        ->update([
                            'withdrawable_money' => $afterWithdrawable,
                        ]);

                    // 2. 记录返息日志
                    $logId = Db::name('finance_income_log')->insertGetId([
                        'order_id' => $order['id'],
                        'user_id' => $order['user_id'],
                        'product_id' => $order['product_id'],
                        'income_type' => 'daily',
                        'income_amount' => $incomeAmount,
                        'income_date' => $today,
                        'status' => 1,
                        'settle_time' => $now,
                        'remark' => "每日返息：{$order['product_name']}",
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);

                    // 3. 记录余额变动日志（记录withdrawable_money的变化）
                    Db::name('user_money_log')->insert([
                        'user_id' => $order['user_id'],
                        'money' => $incomeAmount,
                        'before' => $beforeWithdrawable,
                        'after' => $afterWithdrawable,
                        'memo' => "每日返息（可提现余额）：{$order['product_name']}，订单号：{$order['order_no']}，返息金额：{$incomeAmount}元",
                        'create_time' => $now,
                    ]);

                    // 4. 记录用户活动日志
                    Db::name('user_activity_log')->insert([
                        'user_id' => $order['user_id'],
                        'related_user_id' => 0,
                        'action_type' => 'finance_income_daily',
                        'change_field' => 'withdrawable_money',
                        'change_value' => $incomeAmount,
                        'before_value' => $beforeWithdrawable,
                        'after_value' => $afterWithdrawable,
                        'remark' => "每日返息：{$order['product_name']}",
                        'extra' => json_encode([
                            'order_no' => $order['order_no'],
                            'order_id' => $order['id'],
                            'income_type' => 'daily',
                            'income_amount' => $incomeAmount,
                            'income_date' => $today,
                        ]),
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);

                    // 提交事务
                    Db::commit();
                    $successCount++;
                    
                    $output->writeln("✓ 订单 {$order['order_no']} 每日返息成功，用户ID：{$order['user_id']}，金额：{$incomeAmount}元");

                } catch (\Exception $e) {
                    // 回滚事务
                    Db::rollback();
                    $errorCount++;
                    $output->writeln("✗ 订单 {$order['order_no']} 每日返息失败：" . $e->getMessage());
                }
            }

            $output->writeln("\n每日返息处理完成！");
            $output->writeln("总计：{$processCount} 个订单");
            $output->writeln("成功：{$successCount} 个");
            $output->writeln("失败：{$errorCount} 个");
            
            return 0;

        } catch (\Exception $e) {
            $output->writeln("处理异常：" . $e->getMessage());
            return 1;
        }
    }

    /**
     * 计算返息金额
     * @param float $amount 投资金额
     * @param string $valueType 收益值类型：percent=百分比, fixed=固定金额
     * @param float $value 收益值
     * @return float
     */
    protected function calculateIncome(float $amount, string $valueType, float $value): float
    {
        if ($valueType === 'percent') {
            // 百分比：投资金额 * 百分比
            return round($amount * ($value / 100), 2);
        } else {
            // 固定金额
            return round($value, 2);
        }
    }
}

