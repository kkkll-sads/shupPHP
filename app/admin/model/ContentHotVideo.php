<?php

namespace app\admin\model;

use think\Model;

/**
 * @property int         $id
 * @property string      $title
 * @property string|null $summary
 * @property string|null $video_url
 * @property string|null $cover_image
 * @property string      $status
 * @property int         $publish_time
 * @property int         $sort
 * @property int         $view_count
 * @property int         $create_time
 * @property int         $update_time
 */
class ContentHotVideo extends Model
{
    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    public function getCoverImageAttr($value): ?string
    {
        if (!$value) {
            return null;
        }
        $url = full_url($value, false);
        // 如果返回的URL是 // 开头，补全为 http://
        if ($url && strpos($url, '//') === 0) {
            $url = 'http:' . $url;
        }
        return $url;
    }

    public function getVideoUrlAttr($value): ?string
    {
        if (!$value) {
            return null;
        }
        $url = full_url($value, false);
        // 如果返回的URL是 // 开头，补全为 http://
        if ($url && strpos($url, '//') === 0) {
            $url = 'http:' . $url;
        }
        return $url;
    }

    public function getPublishTimeAttr($value): int
    {
        return (int)$value;
    }

    public function setPublishTimeAttr($value): int
    {
        if (empty($value)) {
            return time();
        }
        if (is_numeric($value)) {
            return (int)$value;
        }
        return strtotime($value) ?: time();
    }
}

