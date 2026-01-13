<?php

declare(strict_types=1);

namespace app\common\model;

use think\Model;

class UserSignIn extends Model
{
    protected $table = 'ba_user_sign_in';
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $json = ['extra'];
    protected $jsonAssoc = true;
}

