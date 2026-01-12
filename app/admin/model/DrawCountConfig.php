<?php

namespace app\admin\model;

use think\Model;

class DrawCountConfig extends Model
{
    // 表名
    protected $table = 'ba_draw_count_config';
    protected $pk = 'id';

    // 时间戳
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    // 字段类型
    protected $cast = [];

    public function getDrawCountByPeople($people)
    {
        return (int)$this->where('direct_people', '<=', $people)->sum('draw_count');
    }

    public function getAllConfigs()
    {
        return $this->order('direct_people', 'asc')->select()->toArray();
    }
}

