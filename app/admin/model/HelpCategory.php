<?php

namespace app\admin\model;

use think\Model;

/**
 * 帮助问题分类表模型
 *
 * @property int    $id
 * @property string $name
 * @property string $code
 * @property int    $sort
 * @property int    $status
 * @property int    $create_time
 * @property int    $update_time
 */
class HelpCategory extends Model
{
    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';
}


