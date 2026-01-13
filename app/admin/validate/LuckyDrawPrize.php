<?php

namespace app\admin\validate;

use think\Validate;

class LuckyDrawPrize extends Validate
{
    protected $rule = [
        'name|奖品名称' => 'require|max:100',
        'description|奖品简介' => 'max:255',
        'prize_type|奖品类型' => 'require|in:score,money,coupon,item',
        'prize_value|奖品数值' => 'require|integer|egt:0',
        'probability|中奖概率' => 'require|integer|egt:0',
        'daily_limit|每日限制数量' => 'integer|egt:0',
        'total_limit|总限制数量' => 'integer|egt:0',
    ];

    protected $message = [
        'name.require' => '奖品名称不能为空',
        'name.max' => '奖品名称不能超过100个字符',
        'prize_type.require' => '奖品类型不能为空',
        'prize_type.in' => '奖品类型值不正确',
        'prize_value.require' => '奖品数值不能为空',
        'prize_value.integer' => '奖品数值必须为整数',
        'prize_value.egt' => '奖品数值不能为负数',
        'probability.require' => '中奖概率不能为空',
        'probability.integer' => '中奖概率必须为整数',
        'probability.egt' => '中奖概率不能为负数',
    ];
}

