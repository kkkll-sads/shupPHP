<?php

namespace app\admin\model;

use think\Model;

/**
 * @property int         $id
 * @property int         $order_id
 * @property int         $item_id
 * @property string      $item_title
 * @property string|null $item_image
 * @property float       $price
 * @property int         $quantity
 * @property float       $subtotal
 * @property string|null $product_id_record
 * @property int         $create_time
 */
class CollectionOrderItem extends Model
{
    protected $autoWriteTimestamp = false;

    protected $createTime = 'create_time';

    public function order()
    {
        return $this->belongsTo(CollectionOrder::class, 'order_id', 'id');
    }

    public function getPriceAttr($value): float
    {
        return (float)$value;
    }

    public function getSubtotalAttr($value): float
    {
        return (float)$value;
    }
}

