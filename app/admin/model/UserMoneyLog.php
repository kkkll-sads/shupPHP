<?php

namespace app\admin\model;

use Throwable;
use think\model;
use think\Exception;
use think\model\relation\BelongsTo;

/**
 * UserMoneyLog 模型（已废弃自动更新功能）
 * 注意：money字段已改为派生值（总资产展示），不再参与业务入账和扣款
 * UserMoneyLog现在仅用于记录历史日志，不会自动修改user表的money字段
 * 业务逻辑应直接操作四个真实余额池：balance_available, withdrawable_money, score, service_fee_balance
 * 创建余额日志时，请开启事务
 */
class UserMoneyLog extends model
{
    protected $autoWriteTimestamp = true;
    protected $updateTime         = false;

    /**
     * 入库前钩子（已禁用自动更新money功能）
     * 由于money字段已改为派生值，此钩子不再自动修改user表
     * 业务逻辑应在插入日志前，自行更新对应的真实余额池
     * @throws Throwable
     */
    public static function onBeforeInsert($model): void
    {
        // 验证用户存在
        $user = User::where('id', $model->user_id)->find();
        if (!$user) {
            throw new Exception("The user can't find it");
        }
        if (!$model->memo) {
            throw new Exception("Change note cannot be blank");
        }
        
        // 注意：不再自动修改user表的money字段
        // money是派生值，由四个真实余额池自动计算：
        // money = balance_available + withdrawable_money + score + service_fee_balance
        // 
        // 如果before/after字段为空，可以自动填充当前money值作为参考
        // 但不会修改user表
        if (!isset($model->before)) {
            $model->before = $user->money; // 读取计算后的总资产
        }
        if (!isset($model->after)) {
            $model->after = $user->money; // 读取计算后的总资产
        }
    }

    public static function onBeforeDelete(): bool
    {
        return false;
    }

    // 移除金额访问器，直接使用原始值（元）存储和读取
    // public function getMoneyAttr($value): string
    // {
    //     return bcdiv($value, 100, 2);
    // }

    // public function setMoneyAttr($value): string
    // {
    //     return bcmul($value, 100, 2);
    // }

    // public function getBeforeAttr($value): string
    // {
    //     return bcdiv($value, 100, 2);
    // }

    // public function setBeforeAttr($value): string
    // {
    //     return bcmul($value, 100, 2);
    // }

    // public function getAfterAttr($value): string
    // {
    //     return bcdiv($value, 100, 2);
    // }

    // public function setAfterAttr($value): string
    // {
    //     return bcmul($value, 100, 2);
    // }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}