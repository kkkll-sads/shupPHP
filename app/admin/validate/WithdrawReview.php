<?php

namespace app\admin\validate;

use think\Validate;

class WithdrawReview extends Validate
{
    protected $failException = true;

    protected $rule = [
        'applicant_type' => 'require|in:user,company,partner',
        'applicant_id' => 'integer|egt:0',
        'applicant_name' => 'require|max:120',
        'amount' => 'require|float|gt:0',
        'status' => 'require|in:0,1,2',
        'apply_reason' => 'max:500',
        'audit_remark' => 'max:500',
    ];

    protected $message = [];

    protected $field = [];

    protected $scene = [
        'add' => ['applicant_type', 'applicant_id', 'applicant_name', 'amount', 'status', 'apply_reason'],
        'edit' => ['applicant_type', 'applicant_id', 'applicant_name', 'amount', 'status', 'apply_reason', 'audit_remark'],
    ];

    public function __construct()
    {
        $this->field = [
            'applicant_type' => __('Applicant Type'),
            'applicant_id' => __('Applicant Id'),
            'applicant_name' => __('Applicant Name'),
            'amount' => __('Amount'),
            'status' => __('Status'),
            'apply_reason' => __('Apply Reason'),
            'audit_remark' => __('Audit Remark'),
        ];

        $this->message = array_merge($this->message, [
            'applicant_type.in' => __('Invalid applicant type'),
            'status.in' => __('Invalid status value'),
        ]);

        parent::__construct();
    }
}

