<?php

namespace app\admin\validate;

use think\Validate;

class ContentMedia extends Validate
{
    protected $failException = true;

    protected $rule = [
        'category' => 'require|in:promo_video,resource,hot_video',
        'title' => 'require|max:200',
        'media_type' => 'require|in:image,video,document,other',
        'media_url' => 'require|max:255',
        'cover_image' => 'max:255',
        'status' => 'require|in:0,1',
        'sort' => 'number|between:0,999',
    ];

    protected array $message = [];

    protected array $field = [];

    protected $scene = [
        'add' => ['category', 'title', 'media_type', 'media_url', 'cover_image', 'status', 'sort'],
        'edit' => ['category', 'title', 'media_type', 'media_url', 'cover_image', 'status', 'sort'],
    ];

    public function __construct()
    {
        $this->field = [
            'category' => __('Category'),
            'title' => __('Title'),
            'media_type' => __('Media Type'),
            'media_url' => __('Media Url'),
            'cover_image' => __('Cover Image'),
            'status' => __('Status'),
            'sort' => __('Sort'),
        ];

        $this->message = array_merge($this->message, [
            'category.in' => __('Category is invalid'),
            'status.in' => __('Status value is incorrect'),
            'media_type.in' => __('Media type is invalid'),
            'media_url.require' => __('Media resource is required'),
        ]);

        parent::__construct();
    }
}


