<?php

namespace app\admin\model;

use think\Model;

/**
 * @property int         $id
 * @property string      $title
 * @property string|null $image
 * @property string      $start_time
 * @property string      $end_time
 * @property string      $status
 * @property int         $sort
 * @property string      $roi
 * @property string      $quota
 * @property string      $code
 * @property string      $sub_name
 * @property int         $create_time
 * @property int         $update_time
 */
class CollectionSession extends Model
{
    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    public function getImageAttr($value): ?string
    {
        if (!$value) {
            return null;
        }
        return full_url($value, false);
    }
}

