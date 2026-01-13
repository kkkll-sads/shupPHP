<?php

namespace app\admin\model;

use think\Model;

/**
 * 艺术家模型
 * @property int    $id
 * @property string $name   艺术家姓名
 * @property string $image  头像图片
 * @property string $title  头衔/职称
 * @property string $bio    艺术家简介
 * @property string $status 状态:0=禁用,1=启用
 * @property int    $sort   排序(倒序)
 * @property int    $create_time
 * @property int    $update_time
 */
class Artist extends Model
{
    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';
}


