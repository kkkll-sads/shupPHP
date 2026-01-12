<?php

namespace app\admin\model;

use think\Model;

/**
 * 寄售记录模型
 * @property int $id
 * @property int $user_id 寄售用户ID
 * @property int $user_collection_id 用户藏品记录ID
 * @property int $item_id 藏品商品ID
 * @property int $package_id 归属资产包ID
 * @property string $package_name 归属资产包名称
 * @property float $price 寄售价格
 * @property float $original_price 卖家原购买价格
 * @property float $service_fee 服务费
 * @property int $coupon_used 是否使用寄售券
 * @property int $coupon_waived 是否豁免寄售券
 * @property string $waive_type 豁免类型
 * @property int $free_relist_used 免费重发资格已使用
 * @property int $coupon_id 使用的寄售券ID
 * @property int $status 状态: 1=寄售中, 2=已售出, 3=流拍, 4=已取消
 * @property int $create_time 创建时间
 * @property int $update_time 更新时间
 */
class CollectionConsignment extends Model
{
    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 关联藏品商品
     */
    public function item()
    {
        return $this->belongsTo(CollectionItem::class, 'item_id', 'id');
    }

    /**
     * 关联资产包
     */
    public function package()
    {
        return $this->belongsTo(AssetPackage::class, 'package_id', 'id');
    }

    /**
     * 获取状态文本
     */
    public function getStatusTextAttr($value, $data): string
    {
        $statusMap = [
            1 => '寄售中',
            2 => '已售出',
            3 => '流拍',
            4 => '已取消',
        ];
        return $statusMap[$data['status']] ?? '未知';
    }

    /**
     * 价格访问器
     */
    public function getPriceAttr($value): float
    {
        return (float)$value;
    }

    public function getOriginalPriceAttr($value): float
    {
        return (float)$value;
    }

    public function getServiceFeeAttr($value): float
    {
        return (float)$value;
    }

    public function getSoldPriceAttr($value): float
    {
        return (float)$value ?? 0.00;
    }
}

