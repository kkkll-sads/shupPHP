<?php

namespace app\admin\model;

use think\Model;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $thumbnail
 * @property string|null $images
 * @property string|null $description
 * @property string|null $category
 * @property float       $price
 * @property int         $score_price
 * @property int         $stock
 * @property int         $sales
 * @property string      $purchase_type
 * @property string      $status
 * @property string      $is_physical
 * @property string      $is_card_product
 * @property int         $sort
 * @property int         $create_time
 * @property int         $update_time
 */
class ShopProduct extends Model
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

    public function getImagesAttr($value): array
    {
        if (!$value) {
            return [];
        }
        $images = explode(',', $value);
        return array_map(function ($img) {
            return full_url($img, false);
        }, $images);
    }

    public function setImagesAttr($value): string
    {
        if (is_array($value)) {
            return implode(',', $value);
        }
        return $value;
    }

    public function getPriceAttr($value): float
    {
        return (float)$value;
    }
}

