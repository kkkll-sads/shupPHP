<?php

namespace app\admin\validate;

use think\Validate;

class FinanceProduct extends Validate
{
    protected $failException = true;

    protected $rule = [
        'name' => 'require|max:150',
        'slug' => 'max:120',
        'thumbnail' => 'max:255',
        'price' => 'require|float|egt:0',
        'cycle_days' => 'require|integer|egt:0',
        'yield_rate' => 'require|float|egt:0',
        'total_amount' => 'require|integer|egt:0',
        'sold_amount' => 'integer|egt:0',
        'min_purchase' => 'require|integer|gt:0',
        'max_purchase' => 'integer|egt:0',
        'status' => 'require|in:0,1',
        'sort' => 'number|between:0,999',
    ];

    protected $message = [];

    protected $field = [];

    protected $scene = [
        'add' => ['name', 'slug', 'thumbnail', 'price', 'cycle_days', 'yield_rate', 'total_amount', 'sold_amount', 'min_purchase', 'max_purchase', 'status', 'sort'],
        'edit' => ['name', 'slug', 'thumbnail', 'price', 'cycle_days', 'yield_rate', 'total_amount', 'sold_amount', 'min_purchase', 'max_purchase', 'status', 'sort'],
    ];

    public function __construct()
    {
        $this->field = [
            'name' => __('Name'),
            'slug' => __('Slug'),
            'thumbnail' => __('Thumbnail'),
            'price' => __('Price'),
            'cycle_days' => __('Cycle Days'),
            'yield_rate' => __('Yield Rate'),
            'total_amount' => __('Total Amount'),
            'sold_amount' => __('Sold Amount'),
            'min_purchase' => __('Min Purchase'),
            'max_purchase' => __('Max Purchase'),
            'status' => __('Status'),
            'sort' => __('Sort'),
        ];

        $this->message = array_merge($this->message, [
            'name.require' => __('Name is required'),
            'price.float' => __('Price must be numeric'),
            'total_amount.integer' => __('Total amount must be integer'),
            'min_purchase.gt' => __('Min purchase must be greater than zero'),
            'status.in' => __('Status value is incorrect'),
        ]);

        parent::__construct();
    }
}


