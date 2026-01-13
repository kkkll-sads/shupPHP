<?php

namespace app\admin\model;

use think\Model;

/**
 * Banner 轮番图模型
 * @property int    $id           轮番图ID
 * @property string $title        标题
 * @property string $image        图片路径
 * @property string $url          跳转链接
 * @property string $description  描述
 * @property int    $sort         排序
 * @property string $status       状态:0=隐藏,1=显示
 * @property int    $start_time   开始时间
 * @property int    $end_time     结束时间
 */
class Banner extends Model
{
    protected $autoWriteTimestamp = 'int';

    protected $dateFormat = 'Y-m-d H:i:s';

    /**
     * 获取创建时间（返回时间戳，让前端datetime渲染器处理）
     * @param int $value
     * @return int
     */
    public function getCreateTimeAttr($value): int
    {
        return (int)$value;
    }

    /**
     * 获取更新时间（返回时间戳，让前端datetime渲染器处理）
     * @param int $value
     * @return int
     */
    public function getUpdateTimeAttr($value): int
    {
        return (int)$value;
    }

    /**
     * 获取图片完整URL
     * @param string $value
     * @return string
     */
    public function getImageAttr($value): string
    {
        return full_url($value, false);
    }

    /**
     * 设置图片路径
     * @param string $value
     * @return string
     */
    public function setImageAttr($value): string
    {
        return $value;
    }

    /**
     * 获取状态文本
     * @param string $value
     * @return string
     */
    public function getStatusTextAttr($value, $data): string
    {
        $statusText = ['0' => '隐藏', '1' => '显示'];
        return $statusText[$data['status']] ?? '';
    }

    /**
     * 获取开始时间格式化
     * @param int $value
     * @return string
     */
    public function getStartTimeAttr($value): string
    {
        return $value ? date($this->dateFormat, $value) : '';
    }

    /**
     * 获取结束时间格式化
     * @param int $value
     * @return string
     */
    public function getEndTimeAttr($value): string
    {
        return $value ? date($this->dateFormat, $value) : '';
    }


    /**
     * 设置开始时间
     * @param string $value
     * @return int
     */
    public function setStartTimeAttr($value): int
    {
        return $value ? strtotime($value) : 0;
    }

    /**
     * 设置结束时间
     * @param string $value
     * @return int
     */
    public function setEndTimeAttr($value): int
    {
        return $value ? strtotime($value) : 0;
    }

    /**
     * 设置创建时间（防止格式化字符串被插入）
     * @param mixed $value
     * @return mixed
     */
    public function setCreateTimeAttr($value)
    {
        // 如果是格式化的字符串，忽略这个值（不更新创建时间）
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return $this->getOrigin('create_time'); // 返回原始值，不更新
        }
        return $value;
    }

    /**
     * 设置更新时间（防止格式化字符串被插入）
     * @param mixed $value
     * @return mixed
     */
    public function setUpdateTimeAttr($value)
    {
        // 如果是格式化的字符串，不要更新更新时间
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return null; // 不更新，让ThinkPHP自动处理
        }
        return $value;
    }

}
