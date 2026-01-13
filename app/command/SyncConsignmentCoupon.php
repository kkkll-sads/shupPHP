<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 同步用户寄售券数量到user表
 * 用于确保user.consignment_coupon字段与实际寄售券数量保持一致
 */
class SyncConsignmentCoupon extends Command
{
    protected function configure()
    {
        $this->setName('sync:consignment_coupon')
             ->setDescription('同步用户寄售券数量到user表');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('开始同步用户寄售券数量...');

        $now = time();

        // 获取所有用户的实际寄售券数量
        $userCoupons = Db::name('user_consignment_coupon')
            ->field([
                'user_id',
                'COUNT(*) as coupon_count'
            ])
            ->where('status', 1)
            ->where('expire_time', '>', $now)
            ->groupBy('user_id')
            ->select()
            ->toArray();

        $output->writeln("找到 " . count($userCoupons) . " 个有寄售券的用户");

        $updated = 0;
        $totalUsers = 0;

        foreach ($userCoupons as $userCoupon) {
            $userId = $userCoupon['user_id'];
            $actualCount = (int)$userCoupon['coupon_count'];

            // 获取用户当前记录的数量
            $user = Db::name('user')
                ->field(['id', 'consignment_coupon'])
                ->where('id', $userId)
                ->find();

            if (!$user) {
                $output->writeln("<error>用户 {$userId} 不存在，跳过</error>");
                continue;
            }

            $recordedCount = (int)($user['consignment_coupon'] ?? 0);

            if ($actualCount !== $recordedCount) {
                // 更新user表的寄售券数量
                Db::name('user')
                    ->where('id', $userId)
                    ->update([
                        'consignment_coupon' => $actualCount,
                        'update_time' => $now,
                    ]);

                $updated++;

                // 记录同步日志
                Db::name('user_activity_log')->insert([
                    'user_id' => $userId,
                    'related_user_id' => 0,
                    'action_type' => 'consignment_coupon_sync',
                    'change_field' => 'consignment_coupon',
                    'change_value' => $actualCount - $recordedCount,
                    'before_value' => $recordedCount,
                    'after_value' => $actualCount,
                    'remark' => '同步寄售券数量：' . $recordedCount . ' → ' . $actualCount,
                    'extra' => json_encode([
                        'sync_type' => 'batch_sync',
                        'actual_count' => $actualCount,
                        'previous_count' => $recordedCount,
                    ], JSON_UNESCAPED_UNICODE),
                    'create_time' => $now,
                    'update_time' => $now,
                ]);

                $output->writeln("用户 {$userId}: {$recordedCount} → {$actualCount}");
            }

            $totalUsers++;
        }

        // 同时处理没有寄售券的用户，确保他们的字段也是0
        $usersWithoutCoupons = Db::name('user')
            ->field(['id', 'consignment_coupon'])
            ->where('consignment_coupon', '>', 0)
            ->whereNotIn('id', array_column($userCoupons, 'user_id'))
            ->select()
            ->toArray();

        foreach ($usersWithoutCoupons as $user) {
            $userId = $user['id'];
            $recordedCount = (int)$user['consignment_coupon'];

            // 再次确认用户确实没有寄售券
            $actualCount = (int)Db::name('user_consignment_coupon')
                ->where('user_id', $userId)
                ->where('status', 1)
                ->where('expire_time', '>', $now)
                ->count();

            if ($actualCount === 0 && $recordedCount > 0) {
                // 更新为0
                Db::name('user')
                    ->where('id', $userId)
                    ->update([
                        'consignment_coupon' => 0,
                        'update_time' => $now,
                    ]);

                $updated++;

                // 记录同步日志
                Db::name('user_activity_log')->insert([
                    'user_id' => $userId,
                    'related_user_id' => 0,
                    'action_type' => 'consignment_coupon_sync',
                    'change_field' => 'consignment_coupon',
                    'change_value' => -$recordedCount,
                    'before_value' => $recordedCount,
                    'after_value' => 0,
                    'remark' => '同步寄售券数量：' . $recordedCount . ' → 0',
                    'extra' => json_encode([
                        'sync_type' => 'batch_sync_zero',
                        'actual_count' => 0,
                        'previous_count' => $recordedCount,
                    ], JSON_UNESCAPED_UNICODE),
                    'create_time' => $now,
                    'update_time' => $now,
                ]);

                $output->writeln("用户 {$userId}: {$recordedCount} → 0");
            }
        }

        $output->writeln("<info>同步完成，共处理 {$totalUsers} 个用户，更新 {$updated} 个用户</info>");
    }
}
