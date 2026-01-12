<?php

declare(strict_types=1);

namespace app\common\library;

use Throwable;
use RuntimeException;
use app\common\model\User;
use think\facade\Db;
use app\common\model\UserSignIn;
use app\common\model\UserScoreLog;
use app\common\model\UserActivityLog;
use app\common\model\SignInActivity;
use app\admin\library\DrawCountService;

class SignIn
{
    public static function getDailyRewardScore(): int
    {
        return max(0, (int)(get_sys_config('sign_in_daily_score') ?? 0));
    }

    public static function getReferrerRewardScore(): int
    {
        return max(0, (int)(get_sys_config('sign_in_referrer_score') ?? 0));
    }

    public static function getCalendarRange(): int
    {
        return max(0, (int)(get_sys_config('sign_in_calendar_range') ?? 1));
    }

    public static function hasSigned(int $userId, ?string $date = null): bool
    {
        $date = $date ?: date('Y-m-d');
        return UserSignIn::where('user_id', $userId)
            ->where('sign_date', $date)
            ->count() > 0;
    }

    /**
     * 获取当前有效的签到活动
     */
    public static function getActiveActivity(): ?SignInActivity
    {
        return SignInActivity::getActiveActivity();
    }

    /**
     * 生成随机金额（在指定范围内）
     */
    protected static function generateRandomMoney(float $min, float $max): float
    {
        if ($min >= $max) {
            return $min;
        }
        // 生成0.01精度的随机金额
        $random = mt_rand((int)($min * 100), (int)($max * 100)) / 100;
        return round($random, 2);
    }

    /**
     * @throws Throwable
     */
    public static function sign(int $userId): array
    {
        $today = date('Y-m-d');
        
        // 优先使用活动配置，如果没有活动则使用系统配置
        $activity = self::getActiveActivity();
        $useActivity = $activity && $activity->isActive();
        
        if ($useActivity) {
            // 使用活动配置：随机金额奖励
            $signRewardMin = (float)$activity->sign_reward_min;
            $signRewardMax = (float)$activity->sign_reward_max;
            $dailyReward = self::generateRandomMoney($signRewardMin, $signRewardMax);
            $rewardType = 'money'; // 金额奖励
            $referrerReward = 0; // 活动模式下暂不支持邀请人奖励
        } else {
            // 使用系统配置：积分奖励
            $dailyReward = self::getDailyRewardScore();
            $rewardType = 'score'; // 积分奖励
            $referrerReward = self::getReferrerRewardScore();
        }

        $result = Db::transaction(function () use ($userId, $today, $dailyReward, $referrerReward, $rewardType, $activity, $useActivity) {
            $existing = UserSignIn::where('user_id', $userId)
                ->where('sign_date', $today)
                ->lock(true)
                ->find();
            if ($existing) {
                throw new RuntimeException('今日已签到，请勿重复操作');
            }

            /** @var User|null $user */
            $user = User::where('id', $userId)->lock(true)->find();
            if (!$user) {
                throw new RuntimeException('用户不存在');
            }
            // 未实名认证的用户不可签到
            $isVerified = (int)($user->real_name_status ?? 0) === 2;
            if (!$isVerified) {
                throw new RuntimeException('请先完成实名认证后再签到');
            }
            // 是否首签（用于控制邀请奖励）
            $hasPreviousSign = UserSignIn::where('user_id', $userId)->count() > 0;

            // 获取当前配置信息
            $config = [
                'reward_type' => $rewardType,
                'daily_reward' => $dailyReward,
                'referrer_reward' => $referrerReward,
                'activity_id' => $useActivity ? $activity->id : null,
                'activity_name' => $useActivity ? $activity->name : null,
            ];

            $signRecord = UserSignIn::create([
                'user_id' => $userId,
                'sign_date' => $today,
                'reward_score' => $rewardType === 'score' ? (int)$dailyReward : 0, // 兼容旧字段
                'extra' => [
                    'source' => $useActivity ? 'activity' : 'daily',
                    'reward_type' => $rewardType,
                    'reward_money' => $rewardType === 'money' ? $dailyReward : 0,
                    'reward_score' => $rewardType === 'score' ? (int)$dailyReward : 0,
                    'config' => $config,
                ],
            ]);

            $scoreLog = null;
            $moneyLog = null;
            $afterWithdrawable = null;
            
            if ($dailyReward > 0) {
                if ($rewardType === 'money') {
                    $beforeWithdrawable = (float)$user->withdrawable_money;
                    $afterWithdrawable = round($beforeWithdrawable + $dailyReward, 2);
                    $user->withdrawable_money = $afterWithdrawable;
                    $user->save();

                    // 记录资金流水日志（显示在 allLog 中）
                    $moneyLog = Db::name('user_money_log')->insertGetId([
                        'user_id' => $userId,
                        'field_type' => 'withdrawable_money',
                        'money' => $dailyReward,
                        'before' => $beforeWithdrawable,
                        'after' => $afterWithdrawable,
                        'memo' => sprintf('每日签到奖励%.2f元%s', $dailyReward, $useActivity ? '（活动）' : ''),
                        'create_time' => time(),
                        'biz_type' => 'sign_in',
                        'extra_json' => json_encode([
                            'sign_date' => $today,
                            'sign_record_id' => $signRecord->id,
                            'reward_money' => $dailyReward,
                            'activity_id' => $useActivity ? $activity->id : null,
                            'activity_name' => $useActivity ? $activity->name : null,
                        ], JSON_UNESCAPED_UNICODE),
                    ]);

                    UserActivityLog::create([
                        'user_id' => $userId,
                        'related_user_id' => 0,
                        'action_type' => 'sign_in',
                        'change_field' => 'withdrawable_money',
                        'change_value' => $dailyReward,
                        'before_value' => $beforeWithdrawable,
                        'after_value' => $afterWithdrawable,
                        'remark' => sprintf('每日签到获得金额：%.2f元%s', $dailyReward, $useActivity ? sprintf('（活动：%s）', $activity->name) : ''),
                        'extra' => [
                            'sign_date' => $today,
                            'sign_record_id' => $signRecord->id,
                            'reward_money' => $dailyReward,
                            'withdrawable_money' => $afterWithdrawable,
                            'config' => $config,
                        ],
                    ]);
                } else {
                    // 积分奖励（兼容旧逻辑）
                    $scoreLog = UserScoreLog::create([
                        'user_id' => $userId,
                        'score' => (float)$dailyReward,
                        'memo' => '每日签到奖励',
                    ]);

                    UserActivityLog::create([
                        'user_id' => $userId,
                        'related_user_id' => 0,
                        'action_type' => 'sign_in',
                        'change_field' => 'score',
                        'change_value' => (float)$dailyReward,
                        'before_value' => (float)($scoreLog->before ?? 0),
                        'after_value' => (float)($scoreLog->after ?? 0),
                        'remark' => sprintf('每日签到获得积分：%.2f积分（根据配置：sign_in_daily_score，配置值：%.2f）', (float)$dailyReward, (float)$dailyReward),
                        'extra' => [
                            'sign_date' => $today,
                            'sign_record_id' => $signRecord->id,
                            'reward_score' => (float)$dailyReward,
                            'config' => $config,
                        ],
                    ]);
                }
            }

            $referrerRewardLog = null;
            $referrerActivityId = null;
            $referrerId = (int)($user->inviter_id ?? 0);
            
            // 邀请人奖励（仅积分模式支持）
            if ($referrerId > 0 && $referrerReward > 0 && $rewardType === 'score' && !$hasPreviousSign) {
                $refScoreLog = UserScoreLog::create([
                    'user_id' => $referrerId,
                    'score' => $referrerReward,
                    'memo' => '直推用户签到奖励',
                ]);

                $activity = UserActivityLog::create([
                    'user_id' => $referrerId,
                    'related_user_id' => $userId,
                    'action_type' => 'sign_in_referral',
                    'change_field' => 'score',
                    'change_value' => $referrerReward,
                    'before_value' => $refScoreLog->before ?? 0,
                    'after_value' => $refScoreLog->after ?? 0,
                    'remark' => sprintf('邀请用户签到获得积分：%d积分（根据配置：sign_in_referrer_score，配置值：%d）', $referrerReward, $referrerReward),
                    'extra' => [
                        'sign_date' => $today,
                        'sign_record_id' => $signRecord->id,
                        'reward_score' => $referrerReward,
                        'invite_user_id' => $userId,
                        'config' => $config,
                    ],
                ]);
                $referrerRewardLog = $refScoreLog;
                $referrerActivityId = $activity->id ?? null;

                try {
                    $drawService = new DrawCountService();
                    $drawService->updateUserDrawCount($referrerId, 'sign_in', [
                        'invite_user_id' => $userId,
                        'sign_date' => $today,
                    ]);
                } catch (Throwable $e) {
                    // 记录日志但不中断主流程
                    trace(sprintf('更新邀请人抽奖次数失败: %s', $e->getMessage()), 'error');
                }
            }

            return [
                'record' => $signRecord,
                'score_log' => $scoreLog,
                'money_log' => $moneyLog,
                'referrer_reward_log' => $referrerRewardLog,
                'referrer_activity_id' => $referrerActivityId,
                'referrer_id' => $referrerId,
                'daily_reward' => $dailyReward,
                'referrer_reward' => $referrerId > 0 ? $referrerReward : 0,
                'reward_type' => $rewardType,
            ];
        });

        $statistics = self::getStatistics($userId);

        return array_merge($statistics, [
            'sign_record_id' => $result['record']->id,
            'sign_date' => $result['record']->sign_date,
            'daily_reward' => $result['daily_reward'],
            'referrer_reward' => $result['referrer_reward'],
            'reward_type' => $result['reward_type'],
            'message' => '签到成功',
        ]);
    }

    public static function getStatistics(int $userId, ?int $rangeMonths = null): array
    {
        $range = $rangeMonths ?? self::getCalendarRange();
        $today = date('Y-m-d');
        $dailyReward = self::getDailyRewardScore();

        $start = new \DateTimeImmutable('first day of this month');
        if ($range > 0) {
            $start = $start->modify(sprintf('-%d months', $range));
        }
        $end = new \DateTimeImmutable('last day of this month');
        if ($range > 0) {
            $end = $end->modify(sprintf('+%d months', $range));
        }

        $startDate = $start->format('Y-m-01');
        $endDate = $end->format('Y-m-t');

        $calendarRecords = UserSignIn::where('user_id', $userId)
            ->whereBetween('sign_date', [$startDate, $endDate])
            ->order('sign_date', 'asc')
            ->select()
            ->toArray();

        $dateSet = [];
        $calendar = [];
        foreach ($calendarRecords as $item) {
            $dateSet[$item['sign_date']] = true;
            $calendar[] = [
                'date' => $item['sign_date'],
                'reward_score' => (int)$item['reward_score'],
                'record_id' => $item['id'],
            ];
        }

        $todayRecord = null;
        if (isset($dateSet[$today])) {
            foreach ($calendar as $item) {
                if ($item['date'] === $today) {
                    $todayRecord = $item;
                    break;
                }
            }
        }

        $todaySigned = $todayRecord !== null;
        $todayReward = $todayRecord['reward_score'] ?? 0;

        $totalReward = (int)UserSignIn::where('user_id', $userId)->sum('reward_score');
        $signDays = count($dateSet);

        $streak = self::calculateStreak($dateSet, $todaySigned ? $today : date('Y-m-d', strtotime('-1 day')));

        $recentRecords = UserSignIn::where('user_id', $userId)
            ->order('sign_date', 'desc')
            ->limit(30)
            ->select()
            ->toArray();

        $recent = array_map(static function ($item) {
            return [
                'id' => $item['id'],
                'date' => $item['sign_date'],
                'reward_score' => (int)$item['reward_score'],
                'create_time' => $item['create_time'],
            ];
        }, $recentRecords);

        return [
            'today_signed' => $todaySigned,
            'today_reward' => $todaySigned ? $todayReward : 0,
            'daily_reward' => $dailyReward,
            'total_reward' => $totalReward,
            'sign_days' => $signDays,
            'streak' => $streak,
            'calendar' => [
                'start' => $startDate,
                'end' => $endDate,
                'signed_dates' => array_keys($dateSet),
                'records' => $calendar,
            ],
            'recent_records' => $recent,
            'config' => [
                'daily_reward' => $dailyReward,
                'referrer_reward' => self::getReferrerRewardScore(),
            ],
        ];
    }

    protected static function calculateStreak(array $dateSet, string $startDate): int
    {
        $streak = 0;
        $current = $startDate;
        while (isset($dateSet[$current])) {
            $streak++;
            $current = date('Y-m-d', strtotime($current . ' -1 day'));
        }
        return $streak;
    }
}

