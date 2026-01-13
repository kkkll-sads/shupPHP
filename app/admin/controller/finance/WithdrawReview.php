<?php

namespace app\admin\controller\finance;

use Throwable;
use think\facade\Db;
use think\exception\HttpResponseException;
use app\common\controller\Backend;
use app\admin\model\WithdrawReview as WithdrawReviewModel;
use app\common\model\UserActivityLog;

class WithdrawReview extends Backend
{
    /**
     * @var WithdrawReviewModel
     */
    protected object $model;

    protected string|array $quickSearchField = ['applicant_name', 'applicant_type'];

    protected string|array $defaultSortField = 'id desc';

    protected array $withJoinTable = ['auditAdmin'];

    protected bool $modelSceneValidate = true;

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new WithdrawReviewModel();
    }

    protected array $noNeedPermission = ['stats'];

    /**
     * 获取统计数据
     */
    public function stats(): void
    {
        $stats = [
            'total_amount' => $this->model->sum('amount'),
            'pending_amount' => $this->model->where('status', '0')->sum('amount'),
            'approved_amount' => $this->model->where('status', '1')->sum('amount'),
            'rejected_amount' => $this->model->where('status', '2')->sum('amount'),
        ];
        $this->success('', ['stats' => $stats]);
    }

    /**
     * 获取用户手机号
     * @param string $applicantType 申请方类型
     * @param int $applicantId 申请方ID
     * @return string
     */
    private function getApplicantMobile(string $applicantType, int $applicantId): string
    {
        if ($applicantType === 'user' && $applicantId > 0) {
            $user = Db::name('user')->where('id', $applicantId)->find();
            if ($user) {
                return $user['mobile'] ?? '';
            } else {
                return '用户不存在';
            }
        }
        return '';
    }

    /**
     * 获取收款方式类型
     * @param string $applicantType 申请方类型
     * @param int $applicantId 申请方ID
     * @param float $amount 金额
     * @param int $createTime 创建时间
     * @return string
     */
    private function getPaymentTypeText(string $applicantType, int $applicantId, float $amount, int $createTime): string
    {
        if ($applicantType !== 'user' || $applicantId <= 0) {
            return '';
        }

        // 查找对应的提现记录
        $userWithdraw = Db::name('user_withdraw')
            ->where('user_id', $applicantId)
            ->where('amount', $amount)
            ->order('id desc')
            ->find();

        // 如果精确匹配失败，尝试通过时间范围匹配
        if (!$userWithdraw) {
            $timeRange = 24 * 3600; // 24小时
            $minTime = $createTime - $timeRange;
            $maxTime = $createTime + $timeRange;

            $userWithdraw = Db::name('user_withdraw')
                ->where('user_id', $applicantId)
                ->where('create_time', '>=', $minTime)
                ->where('create_time', '<=', $maxTime)
                ->orderRaw('ABS(CAST(create_time AS SIGNED) - ' . (int)$createTime . ')')
                ->find();
        }

        // 如果还是没找到，尝试匹配最近的记录
        if (!$userWithdraw) {
            $userWithdraw = Db::name('user_withdraw')
                ->where('user_id', $applicantId)
                ->order('id desc')
                ->find();
        }

        if ($userWithdraw && !empty($userWithdraw['payment_account_id'])) {
            // 获取收款账户信息
            $paymentAccount = Db::name('user_payment_account')
                ->where('id', $userWithdraw['payment_account_id'])
                ->find();

            if ($paymentAccount && !empty($paymentAccount['type'])) {
                $paymentTypeMap = [
                    'bank_card' => '银行卡',
                    'alipay' => '支付宝',
                    'wechat' => '微信',
                    'usdt' => 'USDT',
                ];
                $paymentType = $paymentAccount['type'];
                return $paymentTypeMap[$paymentType] ?? $paymentType;
            }
        }

        return '未找到收款信息';
    }

    /**
     * 查看
     * @throws Throwable
     */
    public function index(): void
    {
        if ($this->request->param('select')) {
            $this->select();
        }

        [$where, $alias, $limit, $order] = $this->queryBuilder();

        // 处理快速搜索中的手机号
        $quickSearch = $this->request->get('quickSearch/s', '');
        $quickSearchMobile = '';
        
        // 如果快速搜索值看起来像手机号（11位数字），则作为手机号搜索
        if ($quickSearch && preg_match('/^\d{11}$/', $quickSearch)) {
            $quickSearchMobile = $quickSearch;
            // 从 $where 中移除快速搜索条件（applicant_name 和 applicant_type）
            foreach ($where as $k => $v) {
                if (is_array($v) && isset($v[0]) && is_string($v[0])) {
                    $fieldName = $v[0];
                    // 移除 applicant_name 和 applicant_type 的快速搜索条件
                    if (strpos($fieldName, 'applicant_name') !== false || strpos($fieldName, 'applicant_type') !== false) {
                        unset($where[$k]);
                    }
                }
            }
        }

        // 自定义筛选：处理 payment_search 和所有计算字段
        // 计算字段列表：type_text, account_name, account_number, bank_name, applicant_mobile
        $paymentSearch = $this->request->param('payment_search', '');
        $searchArr = $this->request->param('search/a', []);
        
        // 计算字段搜索值
        $typeTextSearch = '';
        $applicantMobileSearch = $quickSearchMobile ?: ''; // 优先使用快速搜索的手机号
        $accountNameSearch = '';
        $accountNumberSearch = '';
        $bankNameSearch = '';
        
        // 计算字段列表
        $computedFields = ['type_text', 'account_name', 'account_number', 'bank_name', 'applicant_mobile'];
        
        // 检查通用搜索里的所有计算字段
        foreach ($searchArr as $idx => $item) {
            if (!isset($item['field']) || !isset($item['val'])) {
                continue;
            }
            
            $fieldName = $item['field'];
            $searchValue = $item['val'] ?? '';
            
            if ($fieldName === 'type_text') {
                $typeTextSearch = $searchValue;
                unset($searchArr[$idx]);
            } elseif ($fieldName === 'applicant_mobile') {
                $applicantMobileSearch = $searchValue; // 通用搜索优先级更高
                unset($searchArr[$idx]);
            } elseif ($fieldName === 'account_name') {
                $accountNameSearch = $searchValue;
                unset($searchArr[$idx]);
            } elseif ($fieldName === 'account_number') {
                $accountNumberSearch = $searchValue;
                unset($searchArr[$idx]);
            } elseif ($fieldName === 'bank_name') {
                $bankNameSearch = $searchValue;
                unset($searchArr[$idx]);
            }
        }
        
        // 手动过滤 $where 数组里的所有计算字段相关条件
        foreach ($where as $k => $v) {
            // $v 可能是 ['field', 'op', 'val'] 或者 闭包 或者 raw string
            if (is_array($v) && isset($v[0]) && is_string($v[0])) {
                $fieldName = $v[0];
                $searchValue = $v[2] ?? '';
                
                // 检查是否是计算字段（支持带别名的情况）
                $isComputedField = false;
                foreach ($computedFields as $computedField) {
                    if ($fieldName === $computedField || strpos($fieldName, '.' . $computedField) !== false) {
                        $isComputedField = true;
                        
                        // 根据字段名设置对应的搜索值
                        if ($fieldName === $computedField || strpos($fieldName, '.type_text') !== false) {
                            $typeTextSearch = $searchValue;
                        } elseif (strpos($fieldName, '.applicant_mobile') !== false || $fieldName === 'applicant_mobile') {
                            $applicantMobileSearch = $searchValue;
                        } elseif (strpos($fieldName, '.account_name') !== false || $fieldName === 'account_name') {
                            $accountNameSearch = $searchValue;
                        } elseif (strpos($fieldName, '.account_number') !== false || $fieldName === 'account_number') {
                            $accountNumberSearch = $searchValue;
                        } elseif (strpos($fieldName, '.bank_name') !== false || $fieldName === 'bank_name') {
                            $bankNameSearch = $searchValue;
                        }
                        
                        break;
                    }
                }
                
                // 如果是计算字段，从 WHERE 条件中移除
                if ($isComputedField) {
                    unset($where[$k]);
                }
            }
        }
        
        $aliasName = current($alias);
        
        // 如果有 applicant_mobile 搜索，需要 LEFT JOIN user 表
        if ($applicantMobileSearch) {
            $query = $this->model
                ->alias($aliasName)
                ->leftJoin('ba_user u', "u.id = {$aliasName}.applicant_id")
                ->withJoin($this->withJoinTable, $this->withJoinType)
                ->where($where)
                ->where(function($query) use ($aliasName, $applicantMobileSearch) {
                    // 只对用户类型且手机号匹配的记录进行搜索
                    $query->where("{$aliasName}.applicant_type", '=', 'user')
                          ->where('u.mobile', 'like', "%{$applicantMobileSearch}%");
                });
        } else {
            $query = $this->model
                ->alias($aliasName)
                ->withJoin($this->withJoinTable, $this->withJoinType)
                ->where($where);
        }

        // 如果有支付信息搜索或任何计算字段搜索，需要关联 user_withdraw 表
        $hasPaymentSearch = $paymentSearch || $typeTextSearch || $accountNameSearch || $accountNumberSearch || $bankNameSearch;
        
        if ($hasPaymentSearch) {
            $query->whereExists(function ($query) use ($paymentSearch, $typeTextSearch, $accountNameSearch, $accountNumberSearch, $bankNameSearch, $aliasName) {
                $sub = $query->table('ba_user_withdraw')->alias('uw')
                    ->whereRaw("uw.user_id = {$aliasName}.applicant_id")
                    ->whereRaw("uw.amount = {$aliasName}.amount")
                    ->whereRaw("ABS(CAST(uw.create_time AS SIGNED) - CAST({$aliasName}.create_time AS SIGNED)) < 86400"); // 宽松时间匹配
                
                // payment_search 参数搜索（综合搜索收款姓名和开户行）
                if ($paymentSearch) {
                    $sub->where(function ($q) use ($paymentSearch) {
                         $q->where('uw.account_name', 'like', "%{$paymentSearch}%")
                           ->whereOr('uw.bank_name', 'like', "%{$paymentSearch}%");
                    });
                }
                
                // type_text 搜索（收款方式）
                if ($typeTextSearch) {
                    // type_text 可能是 '支付宝', '银行卡' 等
                    // 需要映射回 account_type
                    if (strpos($typeTextSearch, '支付宝') !== false) {
                        $sub->where('uw.account_type', 'alipay');
                    } elseif (strpos($typeTextSearch, '微信') !== false) {
                         $sub->where('uw.account_type', 'wechat');
                    } elseif (strpos($typeTextSearch, '银行') !== false) {
                         $sub->where('uw.account_type', 'bank_card');
                    } elseif (strpos($typeTextSearch, 'USDT') !== false) {
                         $sub->where('uw.account_type', 'usdt');
                    }
                }
                
                // account_name 搜索（收款姓名）
                if ($accountNameSearch) {
                    $sub->where('uw.account_name', 'like', "%{$accountNameSearch}%");
                }
                
                // account_number 搜索（收款账号）
                // 注意：account_number 在数据库中是 base64 编码存储的
                // 用户输入明文账号，需要编码后搜索
                if ($accountNumberSearch) {
                    $sub->where(function ($q) use ($accountNumberSearch) {
                        // 将用户输入的明文账号进行 base64 编码后搜索（因为数据库存储的是编码后的）
                        $encodedValue = base64_encode($accountNumberSearch);
                        $q->where('uw.account_number', 'like', "%{$encodedValue}%");
                        
                        // 同时支持用户直接输入 base64 编码后的值进行搜索
                        $q->whereOr('uw.account_number', 'like', "%{$accountNumberSearch}%");
                        
                        // 使用 MySQL 的 FROM_BASE64 函数解码后搜索（支持直接搜索明文账号）
                        // 注意：FROM_BASE64 在 MySQL 5.6+ 和 MariaDB 10.0+ 中可用
                        $q->whereOrRaw("FROM_BASE64(uw.account_number) LIKE ?", ["%{$accountNumberSearch}%"]);
                    });
                }
                
                // bank_name 搜索（开户行）
                if ($bankNameSearch) {
                    $sub->where('uw.bank_name', 'like', "%{$bankNameSearch}%");
                }
            });
        }

        $res = $query->order($order)->paginate($limit);

        $statusMap = WithdrawReviewModel::getStatusMap();
        $typeMap = WithdrawReviewModel::getApplicantTypeMap();
        
        // 收款方式类型映射
        $paymentTypeMap = [
            'bank_card' => '银行卡',
            'alipay' => '支付宝',
            'wechat' => '微信',
            'usdt' => 'USDT',
        ];

    $list = $res->items();
    foreach ($list as &$item) {
        $item->status_text = $statusMap[(int)$item->status] ?? '未知';
        $item->applicant_type_text = $typeMap[$item->applicant_type] ?? $item->applicant_type;
        $item->audit_time_text = $item->audit_time ? date('Y-m-d H:i:s', (int)$item->audit_time) : '';
        if ((!$item->audit_admin_name) && isset($item->audit_admin_username)) {
            $item->audit_admin_name = $item->audit_admin_username;
        }

        // 获取申请人手机号
        $item->applicant_mobile = $this->getApplicantMobile($item->applicant_type, $item->applicant_id);

        // 获取详细收款信息
        $paymentInfo = $this->getDetailedPaymentInfo($item);
        $item->type_text = $paymentInfo['type_text'];
        $item->account_name = $paymentInfo['account_name'];
        $item->account_number = trim($paymentInfo['account_number']); // 去掉导出的\t
        $item->bank_name = $paymentInfo['bank_name'];
        $item->bank_branch = $paymentInfo['bank_branch'];
        $item->actual_amount = $paymentInfo['actual_amount'];
        $item->fee = $paymentInfo['fee'];
    }

    $this->success('', [
        'list' => $list,
        'total' => $res->total(),
        'remark' => get_route_remark(),
    ]);
}

    /**
     * 查看详情
     * @throws Throwable
     */
    public function read(): void
    {
        $pk = $this->model->getPk();
        $id = $this->request->param($pk);
        $row = $this->model->with(['auditAdmin'])->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        $statusMap = WithdrawReviewModel::getStatusMap();
        $typeMap = WithdrawReviewModel::getApplicantTypeMap();

        $row->status_text = $statusMap[(int)$row->status] ?? '未知';
        $row->applicant_type_text = $typeMap[$row->applicant_type] ?? $row->applicant_type;
        $row->audit_time_text = $row->audit_time ? date('Y-m-d H:i:s', (int)$row->audit_time) : '';
        if ((!$row->audit_admin_name) && isset($row->audit_admin_username)) {
            $row->audit_admin_name = $row->audit_admin_username;
        }

        // 获取申请人手机号
        $row->applicant_mobile = $this->getApplicantMobile($row->applicant_type, $row->applicant_id);

        // 获取收款方式类型
        $row->type_text = $this->getPaymentTypeText($row->applicant_type, $row->applicant_id, $row->amount, $row->create_time);

        // 如果是用户提现，获取收款信息
        $paymentInfo = null;
        if ($row->applicant_type === 'user' && $row->applicant_id > 0) {
            // 查找对应的提现记录
            $userWithdraw = Db::name('user_withdraw')
                ->where('user_id', $row->applicant_id)
                ->where('amount', $row->amount)
                ->order('id desc')
                ->find();
            
            // 如果精确匹配失败，尝试通过时间范围匹配
            if (!$userWithdraw) {
                $timeRange = 24 * 3600; // 24小时
                $minTime = $row->create_time - $timeRange;
                $maxTime = $row->create_time + $timeRange;
                
                $userWithdraw = Db::name('user_withdraw')
                    ->where('user_id', $row->applicant_id)
                    ->where('create_time', '>=', $minTime)
                    ->where('create_time', '<=', $maxTime)
                    ->orderRaw('ABS(CAST(create_time AS SIGNED) - ' . (int)$row->create_time . ')')
                    ->find();
            }
            
            // 如果还是没找到，尝试匹配最近的记录
            if (!$userWithdraw) {
                $userWithdraw = Db::name('user_withdraw')
                    ->where('user_id', $row->applicant_id)
                    ->order('id desc')
                    ->find();
            }
            
            if ($userWithdraw && !empty($userWithdraw['payment_account_id'])) {
                // 获取收款账户信息
                $paymentAccount = Db::name('user_payment_account')
                    ->where('id', $userWithdraw['payment_account_id'])
                    ->find();
                
                if ($paymentAccount) {
                    // 解密账号/卡号
                    $accountNumber = '';
                    try {
                        $decrypted = base64_decode($paymentAccount['account_number']);
                        if ($decrypted !== false) {
                            $accountNumber = $decrypted;
                        }
                    } catch (Throwable $e) {
                        $accountNumber = '';
                    }
                    
                    // 类型文本映射
                    $typeMap = [
                        'bank_card' => '银行卡',
                        'alipay' => '支付宝',
                        'wechat' => '微信',
                        'usdt' => 'USDT',
                    ];
                    $accountTypeMap = [
                        'personal' => '个人',
                        'company' => '公司',
                    ];
                    
                    $paymentInfo = [
                        'type' => $paymentAccount['type'],
                        'type_text' => $typeMap[$paymentAccount['type']] ?? '未知',
                        'account_type' => $paymentAccount['account_type'],
                        'account_type_text' => $accountTypeMap[$paymentAccount['account_type']] ?? '未知',
                        'account_name' => $paymentAccount['account_name'],
                        'account_number' => $accountNumber,
                        'bank_name' => $paymentAccount['bank_name'] ?? '',
                        'bank_branch' => $paymentAccount['bank_branch'] ?? '',
                        'screenshot' => $paymentAccount['screenshot'] ?? '', // 微信收款码
                    ];
                }
            }
        }
        
        $row->payment_info = $paymentInfo;

        $this->success('', [
            'row' => $row
        ]);
    }

    /**
     * 审核通过
     * @throws Throwable
     */
    public function approve(): void
    {
        $pk = $this->model->getPk();
        $id = $this->request->post($pk, $this->request->param($pk));
        if (!$id) {
            $this->error('缺少必要参数：' . $pk);
        }

        $row = $this->model->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        if ((int)$row->status !== WithdrawReviewModel::STATUS_PENDING) {
            $this->error('仅待审核状态可操作');
        }

        $remark = $this->request->post('audit_remark', '');
        $adminId = $this->auth->id;
        $auditTime = time();

        Db::startTrans();
        try {
            $row->status = WithdrawReviewModel::STATUS_APPROVED;
            $row->audit_admin_id = $adminId;
            $row->audit_time = $auditTime;
            $row->audit_remark = $remark;
            $row->save();

            // 同步更新 user_withdraw 表的状态
            if ($row->applicant_type === 'user' && $row->applicant_id > 0) {
                // 优先通过用户ID和金额精确匹配（状态为待审核）
                $userWithdraw = Db::name('user_withdraw')
                    ->where('user_id', $row->applicant_id)
                    ->where('amount', $row->amount)
                    ->where('status', 0) // 待审核状态
                    ->order('id desc')
                    ->find();
                
                // 如果精确匹配失败，尝试通过时间范围匹配（在审核记录创建时间前后24小时内）
                if (!$userWithdraw) {
                    $timeRange = 24 * 3600; // 24小时
                    $minTime = $row->create_time - $timeRange;
                    $maxTime = $row->create_time + $timeRange;
                    
                    $userWithdraw = Db::name('user_withdraw')
                        ->where('user_id', $row->applicant_id)
                        ->where('status', 0) // 待审核状态
                        ->where('create_time', '>=', $minTime)
                        ->where('create_time', '<=', $maxTime)
                        ->orderRaw('ABS(CAST(create_time AS SIGNED) - ' . (int)$row->create_time . ')') // 按时间最接近排序
                        ->find();
                }
                
                // 如果还是没找到，尝试匹配最近的待审核记录
                if (!$userWithdraw) {
                    $userWithdraw = Db::name('user_withdraw')
                        ->where('user_id', $row->applicant_id)
                        ->where('status', 0) // 待审核状态
                        ->order('id desc')
                        ->find();
                }
                
                if ($userWithdraw) {
                    // 审核通过：不再扣减可用余额，只更新提现状态
                    Db::name('user_withdraw')
                        ->where('id', $userWithdraw['id'])
                        ->update([
                            'status' => 1, // 审核通过
                            'audit_time' => $auditTime,
                            'audit_admin_id' => $adminId,
                            'audit_reason' => $remark,
                            'update_time' => $auditTime,
                        ]);
                }
            }

            Db::commit();
            $this->success('审核通过成功');
        } catch (HttpResponseException $e) {
            // HttpResponseException 是 success/error 方法抛出的正常响应异常
            // 此时事务已经 commit，不需要 rollback，直接重新抛出
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $this->error('审核失败：' . $e->getMessage());
        }
    }

    /**
     * 审核拒绝
     * @throws Throwable
     */
    public function reject(): void
    {
        $pk = $this->model->getPk();
        $id = $this->request->post($pk, $this->request->param($pk));
        if (!$id) {
            $this->error('缺少必要参数：' . $pk);
        }

        $row = $this->model->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        if ((int)$row->status !== WithdrawReviewModel::STATUS_PENDING) {
            $this->error('仅待审核状态可操作');
        }

        $remark = trim((string)$this->request->post('audit_remark', ''));
        if ($remark === '') {
            $this->error('拒绝原因不能为空');
        }

        $adminId = $this->auth->id;
        $auditTime = time();

        Db::startTrans();
        try {
            $row->status = WithdrawReviewModel::STATUS_REJECTED;
            $row->audit_admin_id = $adminId;
            $row->audit_time = $auditTime;
            $row->audit_remark = $remark;
            $row->save();

            // 同步更新 user_withdraw 表的状态
            if ($row->applicant_type === 'user' && $row->applicant_id > 0) {
                // 优先通过用户ID和金额精确匹配（状态为待审核）
                $userWithdraw = Db::name('user_withdraw')
                    ->where('user_id', $row->applicant_id)
                    ->where('amount', $row->amount)
                    ->where('status', 0) // 待审核状态
                    ->order('id desc')
                    ->find();
                
                // 如果精确匹配失败，尝试通过时间范围匹配（在审核记录创建时间前后24小时内）
                if (!$userWithdraw) {
                    $timeRange = 24 * 3600; // 24小时
                    $minTime = $row->create_time - $timeRange;
                    $maxTime = $row->create_time + $timeRange;
                    
                    $userWithdraw = Db::name('user_withdraw')
                        ->where('user_id', $row->applicant_id)
                        ->where('status', 0) // 待审核状态
                        ->where('create_time', '>=', $minTime)
                        ->where('create_time', '<=', $maxTime)
                        ->orderRaw('ABS(CAST(create_time AS SIGNED) - ' . (int)$row->create_time . ')') // 按时间最接近排序
                        ->find();
                }
                
                // 如果还是没找到，尝试匹配最近的待审核记录
                if (!$userWithdraw) {
                    $userWithdraw = Db::name('user_withdraw')
                        ->where('user_id', $row->applicant_id)
                        ->where('status', 0) // 待审核状态
                        ->order('id desc')
                        ->find();
                }
                
                if ($userWithdraw) {
                    // 审核拒绝时，需要退回可提现余额
                    $user = Db::name('user')
                        ->where('id', $row->applicant_id)
                        ->lock(true)
                        ->find();
                    
                    if ($user) {
                        $refundAmount = (float)$userWithdraw['amount'];
                        $beforeWithdrawable = (float)$user['withdrawable_money'];
                        $newWithdrawable = round($beforeWithdrawable + $refundAmount, 2);
                        
                        // 检查是否超出最大值（DECIMAL(10,2) 最大值为 99999999.99）
                        $maxValue = 99999999.99;
                        if ($newWithdrawable > $maxValue) {
                            $newWithdrawable = $maxValue;
                        }
                        
                        // 退回可提现余额
                        Db::name('user')
                            ->where('id', $row->applicant_id)
                            ->update([
                                'withdrawable_money' => $newWithdrawable,
                                'update_time' => $auditTime,
                            ]);
                        
                        // 记录资金变动日志
                        Db::name('user_money_log')->insert([
                            'user_id' => $row->applicant_id,
                            'money' => $refundAmount,
                            'before' => $beforeWithdrawable,
                            'after' => $newWithdrawable,
                            'memo' => '提现审核拒绝，退回可提现余额',
                            'biz_type' => 'withdraw_reject',
                            'biz_id' => $userWithdraw['id'],
                            'create_time' => $auditTime,
                        ]);
                        
                        // 记录用户活动日志
                        UserActivityLog::create([
                            'user_id' => $row->applicant_id,
                            'related_user_id' => 0,
                            'action_type' => 'withdrawable_money',
                            'change_field' => 'withdrawable_money',
                            'change_value' => (string)$refundAmount,
                            'before_value' => (string)$beforeWithdrawable,
                            'after_value' => (string)$newWithdrawable,
                            'remark' => '提现审核拒绝，退回可提现余额，提现订单ID：' . $userWithdraw['id'],
                            'extra' => [
                                'withdraw_id' => $userWithdraw['id'],
                                'refund_amount' => $refundAmount,
                                'audit_admin_id' => $adminId,
                                'audit_remark' => $remark,
                            ],
                        ]);
                    }
                    
                    // 更新提现记录状态
                    Db::name('user_withdraw')
                        ->where('id', $userWithdraw['id'])
                        ->update([
                            'status' => 2, // 审核拒绝
                            'audit_time' => $auditTime,
                            'audit_admin_id' => $adminId,
                            'audit_reason' => $remark,
                            'update_time' => $auditTime,
                        ]);
                }
            }

            Db::commit();
            $this->success('已拒绝该提现申请');
        } catch (HttpResponseException $e) {
            // HttpResponseException 是 success/error 方法抛出的正常响应异常
            // 此时事务已经 commit，不需要 rollback，直接重新抛出
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $this->error('审核失败：' . $e->getMessage());
        }
    }
    /**
     * 导出
     * @throws Throwable
     */
    public function export(): void
    {
        [$where, $alias, $limit, $order] = $this->queryBuilder();
        
        // 增加对收款信息的筛选（如果前端传了 payment_search）
        $paymentSearch = $this->request->param('payment_search', '');
        
        $list = $this->model
            ->alias($alias)
            ->withJoin($this->withJoinTable, $this->withJoinType)
            ->where($where)
            ->order($order)
            ->select();

        $data = [];
        foreach ($list as $item) {
            // 获取扩展信息
            $statusMap = WithdrawReviewModel::getStatusMap();
            $typeMap = WithdrawReviewModel::getApplicantTypeMap();
            
            $statusText = $statusMap[(int)$item->status] ?? '未知';
            $applicantTypeText = $typeMap[$item->applicant_type] ?? $item->applicant_type;
            $auditTimeText = $item->audit_time ? date('Y-m-d H:i:s', (int)$item->audit_time) : '';
            $createTimeText = date('Y-m-d H:i:s', $item->create_time);
            $auditAdminName = $item->audit_admin_name ?: ($item->audit_admin_username ?? '');
            $applicantMobile = $this->getApplicantMobile($item->applicant_type, $item->applicant_id);

            // 获取详细支付信息
            $paymentInfo = $this->getDetailedPaymentInfo($item);
            
            // 如果有搜索条件，进行内存筛选
            if ($paymentSearch) {
                $searchStr = $paymentInfo['account_name'] . $paymentInfo['account_number'] . $paymentInfo['bank_name'];
                if (mb_strpos($searchStr, $paymentSearch) === false) {
                    continue;
                }
            }

            $data[] = [
                'id' => $item->id,
                'applicant_name' => $item->applicant_name,
                'applicant_mobile' => $applicantMobile,
                'applicant_type' => $applicantTypeText,
                'amount' => $item->amount,
                'status' => $statusText,
                'payment_type' => $paymentInfo['type_text'],
                'account_name' => $paymentInfo['account_name'],
                'account_number' => $paymentInfo['account_number'], // 明文账号
                'bank_name' => $paymentInfo['bank_name'],
                'bank_branch' => $paymentInfo['bank_branch'],
                'apply_reason' => $item->apply_reason,
                'create_time' => $createTimeText,
                'audit_time' => $auditTimeText,
                'audit_admin' => $auditAdminName,
                'audit_remark' => $item->audit_remark,
            ];
        }

        $filename = '提现审核记录_' . date('YmdHis');
        $header = [
            'ID', '申请方', '手机号', '类型', '提现金额', '状态', 
            '收款方式', '收款姓名', '收款账号', '开户行', '开户支行',
            '申请说明', '申请时间', '审核时间', '审核人', '审核备注'
        ];

        // 简单的CSV导出 (避免依赖复杂的Spreadsheet库如果不需要的话，但TP通常有)
        // 使用内置的导出功能（如果Backend有support）或者手动生成CSV
        // 这里为了兼容性，生成CSV
        
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment;filename="' . $filename . '.csv"');
        header('Cache-Control: max-age=0');
        
        $fp = fopen('php://output', 'a');
        fwrite($fp, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM头，防止乱码
        fputcsv($fp, $header);
        
        foreach ($data as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);
        exit;
    }

    /**
     * 获取详细支付信息（含解密）
     */
    private function getDetailedPaymentInfo($row): array
    {
        $info = [
            'type_text' => '',
            'account_name' => '',
            'account_number' => '',
            'bank_name' => '',
            'bank_branch' => '',
            'actual_amount' => 0,
            'fee' => 0,
        ];

        if ($row->applicant_type !== 'user' || $row->applicant_id <= 0) {
            return $info;
        }

        // 查找对应的提现记录 (复用逻辑)
        $userWithdraw = null;
        
        // 尝试时间范围匹配
        $timeRange = 24 * 3600;
        $minTime = $row->create_time - $timeRange;
        $maxTime = $row->create_time + $timeRange;
        
        // 优先尝试精确金额匹配 + 时间范围
        $userWithdraw = Db::name('user_withdraw')
            ->where('user_id', $row->applicant_id)
            ->where('amount', $row->amount)
            ->where('create_time', '>=', $minTime)
            ->where('create_time', '<=', $maxTime)
            ->order('id desc')
            ->find();

        // 没找到再尝试宽泛匹配
        if (!$userWithdraw) {
             $userWithdraw = Db::name('user_withdraw')
                ->where('user_id', $row->applicant_id)
                ->where('create_time', '>=', $minTime)
                ->where('create_time', '<=', $maxTime)
                ->orderRaw('ABS(CAST(create_time AS SIGNED) - ' . (int)$row->create_time . ')')
                ->find();
        }

        if ($userWithdraw) {
            // 直接使用 user_withdraw 的快照数据
            
            // 解密
            $accountNumber = '';
            try {
                // user_withdraw 里的 account_number 通常也是 base64 编码的
                $decrypted = base64_decode($userWithdraw['account_number']);
                if ($decrypted !== false) {
                    $accountNumber = $decrypted;
                } else {
                    $accountNumber = $userWithdraw['account_number']; // 如果解密失败，保留原值
                }
            } catch (Throwable $e) {
                $accountNumber = $userWithdraw['account_number'];
            }

            $typeMap = ['bank_card' => '银行卡', 'alipay' => '支付宝', 'wechat' => '微信', 'usdt' => 'USDT'];
            $accountTypeMap = ['personal' => '个人', 'company' => '公司'];
            
            $info['type_text'] = $typeMap[$userWithdraw['account_type']] ?? ($userWithdraw['account_type'] ?: '未知');
            // 注意：user_withdraw 表的 account_type 可能存储的是 'bank_card' 这种值，也可能是 'personal'/'company'?
            // 查看 Step 1188 的 DESCRIBE，account_type 是 varchar(20)
            // 通常是 payment_type (bank_card) 还是 account_type (personal)?
            // Step 1241 的数据里没有显示 account_type 列的值，但 DESCRIBE 有。
            // 假设它存的是 payment_type (如 bank_card)。如果 user_withdraw 存的是 'type' 字段... 等等 Step 1188 里有 'account_type' 字段
            // 让我们再检查一下 Step 1189 的代码，index 方法里：
            // $paymentType = $paymentAccount['type'];
            // 看来 user_withdraw 的 account_type 字段含义不明，可能和 payment_account 的 type 不同。
            // 为了保险，如果 user_withdraw 里没有 type 字段，我们可能还得依赖 account_type ? 
            // Step 1188 显示 user_withdraw 有 account_type, account_name, bank_name. 但没有 type 字段? 
            // 之前的代码 index 方法里： getPaymentTypeText 也是查 payment_account['type']。
            // 让我们看看 user_withdraw 的 account_type 存的是什么。
            
            $info['account_name'] = $userWithdraw['account_name'];
            $info['account_number'] = $accountNumber . "\t"; 
            $info['bank_name'] = $userWithdraw['bank_name'] ?? '';
            $info['bank_branch'] = $userWithdraw['bank_branch'] ?? '';
            $info['actual_amount'] = (float)($userWithdraw['actual_amount'] ?? 0);
            $info['fee'] = (float)($userWithdraw['fee'] ?? 0);
            
            // 尝试从 account_type 或 bank_name 推断支付方式
            if ($userWithdraw['bank_name']) {
                $info['type_text'] = '银行卡';
            } elseif (strpos($userWithdraw['account_number'], '@') !== false) { // 简单猜测
                 // 无法准确判断
            }
            
            // 为了获取准确的 'type' (bank_card/alipay等)，我们可能还是需要 payment_account_id
            // 或者看 user_withdraw 表是否有 type 字段。Step 1188 没有显示 type 字段，只有 account_type。
            // 让我们快速查一下 index 方法里之前的逻辑：
            // $paymentAccount = Db::name('user_payment_account')->where('id', $userWithdraw['payment_account_id'])->find();
            // $paymentType = $paymentAccount['type'];
            
            // 所以，为了获取支付方式（银行卡/支付宝），还是得查 payment_account，或者如果查不到，就只能显示未知或根据 bank_name 判断。
            // 混合策略：先查 payment_account 获取 type，如果 payment_account 没了，就用 user_withdraw 的数据。
            
            if (!empty($userWithdraw['payment_account_id'])) {
                 $paymentAccount = Db::name('user_payment_account')
                    ->where('id', $userWithdraw['payment_account_id'])
                    ->find();
                 if ($paymentAccount) {
                     $info['type_text'] = $typeMap[$paymentAccount['type']] ?? $paymentAccount['type'];
                 } else {
                     // 账户已删，根据 bank_name 判断
                     if (!empty($userWithdraw['bank_name'])) {
                         $info['type_text'] = '银行卡';
                     } else {
                         $info['type_text'] = '未知'; 
                     }
                 }
            } else {
                 if (!empty($userWithdraw['bank_name'])) {
                     $info['type_text'] = '银行卡';
                 }
            }
        }
        
        return $info;
    }
}

