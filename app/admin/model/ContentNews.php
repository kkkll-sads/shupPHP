<?php

namespace app\admin\model;

use think\Model;

/**
 * @property int         $id
 * @property string      $title
 * @property string|null $summary
 * @property string|null $cover_image
 * @property string|null $content
 * @property string|null $link_url
 * @property int         $is_hot
 * @property string      $status
 * @property int         $publish_time
 * @property int         $sort
 * @property int         $view_count
 * @property int         $create_time
 * @property int         $update_time
 */
class ContentNews extends Model
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

    public function getLinkUrlAttr($value): ?string
    {
        if (!$value) {
            return null;
        }
        // 如果已经包含协议头，直接返回
        if (preg_match('/^(https?:\/\/)/', $value)) {
            return $value;
        }
        // 否则自动添加 http:// 前缀
        return 'http://' . $value;
    }

    public function getContentAttr($value): ?string
    {
        if (!$value) {
            return null;
        }
        // 处理转义的括号，将 \( 和 \) 还原为 ( 和 )
        // 这通常发生在 URL 中的括号被转义时（如 image\(29\) -> image(29)）
        $value = str_replace(['\(', '\)'], ['(', ')'], $value);
        
        // 处理内容中的 // 开头的链接，补全为 http://
        // 1. 处理 HTML 标签中的链接 (如 src="//" 或 href="//")
        $value = preg_replace('/(src|href)="\/\//', '$1="http://', $value);
        // 2. 处理 Markdown 格式的图片链接 ![](//...)
        $value = preg_replace('/!\[\]\(\/\//', '![](http://', $value);
        return $value;
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


