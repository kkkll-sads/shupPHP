<?php

declare(strict_types=1);

namespace app\common\model;

use think\Model;

class UserRecharge extends Model
{
    protected $name = 'recharge_order';

    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    // 类型转换
    protected $type = [
        'amount' => 'float',
    ];

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 关联公司收款账户
     */
    public function companyAccount()
    {
        return $this->belongsTo(\app\admin\model\CompanyPaymentAccount::class, 'company_account_id');
    }
}
