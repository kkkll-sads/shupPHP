<?php

namespace app\admin\validate;

use think\Validate;

class Banner extends Validate
{
    protected $failException = true;

    protected $rule = [
        'title'       => 'require|max:100',
        'image'       => 'require',
        'url'         => 'url',
        'description' => 'max:255',
        'sort'        => 'number|between:0,999',
        'status'      => 'require|in:0,1',
        'start_time'  => 'date',
        'end_time'    => 'date',
    ];

    /**
     * 验证提示信息
     * @var array
     */
    protected $message = [];

    /**
     * 字段描述
     */
    protected $field = [
    ];

    /**
     * 验证场景
     */
    protected $scene = [
        'add'  => ['title', 'image', 'url', 'description', 'sort', 'status', 'start_time', 'end_time'],
        'edit' => ['title', 'image', 'url', 'description', 'sort', 'status', 'start_time', 'end_time'],
    ];

    public function __construct()
    {
        $this->field = [
            'title'       => __('Title'),
            'image'       => __('Image'),
            'url'         => __('Url'),
            'description' => __('Description'),
            'sort'        => __('Sort'),
            'status'      => __('Status'),
            'start_time'  => __('Start Time'),
            'end_time'    => __('End Time'),
        ];
        $this->message = array_merge($this->message, [
            'title.require'  => __('Title is required'),
            'image.require'  => __('Image is required'),
            'url.url'        => __('Url format is incorrect'),
            'sort.number'    => __('Sort must be a number'),
            'status.in'      => __('Status value is incorrect'),
        ]);
        parent::__construct();
    }
}
