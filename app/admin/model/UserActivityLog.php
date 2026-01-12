<?php

namespace app\admin\model;

use think\Model;
use think\model\relation\BelongsTo;

class UserActivityLog extends Model
{
    protected $table = 'ba_user_activity_log';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $json = ['extra'];
    protected $jsonAssoc = true;

    protected $field = [
        'id',
        'user_id',
        'related_user_id',
        'action_type',
        'change_field',
        'change_value',
        'before_value',
        'after_value',
        'remark',
        'extra',
        'create_time',
        'update_time',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function relatedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'related_user_id');
    }
}
