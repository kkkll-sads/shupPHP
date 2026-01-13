<?php

namespace app\admin\model;

use think\Model;

/**
 * @property int         $id
 * @property int         $order_id
 * @property int         $product_id
 * @property string      $product_name
 * @property string|null $product_thumbnail
 * @property float       $price
 * @property int         $score_price
 * @property int         $quantity
 * @property float       $subtotal
 * @property int         $subtotal_score
 * @property int         $create_time
 */
class ShopOrderItem extends Model
{
    protected $autoWriteTimestamp = false;

    public function order()
    {
        return $this->belongsTo(ShopOrder::class, 'order_id', 'id');
    }

    public function product()
    {
        return $this->belongsTo(ShopProduct::class, 'product_id', 'id');
    }

    public function getProductThumbnailAttr($value): ?string
    {
        if (!$value) {
            return null;
        }
        return full_url($value, false);
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

