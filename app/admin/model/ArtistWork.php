<?php

namespace app\admin\model;

use think\Model;

/**
 * 艺术家作品模型
 * @property int    $id
 * @property int    $artist_id 艺术家ID
 * @property string $title     作品标题
 * @property string $image     作品图片
 * @property string $description 作品描述
 * @property float  $price     作品价格
 * @property string $status    状态:0=隐藏,1=显示
 * @property int    $sort      排序(倒序)
 * @property int    $create_time
 * @property int    $update_time
 */
class ArtistWork extends Model
{
    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';
}


