<?php

namespace app\admin\model;

use think\Model;

/**
 * @property int         $id
 * @property int         $session_id
 * @property string      $title
 * @property string|null $image
 * @property string|null $images
 * @property float       $price
 * @property float       $issue_price
 * @property string|null $price_zone
 * @property string|null $description
 * @property string|null $artist
 * @property int         $zone_id
 * @property string|null $core_enterprise
 * @property string|null $farmer_info
 * @property string|null $tx_hash 存证指纹（数据库字段名）
 * @property int         $stock
 * @property int         $sales
 * @property string      $status
 * @property int         $sort
 * @property int         $create_time
 * @property int         $update_time
 */
class CollectionItem extends Model
{
    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    /**
     * 模型事件：创建前自动设置发行价
     */
    public static function onBeforeInsert($item)
    {
        // 如果发行价为0或未设置，使用当前价格作为发行价
        if (!isset($item->issue_price) || (float)$item->issue_price <= 0) {
            $item->issue_price = (float)$item->price;
        }

        // 新增时若未填写存证指纹则自动生成
        if (empty($item->tx_hash)) {
            // 如果已有确权编号，基于确权编号生成哈希（与AssetPackage保持一致）
            if (!empty($item->asset_code)) {
                $item->tx_hash = self::generateFingerprintFromAssetCode($item->asset_code);
            } else {
                $item->tx_hash = self::generateFingerprint();
            }
        }
    }

    /**
     * 更新前自动补全存证指纹（仅当原值为空）
     */
    public static function onBeforeUpdate($item)
    {
        if (empty($item->tx_hash)) {
            // 如果已有确权编号，基于确权编号生成哈希
            if (!empty($item->asset_code)) {
                $item->tx_hash = self::generateFingerprintFromAssetCode($item->asset_code);
            } else {
                $item->tx_hash = self::generateFingerprint();
            }
        }
    }

    /**
     * 删除前检查是否有关联数据
     */
    public static function onBeforeDelete($item)
    {
        // 检查是否有用户持有
        $count = \think\facade\Db::name('user_collection')
            ->where('item_id', $item->id)
            ->count();
        
        if ($count > 0) {
            throw new \Exception('该藏品已被用户持有，无法删除（ID:' . $item->id . '）');
        }

        // 检查是否有寄售记录
        $consignCount = \think\facade\Db::name('collection_consignment')
            ->where('item_id', $item->id)
            ->where('status', '<>', 0) // 非已取消
            ->count();

        if ($consignCount > 0) {
            throw new \Exception('该藏品有相关寄售/挂单记录，无法删除（ID:' . $item->id . '）');
        }
    }

    /**
     * 基于确权编号生成存证指纹（与AssetPackage生成方式保持一致）
     * 格式：0x + MD5(asset_code) 的32位十六进制
     */
    protected static function generateFingerprintFromAssetCode(string $assetCode): string
    {
        return '0x' . md5($assetCode);
    }

    /**
     * 生成随机存证指纹（0x + 32字节十六进制）
     * 当没有确权编号时使用
     */
    protected static function generateFingerprint(): string
    {
        $hex = '';
        try {
            $hex = bin2hex(random_bytes(16));
        } catch (\Throwable) {
            $hex = md5(uniqid((string)microtime(true), true));
        }
        return '0x' . $hex;
    }

    public function getImageAttr($value): ?string
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

    /**
     * 关联资产包
     */
    public function package()
    {
        return $this->belongsTo(\app\admin\model\AssetPackage::class, 'package_id', 'id');
    }
}

