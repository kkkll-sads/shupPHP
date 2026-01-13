<?php

namespace app\admin\validate;

use think\Validate;

/**
 * 资产包验证器
 */
class AssetPackage extends Validate
{
    protected $rule = [
        'session_id' => 'require|integer|gt:0',
        'name' => 'require|max:100',
        'zone_id' => 'integer|egt:0',
        'min_price' => 'float|egt:0',
        'max_price' => 'float|egt:0',
        'status' => 'in:0,1',
        'is_default' => 'in:0,1',
    ];

    protected $message = [
        'session_id.require' => '请选择关联场次',
        'session_id.integer' => '场次ID格式错误',
        'session_id.gt' => '请选择有效的场次',
        'name.require' => '请输入资产包名称',
        'name.max' => '资产包名称最多100个字符',
        'zone_id.integer' => '分区ID格式错误',
        'zone_id.egt' => '分区ID不能为负数',
        'min_price.float' => '最低价格式错误',
        'min_price.egt' => '最低价不能为负数',
        'max_price.float' => '最高价格式错误',
        'max_price.egt' => '最高价不能为负数',
    ];

    protected $scene = [
        'add' => ['session_id', 'name', 'zone_id', 'min_price', 'max_price', 'status', 'is_default'],
        'edit' => ['session_id', 'name', 'zone_id', 'min_price', 'max_price', 'status', 'is_default'],
    ];
}
