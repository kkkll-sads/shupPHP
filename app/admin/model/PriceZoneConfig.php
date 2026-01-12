<?php

namespace app\admin\model;

use think\Model;

/**
 * @property int         $id
 * @property string      $name
 * @property float       $min_price
 * @property float|null  $max_price
 * @property string      $status
 * @property int         $sort
 * @property int         $create_time
 * @property int         $update_time
 */
class PriceZoneConfig extends Model
{
    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';
}

