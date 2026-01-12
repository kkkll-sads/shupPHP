<?php

namespace app\admin\model;

use think\Model;

/**
 * 帮助问题表模型
 *
 * @property int    $id
 * @property int    $category_id
 * @property string $title
 * @property string $content
 * @property int    $sort
 * @property int    $status
 * @property int    $create_time
 * @property int    $update_time
 */
class HelpQuestion extends Model
{
    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';
}


