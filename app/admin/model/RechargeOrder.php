<?php

namespace app\admin\model;

use think\model;
use think\model\relation\BelongsTo;

class RechargeOrder extends model
{
    protected $name = 'recharge_order';
    
    protected $autoWriteTimestamp = 'int';
    
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    
    // 类型转换
    protected $type = [
        'amount' => 'float',
    ];
    
    /**
     * 关联用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    
    /**
     * 关联公司收款账户
     */
    public function companyAccount(): BelongsTo
    {
        return $this->belongsTo(CompanyPaymentAccount::class, 'company_account_id');
    }
}

