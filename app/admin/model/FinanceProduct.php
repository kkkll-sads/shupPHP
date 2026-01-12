<?php

namespace app\admin\model;

use think\Model;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $slug
 * @property string|null $thumbnail
 * @property string|null $summary
 * @property float       $price
 * @property int         $cycle_days
 * @property string      $cycle_type
 * @property int         $cycle_value
 * @property float       $yield_rate
 * @property int         $total_amount
 * @property int         $sold_amount
 * @property float       $progress
 * @property int         $min_purchase
 * @property int         $max_purchase
 * @property int         $per_user_limit
 * @property string      $status
 * @property int         $sort
 * @property int         $create_time
 * @property int         $update_time
 */
class FinanceProduct extends Model
{
    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    public function getThumbnailAttr($value): ?string
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

    public function getYieldRateAttr($value): float
    {
        return (float)$value;
    }

    public function getProgressAttr($value): float
    {
        return (float)$value;
    }
}


