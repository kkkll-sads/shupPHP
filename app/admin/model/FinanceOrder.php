<?php

namespace app\admin\model;

use think\Model;

/**
 * @property int         $id
 * @property string      $order_no
 * @property int         $user_id
 * @property int         $product_id
 * @property int         $quantity
 * @property float       $unit_price
 * @property float       $amount
 * @property string|null $payment_channel
 * @property float       $yield_rate
 * @property string      $status
 * @property string|null $remark
 * @property array|null  $extra
 * @property int         $pay_time
 * @property int         $complete_time
 * @property int         $create_time
 * @property int         $update_time
 */
class FinanceOrder extends Model
{
    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $json = ['extra'];

    public function getUnitPriceAttr($value): float
    {
        return (float)$value;
    }

    public function getAmountAttr($value): float
    {
        return (float)$value;
    }

    public function getYieldRateAttr($value): float
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
}


