<?php

namespace app\admin\model;

use think\Model;

class RightsDeclaration extends Model
{
    protected $table = 'ba_rights_declaration';

    protected $pk = 'id';

    protected $autoWriteTimestamp = true;

    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    /**
     * 获取状态映射
     */
    public static function getStatusMap(): array
    {
        return [
            'pending' => '待审核',
            'approved' => '已通过',
            'rejected' => '已拒绝',
            'cancelled' => '已撤销',
        ];
    }

    /**
     * 获取凭证类型映射
     */
    public static function getVoucherTypeMap(): array
    {
        return [
            'screenshot' => '截图',
            'transfer_record' => '转账记录',
            'other' => '其他凭证',
        ];
    }

    /**
     * 获取用户关联
     */
    public function user()
    {
        return $this->belongsTo('app\\common\\model\\User', 'user_id', 'id');
    }

    /**
     * 获取审核管理员关联
     */
    public function reviewAdmin()
    {
        return $this->belongsTo('app\\admin\\model\\Admin', 'review_admin_id', 'id');
    }
}
