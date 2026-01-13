<?php

namespace app\admin\model;

use think\Model;

/**
 * @property int    $id
 * @property string $platform     平台:android=安卓,ios=苹果
 * @property string $app_name     软件名称
 * @property string $version_code 版本号
 * @property string $download_url 软件直链
 * @property int    $create_time
 * @property int    $update_time
 */
class AppVersion extends Model
{
    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    /**
     * 获取创建时间（返回时间戳）
     * @param int $value
     * @return int
     */
    public function getCreateTimeAttr($value): int
    {
        return (int)$value;
    }

    /**
     * 获取更新时间（返回时间戳）
     * @param int $value
     * @return int
     */
    public function getUpdateTimeAttr($value): int
    {
        return (int)$value;
    }

    /**
     * 获取平台文本
     * @param string $value
     * @return string
     */
    public function getPlatformTextAttr($value, $data): string
    {
        $platformText = ['android' => '安卓', 'ios' => '苹果'];
        return $platformText[$data['platform']] ?? '';
    }
}

