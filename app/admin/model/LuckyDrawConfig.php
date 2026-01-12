<?php

namespace app\admin\model;

use think\Model;

/**
 * 幸运转盘配置模型
 * @property int    $id           配置ID
 * @property string $config_key   配置键
 * @property string $config_value 配置值
 * @property string $remark       配置备注
 */
class LuckyDrawConfig extends Model
{
    protected $name = 'lucky_draw_config';
    protected $autoWriteTimestamp = true;
}

