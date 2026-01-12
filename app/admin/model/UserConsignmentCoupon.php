<?php

namespace app\admin\model;

use think\model;
use think\model\relation\BelongsTo;

/**
 * UserConsignmentCoupon 模型
 * 寄售券明细
 */
class UserConsignmentCoupon extends model
{
    protected $name = 'user_consignment_coupon';
    protected $autoWriteTimestamp = true;

    /**
     * 关联用户
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 关联场次
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CollectionSession::class, 'session_id');
    }

    /**
     * 关联价格分区
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(PriceZoneConfig::class, 'zone_id');
    }

    /**
     * 格式化过期时间
     */
    public function getExpireTimeAttr($value): string
    {
        return $value ? date('Y-m-d H:i:s', $value) : '';
    }

    /**
     * 状态文字
     */
    public function getStatusTextAttr($value, $data): string
    {
        $now = time();
        $status = (int)($data['status'] ?? 0);
        $expireTime = (int)($data['expire_time'] ?? 0);
        
        if ($status === 0) {
            return '已使用';
        }
        if ($expireTime > 0 && $expireTime < $now) {
            return '已过期';
        }
        return '可用';
    }
}
