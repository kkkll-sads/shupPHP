<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 每日分红与次日自动上架
 * php think collection:daily:dividend
 */
class CollectionDailyDividend extends Command
{
    protected function configure()
    {
        $this->setName('collection:daily:dividend')
            ->setDescription('每日分红结算（按当日已售出订单利润分配）并处理次日自动上架');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('[' . date('Y-m-d H:i:s') . '] 开始执行每日分红与次日上架处理...');

        $now = time();
        // 处理昨日（前一自然日）的订单
        $yesterdayStart = strtotime('yesterday 00:00:00');
        $yesterdayEnd = strtotime('yesterday 23:59:59');

        // 确保记录分红日志表存在
        $createSql = <<<SQL
CREATE TABLE IF NOT EXISTS `ba_daily_dividend_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL DEFAULT 0,
  `seller_id` int unsigned NOT NULL DEFAULT 0,
  `profit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `score_amount` int NOT NULL DEFAULT 0,
  `create_time` bigint NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        Db::execute($createSql);

        // 获取昨日成交（寄售购买）订单：通过 remark 中包含 consignment_id 标识
        $orders = Db::name('collection_order')
            ->whereIn('status', ['paid', 'completed'])
            ->whereBetween('pay_time', [$yesterdayStart, $yesterdayEnd])
            ->select()
            ->toArray();

        $processedCount = 0;
        foreach ($orders as $order) {
            $orderId = (int)$order['id'];
            $payTime = (int)$order['pay_time'];
            $remark = $order['remark'] ?? '';

            // 跳过已经记录过的订单
            $exists = Db::name('daily_dividend_log')->where('order_id', $orderId)->find();
            if ($exists) {
                continue;
            }

            // 只处理寄售购买（remark 包含 consignment_id）
            if (preg_match('/consignment_id:(\d+)/', $remark, $m)) {
                $consignmentId = (int)$m[1];
                $consignment = Db::name('collection_consignment')->where('id', $consignmentId)->find();
                if (!$consignment) {
                    continue;
                }
                $sellerId = (int)$consignment['user_id'];
                $itemId = (int)$consignment['item_id'];
                $consignmentPrice = (float)$consignment['price'];

                // 获取卖家持有记录以取买入价
                $sellerCollection = Db::name('user_collection')
                    ->where('user_id', $sellerId)
                    ->where('item_id', $itemId)
                    ->order('id desc')
                    ->find();
                $buyPrice = $sellerCollection ? (float)$sellerCollection['price'] : 0.0;
                if ($buyPrice <= 0) {
                    $buyPrice = $consignmentPrice; // 兼容处理
                }

                $profit = $consignmentPrice - $buyPrice;
                if ($profit <= 0) {
                    $profit = 0;
                }

                // 利润分配比例（默认50%/50%），可配置
                $balanceRate = (float)(get_sys_config('daily_dividend_balance_rate') ?? 0.5);
                $scoreRate = (float)(get_sys_config('daily_dividend_score_rate') ?? 0.5);
                if (abs($balanceRate + $scoreRate - 1.0) > 0.0001) {
                    $balanceRate = 0.5;
                    $scoreRate = 0.5;
                }

                $balanceAmount = round($profit * $balanceRate, 2);
                $scoreAmount = (int)round($profit * $scoreRate);

                Db::startTrans();
                try {
                    // 给卖家分配：可提取收益与积分
                    $seller = Db::name('user')->where('id', $sellerId)->lock(true)->find();
                    if ($seller) {
                        $beforeWithdrawable = (float)$seller['withdrawable_money'];
                        $afterWithdrawable = round($beforeWithdrawable + $balanceAmount, 2);
                        $beforeScore = (int)$seller['score'];
                        $afterScore = $beforeScore + $scoreAmount;

                        Db::name('user')->where('id', $sellerId)->update([
                            'withdrawable_money' => $afterWithdrawable,
                            'score' => $afterScore,
                            'update_time' => $now,
                        ]);

                        // 记录余额日志
                        if ($balanceAmount > 0) {
                            Db::name('user_money_log')->insert([
                                'user_id' => $sellerId,
                                'money' => $balanceAmount,
                                'before' => $beforeWithdrawable,
                                'after' => $afterWithdrawable,
                                'memo' => '每日分红-可提取收益',
                                'create_time' => $now,
                            ]);
                        }
                        // 记录积分日志
                        if ($scoreAmount > 0) {
                            Db::name('user_score_log')->insert([
                                'user_id' => $sellerId,
                                'score' => $scoreAmount,
                                'before' => $beforeScore,
                                'after' => $afterScore,
                                'memo' => '每日分红-消费金分配',
                                'create_time' => $now,
                            ]);
                        }

                        // 记录活动日志
                        Db::name('user_activity_log')->insert([
                            'user_id' => $sellerId,
                            'action_type' => 'daily_dividend',
                            'change_field' => 'withdrawable_money,score',
                            'change_value' => json_encode(['withdrawable_money' => $balanceAmount, 'score' => $scoreAmount], JSON_UNESCAPED_UNICODE),
                            'before_value' => json_encode(['withdrawable_money' => $beforeWithdrawable, 'score' => $beforeScore], JSON_UNESCAPED_UNICODE),
                            'after_value' => json_encode(['withdrawable_money' => $afterWithdrawable, 'score' => $afterScore], JSON_UNESCAPED_UNICODE),
                            'remark' => sprintf('每日分红结算：利润 %.2f，分配为 可提取 %.2f，消费金 %d', $profit, $balanceAmount, $scoreAmount),
                            'extra' => json_encode(['order_id' => $orderId, 'consignment_id' => $consignmentId], JSON_UNESCAPED_UNICODE),
                            'create_time' => $now,
                        ]);

                        // 写入分红日志，防止重复处理
                        Db::name('daily_dividend_log')->insert([
                            'order_id' => $orderId,
                            'seller_id' => $sellerId,
                            'profit' => $profit,
                            'balance_amount' => $balanceAmount,
                            'score_amount' => $scoreAmount,
                            'create_time' => $now,
                        ]);
                    }

                    Db::commit();
                    $processedCount++;
                } catch (\Exception $e) {
                    Db::rollback();
                    $output->writeln("订单 {$orderId} 分红处理失败: " . $e->getMessage());
                }
            }
        }

        $output->writeln("分红处理完成：共处理 {$processedCount} 个订单");

        // 次日自动上架逻辑已移除；请使用用户手动上架接口进行上架操作。
        $output->writeln('[' . date('Y-m-d H:i:s') . '] 每日分红与次日上架任务结束。');
    }
}





