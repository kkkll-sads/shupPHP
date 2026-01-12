<?php

namespace app\admin\model;

use think\Model;

class InviteRecord extends Model
{
    // 表名
    protected $table = 'ba_invite_record';
    protected $pk = 'id';

    // 时间戳
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    // 时间戳格式
    protected $dateFormat = 'U';

    // 关系
    public function user()
    {
        return $this->belongsTo('User', 'user_id', 'id');
    }

    public function inviter()
    {
        return $this->belongsTo('User', 'inviter_id', 'id');
    }

    // 获取某个用户邀请的直推人数
    public function getDirectInviteCount($inviterId)
    {
        return $this->where('inviter_id', $inviterId)->count();
    }

    // 获取某个用户的邀请人
    public function getInviter($userId)
    {
        return $this->where('user_id', $userId)->value('inviter_id');
    }
}

