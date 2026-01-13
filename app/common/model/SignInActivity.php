<?php

namespace app\common\model;

use think\Model;

/**
 * 签到活动模型（公共模型）
 */
class SignInActivity extends Model
{
    protected $name = 'sign_in_activity';
    protected $autoWriteTimestamp = true;

    /**
     * 获取当前有效的活动
     */
    public static function getActiveActivity(): ?self
    {
        $now = date('Y-m-d H:i:s');
        return self::where('status', '1')
            ->where('start_time', '<=', $now)
            ->where('end_time', '>=', $now)
            ->order('id', 'desc')
            ->find();
    }

    /**
     * 检查活动是否有效
     */
    public function isActive(): bool
    {
        if ($this->status != '1') {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        return $this->start_time <= $now && $this->end_time >= $now;
    }
}

