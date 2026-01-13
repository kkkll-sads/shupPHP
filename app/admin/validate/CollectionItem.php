<?php

namespace app\admin\validate;

use think\Validate;
use think\facade\App;

class CollectionItem extends Validate
{
    protected $failException = true;

    protected $rule = [
        'session_id' => 'require|integer|egt:0',
        'title' => 'require|max:255',
        'image' => 'max:255',
        'price' => 'require|float|egt:0',
        'artist' => 'max:100',
        'zone_id' => 'require|integer|egt:0',
        'core_enterprise' => 'max:255',
        'farmer_info' => 'max:255',
        'stock' => 'require|integer|egt:0',
        'status' => 'in:0,1',
        'sort' => 'number|between:0,9999',
    ];

    protected $message = [];

    protected $field = [];

    protected $scene = [
        'add' => ['session_id', 'zone_id', 'title', 'image', 'price', 'artist', 'core_enterprise', 'farmer_info', 'stock', 'status', 'sort'],
        // edit 场景：允许部分字段更新（如快速编辑状态），移除require验证
        'edit' => [
            'session_id' => 'integer|egt:0',
            'zone_id' => 'integer|egt:0',
            'title' => 'max:255',
            'image' => 'max:255',
            'price' => 'float|egt:0',
            'artist' => 'max:100',
            'core_enterprise' => 'max:255',
            'farmer_info' => 'max:255',
            'stock' => 'integer|egt:0',
            'status' => 'in:0,1',
            'sort' => 'number|between:0,9999',
        ],
    ];

    public function __construct()
    {
        parent::__construct();
        // 确保语言对象可用，避免解析错误提示时 $this->lang 为空
        $this->setLang(app()->lang);

        $this->field = [
            'session_id' => '专场ID',
            'title' => '藏品标题',
            'image' => '藏品图片',
            'price' => '价格',
            'artist' => '艺术家/创作者',
            'zone_id' => '价格分区',
            'core_enterprise' => '核心企业',
            'farmer_info' => '关联农户',
            'stock' => '库存数量',
            'status' => '状态',
            'sort' => '排序',
        ];
    }
}

