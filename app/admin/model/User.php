<?php

namespace app\admin\model;

use think\Model;
use think\model\relation\BelongsTo;

/**
 * User 模型
 * @property int    $id      用户ID
 * @property string password 密码密文
 */
class User extends Model
{
    protected $autoWriteTimestamp = true;

    public function getAvatarAttr($value): string
    {
        return full_url($value, false, config('buildadmin.default_avatar'));
    }

    public function setAvatarAttr($value): string
    {
        return $value == full_url('', false, config('buildadmin.default_avatar')) ? '' : $value;
    }

    /**
     * money字段访问器 - 总资产（派生值，不参与业务入账和扣款）
     * 自动计算四个真实余额池的总和
     * @param mixed $value 数据库中的原始值（会被忽略，始终重新计算）
     * @return string 总资产金额
     */
    public function getMoneyAttr($value): string
    {
        // money = 四个真实余额池的总和
        $balanceAvailable = $this->getData('balance_available') ?? 0;
        $withdrawableMoney = $this->getData('withdrawable_money') ?? 0;
        $score = $this->getData('score') ?? 0;
        $serviceFeeBalance = $this->getData('service_fee_balance') ?? 0;
        
        // 计算总资产
        $total = bcadd($balanceAvailable, $withdrawableMoney, 2);
        $total = bcadd($total, $score, 2);
        $total = bcadd($total, $serviceFeeBalance, 2);
        
        return $total;
    }

    /**
     * money字段修改器 - 禁止直接修改
     * money是派生值，不允许直接写入
     * 业务逻辑应只操作四个真实余额池：balance_available, withdrawable_money, score, service_fee_balance
     * @param mixed $value
     * @return mixed 返回原值不做处理（数据库中的money字段将被忽略）
     */
    public function setMoneyAttr($value)
    {
        // 不做任何处理，直接返回原值
        // money字段在数据库中保留但不再使用，所有读取都通过计算得出
        return $value;
    }

    public function userGroup(): BelongsTo
    {
        return $this->belongsTo(UserGroup::class, 'group_id');
    }

    public function inviteCode(): \think\model\relation\HasOne
    {
        return $this->hasOne(InviteCode::class, 'user_id', 'id');
    }

    /**
     * 重置用户密码
     * @param int|string $uid         用户ID
     * @param string     $newPassword 新密码
     * @return int|User
     */
    public function resetPassword(int|string $uid, string $newPassword): int|User
    {
        return $this->where(['id' => $uid])->update(['password' => hash_password($newPassword), 'salt' => '']);
    }
}