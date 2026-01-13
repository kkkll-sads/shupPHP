<?php

namespace app\common\model;

use think\Model;
use think\facade\Db;

/**
 * 会员公共模型
 * @property int    $id              会员ID
 * @property string $password        密码密文
 * @property string $salt            密码盐（废弃待删）
 * @property int    $login_failure   登录失败次数
 * @property string $last_login_time 上次登录时间
 * @property string $last_login_ip   上次登录IP
 * @property string $email           会员邮箱
 * @property string $mobile          会员手机号
 * @property string $status          状态:enable=启用,disable=禁用,...(string存储，可自定义其他)
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

    public function resetPassword($uid, $newPassword): int|User
    {
        return $this->where(['id' => $uid])->update(['password' => hash_password($newPassword), 'salt' => '']);
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

    /**
     * 用户创建后自动生成邀请码
     */
    protected static function onAfterInsert($user)
    {
        // 为新用户自动生成唯一的邀请码
        $inviteCode = self::generateUniqueInviteCode();
        Db::name('invite_code')->insert([
            'code' => $inviteCode,
            'user_id' => $user->id,
            'status' => '1',
            'use_count' => 0,
            'max_use' => 0,
            'createtime' => time(),
            'updatetime' => time(),
        ]);
    }

    /**
     * 生成唯一的邀请码
     */
    private static function generateUniqueInviteCode(): string
    {
        do {
            // 生成6位随机字母数字邀请码
            $code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
        } while (Db::name('invite_code')->where('code', $code)->find());

        return $code;
    }
}