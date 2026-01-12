<?php

namespace app\admin\model;

use think\Model;

/**
 * Announcement 公告模型
 * @property int    $id           公告ID
 * @property string $title        公告标题
 * @property string $content      公告内容
 * @property string $type         公告类型:normal=平台公告,important=平台动态
 * @property string $status       状态:0=禁用,1=启用
 * @property int    $is_popup     是否弹出显示:0=否,1=是
 * @property int    $popup_delay  弹出延迟时间（毫秒）
 * @property int    $sort         排序
 * @property int    $start_time   开始时间
 * @property int    $end_time     结束时间
 * @property int    $view_count   查看次数
 */
class Announcement extends Model
{
    protected $autoWriteTimestamp = true;

    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    // 使用 UNIX 时间戳格式（与数据库 bigint 类型匹配）
    protected $dateFormat = false;

    /**
     * 获取公告类型文本
     * @param string $value
     * @return string
     */
    public function getTypeTextAttr($value, $data): string
    {
        $typeText = ['normal' => '平台公告', 'important' => '平台动态'];
        return $typeText[$data['type']] ?? '';
    }

    /**
     * 获取状态文本
     * @param string $value
     * @return string
     */
    public function getStatusTextAttr($value, $data): string
    {
        $statusText = ['0' => '禁用', '1' => '启用'];
        return $statusText[$data['status']] ?? '';
    }

    /**
     * 获取是否弹出文本
     * @param int $value
     * @return string
     */
    public function getIsPopupTextAttr($value, $data): string
    {
        $isPopupText = ['0' => '否', '1' => '是'];
        return $isPopupText[$data['is_popup']] ?? '';
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
     * 设置弹出延迟时间
     * @param int $value
     * @return int
     */
    public function setPopupDelayAttr($value): int
    {
        return $value ?: 3000;
    }
}
