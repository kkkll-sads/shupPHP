<?php

namespace app\admin\validate;

use think\Validate;

class Announcement extends Validate
{
    protected $failException = true;

    protected $rule = [
        'title'       => 'require|max:200',
        'content'     => 'require',
        'type'        => 'require|in:normal,important',
        'status'      => 'require|in:0,1',
        'is_popup'    => 'require|in:0,1',
        'popup_delay' => 'integer|between:0,10000',
        'sort'        => 'number|between:0,999',
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
        'add'  => ['title', 'content', 'type', 'status', 'is_popup', 'popup_delay', 'sort', 'start_time', 'end_time'],
        'edit' => ['title', 'content', 'type', 'status', 'is_popup', 'popup_delay', 'sort', 'start_time', 'end_time'],
    ];

    public function __construct()
    {
        $this->field = [
            'title'       => __('公告标题'),
            'content'     => __('公告内容'),
            'type'        => __('公告类型'),
            'status'      => __('状态'),
            'is_popup'    => __('是否弹出'),
            'popup_delay' => __('弹出延迟'),
            'sort'        => __('排序'),
            'start_time'  => __('开始时间'),
            'end_time'    => __('结束时间'),
        ];
        $this->message = array_merge($this->message, [
            'title.require'     => __('公告标题不能为空'),
            'content.require'   => __('公告内容不能为空'),
            'type.in'           => __('公告类型值不正确'),
            'status.in'         => __('状态值不正确'),
            'is_popup.in'       => __('是否弹出值不正确'),
            'popup_delay.number' => __('弹出延迟必须是数字'),
        ]);
        parent::__construct();
    }
}
