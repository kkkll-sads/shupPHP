<?php

declare(strict_types=1);

namespace app\common\model;

use think\Model;

class UserActivityLog extends Model
{
    protected $table = 'ba_user_activity_log';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $json = ['extra'];
    protected $jsonAssoc = true;
}

