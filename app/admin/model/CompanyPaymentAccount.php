<?php

namespace app\admin\model;

use think\model;

class CompanyPaymentAccount extends model
{
    protected $name = 'company_payment_account';
    
    protected $autoWriteTimestamp = 'int';
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    public const STATUS_RECHARGE = 1;
    public const STATUS_WITHDRAW = 2;
    public const STATUS_ALL = 3;
    public const STATUS_CLOSED = 0;

    // 类型转换
    protected $type = [
        'status' => 'integer',
    ];

    public static function getStatusMap(): array
    {
        return [
            self::STATUS_RECHARGE => '充值可用',
            self::STATUS_WITHDRAW => '提现可用',
            self::STATUS_ALL => '充值提现可用',
            self::STATUS_CLOSED => '关闭',
        ];
    }
    
    /**
     * 分类字段获取器 - 转换为数组
     */
    public function getCategoryAttr($value)
    {
        return $value ? explode(',', $value) : [];
    }
    
    /**
     * 分类字段修改器 - 转换为字符串
     */
    public function setCategoryAttr($value)
    {
        return is_array($value) ? implode(',', $value) : $value;
    }

}

