<?php

namespace app\admin\model;

use think\Model;

/**
 * @property int         $id
 * @property string      $order_no
 * @property int         $user_id
 * @property float       $total_amount
 * @property string      $pay_type
 * @property string      $status
 * @property string|null $remark
 * @property int         $pay_time
 * @property int         $complete_time
 * @property int         $create_time
 * @property int         $update_time
 */
class CollectionOrder extends Model
{
    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function items()
    {
        return $this->hasMany(CollectionOrderItem::class, 'order_id', 'id');
    }

    public function getTotalAmountAttr($value): float
    {
        return (float)$value;
    }

    public function getStatusTextAttr($value, $data): string
    {
        $statusMap = [
            'pending' => '待支付',
            'paid' => '已支付',
            'completed' => '已完成',
            'cancelled' => '已取消',
            'refunded' => '已退款',
        ];
        return $statusMap[$data['status']] ?? $data['status'];
    }

    public function getPayTypeTextAttr($value, $data): string
    {
        $payTypeMap = [
            'money' => '余额支付',
            'score' => '积分兑换',
        ];
        return $payTypeMap[$data['pay_type']] ?? $data['pay_type'];
    }
}

