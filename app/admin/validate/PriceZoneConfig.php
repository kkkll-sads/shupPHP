<?php

namespace app\admin\validate;

use think\Validate;

class PriceZoneConfig extends Validate
{
    protected $failException = true;

    protected $rule = [
        'name' => 'require|max:50',
        'min_price' => 'require|float|egt:0',
        'max_price' => 'float|egt:0',
        'status' => 'require|in:0,1',
        'sort' => 'number|between:0,9999',
    ];

    protected $message = [];

    protected $field = [];

    protected $scene = [
        'add' => ['name', 'min_price', 'max_price', 'status', 'sort'],
        'edit' => ['name', 'min_price', 'max_price', 'status', 'sort'],
    ];

    public function __construct()
    {
        $this->field = [
            'name' => '分区名称',
            'min_price' => '最低价格',
            'max_price' => '最高价格',
            'status' => '状态',
            'sort' => '排序',
        ];
    }
}

