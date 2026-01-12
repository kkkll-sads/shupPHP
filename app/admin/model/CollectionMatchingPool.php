<?php

namespace app\admin\model;

use think\Model;

/**
 * @property int         $id
 * @property int         $item_id
 * @property int         $session_id
 * @property int         $user_id
 * @property float       $power_used
 * @property int         $weight
 * @property string      $status
 * @property int         $match_time
 * @property int         $match_order_id
 * @property int         $create_time
 * @property int         $update_time
 */
class CollectionMatchingPool extends Model
{
    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $name = 'collection_matching_pool';

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function item()
    {
        return $this->belongsTo(CollectionItem::class, 'item_id', 'id');
    }

    public function session()
    {
        return $this->belongsTo(CollectionSession::class, 'session_id', 'id');
    }

    public function matchOrder()
    {
        return $this->belongsTo(CollectionOrder::class, 'match_order_id', 'id');
    }

    public function getPowerUsedAttr($value): float
    {
        return (float)$value;
    }

    public function getStatusTextAttr($value, $data): string
    {
        $statusMap = [
            'pending' => '待撮合',
            'matched' => '已撮合',
            'cancelled' => '已取消',
        ];
        return $statusMap[$data['status']] ?? '未知';
    }
}




