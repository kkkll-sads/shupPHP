<?php

namespace app\admin\validate;

use think\Validate;

class ShopProduct extends Validate
{
    protected $failException = true;

    protected $rule = [
        'name' => 'require|max:255',
        'thumbnail' => 'max:255',
        'category' => 'max:50',
        'price' => 'require|float|egt:0',
        'score_price' => 'require|integer|egt:0',
        'stock' => 'require|integer|egt:0',
        'purchase_type' => 'require|in:money,score,both',
        'status' => 'require|in:0,1',
        'is_physical' => 'require|in:0,1',
        'is_card_product' => 'in:0,1',
        'sort' => 'number|between:0,9999',
    ];

    protected $message = [];

    protected $field = [];

    protected $scene = [
        'add' => ['name', 'thumbnail', 'category', 'price', 'score_price', 'stock', 'purchase_type', 'status', 'is_physical', 'is_card_product', 'sort'],
        'edit' => ['name', 'thumbnail', 'category', 'price', 'score_price', 'stock', 'purchase_type', 'status', 'is_physical', 'is_card_product', 'sort'],
    ];

    public function __construct()
    {
        $this->field = [
            'name' => '商品名称',
            'thumbnail' => '商品缩略图',
            'category' => '商品分类',
            'price' => '商品价格',
            'score_price' => '积分价格',
            'stock' => '库存数量',
            'purchase_type' => '购买方式',
            'status' => '状态',
            'is_physical' => '商品类型',
            'sort' => '排序',
        ];

        $this->message = array_merge($this->message, [
            'name.require' => '商品名称不能为空',
            'name.max' => '商品名称最多255个字符',
            'price.require' => '商品价格不能为空',
            'price.float' => '商品价格必须是数字',
            'price.egt' => '商品价格不能小于0',
            'score_price.require' => '积分价格不能为空',
            'score_price.integer' => '积分价格必须是整数',
            'score_price.egt' => '积分价格不能小于0',
            'stock.require' => '库存数量不能为空',
            'stock.integer' => '库存数量必须是整数',
            'stock.egt' => '库存数量不能小于0',
            'purchase_type.require' => '购买方式不能为空',
            'purchase_type.in' => '购买方式值不正确',
            'status.in' => '状态值不正确',
            'is_physical.in' => '商品类型值不正确',
        ]);

        parent::__construct();
    }
}

