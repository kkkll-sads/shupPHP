<?php

namespace app\admin\model;

use think\Model;

/**
 * InviteCode 模型
 * @property int $status 状态:0=禁用,1=启用
 */
class InviteCode extends Model
{
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    /**
     * 获取邀请码创建者
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
