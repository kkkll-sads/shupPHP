<?php

namespace app\admin\validate;

use think\Validate;

class ContentNews extends Validate
{
    protected $failException = true;

    protected $rule = [
        'title' => 'require|max:200',
        'summary' => 'max:500',
        'cover_image' => 'max:255',
        'link_url' => 'max:255',
        'is_hot' => 'in:0,1',
        'status' => 'require|in:0,1',
        'sort' => 'number|between:0,999',
    ];

    protected $message = [];

    protected $field = [];

    protected $scene = [
        'add' => ['title', 'summary', 'cover_image', 'link_url', 'is_hot', 'status', 'sort'],
        'edit' => ['title', 'summary', 'cover_image', 'link_url', 'is_hot', 'status', 'sort'],
    ];

    public function __construct()
    {
        $this->field = [
            'title' => __('Title'),
            'summary' => __('Summary'),
            'cover_image' => __('Cover Image'),
            'link_url' => __('Link Url'),
            'is_hot' => __('Is Hot'),
            'status' => __('Status'),
            'publish_time' => __('Publish Time'),
            'sort' => __('Sort'),
        ];

        $this->message = array_merge($this->message, [
            'title.require' => __('Title is required'),
            'status.in' => __('Status value is incorrect'),
            'is_hot.in' => __('Value is incorrect'),
        ]);

        parent::__construct();
    }
}


