<?php

namespace app\admin\validate;

use think\Validate;

class TradingTimeConfig extends Validate
{
    protected $failException = true;

    protected $rule = [
        'name' => 'require|max:50',
        'start_time' => 'require|regex:/^\d{2}:\d{2}$/',
        'end_time' => 'require|regex:/^\d{2}:\d{2}$/',
        'status' => 'require|in:0,1',
        'sort' => 'number|between:0,9999',
    ];

    protected $message = [];

    protected $field = [];

    protected $scene = [
        'add' => ['name', 'start_time', 'end_time', 'status', 'sort'],
        'edit' => ['name', 'start_time', 'end_time', 'status', 'sort'],
    ];

    public function __construct()
    {
        $this->field = [
            'name' => '时间区间名称',
            'start_time' => '开始时间',
            'end_time' => '结束时间',
            'status' => '状态',
            'sort' => '排序',
        ];
    }
}

