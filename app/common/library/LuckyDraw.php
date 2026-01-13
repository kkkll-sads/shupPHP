<?php

namespace app\common\library;

use app\admin\model\LuckyDrawPrize;
use app\admin\model\LuckyDrawRecord;
use app\admin\model\LuckyDrawConfig;
use app\admin\model\LuckyDrawDailyCount;
use app\common\model\User;
use app\common\model\UserActivityLog;
use app\common\model\UserScoreLog;
use think\Exception;
use think\facade\Db;

/**
 * 幸运转盘业务逻辑类
 */
class LuckyDraw
{
    /**
     * 获取配置值
     */
    public static function getConfig(string $key, $default = null)
    {
        $config = LuckyDrawConfig::where('config_key', $key)->find();
        return $config ? $config['config_value'] : $default;
    }

    /**
     * 设置配置值
     */
    public static function setConfig(string $key, $value, string $remark = ''): bool
    {
        $config = LuckyDrawConfig::where('config_key', $key)->find();
        if ($config) {
            $config->config_value = $value;
            if ($remark !== '') {
                $config->remark = $remark;
            }
            return (bool)$config->save();
        } else {
            return (bool)LuckyDrawConfig::create([
                'config_key' => $key,
                'config_value' => $value,
                'remark' => $remark,
            ]);
        }
    }

    /**
     * 获取所有启用的奖品
     */
    public static function getEnabledPrizes(): array
    {
        return LuckyDrawPrize::where('status', '1')
            ->order('sort', 'desc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 根据概率抽取奖品
     */
    public static function drawPrizeByProbability(): ?LuckyDrawPrize
    {
        $prizes = self::getEnabledPrizes();
        if (empty($prizes)) {
            return null;
        }

        $totalProbability = 0;
        foreach ($prizes as $prize) {
            $totalProbability += $prize['probability'];
        }

        if ($totalProbability <= 0) {
            return null;
        }

        $rand = mt_rand(1, $totalProbability);
        $currentProbability = 0;

        foreach ($prizes as $prize) {
            $currentProbability += $prize['probability'];
            if ($rand <= $currentProbability) {
                return new LuckyDrawPrize($prize);
            }
        }

        return null;
    }

    /**
     * 检查用户是否可以进行抽奖
     */
    public static function checkCanDraw(int $userId): array
    {
        // 检查用户是否存在
        $user = User::find($userId);
        if (!$user) {
            return ['code' => -1, 'msg' => '用户不存在', 'can_draw' => false];
        }

        // 获取配置
        $dailyLimit = (int)self::getConfig('daily_draw_limit', 5);
        $availableDrawCount = (int)($user['draw_count'] ?? 0);

        if ($availableDrawCount <= 0) {
            return [
                'code' => -2,
                'msg' => '抽奖次数不足',
                'can_draw' => false,
                'current_draw_count' => 0,
                'current_score' => 0,
                'remaining_count' => 0,
                'daily_limit' => $dailyLimit,
                'used_today' => 0,
                'score_cost' => 0,
            ];
        }

        // 检查每日抽奖次数
        $today = date('Y-m-d');
        $todayRecord = LuckyDrawDailyCount::where([
            'user_id' => $userId,
            'draw_date' => $today
        ])->find();

        $usedToday = $todayRecord ? (int)$todayRecord['draw_count'] : 0;
        $dailyRemaining = $dailyLimit > 0 ? max(0, $dailyLimit - $usedToday) : $availableDrawCount;
        $remainingCount = $dailyLimit > 0 ? min($dailyRemaining, $availableDrawCount) : $availableDrawCount;

        if ($remainingCount <= 0) {
            return [
                'code' => -3,
                'msg' => '今日抽奖次数已用完',
                'can_draw' => false,
                'current_draw_count' => $availableDrawCount,
                'current_score' => $availableDrawCount,
                'remaining_count' => 0,
                'daily_limit' => $dailyLimit,
                'used_today' => $usedToday,
                'score_cost' => 0,
            ];
        }

        return [
            'code' => 0,
            'msg' => '可以抽奖',
            'can_draw' => true,
            'current_draw_count' => $availableDrawCount,
            'current_score' => $availableDrawCount,
            'remaining_count' => $remainingCount,
            'daily_limit' => $dailyLimit,
            'used_today' => $usedToday,
            'score_cost' => 0,
        ];
    }

    /**
     * 执行抽奖
     */
    public static function draw(int $userId): array
    {
        // 验证是否可以抽奖
        $checkResult = self::checkCanDraw($userId);
        if (!$checkResult['can_draw']) {
            return $checkResult;
        }

        try {
            Db::startTrans();

            $user = User::lock(true)->find($userId);
            if (!$user) {
                Db::rollback();
                return ['code' => -1, 'msg' => '用户不存在', 'can_draw' => false];
            }

            $availableDrawCount = (int)($user['draw_count'] ?? 0);
            if ($availableDrawCount <= 0) {
                Db::rollback();
                return [
                    'code' => -2,
                    'msg' => '抽奖次数不足',
                    'can_draw' => false,
                    'current_draw_count' => 0,
                    'current_score' => 0,
                    'remaining_count' => 0,
                    'daily_limit' => $checkResult['daily_limit'] ?? 0,
                    'used_today' => $checkResult['used_today'] ?? 0,
                    'score_cost' => 0,
                ];
            }

            // 抽取奖品
            $prize = self::drawPrizeByProbability();
            if (!$prize) {
                Db::rollback();
                return ['code' => -4, 'msg' => '没有可用的奖品', 'can_draw' => false];
            }

            // 检查奖品数量限制
            if ($prize['daily_limit'] > 0 && $prize['daily_count'] >= $prize['daily_limit']) {
                Db::rollback();
                return ['code' => -5, 'msg' => '该奖品今日已达到领取上限', 'can_draw' => false];
            }

            if ($prize['total_limit'] > 0 && $prize['total_count'] >= $prize['total_limit']) {
                Db::rollback();
                return ['code' => -6, 'msg' => '该奖品已达到领取上限', 'can_draw' => false];
            }

            // 创建抽奖记录
            $drawTime = time();
            $record = LuckyDrawRecord::create([
                'user_id' => $userId,
                'prize_id' => $prize['id'],
                'prize_name' => $prize['name'],
                'prize_type' => $prize['prize_type'],
                'prize_value' => $prize['prize_value'],
                'status' => 1,  // 待发放
                'draw_time' => $drawTime,
                'create_time' => $drawTime
            ]);

            // 更新奖品统计
            LuckyDrawPrize::where('id', $prize['id'])->update([
                'daily_count' => Db::raw('daily_count + 1'),
                'total_count' => Db::raw('total_count + 1'),
                'update_time' => time(),
            ]);

            // 更新每日抽奖次数
            $today = date('Y-m-d');
            $dailyCount = LuckyDrawDailyCount::where([
                'user_id' => $userId,
                'draw_date' => $today
            ])->find();

            if ($dailyCount) {
                LuckyDrawDailyCount::where('id', $dailyCount['id'])->update([
                    'draw_count' => Db::raw('draw_count + 1'),
                    'update_time' => time(),
                ]);
            } else {
                LuckyDrawDailyCount::create([
                    'user_id' => $userId,
                    'draw_date' => $today,
                    'draw_count' => 1,
                    'reset_time' => time(),
                    'create_time' => time(),
                    'update_time' => time()
                ]);
            }

            // 扣减用户剩余抽奖次数
            $affected = Db::name('user')
                ->where('id', $userId)
                ->where('draw_count', '>', 0)
                ->update([
                    'draw_count' => Db::raw('draw_count - 1'),
                    'update_time' => time(),
                ]);
            if ($affected === 0) {
                throw new Exception('扣减抽奖次数失败');
            }
            $currentDrawCount = max(0, $availableDrawCount - 1);

            // 记录抽奖行为到活动日志
            $typeNameMap = [
                'score' => '积分',
                'money' => '余额',
                'coupon' => '优惠券',
                'item' => '实物',
            ];
            $typeName = $typeNameMap[$prize['prize_type']] ?? $prize['prize_type'];
            
            UserActivityLog::create([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'lucky_draw',
                'change_field' => 'draw_count',
                'change_value' => -1,
                'before_value' => $availableDrawCount,
                'after_value' => $currentDrawCount,
                'remark' => sprintf('抽奖：获得 %s (%s)', $prize['name'], $typeName),
                'extra' => [
                    'record_id' => $record->id,
                    'prize_id' => $prize['id'],
                    'prize_name' => $prize['name'],
                    'prize_type' => $prize['prize_type'],
                    'prize_value' => $prize['prize_value'],
                    'draw_time' => $drawTime,
                ],
            ]);

            // 是否自动发放奖品
            $autoSend = (int)self::getConfig('prize_send_auto', 1);
            if ($autoSend == 1) {
                $sendResult = self::sendPrize($record->id, $userId, $prize['prize_type'], $prize['prize_value']);
                if (!$sendResult) {
                    throw new Exception('自动发放奖品失败');
                }
            }

            Db::commit();

            $dailyLimit = (int)self::getConfig('daily_draw_limit', 5);
            $todayRecord = LuckyDrawDailyCount::where([
                'user_id' => $userId,
                'draw_date' => $today
            ])->find();
            $usedToday = $todayRecord ? (int)$todayRecord['draw_count'] : 0;
            $dailyRemaining = $dailyLimit > 0 ? max(0, $dailyLimit - $usedToday) : $currentDrawCount;
            $remainingCount = $dailyLimit > 0 ? min($dailyRemaining, $currentDrawCount) : $currentDrawCount;

            return [
                'code' => 0,
                'msg' => '抽奖成功',
                'data' => [
                    'prize_id' => $prize['id'],
                    'prize_name' => $prize['name'],
                    'prize_type' => $prize['prize_type'],
                    'prize_value' => $prize['prize_value'],
                    'description' => $prize['description'],
                    'thumbnail' => $prize['thumbnail'],
                    'record_id' => $record->id
                ],
                'current_draw_count' => $currentDrawCount,
                'current_score' => $currentDrawCount,
                'remaining_count' => $remainingCount,
                'daily_limit' => $dailyLimit,
                'used_today' => $usedToday,
                'score_cost' => 0,
                'can_draw' => $remainingCount > 0,
            ];
        } catch (\Exception $e) {
            Db::rollback();
            return ['code' => -99, 'msg' => '抽奖失败: ' . $e->getMessage(), 'can_draw' => false];
        }
    }

    /**
     * 发放奖品
     */
    public static function sendPrize(int $recordId, int $userId, string $prizeType, int $prizeValue): bool
    {
        try {
            $record = LuckyDrawRecord::find($recordId);
            if (!$record) {
                throw new Exception('抽奖记录不存在');
            }
            if ($record['status'] == 2) {
                return true;  // 已发放，直接返回成功
            }

            $user = User::find($userId);
            if (!$user) {
                throw new Exception('用户不存在');
            }

            $beforeValue = 0;
            $afterValue = 0;
            $changeField = '';

            switch ($prizeType) {
                case 'score':
                    // 记录积分日志（自动更新用户积分）
                    $beforeValue = (float)$user['score'];
                    $changeField = 'score';

                    $scoreLog = UserScoreLog::create([
                        'user_id' => $userId,
                        'score' => (float)$prizeValue,
                        'memo' => sprintf('抽奖奖品发放：%s', $record['prize_name']),
                    ]);
                    $afterValue = (float)$scoreLog['after'];
                    $user->setAttr('score', $afterValue);
                    break;
                case 'money':
                    // 增加金额（prizeValue 和数据库 money 字段都以元为单位，直接使用原始值）
                    $beforeValue = (float)$user['money'];
                    $increment = (float)$prizeValue;
                    $afterValue = $beforeValue + $increment;
                    $changeField = 'money';
                    
                    Db::name('user')->where('id', $userId)->update([
                        'money' => Db::raw('money + ' . $increment),
                        'update_time' => time(),
                    ]);
                    $user->setAttr('money', $afterValue);
                    break;
                case 'coupon':
                case 'item':
                    // 优惠券和实物需要人工处理，这里只记录
                    break;
            }

            // 更新记录状态为已发放
            LuckyDrawRecord::where('id', $recordId)->update([
                'status' => 2,
                'send_time' => time()
            ]);

            // 记录活动日志
            $typeNameMap = [
                'score' => '积分',
                'money' => '余额',
                'coupon' => '优惠券',
                'item' => '实物',
            ];
            $typeName = $typeNameMap[$prizeType] ?? $prizeType;
            
            // 对于积分和余额类型，记录变动值
            if (in_array($prizeType, ['score', 'money']) && $changeField) {
                UserActivityLog::create([
                    'user_id' => $userId,
                    'related_user_id' => 0,
                    'action_type' => 'lucky_draw_prize',
                    'change_field' => $changeField,
                    'change_value' => $prizeValue, // prizeValue 已经是分为单位，直接使用
                    'before_value' => $beforeValue,
                    'after_value' => $afterValue,
                    'remark' => sprintf('抽奖奖品发放：%s (%s)', $record['prize_name'], $typeName),
                    'extra' => [
                        'record_id' => $recordId,
                        'prize_name' => $record['prize_name'],
                        'prize_type' => $prizeType,
                        'prize_value' => $prizeValue,
                        'draw_time' => $record['draw_time'],
                    ],
                ]);
            } else {
                // 对于优惠券和实物类型，也记录活动日志（但不记录变动值）
                UserActivityLog::create([
                    'user_id' => $userId,
                    'related_user_id' => 0,
                    'action_type' => 'lucky_draw_prize',
                    'change_field' => '',
                    'change_value' => 0,
                    'before_value' => 0,
                    'after_value' => 0,
                    'remark' => sprintf('抽奖奖品发放：%s (%s)', $record['prize_name'], $typeName),
                    'extra' => [
                        'record_id' => $recordId,
                        'prize_name' => $record['prize_name'],
                        'prize_type' => $prizeType,
                        'prize_value' => $prizeValue,
                        'draw_time' => $record['draw_time'],
                    ],
                ]);
            }

            return true;
        } catch (\Exception $e) {
            // 重新抛出异常，让调用者处理
            throw $e;
        }
    }

    /**
     * 获取用户的抽奖记录
     */
    public static function getUserRecords(int $userId, int $page = 1, int $pageSize = 10): array
    {
        $query = LuckyDrawRecord::where('user_id', $userId);
        $total = $query->count();
        $records = $query->order('draw_time', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'records' => $records
        ];
    }

    /**
     * 获取用户抽奖统计
     */
    public static function getUserStats(int $userId): array
    {
        $user = User::find($userId);
        if (!$user) {
            return [];
        }

        $today = date('Y-m-d');
        $todayRecord = LuckyDrawDailyCount::where([
            'user_id' => $userId,
            'draw_date' => $today
        ])->find();

        $dailyLimit = (int)self::getConfig('daily_draw_limit', 5);
        $usedToday = $todayRecord ? (int)$todayRecord['draw_count'] : 0;
        $currentDrawCount = (int)($user['draw_count'] ?? 0);
        $dailyRemaining = $dailyLimit > 0 ? max(0, $dailyLimit - $usedToday) : $currentDrawCount;
        $remainingCount = $dailyLimit > 0 ? min($dailyRemaining, $currentDrawCount) : $currentDrawCount;

        return [
            'current_draw_count' => $currentDrawCount,
            'current_score' => $currentDrawCount,
            'daily_limit' => $dailyLimit,
            'used_today' => $usedToday,
            'remaining_count' => max(0, $remainingCount),
            'score_cost' => 0,
            'total_draw_count' => LuckyDrawRecord::where('user_id', $userId)->count(),
            'total_win_count' => LuckyDrawRecord::where('user_id', $userId)->whereIn('status', [1, 2])->count()
        ];
    }

    /**
     * 清除已过期的每日计数
     */
    public static function clearExpiredDailyCount(): int
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        return Db::name('lucky_draw_daily_count')->where('draw_date', '<', $yesterday)->delete();
    }

    /**
     * 重置奖品每日计数
     */
    public static function resetPrizesDailyCount(): int
    {
        return Db::name('lucky_draw_prize')->update(['daily_count' => 0]);
    }
}

