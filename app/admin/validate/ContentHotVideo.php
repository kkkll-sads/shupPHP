<?php

namespace app\admin\validate;

use think\Validate;

class ContentHotVideo extends Validate
{
    protected $failException = true;

    protected $rule = [
        'title' => 'require|max:200',
        'summary' => 'max:500',
        'video_url' => 'max:500',
        'cover_image' => 'max:255',
        'status' => 'require|in:0,1',
        'sort' => 'number|between:0,999',
    ];

    protected $message = [];

    protected $field = [];

    protected $scene = [
        'add' => ['title', 'summary', 'video_url', 'cover_image', 'status', 'sort'],
        'edit' => ['title', 'summary', 'video_url', 'cover_image', 'status', 'sort'],
    ];

    public function __construct()
    {
        $this->field = [
            'title' => __('Title'),
            'summary' => __('Summary'),
            'video_url' => __('Video Url'),
            'cover_image' => __('Cover Image'),
            'status' => __('Status'),
            'publish_time' => __('Publish Time'),
            'sort' => __('Sort'),
        ];

        $this->message = array_merge($this->message, [
            'title.require' => __('Title is required'),
            'status.in' => __('Status value is incorrect'),
        ]);

        parent::__construct();
    }
}

