<?php

declare(strict_types=1);

namespace app\api\controller;

use Throwable;
use RuntimeException;
use app\common\library\SignIn as SignInLibrary;
use app\common\library\LuckyDraw as LuckyDrawLibrary;
use app\common\controller\Frontend;
use app\common\model\UserSignIn;
use app\common\model\SignInActivity;
use hg\apidoc\annotation as Apidoc;

#[Apidoc\Title("每日签到")]
class SignIn extends Frontend
{
    protected array $noNeedLogin = ['rules'];
    protected array $noNeedPermission = ['rules'];

    #[
        Apidoc\Title("获取签到规则"),
        Apidoc\Tag("签到,配置"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/SignIn/rules"),
        Apidoc\Returned("code", type: "int", desc: "业务状态码"),
        Apidoc\Returned("msg", type: "string", desc: "提示信息"),
        Apidoc\Returned("data", type: "object", desc: "签到规则配置"),
        Apidoc\Returned("data.config.daily_reward", type: "int", desc: "每日签到消费金（系统配置）"),
        Apidoc\Returned("data.config.referrer_reward", type: "int", desc: "直推用户签到奖励消费金（系统配置）"),
        Apidoc\Returned("data.config.calendar_range_months", type: "int", desc: "签到日历可查看的前后月份范围"),
        Apidoc\Returned("data.config.calendar_start", type: "string", desc: "日历可查看范围起始日期"),
        Apidoc\Returned("data.config.calendar_end", type: "string", desc: "日历可查看范围结束日期"),
        Apidoc\Returned("data.activity", type: "object", desc: "当前有效活动信息（如果有）"),
        Apidoc\Returned("data.activity.id", type: "int", desc: "活动ID"),
        Apidoc\Returned("data.activity.name", type: "string", desc: "活动名称"),
        Apidoc\Returned("data.activity.start_time", type: "string", desc: "活动开始时间"),
        Apidoc\Returned("data.activity.end_time", type: "string", desc: "活动结束时间"),
        Apidoc\Returned("data.activity.register_reward", type: "float", desc: "注册奖励金额（元）"),
        Apidoc\Returned("data.activity.sign_reward_min", type: "float", desc: "签到奖励最小金额（元）"),
        Apidoc\Returned("data.activity.sign_reward_max", type: "float", desc: "签到奖励最大金额（元）"),
        Apidoc\Returned("data.activity.invite_reward_min", type: "float", desc: "邀请好友奖励最小金额（元）"),
        Apidoc\Returned("data.activity.invite_reward_max", type: "float", desc: "邀请好友奖励最大金额（元）"),
        Apidoc\Returned("data.activity.withdraw_min_amount", type: "float", desc: "提现最低金额（元）"),
        Apidoc\Returned("data.activity.withdraw_daily_limit", type: "int", desc: "每日提现次数限制（0表示不限制）"),
        Apidoc\Returned("data.activity.withdraw_audit_hours", type: "int", desc: "提现审核时间（小时）"),
        Apidoc\Returned("data.rules", type: "array", desc: "规则说明列表"),
        Apidoc\Returned("data.rules[].key", type: "string", desc: "规则标识"),
        Apidoc\Returned("data.rules[].title", type: "string", desc: "规则标题"),
        Apidoc\Returned("data.rules[].description", type: "string", desc: "规则说明文本"),
    ]
    /**
     * 获取签到规则（公开接口，无需登录）
     */
    public function rules()
    {
        try {
            $dailyReward = SignInLibrary::getDailyRewardScore();
            $referrerReward = SignInLibrary::getReferrerRewardScore();
            $calendarRangeMonths = SignInLibrary::getCalendarRange();

            $start = new \DateTimeImmutable('first day of this month');
            $end = new \DateTimeImmutable('last day of this month');
            if ($calendarRangeMonths > 0) {
                $start = $start->modify(sprintf('-%d months', $calendarRangeMonths));
                $end = $end->modify(sprintf('+%d months', $calendarRangeMonths));
            }

            // 获取当前有效活动
            $activity = SignInLibrary::getActiveActivity();
            $activityData = null;
            if ($activity && $activity->isActive()) {
                $activityData = [
                    'id' => $activity->id,
                    'name' => $activity->name,
                    'start_time' => $activity->start_time,
                    'end_time' => $activity->end_time,
                    'register_reward' => (float)$activity->register_reward,
                    'sign_reward_min' => (float)$activity->sign_reward_min,
                    'sign_reward_max' => (float)$activity->sign_reward_max,
                    'invite_reward_min' => (float)$activity->invite_reward_min,
                    'invite_reward_max' => (float)$activity->invite_reward_max,
                    'withdraw_min_amount' => (float)$activity->withdraw_min_amount,
                    'withdraw_daily_limit' => (int)$activity->withdraw_daily_limit,
                    'withdraw_audit_hours' => (int)$activity->withdraw_audit_hours,
                ];
            }

            $rules = [
                [
                    'key' => 'daily_reward',
                    'title' => '每日签到奖励',
                    'description' => $activityData
                        ? sprintf('每日首次签到可获得 %.2f - %.2f 元随机金额奖励', 
                            $activityData['sign_reward_min'], 
                            $activityData['sign_reward_max'])
                        : ($dailyReward > 0
                            ? sprintf('每日首次签到可获得 %d 消费金。', $dailyReward)
                            : '当前每日签到暂不奖励消费金。'),
                ],
                [
                    'key' => 'register_reward',
                    'title' => '注册奖励',
                    'description' => $activityData && $activityData['register_reward'] > 0
                        ? sprintf('新用户注册/激活可获得 %.2f 元奖励', 
                            $activityData['register_reward'])
                        : '当前暂无注册奖励活动。',
                ],
                [
                    'key' => 'invite_reward',
                    'title' => '邀请好友奖励',
                    'description' => $activityData && $activityData['invite_reward_min'] > 0
                        ? sprintf('邀请好友注册可获得 %.2f - %.2f 元随机金额奖励', 
                            $activityData['invite_reward_min'],
                            $activityData['invite_reward_max'])
                        : ($referrerReward > 0
                            ? sprintf('当直推用户完成签到时，邀请人额外获得 %d 消费金。', $referrerReward)
                            : '暂不额外奖励邀请人。'),
                ],
                [
                    'key' => 'withdraw_rules',
                    'title' => '提现规则',
                    'description' => $activityData && isset($activityData['withdraw_min_amount']) && $activityData['withdraw_min_amount'] > 0
                        ? sprintf(
                            '账户余额满 %.2f 元可申请提现，每人每天限提 %s 次，%d 小时内审核到账。',
                            $activityData['withdraw_min_amount'],
                            $activityData['withdraw_daily_limit'] > 0 ? (string)$activityData['withdraw_daily_limit'] : '不限',
                            $activityData['withdraw_audit_hours']
                        )
                        : '当前暂无提现活动规则。',
                ],
            ];

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    'config' => [
                        'daily_reward' => $dailyReward,
                        'referrer_reward' => $referrerReward,
                        'calendar_range_months' => $calendarRangeMonths,
                        'calendar_start' => $start->format('Y-m-01'),
                        'calendar_end' => $end->format('Y-m-t'),
                    ],
                    'activity' => $activityData,
                    'rules' => $rules,
                ],
            ]);
        } catch (Throwable $e) {
            return json([
                'code' => -1,
                'msg' => $e->getMessage(),
            ]);
        }
    }

    #[
        Apidoc\Title("签到概览"),
        Apidoc\Tag("签到,消费金"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/SignIn/info"),
        Apidoc\Returned("code", type: "int", desc: "业务状态码"),
        Apidoc\Returned("msg", type: "string", desc: "提示信息"),
        Apidoc\Returned("data.today_signed", type: "bool", desc: "今日是否已签到"),
        Apidoc\Returned("data.today_reward", type: "float", desc: "今日已领取奖励（消费金或金额）"),
        Apidoc\Returned("data.daily_reward", type: "float", desc: "每日签到奖励（消费金或金额）"),
        Apidoc\Returned("data.total_reward", type: "float", desc: "累计签到奖励（消费金或金额）"),
        Apidoc\Returned("data.sign_days", type: "int", desc: "累计签到天数"),
        Apidoc\Returned("data.streak", type: "int", desc: "连续签到天数"),
        Apidoc\Returned("data.reward_type", type: "string", desc: "奖励类型：money=金额,score=消费金"),
        Apidoc\Returned("data.calendar.signed_dates", type: "array", desc: "已签到日期列表"),
        Apidoc\Returned("data.recent_records", type: "array", desc: "最近签到记录"),
        Apidoc\Returned("data.config.daily_reward", type: "float", desc: "当前每日签到奖励配置"),
        Apidoc\Returned("data.config.referrer_reward", type: "int", desc: "直推签到奖励积分配置"),
        Apidoc\Returned("data.activity", type: "object", desc: "当前有效活动信息（如果有）"),
    ]
    public function info()
    {
        try {
            $statistics = SignInLibrary::getStatistics($this->auth->id);
            
            // 获取当前有效活动
            $activity = SignInLibrary::getActiveActivity();
            if ($activity && $activity->isActive()) {
                $statistics['activity'] = [
                    'id' => $activity->id,
                    'name' => $activity->name,
                    'start_time' => $activity->start_time,
                    'end_time' => $activity->end_time,
                    'register_reward' => (float)$activity->register_reward,
                    'sign_reward_min' => (float)$activity->sign_reward_min,
                    'sign_reward_max' => (float)$activity->sign_reward_max,
                    'invite_reward_min' => (float)$activity->invite_reward_min,
                    'invite_reward_max' => (float)$activity->invite_reward_max,
                ];
                $statistics['reward_type'] = 'money';
            } else {
                $statistics['reward_type'] = 'score';
            }
            
            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => $statistics
            ]);
        } catch (Throwable $e) {
            return json([
                'code' => -1,
                'msg' => $e->getMessage()
            ]);
        }
    }

    #[
        Apidoc\Title("执行签到"),
        Apidoc\Tag("签到,消费金"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/SignIn/do"),
        Apidoc\Returned("code", type: "int", desc: "业务状态码"),
        Apidoc\Returned("msg", type: "string", desc: "提示信息"),
        Apidoc\Returned("data.sign_record_id", type: "int", desc: "签到记录ID"),
        Apidoc\Returned("data.sign_date", type: "string", desc: "签到日期"),
        Apidoc\Returned("data.today_signed", type: "bool", desc: "今日是否已签到"),
        Apidoc\Returned("data.today_reward", type: "float", desc: "今日已领取奖励（消费金或金额）"),
        Apidoc\Returned("data.daily_reward", type: "float", desc: "本次签到获得的奖励（消费金或金额）"),
        Apidoc\Returned("data.total_reward", type: "float", desc: "累计签到奖励（消费金或金额）"),
        Apidoc\Returned("data.sign_days", type: "int", desc: "累计签到天数"),
        Apidoc\Returned("data.streak", type: "int", desc: "连续签到天数"),
        Apidoc\Returned("data.reward_type", type: "string", desc: "奖励类型：money=金额,score=消费金"),
        Apidoc\Returned("data.referrer_reward", type: "int", desc: "邀请人此次获取消费金（仅消费金模式）"),
    ]
    public function do()
    {
        try {
            $result = SignInLibrary::sign($this->auth->id);
            return json([
                'code' => 0,
                'msg' => $result['message'] ?? 'success',
                'data' => $result
            ]);
        } catch (RuntimeException $e) {
            return json([
                'code' => -1,
                'msg' => $e->getMessage()
            ]);
        } catch (Throwable $e) {
            return json([
                'code' => -1,
                'msg' => $e->getMessage()
            ]);
        }
    }

    #[
        Apidoc\Title("签到进度"),
        Apidoc\Tag("签到,进度,提现"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/SignIn/progress"),
        Apidoc\Returned("code", type: "int", desc: "业务状态码"),
        Apidoc\Returned("msg", type: "string", desc: "提示信息"),
        Apidoc\Returned("data.withdrawable_money", type: "float", desc: "当前可提现金额（元）"),
        Apidoc\Returned("data.withdraw_min_amount", type: "float", desc: "提现最低金额（元）"),
        Apidoc\Returned("data.progress", type: "float", desc: "提现进度百分比（0-100）"),
        Apidoc\Returned("data.remaining_amount", type: "float", desc: "距离提现还差金额（元）"),
        Apidoc\Returned("data.can_withdraw", type: "bool", desc: "是否可提现"),
        Apidoc\Returned("data.total_money", type: "float", desc: "总余额（元）"),
        Apidoc\Returned("data.activity", type: "object", desc: "当前有效活动信息（如果有）"),
    ]
    /**
     * 签到进度（显示距离提现门槛的进度）
     */
    public function progress()
    {
        try {
            $user = $this->auth->getUser();
            if (!$user) {
                return json([
                    'code' => -1,
                    'msg' => '用户不存在'
                ]);
            }

            $withdrawableMoney = (float)$user->withdrawable_money;
            $totalMoney = (float)$user->money;

            // 获取当前有效活动
            $activity = SignInLibrary::getActiveActivity();
            $activityData = null;
            $withdrawMinAmount = 0;

            if ($activity && $activity->isActive()) {
                $withdrawMinAmount = (float)$activity->withdraw_min_amount;
                $activityData = [
                    'id' => $activity->id,
                    'name' => $activity->name,
                    'withdraw_min_amount' => $withdrawMinAmount,
                    'withdraw_daily_limit' => (int)$activity->withdraw_daily_limit,
                    'withdraw_audit_hours' => (int)$activity->withdraw_audit_hours,
                ];
            } else {
                // 使用系统配置
                $withdrawMinAmount = (float)(get_sys_config('withdraw_min_amount') ?? 0);
            }

            // 计算进度
            $progress = 0;
            $remainingAmount = 0;
            $canWithdraw = false;

            if ($withdrawMinAmount > 0) {
                if ($withdrawableMoney >= $withdrawMinAmount) {
                    $progress = 100;
                    $canWithdraw = true;
                } else {
                    $progress = round(($withdrawableMoney / $withdrawMinAmount) * 100, 2);
                    $remainingAmount = round($withdrawMinAmount - $withdrawableMoney, 2);
                }
            } else {
                // 没有设置提现门槛，默认100%
                $progress = 100;
                $canWithdraw = true;
            }

            return json([
                'code' => 0,
                'msg' => 'success',
                'data' => [
                    'withdrawable_money' => $withdrawableMoney,
                    'withdraw_min_amount' => $withdrawMinAmount,
                    'progress' => $progress,
                    'remaining_amount' => $remainingAmount,
                    'can_withdraw' => $canWithdraw,
                    'total_money' => $totalMoney,
                    'activity' => $activityData,
                ]
            ]);
        } catch (Throwable $e) {
            return json([
                'code' => -1,
                'msg' => $e->getMessage()
            ]);
        }
    }

    #[
        Apidoc\Title("签到记录"),
        Apidoc\Tag("签到,消费金"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/SignIn/records"),
        Apidoc\Query(name: "page", type: "int", require: false, desc: "页码", example: "1"),
        Apidoc\Query(name: "page_size", type: "int", require: false, desc: "每页数量", example: "10"),
        Apidoc\Returned("code", type: "int", desc: "业务状态码"),
        Apidoc\Returned("msg", type: "string", desc: "提示信息"),
        Apidoc\Returned("data.total", type: "int", desc: "总记录数"),
        Apidoc\Returned("data.page", type: "int", desc: "当前页码"),
        Apidoc\Returned("data.page_size", type: "int", desc: "每页数量"),
        Apidoc\Returned("data.total_score", type: "int", desc: "累计签到总消费金（系统模式）"),
        Apidoc\Returned("data.total_money", type: "float", desc: "累计签到总金额（活动模式）"),
        Apidoc\Returned("data.is_today_signed", type: "bool", desc: "今天是否已签到"),
        Apidoc\Returned("data.lucky_draw_info", type: "object", desc: "抽奖信息"),
        Apidoc\Returned("data.lucky_draw_info.current_draw_count", type: "int", desc: "当前可抽奖次数"),
        Apidoc\Returned("data.lucky_draw_info.daily_limit", type: "int", desc: "每日抽奖次数上限(0表示不限)"),
        Apidoc\Returned("data.lucky_draw_info.used_today", type: "int", desc: "今日已抽次数"),
        Apidoc\Returned("data.lucky_draw_info.remaining_count", type: "int", desc: "今日剩余可抽次数"),
        Apidoc\Returned("data.lucky_draw_rules", type: "string", desc: "抽奖规则说明"),
        Apidoc\Returned("data.records", type: "array", desc: "签到记录列表"),
        Apidoc\Returned("data.records[].reward_type", type: "string", desc: "奖励类型：money=金额,score=消费金"),
        Apidoc\Returned("data.records[].reward_money", type: "float", desc: "奖励金额（活动模式）"),
        Apidoc\Returned("data.records[].reward_score", type: "int", desc: "奖励消费金（系统模式）"),
    ]
    public function records()
    {
        $page = max(1, (int)$this->request->post('page', 1));
        $pageSize = max(1, min(100, (int)$this->request->post('page_size', 10)));

        $query = UserSignIn::where('user_id', $this->auth->id);
        $total = (clone $query)->count();
        
        // 判断今天是否已签到
        $today = date('Y-m-d');
        $isTodaySigned = (clone $query)->where('sign_date', $today)->count() > 0;
        
        // 获取用户抽奖信息
        $luckyDrawInfo = LuckyDrawLibrary::getUserStats($this->auth->id);
        
        // 抽奖规则说明
        $luckyDrawRules = LuckyDrawLibrary::getConfig('draw_rules', '每次签到可获得抽奖次数，每日可抽奖次数有限，抽奖可获得积分、余额、优惠券等奖励。');
        
        $records = $query->order('sign_date', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        $list = array_map(static function ($item) {
            $extra = $item['extra'] ?? [];
            $config = $extra['config'] ?? [];
            $rewardType = $extra['reward_type'] ?? 'score';
            return [
                'id' => $item['id'],
                'sign_date' => $item['sign_date'],
                'reward_score' => (int)($item['reward_score'] ?? 0),
                'reward_money' => (float)($extra['reward_money'] ?? 0),
                'reward_type' => $rewardType,
                'create_time' => $item['create_time'],
                'config' => [
                    'daily_reward' => $rewardType === 'money' 
                        ? (float)($extra['reward_money'] ?? 0)
                        : (int)($config['daily_reward'] ?? $item['reward_score']),
                    'referrer_reward' => (int)($config['referrer_reward'] ?? 0),
                ],
            ];
        }, $records);
        
        // 计算总金额和总积分
        $totalMoney = 0;
        $totalScore = 0;
        foreach ($list as $record) {
            if ($record['reward_type'] === 'money') {
                $totalMoney += $record['reward_money'];
            } else {
                $totalScore += $record['reward_score'];
            }
        }

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
                'total_score' => $totalScore,
                'total_money' => round($totalMoney, 2),
                'is_today_signed' => $isTodaySigned,
                'lucky_draw_info' => [
                    'current_draw_count' => $luckyDrawInfo['current_draw_count'] ?? 0,
                    'daily_limit' => $luckyDrawInfo['daily_limit'] ?? 0,
                    'used_today' => $luckyDrawInfo['used_today'] ?? 0,
                    'remaining_count' => $luckyDrawInfo['remaining_count'] ?? 0,
                ],
                'lucky_draw_rules' => $luckyDrawRules,
                'records' => $list,
            ],
        ]);
    }
}

