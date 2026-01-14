<?php

namespace app\common\service;

use think\facade\Db;
use think\facade\Log;

/**
 * 用户服务类
 * 处理用户相关的业务逻辑
 */
class UserService
{
    /**
     * 购买藏品后检查并升级用户等级，同时为交易用户发放场次+区间绑定的寄售券
     * 
     * 规则：
     * - 第1次购买完成：升级为普通用户 (user_type: 0→1)
     * - 第2次购买完成：升级为交易用户 (user_type: 1→2)
     * - 交易用户(user_type >= 2)每次购买都发放一张绑定到场次+价格区间的寄售券
     * 
     * @param int $userId 用户ID
     * @param int $sessionId 场次ID（用于绑定寄售券）
     * @param int $zoneId 价格区间ID（用于绑定寄售券）
     * @return array 升级结果
     */
    public static function checkAndUpgradeUserAfterPurchase(int $userId, int $sessionId = 0, int $zoneId = 0, bool $forceIssueCoupon = false): array
    {
        $result = [
            'upgraded' => false,
            'old_user_type' => null,
            'new_user_type' => null,
            'coupon_issued' => false,
            'coupon_id' => null,
            'purchase_count' => 0,
        ];

        if ($userId <= 0) {
            return $result;
        }

        try {
            // 统计用户购买次数（user_collection记录数）
            $purchaseCount = Db::name('user_collection')
                ->where('user_id', $userId)
                ->count();

            $result['purchase_count'] = $purchaseCount;

            // 获取当前用户信息
            $user = Db::name('user')
                ->where('id', $userId)
                ->find();

            if (!$user) {
                Log::warning('UserService::checkAndUpgradeUserAfterPurchase - 用户不存在', [
                    'user_id' => $userId
                ]);
                return $result;
            }

            $currentUserType = (int)($user['user_type'] ?? 0);
            $result['old_user_type'] = $currentUserType;

            $now = time();
            $updateData = [];
            
            // 🚀 如果强制发放（如旧资产），则默认需要发券
            $needIssueCoupon = $forceIssueCoupon;

            // 用户等级升级逻辑
            if ($currentUserType < 2) {
                // 🔧 修复：如果当前等级 < 1，无论购买次数多少，都应该先升级到1（触发首次交易奖励）
                if ($currentUserType < 1 && $purchaseCount >= 1) {
                    $updateData['user_type'] = 1;
                    $result['new_user_type'] = 1;
                    
                    Log::info('UserService - 用户首次购买，升级为普通用户', [
                        'user_id' => $userId,
                        'purchase_count' => $purchaseCount,
                        'old_user_type' => $currentUserType,
                        'new_user_type' => 1,
                    ]);
                }
                // 如果购买次数 >= 2 且当前等级 < 2：升级为交易用户
                elseif ($purchaseCount >= 2 && $currentUserType < 2) {
                    $updateData['user_type'] = 2;
                    $result['new_user_type'] = 2;
                    $needIssueCoupon = true; // 首次升级为交易用户时发放一张寄售券
                    
                    Log::info('UserService - 用户升级为交易用户', [
                        'user_id' => $userId,
                        'purchase_count' => $purchaseCount,
                        'old_user_type' => $currentUserType,
                        'new_user_type' => 2,
                    ]);
                }
            } else {
                // 已经是交易用户（user_type >= 2），每次购买都发放寄售券
                $needIssueCoupon = true;
            }

            // 更新用户等级
            if (!empty($updateData)) {
                $updateData['update_time'] = $now;

                Db::name('user')
                    ->where('id', $userId)
                    ->update($updateData);

                $result['upgraded'] = true;

                // 记录用户升级活动日志
                $remark = $result['new_user_type'] == 1
                    ? '首次购买藏品，升级为普通用户'
                    : '购买藏品满2次，升级为交易用户';

                Db::name('user_activity_log')->insert([
                    'user_id' => $userId,
                    'related_user_id' => 0,
                    'action_type' => 'user_type_upgrade',
                    'change_field' => 'user_type',
                    'change_value' => (float)$result['new_user_type'],
                    'before_value' => (float)$currentUserType,
                    'after_value' => (float)$result['new_user_type'],
                    'remark' => $remark,
                    'extra' => json_encode([
                        'purchase_count' => $purchaseCount,
                        'trigger' => 'collection_purchase',
                    ], JSON_UNESCAPED_UNICODE),
                    'create_time' => $now,
                    'update_time' => $now,
                ]);

                // 首次交易奖励上级（邀请人）
                if ($result['new_user_type'] == 1) {
                    // 奖励上级
                    if ($user['pid'] > 0) {
                        self::rewardInviterOnSubordinateFirstTrade($user['pid'], $userId, $now);
                    }
                    // 奖励自己
                    self::rewardFirstTrade($userId, $now);
                }
            }

            // 发放场次+区间绑定的寄售券
            if ($needIssueCoupon && $sessionId > 0 && $zoneId > 0) {
                $couponId = self::issueConsignmentCoupon($userId, $sessionId, $zoneId);
                if ($couponId) {
                    $result['coupon_issued'] = true;
                    $result['coupon_id'] = $couponId;
                }
            }

        } catch (\Exception $e) {
            Log::error('UserService::checkAndUpgradeUserAfterPurchase - 异常', [
                'user_id' => $userId,
                'session_id' => $sessionId,
                'zone_id' => $zoneId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        return $result;
    }

    /**
     * 发放场次+价格区间绑定的寄售券
     * 
     * @param int $userId 用户ID
     * @param int $sessionId 场次ID
     * @param int $zoneId 价格区间ID
     * @param int $expireDays 有效天数（默认30天）
     * @return int|null 寄售券ID，失败返回null
     */
    public static function issueConsignmentCoupon(int $userId, int $sessionId, int $zoneId, int $expireDays = 30): ?int
    {
        if ($userId <= 0 || $sessionId <= 0 || $zoneId <= 0) {
            return null;
        }

        try {
            $now = time();
            $expireTime = $now + ($expireDays * 86400);

            // 获取价格区间信息
            $zone = Db::name('price_zone_config')
                ->where('id', $zoneId)
                ->find();

            $zoneName = $zone ? $zone['name'] : "区间{$zoneId}";
            // 修复：price_zone字段长度限制为20字符，避免插入失败
            $zoneName = mb_substr($zoneName, 0, 20, 'UTF-8');

            // 插入寄售券记录
            $couponId = Db::name('user_consignment_coupon')->insertGetId([
                'user_id' => $userId,
                'session_id' => $sessionId,
                'zone_id' => $zoneId,
                'price_zone' => $zoneName, // 兼容旧字段，已截断长度
                'expire_time' => $expireTime,
                'status' => 1, // 1=可用
                'create_time' => $now,
                'update_time' => $now,
            ]);

            if ($couponId) {
                // 获取用户当前寄售券数量
                $currentCount = (int)Db::name('user_consignment_coupon')
                    ->where('user_id', $userId)
                    ->where('status', 1)
                    ->where('expire_time', '>', $now)
                    ->count();

                // 同步更新user表的consignment_coupon字段
                Db::name('user')->where('id', $userId)->update([
                    'consignment_coupon' => $currentCount,
                    'update_time' => $now,
                ]);

                // 记录活动日志
                Db::name('user_activity_log')->insert([
                    'user_id' => $userId,
                    'related_user_id' => 0,
                    'action_type' => 'consignment_coupon_issued',
                    'change_field' => 'consignment_coupon',
                    'change_value' => 1,
                    'before_value' => $currentCount - 1,
                    'after_value' => $currentCount,
                    'remark' => "获得寄售券：{$zoneName}（场次#{$sessionId}）",
                    'extra' => json_encode([
                        'coupon_id' => $couponId,
                        'session_id' => $sessionId,
                        'zone_id' => $zoneId,
                        'zone_name' => $zoneName,
                        'expire_time' => $expireTime,
                        'trigger' => 'collection_purchase',
                    ], JSON_UNESCAPED_UNICODE),
                    'create_time' => $now,
                    'update_time' => $now,
                ]);

                Log::info('UserService - 发放寄售券', [
                    'user_id' => $userId,
                    'coupon_id' => $couponId,
                    'session_id' => $sessionId,
                    'zone_id' => $zoneId,
                    'zone_name' => $zoneName,
                ]);

                return $couponId;
            }
        } catch (\Exception $e) {
            Log::error('UserService::issueConsignmentCoupon - 异常', [
                'user_id' => $userId,
                'session_id' => $sessionId,
                'zone_id' => $zoneId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * 发放一次性寄售券（用于旧资产解锁等场景）
     * 确保每个用户只发放一次
     * 
     * @param int $userId 用户ID
     * @param int $sessionId 场次ID 
     * @param int $zoneId 价格区间ID
     * @return int|null 寄售券ID
     */
    public static function addConsignmentCouponOnce(int $userId, int $sessionId, int $zoneId): ?int
    {
        // 检查是否已经发放过（通过 remark 标记来判断，避免重复）
        $exists = Db::name('user_consignment_coupon')
            ->alias('c')
            ->join('user_activity_log l', 'l.extra like concat("%", c.id, "%")')
            ->where('c.user_id', $userId)
            ->where('l.action_type', 'old_assets_unlock')
            ->count();
            
        if ($exists > 0) {
            return null;
        }

        return self::issueConsignmentCoupon($userId, $sessionId, $zoneId);
    }

    /**
     * 检查用户是否有可用的寄售券（用于寄售时验证）
     * 
     * @param int $userId 用户ID
     * @param int $sessionId 场次ID
     * @param int $targetZoneId 目标价格区间ID
     * @return array|null 可用的寄售券信息，无则返回null
     */
    public static function getAvailableCouponForConsignment(int $userId, int $sessionId, int $targetZoneId): ?array
    {
        if ($userId <= 0 || $sessionId <= 0 || $targetZoneId <= 0) {
            return null;
        }

        try {
            $now = time();

            // 查找可用的寄售券：
            // 1. 同一场次
            // 2. 不限价格分区（已移除分区限制）
            
            $coupon = Db::name('user_consignment_coupon')
                ->where('user_id', $userId)
                ->where('session_id', $sessionId)
                ->where('status', 1) // 可用
                ->where('expire_time', '>', $now)
                // 移除分区限制：->whereIn('zone_id', $allowedZones)
                ->order('expire_time asc') // 优先使用快过期的
                ->find();

            return $coupon;
        } catch (\Exception $e) {
            Log::error('UserService::getAvailableCouponForConsignment - 异常', [
                'user_id' => $userId,
                'session_id' => $sessionId,
                'target_zone_id' => $targetZoneId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * 使用寄售券（带行锁，确保并发安全）
     * 
     * @param int $couponId 寄售券ID
     * @param int $userId 用户ID（验证归属）
     * @return bool 是否成功
     * @throws \Exception 券不存在或已被使用时抛出异常
     */
    public static function useCoupon(int $couponId, int $userId): bool
    {
        if ($couponId <= 0 || $userId <= 0) {
            throw new \Exception('寄售券ID或用户ID无效');
        }

        try {
            $now = time();

            // 先锁定券记录，防止并发重复使用
            $coupon = Db::name('user_consignment_coupon')
                ->where('id', $couponId)
                ->where('user_id', $userId)
                ->lock(true)
                ->find();

            if (!$coupon) {
                throw new \Exception('寄售券不存在或不属于当前用户');
            }

            if ((int)$coupon['status'] !== 1) {
                throw new \Exception('寄售券已被使用或已过期');
            }

            // 检查券是否过期
            if ((int)$coupon['expire_time'] <= $now) {
                throw new \Exception('寄售券已过期');
            }

            // 更新券状态为已使用
            $updated = Db::name('user_consignment_coupon')
                ->where('id', $couponId)
                ->where('user_id', $userId)
                ->where('status', 1)
                ->update([
                    'status' => 0, // 0=已使用
                    // 注意：表中没有 use_time 字段，使用 update_time 记录使用时间
                    'update_time' => $now,
                ]);

            if ($updated <= 0) {
                throw new \Exception('寄售券使用失败，可能已被其他操作使用');
            }

            // 获取用户剩余寄售券数量并同步更新user表
            $remainingCount = self::getCouponCount($userId);
            Db::name('user')->where('id', $userId)->update([
                'consignment_coupon' => $remainingCount,
                'update_time' => $now,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('UserService::useCoupon - 异常', [
                'coupon_id' => $couponId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            throw $e; // 重新抛出异常，让调用方处理
        }
    }

    /**
     * 统计用户剩余可用寄售券数量
     * 
     * @param int $userId 用户ID
     * @param int|null $sessionId 场次ID（可选，指定场次则只统计该场次的券）
     * @param int|null $zoneId 价格区间ID（可选，指定区间则只统计该区间的券）
     * @return int 可用券数量
     */
    public static function getCouponCount(int $userId, ?int $sessionId = null, ?int $zoneId = null): int
    {
        if ($userId <= 0) {
            return 0;
        }

        try {
            $now = time();

            $query = Db::name('user_consignment_coupon')
                ->where('user_id', $userId)
                ->where('status', 1) // 可用
                ->where('expire_time', '>', $now); // 未过期

            if ($sessionId !== null && $sessionId > 0) {
                $query->where('session_id', $sessionId);
            }

            if ($zoneId !== null && $zoneId > 0) {
                $query->where('zone_id', $zoneId);
            }

            return $query->count();
        } catch (\Exception $e) {
            Log::error('UserService::getCouponCount - 异常', [
                'user_id' => $userId,
                'session_id' => $sessionId,
                'zone_id' => $zoneId,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * 清退场次中未完成的寄售订单
     * 
     * 规则：
     * - 场次结束后，清退所有status=1（寄售中）的订单
     * - 不退还寄售券和手续费
     * - 给用户的藏品增加免费寄售次数（free_consign_attempts +1）
     * 
     * @param int $sessionId 场次ID
     * @return array 清退结果统计
     */
    public static function clearUnsoldConsignments(int $sessionId): array
    {
        $result = [
            'success' => false,
            'cleared_count' => 0,
            'error' => null,
        ];

        if ($sessionId <= 0) {
            $result['error'] = '无效的场次ID';
            return $result;
        }

        try {
            $now = time();

            // 查找该场次所有未完成的寄售订单（status=1）
            $unsoldConsignments = Db::name('collection_consignment')
                ->alias('c')
                ->leftJoin('collection_item ci', 'c.item_id = ci.id')
                ->where('ci.session_id', $sessionId)
                ->where('c.status', 1) // 寄售中
                ->field('c.id, c.user_id, c.user_collection_id, c.item_id, c.price, ci.title')
                ->select()
                ->toArray();

            if (empty($unsoldConsignments)) {
                $result['success'] = true;
                return $result;
            }

            Db::startTrans();

            foreach ($unsoldConsignments as $consignment) {
                $consignmentId = (int)$consignment['id'];
                $userId = (int)$consignment['user_id'];
                $userCollectionId = (int)$consignment['user_collection_id'];

                // 1. 更新寄售记录状态为已取消(status=3)
                Db::name('collection_consignment')
                    ->where('id', $consignmentId)
                    ->update([
                        'status' => 3, // 3=已取消/清退
                        'update_time' => $now,
                    ]);

                // 2. 更新用户藏品的寄售状态为0（未寄售）
                Db::name('user_collection')
                    ->where('id', $userCollectionId)
                    ->update([
                        'consignment_status' => 0,
                        'update_time' => $now,
                    ]);

                // 3. 给用户藏品增加一次免费寄售机会
                Db::name('user_collection')
                    ->where('id', $userCollectionId)
                    ->inc('free_consign_attempts', 1)
                    ->update(['update_time' => $now]);

                // 4. 记录活动日志
                Db::name('user_activity_log')->insert([
                    'user_id' => $userId,
                    'related_user_id' => 0,
                    'action_type' => 'consignment_cleared',
                    'change_field' => 'consignment_status',
                    'change_value' => 0,
                    'before_value' => 1,
                    'after_value' => 0,
                    'remark' => '场次结束清退寄售订单，下次免费寄售',
                    'extra' => json_encode([
                        'session_id' => $sessionId,
                        'consignment_id' => $consignmentId,
                        'user_collection_id' => $userCollectionId,
                        'item_id' => $consignment['item_id'],
                        'item_title' => $consignment['title'],
                        'price' => $consignment['price'],
                    ], JSON_UNESCAPED_UNICODE),
                    'create_time' => $now,
                    'update_time' => $now,
                ]);

                $result['cleared_count']++;
            }

            Db::commit();
            $result['success'] = true;

            Log::info('UserService - 清退场次寄售订单', [
                'session_id' => $sessionId,
                'cleared_count' => $result['cleared_count'],
            ]);

        } catch (\Exception $e) {
            Db::rollback();
            $result['error'] = $e->getMessage();
            
            Log::error('UserService::clearUnsoldConsignments - 异常', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }

        return $result;
    }

    /**
     * 下级首次交易奖励上级
     * @param int $inviterId 上级用户ID
     * @param int $subordinateId 下级用户ID
     * @param int $time 时间戳
     */
    public static function rewardInviterOnSubordinateFirstTrade(int $inviterId, int $subordinateId, int $time): void
    {
        if ($inviterId <= 0 || $subordinateId <= 0) {
            return;
        }

        // 获取奖励配置
        $rewardScore = (float)Db::name('config')->where('name', 'sub_trade_reward_score')->value('value');
        $rewardPower = (float)Db::name('config')->where('name', 'sub_trade_reward_power')->value('value');

        if ($rewardScore <= 0 && $rewardPower <= 0) {
            return;
        }

        $inviter = Db::name('user')->where('id', $inviterId)->find();
        if (!$inviter) {
            return;
        }

        // 发放消费金
        if ($rewardScore > 0) {
            Db::name('user')
                ->where('id', $inviterId)
                ->inc('score', $rewardScore)
                ->update();

            Db::name('user_score_log')->insert([
                'user_id' => $inviterId,
                'score' => $rewardScore,
                'before' => $inviter['score'],
                'after' => $inviter['score'] + $rewardScore,
                'memo' => '下级首次交易奖励',
                'create_time' => $time,
            ]);
            
            // 记录活动日志
            Db::name('user_activity_log')->insert([
                'user_id' => $inviterId,
                'related_user_id' => $subordinateId,
                'action_type' => 'invite_reward',
                'change_field' => 'score',
                'change_value' => $rewardScore,
                'before_value' => (float)$inviter['score'],
                'after_value' => (float)$inviter['score'] + $rewardScore,
                'remark' => '下级首次交易奖励消费金',
                'extra' => json_encode(['invite_reward' => $rewardScore, 'reward_score' => $rewardScore, 'invited_user_id' => $subordinateId], JSON_UNESCAPED_UNICODE),
                'create_time' => $time,
                'update_time' => $time,
            ]);
        }

        // 发放算力
        if ($rewardPower > 0) {
            Db::name('user')
                ->where('id', $inviterId)
                ->inc('green_power', $rewardPower)
                ->update();

            // 记录活动日志
            Db::name('user_activity_log')->insert([
                'user_id' => $inviterId,
                'related_user_id' => $subordinateId,
                'action_type' => 'invite_reward', 
                'change_field' => 'green_power',
                'change_value' => $rewardPower,
                'before_value' => (float)$inviter['green_power'],
                'after_value' => (float)$inviter['green_power'] + $rewardPower,
                'remark' => '下级首次交易奖励算力',
                'extra' => json_encode(['invite_reward' => $rewardPower, 'reward_green_power' => $rewardPower, 'invited_user_id' => $subordinateId], JSON_UNESCAPED_UNICODE),
                'create_time' => $time,
                'update_time' => $time,
            ]);
        }
    }
    /**
     * 用户首次交易奖励（奖励自己）
     * @param int $userId 用户ID
     * @param int $time 时间戳
     */
    public static function rewardFirstTrade(int $userId, int $time): void
    {
        if ($userId <= 0) {
            return;
        }

        // 获取奖励配置
        $rewardScore = (float)Db::name('config')->where('name', 'first_trade_reward_score')->value('value');
        $rewardPower = (float)Db::name('config')->where('name', 'first_trade_reward_power')->value('value');

        if ($rewardScore <= 0 && $rewardPower <= 0) {
            return;
        }

        $user = Db::name('user')->where('id', $userId)->find();
        if (!$user) {
            return;
        }

        // 发放消费金
        if ($rewardScore > 0) {
            Db::name('user')
                ->where('id', $userId)
                ->inc('score', $rewardScore)
                ->update();

            Db::name('user_score_log')->insert([
                'user_id' => $userId,
                'score' => $rewardScore,
                'before' => $user['score'],
                'after' => $user['score'] + $rewardScore,
                'memo' => '首次交易奖励',
                'create_time' => $time,
            ]);
            
            // 记录活动日志
            Db::name('user_activity_log')->insert([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'first_trade_reward',
                'change_field' => 'score',
                'change_value' => $rewardScore,
                'before_value' => (float)$user['score'],
                'after_value' => (float)$user['score'] + $rewardScore,
                'remark' => '首次交易奖励消费金',
                'extra' => json_encode(['reward_score' => $rewardScore], JSON_UNESCAPED_UNICODE),
                'create_time' => $time,
                'update_time' => $time,
            ]);
        }

        // 发放算力
        if ($rewardPower > 0) {
            Db::name('user')
                ->where('id', $userId)
                ->inc('green_power', $rewardPower)
                ->update();

            // 记录活动日志
            Db::name('user_activity_log')->insert([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'first_trade_reward', 
                'change_field' => 'green_power',
                'change_value' => $rewardPower,
                'before_value' => (float)$user['green_power'],
                'after_value' => (float)$user['green_power'] + $rewardPower,
                'remark' => '首次交易奖励算力',
                'extra' => json_encode(['reward_green_power' => $rewardPower], JSON_UNESCAPED_UNICODE),
                'create_time' => $time,
                'update_time' => $time,
            ]);
        }
    }
}
