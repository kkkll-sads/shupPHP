<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 阶段返息定时任务
 * 用于自动发放阶段返息收益
 * 
 * 使用方法：
 * php think finance:income:stage
 * 
 * Crontab 配置示例（每天凌晨3点执行）：
 * 0 3 * * * cd /www/wwwroot/18.166.209.223 && php think finance:income:stage >> /tmp/finance_income_stage.log 2>&1
 */
class FinanceIncomeStage extends Command
{
    protected function configure()
    {
        $this->setName('finance:income:stage')
            ->setDescription('阶段返息自动发放');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('开始处理阶段返息...');
        
        $today = date('Y-m-d');
        $now = time();
        $processCount = 0;
        $successCount = 0;
        $errorCount = 0;

        try {
            // 查询所有收益中且产品为阶段返息模式的订单
            $orders = Db::name('finance_order')
                ->alias('o')
                ->join('finance_product p', 'o.product_id = p.id')
                ->where('o.status', 'earning')
                ->where('p.income_mode', 'stage')
                ->where('p.status', 1)
                ->field('o.*, p.name as product_name, p.stage_income_config')
                ->select()
                ->toArray();

            $processCount = count($orders);
            $output->writeln("找到 {$processCount} 个需要检查阶段返息的订单");

            foreach ($orders as $order) {
                try {
                    // 检查订单是否已到期
                    $extra = json_decode($order['extra'], true);
                    $expireTime = $extra['expire_time'] ?? 0;
                    if ($expireTime > 0 && $expireTime < $now) {
                        $output->writeln("订单 {$order['order_no']} 已到期，跳过");
                        continue;
                    }

                    // 解析阶段配置
                    $stageConfig = json_decode($order['stage_income_config'], true);
                    if (!$stageConfig || !is_array($stageConfig) || empty($stageConfig)) {
                        $output->writeln("订单 {$order['order_no']} 阶段配置无效，跳过");
                        continue;
                    }

                    // 计算订单开始日期（支付日期）
                    $payDate = $order['pay_time'] ? date('Y-m-d', $order['pay_time']) : date('Y-m-d', $order['create_time']);
                    $payTimestamp = strtotime($payDate);

                    // 计算当前是第几天
                    $daysDiff = floor(($now - $payTimestamp) / 86400) + 1;

                    // 查找当前应该发放的阶段
                    $currentStage = null;
                    foreach ($stageConfig as $stage) {
                        $start = (int)($stage['start'] ?? 0);
                        $end = (int)($stage['end'] ?? 0);
                        
                        if ($daysDiff >= $start && $daysDiff <= $end) {
                            $currentStage = $stage;
                            break;
                        }
                    }

                    if (!$currentStage) {
                        $output->writeln("订单 {$order['order_no']} 第{$daysDiff}天不在任何阶段范围内，跳过");
                        continue;
                    }

                    // 检查这个阶段今天是否已经发放过
                    $stageInfo = "{$currentStage['start']}-{$currentStage['end']}";
                    $todayStageLog = Db::name('finance_income_log')
                        ->where('order_id', $order['id'])
                        ->where('income_type', 'stage')
                        ->where('stage_info', $stageInfo)
                        ->where('income_date', $today)
                        ->where('status', 1)
                        ->find();

                    if ($todayStageLog) {
                        $output->writeln("订单 {$order['order_no']} 阶段{$stageInfo}今天已发放，跳过");
                        continue;
                    }

                    // 计算返息金额
                    $valueType = $currentStage['type'] ?? 'percent';
                    $value = (float)($currentStage['value'] ?? 0);
                    $incomeAmount = $this->calculateIncome($order['amount'], $valueType, $value);

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

                    $beforeMoney = $user['money'];
                    $afterMoney = $beforeMoney + $incomeAmount;

                    // 1. 更新用户余额
                    Db::name('user')
                        ->where('id', $order['user_id'])
                        ->update([
                            'money' => $afterMoney,
                        ]);

                    // 2. 记录返息日志
                    $logId = Db::name('finance_income_log')->insertGetId([
                        'order_id' => $order['id'],
                        'user_id' => $order['user_id'],
                        'product_id' => $order['product_id'],
                        'income_type' => 'stage',
                        'income_amount' => $incomeAmount,
                        'income_date' => $today,
                        'stage_info' => $stageInfo,
                        'status' => 1,
                        'settle_time' => $now,
                        'remark' => "阶段返息：{$order['product_name']}，第{$daysDiff}天（{$stageInfo}天）" . ($currentStage['description'] ?? ''),
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);

                    // 3. 记录余额变动日志
                    Db::name('user_money_log')->insert([
                        'user_id' => $order['user_id'],
                        'money' => $incomeAmount,
                        'before' => $beforeMoney,
                        'after' => $afterMoney,
                        'memo' => "阶段返息：{$order['product_name']}，订单号：{$order['order_no']}，第{$daysDiff}天（{$stageInfo}天），返息金额：{$incomeAmount}元",
                        'create_time' => $now,
                    ]);

                    // 4. 记录用户活动日志
                    Db::name('user_activity_log')->insert([
                        'user_id' => $order['user_id'],
                        'related_user_id' => 0,
                        'action_type' => 'finance_income_stage',
                        'change_field' => 'money',
                        'change_value' => $incomeAmount,
                        'before_value' => $beforeMoney,
                        'after_value' => $afterMoney,
                        'remark' => "阶段返息：{$order['product_name']}，第{$daysDiff}天（{$stageInfo}天）",
                        'extra' => json_encode([
                            'order_no' => $order['order_no'],
                            'order_id' => $order['id'],
                            'income_type' => 'stage',
                            'income_amount' => $incomeAmount,
                            'stage_info' => $stageInfo,
                            'days' => $daysDiff,
                            'income_date' => $today,
                        ]),
                        'create_time' => $now,
                        'update_time' => $now,
                    ]);

                    // 提交事务
                    Db::commit();
                    $successCount++;
                    
                    $output->writeln("✓ 订单 {$order['order_no']} 第{$daysDiff}天阶段返息成功，用户ID：{$order['user_id']}，金额：{$incomeAmount}元");

                } catch (\Exception $e) {
                    // 回滚事务
                    Db::rollback();
                    $errorCount++;
                    $output->writeln("✗ 订单 {$order['order_no']} 阶段返息失败：" . $e->getMessage());
                }
            }

            $output->writeln("\n阶段返息处理完成！");
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

