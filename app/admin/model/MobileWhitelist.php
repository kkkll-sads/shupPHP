<?php

namespace app\admin\model;

use think\Model;

/**
 * 手机号白名单模型
 */
class MobileWhitelist extends Model
{
    protected $table = 'ba_mobile_whitelist';
    
    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';
    
    protected $updateTime = 'update_time';
    
    protected $field = [
        'id',
        'mobile',
        'status',
        'remark',
        'admin_id',
        'create_time',
        'update_time',
    ];
}

