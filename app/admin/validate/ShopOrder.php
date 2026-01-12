<?php

namespace app\admin\validate;

use think\Validate;

class ShopOrder extends Validate
{
    protected $failException = true;

    protected $rule = [
        'status' => 'require|in:pending,paid,shipped,completed,cancelled,refunded',
        'shipping_no' => 'max:100',
        'shipping_company' => 'max:50',
        'admin_remark' => 'max:500',
    ];

    protected $message = [];

    protected $field = [];

    protected $scene = [
        'edit' => ['status', 'shipping_no', 'shipping_company', 'admin_remark'],
        'ship' => ['shipping_no' => 'require|max:100', 'shipping_company' => 'require|max:50'],
    ];

    public function __construct()
    {
        $this->field = [
            'status' => '订单状态',
            'shipping_no' => '物流单号',
            'shipping_company' => '物流公司',
            'admin_remark' => '管理员备注',
        ];

        $this->message = array_merge($this->message, [
            'status.require' => '订单状态不能为空',
            'status.in' => '订单状态值不正确',
            'shipping_no.require' => '物流单号不能为空',
            'shipping_no.max' => '物流单号最多100个字符',
            'shipping_company.require' => '物流公司不能为空',
            'shipping_company.max' => '物流公司最多50个字符',
        ]);

        parent::__construct();
    }
}

