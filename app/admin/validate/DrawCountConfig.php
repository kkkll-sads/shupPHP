<?php

namespace app\admin\validate;

use think\Validate;

class DrawCountConfig extends Validate
{
    protected $rule = [
        'id' => 'integer',
        'direct_people' => 'require|integer|min:1|max:99999',
        'draw_count' => 'require|integer|min:1|max:99999',
        'remark' => 'max:255'
    ];

    protected $message = [
        'direct_people.require' => '直推人数不能为空',
        'direct_people.integer' => '直推人数必须为整数',
        'direct_people.min' => '直推人数最小为1',
        'draw_count.require' => '抽奖次数不能为空',
        'draw_count.integer' => '抽奖次数必须为整数',
        'draw_count.min' => '抽奖次数最小为1',
        'remark.max' => '备注不能超过255个字符'
    ];

    protected $scene = [
        'add' => ['direct_people', 'draw_count', 'remark'],
        'edit' => ['id', 'direct_people', 'draw_count', 'remark'],
        'delete' => ['id']
    ];
}

