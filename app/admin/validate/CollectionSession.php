<?php

namespace app\admin\validate;

use think\Validate;

class CollectionSession extends Validate
{
    protected $failException = true;

    protected $rule = [
        'title' => 'require|max:255',
        'image' => 'max:255',
        'start_time' => 'require|regex:/^\d{2}:\d{2}$/',
        'end_time' => 'require|regex:/^\d{2}:\d{2}$/',
        'status' => 'require|in:0,1',
        'sort' => 'number|between:0,9999',
    ];

    protected $message = [];

    protected $field = [];

    protected $scene = [
        'add' => ['title', 'image', 'start_time', 'end_time', 'status', 'sort'],
        'edit' => ['title', 'image', 'start_time', 'end_time', 'status', 'sort'],
    ];

    public function __construct()
    {
        $this->field = [
            'title' => '专场标题',
            'image' => '专场图片',
            'start_time' => '开始时间',
            'end_time' => '结束时间',
            'status' => '状态',
            'sort' => '排序',
        ];
    }
}

