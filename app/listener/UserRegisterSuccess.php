<?php

namespace app\listener;

use Throwable;
use think\facade\Log;
use think\facade\Db;
use app\admin\library\DrawCountService;
use app\common\model\SignInActivity;
use app\common\model\UserActivityLog;

class UserRegisterSuccess
{
    /**
     * @param \app\admin\model\User|array $user
     */
    public function handle($user): void
    {
        try {
            if (!$user) {
                return;
            }

            $userId = (int)($user['id'] ?? $user->id ?? 0);
            $inviterId = (int)($user['inviter_id'] ?? $user->inviter_id ?? 0);

            if ($userId <= 0) {
                return;
            }

            // 处理邀请关系（原有逻辑）
            if ($inviterId > 0) {
                $service = new DrawCountService();
                $service->addInviteRecord($userId, $inviterId);
            }

            // 处理注册奖励
            $this->handleRegisterReward($userId);
            
            // 处理邀请好友奖励（给邀请人发放奖励）
            // 修改：邀请奖励需要在下级实名认证通过后才发放
            // if ($inviterId > 0) {
            //     $this->handleInviteReward($inviterId, $userId);
            // }
        } catch (Throwable $e) {
            Log::error('UserRegisterSuccess listener error: ' . $e->getMessage());
        }
    }

    /**
     * 处理注册奖励
     */
    protected function handleRegisterReward(int $userId): void
    {
        try {
            // 检查是否已经发放过注册奖励
            $existing = UserActivityLog::where('user_id', $userId)
                ->where('action_type', 'register_reward')
                ->find();
            if ($existing) {
                return; // 已经发放过，不再重复发放
            }

            // 优先使用活动配置，如果没有活动则使用系统配置
            $activity = SignInActivity::getActiveActivity();
            $useActivity = $activity && $activity->isActive();
            
            // 从配置中获取注册奖励
            $rewardMoney = 0;
            $rewardScore = 0;
            $rewardWithdrawableMoney = 0;
            $rewardGreenPower = 0;
            
            if ($useActivity) {
                // 使用活动配置
                $rewardMoney = (float)$activity->register_reward;
                // 活动模式下，绿色算力从系统配置读取
                $rewardGreenPower = (float)get_sys_config('sign_act_reg_green', 0);
            } else {
                // 使用系统配置
                $rewardMoney = (float)get_sys_config('sign_act_reg_reward', 0);
                $rewardScore = (int)get_sys_config('register_reward_score', 0);
                $rewardWithdrawableMoney = (float)get_sys_config('register_reward_withdraw', 0);
                $rewardGreenPower = (float)get_sys_config('sign_act_reg_green', 0);
            }

            // 如果所有奖励都为0，则不发放
            if ($rewardMoney <= 0 && $rewardScore <= 0 && $rewardWithdrawableMoney <= 0 && $rewardGreenPower <= 0) {
                return;
            }

            // 在事务中发放奖励
            Db::transaction(function () use ($userId, $rewardMoney, $rewardScore, $rewardWithdrawableMoney, $rewardGreenPower, $activity, $useActivity) {
                // 获取用户信息
                $user = \app\common\model\User::where('id', $userId)->lock(true)->find();
                if (!$user) {
                    return;
                }

                $changes = [];
                $logs = [];

                // 发放可提现金额 - 注册奖励发放到withdrawable_money（可提现余额）
                if ($rewardMoney > 0) {
                    $beforeWithdrawable = (float)$user->withdrawable_money;
                    $afterWithdrawable = round($beforeWithdrawable + $rewardMoney, 2);
                    $user->withdrawable_money = $afterWithdrawable;
                    $changes[] = ['field' => 'withdrawable_money', 'before' => $beforeWithdrawable, 'after' => $afterWithdrawable, 'value' => $rewardMoney];
                    
                    // 记录余额变动日志
                    $now = time();
                    $flowNo = generateSJSFlowNo($userId);
                    $batchNo = generateBatchNo('REG', $userId);
                    Db::name('user_money_log')->insert([
                        'user_id' => $userId,
                        'field_type' => 'withdrawable_money', // 明确指定字段类型
                        'money' => $rewardMoney,
                        'before' => $beforeWithdrawable,
                        'after' => $afterWithdrawable,
                        'memo' => '注册奖励-可提现余额',
                        'flow_no' => $flowNo,
                        'batch_no' => $batchNo,
                        'biz_type' => 'register_reward',
                        'biz_id' => $userId,
                        'create_time' => $now,
                    ]);
                }

                // 发放消费金（积分）
                if ($rewardScore > 0) {
                    $beforeScore = (float)$user->score;
                    $afterScore = $beforeScore + $rewardScore;
                    $user->score = $afterScore;
                    $changes[] = ['field' => 'score', 'before' => $beforeScore, 'after' => $afterScore, 'value' => $rewardScore];
                }

                // 发放可提现金额
                if ($rewardWithdrawableMoney > 0) {
                    $beforeWithdrawable = (float)$user->withdrawable_money;
                    $afterWithdrawable = round($beforeWithdrawable + $rewardWithdrawableMoney, 2);
                    $user->withdrawable_money = $afterWithdrawable;
                    $changes[] = ['field' => 'withdrawable_money', 'before' => $beforeWithdrawable, 'after' => $afterWithdrawable, 'value' => $rewardWithdrawableMoney];
                }

                // 发放绿色算力（如果用户表有该字段）
                if ($rewardGreenPower > 0) {
                    // 检查用户表是否有 green_power 字段
                    $hasGreenPowerField = Db::query("SHOW COLUMNS FROM `ba_user` LIKE 'green_power'");
                    if (!empty($hasGreenPowerField)) {
                        $beforeGreenPower = (float)($user->green_power ?? 0);
                        $afterGreenPower = round($beforeGreenPower + $rewardGreenPower, 2);
                        $user->green_power = $afterGreenPower;
                        $changes[] = ['field' => 'green_power', 'before' => $beforeGreenPower, 'after' => $afterGreenPower, 'value' => $rewardGreenPower];

                        // 记录余额变动日志（绿色算力）
                        $now = time();
                        $flowNo = generateSJSFlowNo($userId);
                        $batchNo = generateBatchNo('REG', $userId);
                        Db::name('user_money_log')->insert([
                            'user_id' => $userId,
                            'field_type' => 'green_power',
                            'money' => $rewardGreenPower,
                            'before' => $beforeGreenPower,
                            'after' => $afterGreenPower,
                            'memo' => '注册奖励-绿色算力',
                            'flow_no' => $flowNo,
                            'batch_no' => $batchNo,
                            'biz_type' => 'register_reward',
                            'biz_id' => $userId,
                            'create_time' => $now,
                        ]);
                    }
                }

                // 保存用户信息
                $user->save();

                // 记录活动日志
                $remarkParts = [];
                if ($rewardMoney > 0) {
                    $remarkParts[] = sprintf('可用金额：%.2f元', $rewardMoney);
                }
                if ($rewardScore > 0) {
                    $remarkParts[] = sprintf('消费金：%d分', $rewardScore);
                }
                if ($rewardWithdrawableMoney > 0) {
                    $remarkParts[] = sprintf('可提现金额：%.2f元', $rewardWithdrawableMoney);
                }
                if ($rewardGreenPower > 0) {
                    $remarkParts[] = sprintf('绿色算力：%.2f', $rewardGreenPower);
                }

                foreach ($changes as $change) {
                    UserActivityLog::create([
                        'user_id' => $userId,
                        'related_user_id' => 0,
                        'action_type' => 'register_reward',
                        'change_field' => $change['field'],
                        'change_value' => $change['value'],
                        'before_value' => $change['before'],
                        'after_value' => $change['after'],
                        'remark' => '注册奖励：' . implode('，', $remarkParts),
                        'extra' => [
                            'source' => $useActivity ? 'activity' : 'config',
                            'activity_id' => $useActivity ? $activity->id : 0,
                            'activity_name' => $useActivity ? $activity->name : '',
                            'reward_money' => $rewardMoney,
                            'reward_score' => $rewardScore,
                            'reward_withdrawable_money' => $rewardWithdrawableMoney,
                            'reward_green_power' => $rewardGreenPower,
                        ],
                    ]);
                }
            });
        } catch (Throwable $e) {
            Log::error('Register reward error: ' . $e->getMessage());
            // 不抛出异常，避免影响注册流程
        }
    }

    /**
     * 处理邀请好友奖励（给邀请人发放奖励）
     * 修改为 public 方法，以便在实名认证通过时调用
     */
    public function handleInviteReward(int $inviterId, int $newUserId): void
    {
        try {
            // 防重复发放：检查是否已经为该下级用户发放过邀请奖励
            $existing = UserActivityLog::where('user_id', $inviterId)
                ->where('related_user_id', $newUserId)
                ->where('action_type', 'invite_reward')
                ->find();
            if ($existing) {
                Log::info("邀请奖励已发放，跳过重复发放。邀请人ID: {$inviterId}, 被邀请人ID: {$newUserId}");
                return; // 已经发放过，不再重复发放
            }

            // 🔧 获取邀请奖励算力配置（不依赖活动）
            $inviteRewardPower = (float)get_sys_config('invite_reward_power', 0);
            
            // 获取当前有效的活动（用于金额奖励）
            $activity = SignInActivity::getActiveActivity();
            $hasActiveActivity = $activity && $activity->isActive();
            
            $inviteRewardMin = 0;
            $inviteRewardMax = 0;
            if ($hasActiveActivity) {
                $inviteRewardMin = (float)$activity->invite_reward_min;
                $inviteRewardMax = (float)$activity->invite_reward_max;
            }
            
            // 🔧 修复：如果金额和算力都为0，不发放
            // 但算力奖励不依赖活动，只要有配置就应该发放
            if (($inviteRewardMin <= 0 || $inviteRewardMax <= 0) && $inviteRewardPower <= 0) {
                return; // 奖励为0，不发放
            }

            // 生成随机金额（仅在有活动时）
            $inviteReward = 0;
            if ($hasActiveActivity && $inviteRewardMin > 0 && $inviteRewardMax > 0) {
                $inviteReward = $this->generateRandomMoney($inviteRewardMin, $inviteRewardMax);
            }

            // 在事务中发放奖励
            Db::transaction(function () use ($inviterId, $newUserId, $inviteReward, $inviteRewardPower, $activity, $hasActiveActivity) {
                // 获取邀请人信息
                $inviter = \app\common\model\User::where('id', $inviterId)->lock(true)->find();
                if (!$inviter) {
                    return;
                }

                $now = time();
                $flowNo = generateSJSFlowNo($inviterId);
                $batchNo = generateBatchNo('INVITE_REWARD', $newUserId);
                $changes = [];

                // 发放金额奖励
                if ($inviteReward > 0) {
                    $beforeWithdrawable = (float)$inviter->withdrawable_money;
                    $afterWithdrawable = round($beforeWithdrawable + $inviteReward, 2);
                    $inviter->withdrawable_money = $afterWithdrawable;
                    $changes[] = ['field' => 'withdrawable_money', 'value' => $inviteReward, 'before' => $beforeWithdrawable, 'after' => $afterWithdrawable];

                    // 记录资金变动日志
                    Db::name('user_money_log')->insert([
                        'user_id' => $inviterId,
                        'field_type' => 'withdrawable_money',
                        'money' => $inviteReward,
                        'before' => $beforeWithdrawable,
                        'after' => $afterWithdrawable,
                        'memo' => sprintf('邀请好友获得金额：%.2f元（被邀请用户ID：%d）', $inviteReward, $newUserId),
                        'flow_no' => $flowNo,
                        'batch_no' => $batchNo,
                        'biz_type' => 'invite_reward',
                        'biz_id' => $newUserId,
                        'create_time' => $now,
                    ]);
                }

                // 🔧 发放算力奖励
                if ($inviteRewardPower > 0) {
                    $beforeGreenPower = (float)($inviter->green_power ?? 0);
                    $afterGreenPower = round($beforeGreenPower + $inviteRewardPower, 2);
                    $inviter->green_power = $afterGreenPower;
                    $changes[] = ['field' => 'green_power', 'value' => $inviteRewardPower, 'before' => $beforeGreenPower, 'after' => $afterGreenPower];

                    // 记录算力变动日志
                    $flowNo2 = generateSJSFlowNo($inviterId);
                    Db::name('user_money_log')->insert([
                        'user_id' => $inviterId,
                        'field_type' => 'green_power',
                        'money' => $inviteRewardPower,
                        'before' => $beforeGreenPower,
                        'after' => $afterGreenPower,
                        'memo' => sprintf('邀请好友获得算力：%.2f（被邀请用户ID：%d）', $inviteRewardPower, $newUserId),
                        'flow_no' => $flowNo2,
                        'batch_no' => $batchNo,
                        'biz_type' => 'invite_reward',
                        'biz_id' => $newUserId,
                        'create_time' => $now,
                    ]);
                }

                // 保存用户信息
                $inviter->save();

                // 记录活动日志（每个变更字段一条日志）
                foreach ($changes as $change) {
                    UserActivityLog::create([
                        'user_id' => $inviterId,
                        'related_user_id' => $newUserId,
                        'action_type' => 'invite_reward',
                        'change_field' => $change['field'],
                        'change_value' => (string)$change['value'],
                        'before_value' => (string)$change['before'],
                        'after_value' => (string)$change['after'],
                        'remark' => $change['field'] === 'withdrawable_money' 
                            ? sprintf('邀请好友获得金额：%.2f元（被邀请用户ID：%d）', $change['value'], $newUserId)
                            : sprintf('邀请好友获得算力：%.2f（被邀请用户ID：%d）', $change['value'], $newUserId),
                        'extra' => [
                            'activity_id' => $hasActiveActivity ? $activity->id : 0,
                            'activity_name' => $hasActiveActivity ? $activity->name : '',
                            'fund_source' => $hasActiveActivity ? $activity->fund_source : '',
                            'invite_reward' => $inviteReward,
                            'invite_reward_power' => $inviteRewardPower,
                            'invited_user_id' => $newUserId,
                        ],
                    ]);
                }
            });
        } catch (Throwable $e) {
            Log::error('Invite reward error: ' . $e->getMessage());
            // 不抛出异常，避免影响注册流程
        }
    }

    /**
     * 生成随机金额（在指定范围内）
     */
    protected function generateRandomMoney(float $min, float $max): float
    {
        if ($min >= $max) {
            return $min;
        }
        // 生成0.01精度的随机金额
        $random = mt_rand((int)($min * 100), (int)($max * 100)) / 100;
        return round($random, 2);
    }
}
