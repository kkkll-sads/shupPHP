<?php

namespace app\admin\model;

use think\Model;

/**
 * @property int         $id
 * @property string      $category
 * @property string      $title
 * @property string|null $description
 * @property string|null $cover_image
 * @property string|null $media_url
 * @property string      $media_type
 * @property string      $status
 * @property int         $sort
 * @property array|null  $extra
 * @property int         $create_time
 * @property int         $update_time
 */
class ContentMedia extends Model
{
    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $json = ['extra'];

    public function getCoverImageAttr($value): ?string
    {
        if (!$value) {
            return null;
        }
        return full_url($value, false);
    }

    public function getMediaUrlAttr($value): ?string
    {
        if (!$value) {
            return null;
        }
        return full_url($value, false);
    }
}


