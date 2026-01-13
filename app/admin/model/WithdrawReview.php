<?php

namespace app\admin\model;

use think\model;
use think\model\relation\BelongsTo;

class WithdrawReview extends model
{
    protected $name = 'withdraw_review';

    protected $autoWriteTimestamp = 'int';

    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    public const STATUS_PENDING = 0;
    public const STATUS_APPROVED = 1;
    public const STATUS_REJECTED = 2;

    protected $type = [
        'amount' => 'float',
        'status' => 'integer',
    ];

    public static function getStatusMap(): array
    {
        return [
            self::STATUS_PENDING => '待审核',
            self::STATUS_APPROVED => '审核通过',
            self::STATUS_REJECTED => '审核拒绝',
        ];
    }

    public static function getApplicantTypeMap(): array
    {
        return [
            'user' => '用户',
            'company' => '公司',
            'partner' => '合作方',
        ];
    }

    public function auditAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'audit_admin_id')
            ->bind([
                'audit_admin_name' => 'nickname',
                'audit_admin_username' => 'username',
            ]);
    }
}

