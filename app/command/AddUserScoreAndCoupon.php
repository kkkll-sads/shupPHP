<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use app\common\model\User;
use app\common\model\UserActivityLog;
use app\common\model\UserScoreLog;

/**
 * 增加用户积分和寄售券命令
 * 用于手动增加指定用户的积分和寄售券，并记录到活动日志
 * 
 * 使用方法：
 * php think add:score:coupon --user_id=74 --score=1000 --coupon=5
 */
class AddUserScoreAndCoupon extends Command
{
    protected function configure()
    {
        $this->setName('add:score:coupon')
            ->setDescription('增加用户积分和寄售券')
            ->addOption('user_id', null, \think\console\input\Option::VALUE_REQUIRED, '用户ID', 74)
            ->addOption('score', null, \think\console\input\Option::VALUE_OPTIONAL, '增加的积分数量', 1000)
            ->addOption('coupon', null, \think\console\input\Option::VALUE_OPTIONAL, '增加的寄售券数量', 5);
    }

    protected function execute(Input $input, Output $output)
    {
        $userId = (int)$input->getOption('user_id');
        $scoreToAdd = (int)$input->getOption('score');
        $couponToAdd = (int)$input->getOption('coupon');

        if ($userId <= 0) {
            $output->writeln('<error>用户ID无效</error>');
            return;
        }

        if ($scoreToAdd <= 0 && $couponToAdd <= 0) {
            $output->writeln('<error>积分和寄售券数量必须至少有一个大于0</error>');
            return;
        }

        $output->writeln("开始处理用户ID: {$userId}");
        $output->writeln("增加积分: {$scoreToAdd}");
        $output->writeln("增加寄售券: {$couponToAdd}");

        Db::startTrans();
        try {
            // 1. 查询用户信息并锁定
            $user = User::where('id', $userId)->lock(true)->find();
            if (!$user) {
                throw new \Exception("用户不存在，ID: {$userId}");
            }

            $output->writeln("用户信息: {$user->username} ({$user->nickname})");
            $output->writeln("当前积分: {$user->score}");
            $output->writeln("当前寄售券: {$user->consignment_coupon}");

            $now = time();

            // 2. 增加积分
            if ($scoreToAdd > 0) {
                $beforeScore = (float)$user->score;
                $afterScore = $beforeScore + $scoreToAdd;

                // 使用 UserScoreLog 自动更新积分（会自动更新用户积分）
                UserScoreLog::create([
                    'user_id' => $userId,
                    'score' => $scoreToAdd,
                    'memo' => '管理员手动增加积分',
                    'create_time' => $now,
                ]);

                // 重新获取用户信息以获取更新后的积分
                $user = User::find($userId);
                $afterScore = (float)$user->score;

                // 记录活动日志
                UserActivityLog::create([
                    'user_id' => $userId,
                    'related_user_id' => 0,
                    'action_type' => 'score',
                    'change_field' => 'score',
                    'change_value' => $scoreToAdd,
                    'before_value' => (string)$beforeScore,
                    'after_value' => (string)$afterScore,
                    'remark' => '管理员手动增加积分',
                    'extra' => [
                        'operator' => 'admin',
                        'score_delta' => $scoreToAdd,
                    ],
                    'create_time' => $now,
                    'update_time' => $now,
                ]);

                $output->writeln("<info>✓ 积分增加成功: {$beforeScore} → {$afterScore}</info>");
            }

            // 3. 增加寄售券
            if ($couponToAdd > 0) {
                $beforeCoupon = (int)$user->consignment_coupon;
                $afterCoupon = $beforeCoupon + $couponToAdd;

                // 更新用户寄售券
                Db::name('user')
                    ->where('id', $userId)
                    ->update([
                        'consignment_coupon' => Db::raw('consignment_coupon + ' . $couponToAdd),
                        'update_time' => $now,
                    ]);

                // 记录活动日志
                UserActivityLog::create([
                    'user_id' => $userId,
                    'related_user_id' => 0,
                    'action_type' => 'consignment_coupon',
                    'change_field' => 'consignment_coupon',
                    'change_value' => $couponToAdd,
                    'before_value' => (string)$beforeCoupon,
                    'after_value' => (string)$afterCoupon,
                    'remark' => '管理员手动增加寄售券',
                    'extra' => [
                        'operator' => 'admin',
                        'consignment_coupon_delta' => $couponToAdd,
                    ],
                    'create_time' => $now,
                    'update_time' => $now,
                ]);

                $output->writeln("<info>✓ 寄售券增加成功: {$beforeCoupon} → {$afterCoupon}</info>");
            }

            Db::commit();
            $output->writeln("<info>操作完成！</info>");

        } catch (\Exception $e) {
            Db::rollback();
            $output->writeln("<error>操作失败: {$e->getMessage()}</error>");
            return;
        }
    }
}

