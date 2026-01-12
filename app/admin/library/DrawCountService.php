<?php

namespace app\admin\library;

use app\admin\model\DrawCountConfig;
use app\admin\model\InviteRecord;
use app\admin\model\User;
use app\admin\model\UserActivityLog;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;

class DrawCountService
{
    protected DrawCountConfig $configModel;
    protected InviteRecord $inviteRecordModel;
    protected User $userModel;
    protected UserActivityLog $activityLogModel;

    public function __construct()
    {
        $this->configModel = new DrawCountConfig();
        $this->inviteRecordModel = new InviteRecord();
        $this->userModel = new User();
        $this->activityLogModel = new UserActivityLog();
    }

    /**
     * 获取用户的直推人数
     * @param int $userId
     * @return int
     * @throws DbException
     */
    public function getDirectInviteCount(int $userId): int
    {
        return $this->inviteRecordModel->where('inviter_id', $userId)->count();
    }

    /**
     * 根据直推人数获取抽奖次数
     * @param int $directCount
     * @return int
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function getDrawCountByDirectPeople(int $directCount): int
    {
        $drawCount = $this->configModel->getDrawCountByPeople($directCount);
        return $drawCount ?? 0;
    }

    /**
     * 获取匹配的配置规则详情
     * @param int $directCount
     * @return array
     * @throws DbException
     */
    public function getMatchedConfigs(int $directCount): array
    {
        return $this->configModel->where('direct_people', '<=', $directCount)
            ->order('direct_people', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 计算用户的抽奖次数
     * @param int $userId
     * @return int
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function calculateUserDrawCount(int $userId): int
    {
        $directCount = $this->getDirectInviteCount($userId);
        return $this->getDrawCountByDirectPeople($directCount);
    }

    /**
     * 更新用户的抽奖次数
     * @param int $userId
     * @return bool
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function updateUserDrawCount(int $userId, string $source = 'auto', array $context = []): bool
    {
        $user = $this->userModel->where('id', $userId)->find();
        if (!$user) {
            return false;
        }

        $before = (int)$user->draw_count;
        $drawCount = $this->calculateUserDrawCount($userId);

        if ($drawCount === $before) {
            return true;
        }

        $updated = (bool)$this->userModel->update([
            'id' => $userId,
            'draw_count' => $drawCount
        ]);

        if ($updated) {
            $directCount = $this->getDirectInviteCount($userId);
            $changeValue = $drawCount - $before;

            // 获取匹配的配置规则详情
            $matchedConfigs = $this->getMatchedConfigs($directCount);
            $configDetails = [];
            foreach ($matchedConfigs as $config) {
                $remark = $config['remark'] ?: '';
                if ($remark) {
                    $configDetails[] = "推广{$config['direct_people']}人({$remark})赠送{$config['draw_count']}次";
                } else {
                    $configDetails[] = "推广{$config['direct_people']}人赠送{$config['draw_count']}次";
                }
            }
            $configInfo = !empty($configDetails) ? '，因为自定义配置设置的' . implode('、', $configDetails) : '';

            $this->activityLogModel->save([
                'user_id' => $userId,
                'related_user_id' => $context['invite_user_id'] ?? $context['removed_user_id'] ?? 0,
                'action_type' => 'draw_count',
                'change_field' => 'draw_count',
                'change_value' => $changeValue,
                'before_value' => $before,
                'after_value' => $drawCount,
                'remark' => "抽奖次数变更 {$before} -> {$drawCount} (直推{$directCount}人{$configInfo})",
                'extra' => [
                    'direct_count' => $directCount,
                    'change' => $changeValue,
                    'source' => $source,
                    'context' => $context,
                    'matched_configs' => $matchedConfigs,
                ],
            ]);
        }

        return $updated;
    }

    /**
     * 重新计算所有用户的抽奖次数
     * @return int 更新的用户数
     * @throws DbException
     */
    public function recalculateAllUsersDrawCount(): int
    {
        $users = $this->userModel->select();
        $updatedCount = 0;

        foreach ($users as $user) {
            try {
                if ($this->updateUserDrawCount($user->id, 'recalculate')) {
                    $updatedCount++;
                }
            } catch (\Exception $e) {
                // 记录错误但继续处理其他用户
                \think\facade\Log::error("更新用户{$user->id}的抽奖次数失败: " . $e->getMessage());
            }
        }

        return $updatedCount;
    }

    /**
     * 添加邀请记录并更新邀请人的抽奖次数
     * @param int $userId 被邀请人ID
     * @param int $inviterId 邀请人ID
     * @return bool
     * @throws DbException
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     */
    public function addInviteRecord(int $userId, int $inviterId): bool
    {
        // 添加邀请记录
        $exists = $this->inviteRecordModel->where(['user_id' => $userId])->find();

        $record = new InviteRecord();
        $isNewRecord = false;

        if ($exists) {
            $result = true;
        } else {
            $result = $record->save([
                'user_id' => $userId,
                'inviter_id' => $inviterId,
                'create_time' => time(),
                'update_time' => time(),
            ]);
            $isNewRecord = (bool)$result;
        }

        if ($result) {
            if ($isNewRecord) {
                $invitedUser = $this->userModel->where('id', $userId)->find();
                $this->activityLogModel->save([
                    'user_id' => $inviterId,
                    'related_user_id' => $userId,
                    'action_type' => 'invite',
                    'change_field' => '',
                    'change_value' => 0,
                    'before_value' => 0,
                    'after_value' => 0,
                    'remark' => sprintf('邀请用户(ID: %d, 昵称: %s, 手机: %s)', $userId, $invitedUser->nickname ?? '-', $invitedUser->mobile ?? '-'),
                    'extra' => [
                        'invite_user' => [
                            'id' => $userId,
                            'nickname' => $invitedUser->nickname ?? '',
                            'mobile' => $invitedUser->mobile ?? '',
                        ],
                        'source' => 'invite',
                    ],
                ]);
            }

            $this->updateUserDrawCount($inviterId, 'invite', ['invite_user_id' => $userId]);
        }

        return (bool)$result;
    }

    /**
     * 删除邀请记录并更新邀请人的抽奖次数
     * @param int $recordId 邀请记录ID
     * @return bool
     * @throws DbException
     * @throws DataNotFoundException
     * @throws ModelNotFoundException
     */
    public function deleteInviteRecord(int $recordId): bool
    {
        // 先获取邀请人信息
        $record = $this->inviteRecordModel->find($recordId);
        if (!$record) {
            return false;
        }

        $inviterId = $record->inviter_id;

        // 删除邀请记录
        $result = $record->delete();

        // 更新邀请人的抽奖次数
        if ($result) {
            $this->updateUserDrawCount($inviterId, 'remove_invite', ['record_id' => $recordId, 'removed_user_id' => $record->user_id]);
        }

        return (bool)$result;
    }

    /**
     * 获取抽奖次数配置统计
     * @return array
     * @throws DbException
     */
    public function getConfigStatistics(): array
    {
        $configs = $this->configModel->getAllConfigs();
        $statistics = [];

        foreach ($configs as $config) {
            $statistics[] = [
                'direct_people' => $config['direct_people'],
                'draw_count' => $config['draw_count'],
                'remark' => $config['remark'],
                'user_count' => $this->getUserCountByDirectPeople($config['direct_people'])
            ];
        }

        return $statistics;
    }

    /**
     * 获取符合特定直推人数的用户数
     * @param int $directPeople
     * @return int
     * @throws DbException
     */
    public function getUserCountByDirectPeople(int $directPeople): int
    {
        return $this->userModel->whereRaw(
            "id IN (SELECT inviter_id FROM ba_invite_record GROUP BY inviter_id HAVING COUNT(*) >= {$directPeople})"
        )->count();
    }
}

