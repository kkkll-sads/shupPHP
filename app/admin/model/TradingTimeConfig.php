<?php

namespace app\admin\model;

use think\Model;

/**
 * @property int         $id
 * @property string      $name
 * @property string      $start_time
 * @property string      $end_time
 * @property string      $status
 * @property int         $sort
 * @property int         $create_time
 * @property int         $update_time
 */
class TradingTimeConfig extends Model
{
    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';
}

