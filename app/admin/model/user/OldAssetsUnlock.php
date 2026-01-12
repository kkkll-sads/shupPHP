<?php

namespace app\admin\model\user;

use think\Model;

/**
 * OldAssetsUnlock
 */
class OldAssetsUnlock extends Model
{
    // 表名
    protected $name = 'user_old_assets_unlock';

    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'create_time';
    protected $updateTime = false; // 没有update_time字段

    // 追加属性
    protected $append = [
        'unlock_status_text',
    ];

    public function getUnlockStatusList()
    {
        return ['0' => __('Unknown'), '1' => __('Success')];
    }

    public function getUnlockStatusTextAttr($value, $data)
    {
        $value = $value ? $value : ($data['unlock_status'] ?? '');
        $list = $this->getUnlockStatusList();
        return isset($list[$value]) ? $list[$value] : '';
    }

    public function user()
    {
        return $this->belongsTo(\app\admin\model\User::class, 'user_id', 'id');
    }
}
