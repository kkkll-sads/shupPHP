<?php

namespace app\admin\model;

use think\Model;

class TradeReservation extends Model
{
    // 表名
    protected $name = 'trade_reservations';

    // 自动写入时间戳字段
    protected $autoWriteTimestamp = true;

    public function user()
    {
        return $this->belongsTo(\app\admin\model\User::class, 'user_id', 'id');
    }

    public function session()
    {
        return $this->belongsTo(\app\admin\model\CollectionSession::class, 'session_id', 'id');
    }

    public function zone()
    {
        return $this->belongsTo(\app\admin\model\PriceZoneConfig::class, 'zone_id', 'id');
    }
    
    public function package()
    {
        return $this->belongsTo(\app\admin\model\AssetPackage::class, 'package_id', 'id');
    }

    public function getStatusTextAttr($value, $data)
    {
        $status = [
            0 => '待处理',
            1 => '已中签',
            2 => '未中签',
            3 => '已取消'
        ];
        return $status[$data['status']] ?? '未知';
    }
}
