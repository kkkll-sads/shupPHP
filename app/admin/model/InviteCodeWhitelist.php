<?php

namespace app\admin\model;

use think\Model;

/**
 * InviteCodeWhitelist
 */
class InviteCodeWhitelist extends Model
{
    // Auto write timestamp
    protected $autoWriteTimestamp = true;

    // Define table name explicitly if needed (optional if following convention)
    protected $name = 'invite_code_whitelist';
}
