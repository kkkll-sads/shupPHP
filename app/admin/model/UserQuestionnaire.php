<?php

namespace app\admin\model;

use think\Model;

/**
 * 用户问卷模型
 */
class UserQuestionnaire extends Model
{
    protected $name = 'user_questionnaire';
    
    protected $autoWriteTimestamp = true;
    
    // 状态常量
    const STATUS_PENDING = 0;   // 待审核
    const STATUS_ADOPTED = 1;   // 已采纳
    const STATUS_REJECTED = 2;  // 已拒绝
    
    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(\app\admin\model\User::class, 'user_id', 'id');
    }
    
    /**
     * 状态名称获取器
     */
    public function getStatusTextAttr($value, $data)
    {
        $status = [
            self::STATUS_PENDING => '待审核',
            self::STATUS_ADOPTED => '已采纳',
            self::STATUS_REJECTED => '已拒绝',
        ];
        return $status[$data['status']] ?? '未知';
    }
}
