<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 充值奖励算力补发脚本
 * 用于补发今天已通过但未赠送算力的充值订单
 */
class RechargeRewardRetroactive extends Command
{
    protected function configure()
    {
        $this->setName('recharge:reward-retroactive')
            ->setDescription('补发今天已通过充值订单的算力奖励')
            ->addOption('date', 'd', \think\console\input\Option::VALUE_OPTIONAL, '指定日期（格式：Y-m-d），默认今天', '');
    }

    protected function execute(Input $input, Output $output): int
    {
        $dateStr = $input->getOption('date');
        if ($dateStr) {
            $dateStart = strtotime($dateStr);
            if ($dateStart === false) {
                $output->writeln('<error>日期格式错误，请使用 Y-m-d 格式，如：2026-01-12</error>');
                return 1;
            }
            $dateEnd = $dateStart + 86400 - 1;
        } else {
            $dateStart = strtotime('today');
            $dateEnd = strtotime('tomorrow') - 1;
        }
        
        $output->writeln('<info>开始检查充值订单算力奖励...</info>');
        $output->writeln('<info>日期范围: ' . date('Y-m-d H:i:s', $dateStart) . ' 至 ' . date('Y-m-d H:i:s', $dateEnd) . '</info>');
        
        // 获取奖励比例配置
        $rewardPowerRate = (float)Db::name('config')
            ->where('name', 'recharge_reward_power_rate')
            ->where('group', 'activity_reward')
            ->value('value', 0);
        
        if ($rewardPowerRate <= 0) {
            $output->writeln('<error>充值奖励比例未配置或为0，请在活动奖励配置中设置 recharge_reward_power_rate</error>');
            return 1;
        }
        
        $output->writeln('<info>奖励比例: ' . $rewardPowerRate . '%</info>');
        
        // 查询今天已通过的充值订单
        $orders = Db::name('recharge_order')
            ->where('status', 1)
            ->where('audit_time', '>=', $dateStart)
            ->where('audit_time', '<=', $dateEnd)
            ->order('id asc')
            ->select()
            ->toArray();
        
        if (empty($orders)) {
            $output->writeln('<info>没有找到符合条件的充值订单</info>');
            return 0;
        }
        
        $output->writeln('<info>找到 ' . count($orders) . ' 个已通过的充值订单</info>');
        $output->writeln('');
        
        $needRetroactive = [];
        $alreadyRewarded = [];
        
        // 检查哪些订单需要补发（通过 extra 字段中的 order_id 判断）
        foreach ($orders as $order) {
            $hasRewardLog = Db::name('user_activity_log')
                ->where('user_id', $order['user_id'])
                ->where('action_type', 'recharge_reward')
                ->where('extra', 'like', '%"order_id":' . $order['id'] . '%')
                ->find();
            
            if (!$hasRewardLog) {
                $needRetroactive[] = $order;
            } else {
                $alreadyRewarded[] = $order;
            }
        }
        
        $output->writeln('<info>已有奖励记录的订单: ' . count($alreadyRewarded) . ' 个</info>');
        $output->writeln('<info>需要补发的订单: ' . count($needRetroactive) . ' 个</info>');
        $output->writeln('');
        
        if (empty($needRetroactive)) {
            $output->writeln('<info>所有订单都已发放奖励，无需补发</info>');
            return 0;
        }
        
        // 开始补发
        $successCount = 0;
        $failCount = 0;
        $totalRewardPower = 0;
        
        foreach ($needRetroactive as $order) {
            try {
                Db::startTrans();
                
                // 计算奖励算力
                $rewardPower = round((float)$order['amount'] * $rewardPowerRate / 100, 2);
                if ($rewardPower <= 0) {
                    Db::rollback();
                    $output->writeln('<warning>订单 ' . $order['order_no'] . ' 奖励算力为0，跳过</warning>');
                    $failCount++;
                    continue;
                }
                
                // 获取用户信息
                $user = Db::name('user')->where('id', $order['user_id'])->lock(true)->find();
                if (!$user) {
                    Db::rollback();
                    $output->writeln('<error>订单 ' . $order['order_no'] . ' 用户不存在，跳过</error>');
                    $failCount++;
                    continue;
                }
                
                $beforeGreenPower = (float)($user['green_power'] ?? 0);
                $afterGreenPower = round($beforeGreenPower + $rewardPower, 2);
                
                // 更新用户算力
                Db::name('user')
                    ->where('id', $order['user_id'])
                    ->update([
                        'green_power' => $afterGreenPower,
                        'update_time' => time(),
                    ]);
                
                $now = time();
                
                // 记录算力变动日志
                Db::name('user_money_log')->insert([
                    'user_id' => $order['user_id'],
                    'field_type' => 'green_power',
                    'money' => $rewardPower,
                    'before' => $beforeGreenPower,
                    'after' => $afterGreenPower,
                    'memo' => '充值奖励-绿色算力（补发）：订单号 ' . $order['order_no'],
                    'flow_no' => generateSJSFlowNo($order['user_id']),
                    'batch_no' => generateBatchNo('RECHARGE_REWARD_RETRO', $order['id']),
                    'biz_type' => 'recharge_reward',
                    'biz_id' => $order['id'],
                    'create_time' => $now,
                ]);
                
                // 记录活动日志
                Db::name('user_activity_log')->insert([
                    'user_id' => $order['user_id'],
                    'related_user_id' => 0,
                    'action_type' => 'recharge_reward',
                    'change_field' => 'green_power',
                    'change_value' => (string)$rewardPower,
                    'before_value' => (string)$beforeGreenPower,
                    'after_value' => (string)$afterGreenPower,
                    'remark' => '充值奖励-绿色算力（补发）：+' . $rewardPower . '（充值金额：' . $order['amount'] . '元，奖励比例：' . $rewardPowerRate . '%）',
                    'extra' => json_encode([
                        'order_id' => $order['id'],
                        'order_no' => $order['order_no'],
                        'amount' => (string)$order['amount'],
                        'reward_power_rate' => (string)$rewardPowerRate,
                        'reward_power' => (string)$rewardPower,
                        'retroactive' => true,
                    ], JSON_UNESCAPED_UNICODE),
                    'create_time' => $now,
                    'update_time' => $now,
                ]);
                
                Db::commit();
                
                $successCount++;
                $totalRewardPower += $rewardPower;
                $output->writeln('<info>✓ 订单 ' . $order['order_no'] . ' 补发成功：+' . $rewardPower . ' 算力（用户ID: ' . $order['user_id'] . '，充值金额: ' . $order['amount'] . '元）</info>');
                
            } catch (\Throwable $e) {
                Db::rollback();
                $failCount++;
                $output->writeln('<error>✗ 订单 ' . $order['order_no'] . ' 补发失败：' . $e->getMessage() . '</error>');
            }
        }
        
        $output->writeln('');
        $output->writeln('<info>=== 补发完成 ===</info>');
        $output->writeln('<info>成功: ' . $successCount . ' 个订单</info>');
        $output->writeln('<info>失败: ' . $failCount . ' 个订单</info>');
        $output->writeln('<info>总补发算力: ' . round($totalRewardPower, 2) . '</info>');
        
        return 0;
    }
}
