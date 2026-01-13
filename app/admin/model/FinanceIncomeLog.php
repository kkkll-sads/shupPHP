<?php

namespace app\admin\model;

use think\Model;

/**
 * @property int         $id
 * @property int         $order_id
 * @property int         $user_id
 * @property int         $product_id
 * @property string      $income_type
 * @property float       $income_amount
 * @property string      $income_date
 * @property string|null $stage_info
 * @property int|null    $period_number
 * @property int         $status
 * @property int|null    $settle_time
 * @property string|null $fail_reason
 * @property string|null $remark
 * @property int         $create_time
 * @property int         $update_time
 */
class FinanceIncomeLog extends Model
{
    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    public function getIncomeAmountAttr($value): float
    {
        return (float)$value;
    }

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 关联理财产品
     */
    public function product()
    {
        return $this->belongsTo(FinanceProduct::class, 'product_id', 'id');
    }

    /**
     * 关联订单
     */
    public function order()
    {
        return $this->belongsTo(FinanceOrder::class, 'order_id', 'id');
    }
}

