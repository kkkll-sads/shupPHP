<?php

namespace app\admin\model;

use think\Model;

/**
 * 资产包模型
 * @property int         $id
 * @property int         $session_id
 * @property int         $zone_id
 * @property string      $name
 * @property string|null $description
 * @property string|null $cover_image
 * @property float       $min_price
 * @property float       $max_price
 * @property int         $total_count
 * @property int         $sold_count
 * @property int         $status
 * @property int         $is_default
 * @property int         $create_time
 * @property int         $update_time
 */
class AssetPackage extends Model
{
    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    /**
     * 获取封面图完整URL
     */
    public function getCoverImageAttr($value): ?string
    {
        if (!$value) {
            return null;
        }
        return full_url($value, false);
    }

    /**
     * 关联场次
     */
    public function session()
    {
        return $this->belongsTo(CollectionSession::class, 'session_id', 'id');
    }

    /**
     * 关联分区
     */
    public function zone()
    {
        return $this->belongsTo(PriceZoneConfig::class, 'zone_id', 'id');
    }

    /**
     * 状态文本转换
     */
    public function getStatusTextAttr($value, $data): string
    {
        return $data['status'] == 1 ? '启用' : '禁用';
    }

    /**
     * 是否默认包文本转换
     */
    public function getIsDefaultTextAttr($value, $data): string
    {
        return $data['is_default'] == 1 ? '是' : '否';
    }
}
