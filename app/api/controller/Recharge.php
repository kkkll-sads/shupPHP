<?php

namespace app\api\controller;

use Throwable;
use think\facade\Db;
use think\facade\Log;
use app\common\controller\Frontend;
use app\common\library\Upload;
use app\common\model\UserActivityLog;
use app\admin\model\CompanyPaymentAccount as CompanyPaymentAccountModel;
use think\exception\HttpResponseException;

use hg\apidoc\annotation as Apidoc;

#[Apidoc\Title("充值管理")]
class Recharge extends Frontend
{
    protected array $noNeedLogin = ['testPayment'];
    
    public function testPayment()
    {
        $paymentResult = \app\api\controller\Payment::startPayment(42, 'test', 'test');
        $this->success('', $paymentResult);
    }
    
    #[
        Apidoc\Title("获取公司收款账户列表"),
        Apidoc\Tag("充值,收款账户"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Recharge/getCompanyAccountList"),
        Apidoc\Query(name:"usage", type: "string", require: false, desc: "使用场景: recharge=充值(默认), withdraw=提现, all=全部启用账户"),
        Apidoc\Returned("list", type: "array", desc: "账户列表"),
        Apidoc\Returned("list[].id", type: "int", desc: "账户ID"),
        Apidoc\Returned("list[].type", type: "string", desc: "账户类型:bank_card=银行卡,alipay=支付宝,wechat=微信,usdt=USDT"),
        Apidoc\Returned("list[].type_text", type: "string", desc: "账户类型文本"),
        Apidoc\Returned("list[].account_name", type: "string", desc: "账户名称"),
        Apidoc\Returned("list[].account_number", type: "string", desc: "账户号码"),
        Apidoc\Returned("list[].bank_name", type: "string", desc: "银行名称(银行卡类型时)"),
        Apidoc\Returned("list[].bank_branch", type: "string", desc: "开户行(银行卡类型时)"),
        Apidoc\Returned("list[].icon", type: "string", desc: "支付图标URL"),
        Apidoc\Returned("list[].remark", type: "string", desc: "备注说明"),
        Apidoc\Returned("list[].status", type: "int", desc: "状态:1=充值可用,2=提现可用,3=充值提现可用"),
        Apidoc\Returned("list[].status_text", type: "string", desc: "状态文本"),
    ]
    /**
     * 获取公司收款账户列表
     */
    public function getCompanyAccountList(): void
    {
        $usage = $this->request->get('usage', 'recharge');
        $usageStatusMap = [
            'recharge' => [
                CompanyPaymentAccountModel::STATUS_RECHARGE,
                CompanyPaymentAccountModel::STATUS_ALL,
            ],
            'withdraw' => [
                CompanyPaymentAccountModel::STATUS_WITHDRAW,
                CompanyPaymentAccountModel::STATUS_ALL,
            ],
            'all' => [
                CompanyPaymentAccountModel::STATUS_RECHARGE,
                CompanyPaymentAccountModel::STATUS_WITHDRAW,
                CompanyPaymentAccountModel::STATUS_ALL,
            ],
        ];
        $statuses = $usageStatusMap[$usage] ?? $usageStatusMap['recharge'];

        $list = Db::name('company_payment_account')
            ->whereIn('status', $statuses)
            ->order('sort desc, id desc')
            ->select()
            ->toArray();

        $statusMap = CompanyPaymentAccountModel::getStatusMap();
        foreach ($list as &$item) {
            // 账户类型文本
            $typeMap = [
                'bank_card' => '银行卡',
                'alipay' => '支付宝',
                'wechat' => '微信',
                'usdt' => 'USDT',
            ];
            $item['type_text'] = $typeMap[$item['type']] ?? '未知';
            $item['status_text'] = $statusMap[(int)$item['status']] ?? '未知';

            // 设置默认支付图标
            if (empty($item['icon'])) {
                $defaultIcons = [
                    'bank_card' => '/static/images/payment/bank_card.png',
                    'alipay' => '/static/images/payment/alipay.png',
                    'wechat' => '/static/images/payment/wechat.png',
                    'usdt' => '/static/images/payment/usdt.png',
                ];
                $item['icon'] = $defaultIcons[$item['type']] ?? '';
            }

            // 直接使用明文，无需解密
            
            // 确保所有字符串字段都是有效的 UTF-8
            $stringFields = ['account_name', 'bank_name', 'bank_branch', 'remark', 'type_text', 'account_number'];
            foreach ($stringFields as $field) {
                if (isset($item[$field]) && is_string($item[$field])) {
                    if (!mb_check_encoding($item[$field], 'UTF-8')) {
                        $item[$field] = mb_convert_encoding($item[$field], 'UTF-8', 'UTF-8,GBK,GB2312,BIG5');
                        if (!mb_check_encoding($item[$field], 'UTF-8')) {
                            $item[$field] = mb_convert_encoding($item[$field], 'UTF-8', 'UTF-8');
                        }
                    }
                }
            }
        }

        $this->success('', ['list' => $list]);
    }

    #[
        Apidoc\Title("提交充值订单"),
        Apidoc\Tag("充值,订单"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/Recharge/submitOrder"),
        Apidoc\Param(name:"amount", type: "float", require: true, desc: "充值金额(元)", mock: "@float(10, 10000, 2)"),
        Apidoc\Param(name:"payment_type", type: "string", require: true, desc: "支付方式:bank_card=银行卡,alipay=支付宝,wechat=微信,usdt=USDT"),
        Apidoc\Param(name:"company_account_id", type: "int", require: true, desc: "公司收款账户ID"),
        Apidoc\Param(name:"payment_method", type: "string", require: false, desc: "支付方式:offline=离线支付(上传截图),online=线上支付(默认:offline)", mock: "offline"),
        Apidoc\Param(name:"payment_screenshot", type: "file", require: false, desc: "付款截图文件(支持jpg/png/gif格式)，离线支付时与payment_screenshot_id或payment_screenshot_url二选一"),
        Apidoc\Param(name:"payment_screenshot_id", type: "int", require: false, desc: "付款截图附件ID（通过/api/ajax/upload接口上传后获取），离线支付时与payment_screenshot或payment_screenshot_url二选一"),
        Apidoc\Param(name:"payment_screenshot_url", type: "string", require: false, desc: "付款截图文件路径（通过/api/ajax/upload接口上传后获取），离线支付时与payment_screenshot或payment_screenshot_id二选一"),
        Apidoc\Param(name:"user_remark", type: "string", require: false, desc: "用户备注(最多500字)", mock: "转账备注信息"),
        Apidoc\Returned("order_no", type: "string", desc: "订单号"),
        Apidoc\Returned("order_id", type: "int", desc: "订单ID"),
        Apidoc\Returned("pay_url", type: "string", desc: "支付链接(线上支付时返回)"),
    ]
    /**
     * 提交充值订单
     */
    public function submitOrder(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error(__('Please login first'));
        }

        $userInfo = $this->auth->getUserInfo();
        $userId = $userInfo['id'];

        $user = Db::name('user')
            ->where('id', $userId)
            ->field('real_name_status')
            ->find();
        if (!$user || (int)$user['real_name_status'] !== 2) {
            $this->error('请先完成实名认证');
        }

        // 获取表单数据
        $amount = $this->request->post('amount/f', 0);
        $paymentType = $this->request->post('payment_type', '');
        $companyAccountId = $this->request->post('company_account_id/d', 0);
        $paymentMethod = $this->request->post('payment_method', 'offline'); // offline=离线支付, online=线上支付
        $paymentScreenshotFile = $this->request->file('payment_screenshot');
        $paymentScreenshotId = $this->request->post('payment_screenshot_id/d', 0);
        $paymentScreenshotUrl = $this->request->post('payment_screenshot_url', '');
        $userRemark = $this->request->post('user_remark', '');

        // 验证必填字段
        if ($amount <= 0) {
            $this->error('充值金额必须大于0');
        }
        if (empty($paymentType)) {
            $this->error('支付方式不能为空');
        }
        if (!in_array($paymentType, ['bank_card', 'alipay', 'wechat', 'usdt'])) {
            $this->error('支付方式不正确');
        }
        if (!$companyAccountId) {
            $this->error('请选择收款账户');
        }
        if (!in_array($paymentMethod, ['offline', 'online'])) {
            $this->error('支付方式参数不正确');
        }

        // 验证收款账户是否存在且启用
        $companyAccount = Db::name('company_payment_account')
            ->where('id', $companyAccountId)
            ->where('status', CompanyPaymentAccountModel::STATUS_RECHARGE)
            ->find();
        if (!$companyAccount) {
            $this->error('收款账户不存在或已禁用');
        }
        if ($companyAccount['type'] != $paymentType) {
            $this->error('支付方式与收款账户类型不匹配');
        }

        // 根据支付方式处理
        if ($paymentMethod === 'online') {
            // 线上支付：调用第三方支付接口
            $this->handleOnlinePayment($companyAccountId, $userId, $amount, $userRemark);
        } else {
            // 离线支付：传统上传截图方式
            $this->handleOfflinePayment($userId, $amount, $paymentType, $companyAccountId, $paymentScreenshotFile, $paymentScreenshotId, $paymentScreenshotUrl, $userRemark);
        }

        // 此处代码已被重构为 handleOnlinePayment 和 handleOfflinePayment 方法
    }

    /**
     * 处理线上支付
     */
    private function handleOnlinePayment($companyAccountId, $userId, $amount, $userRemark = '')
    {
        // 生成订单号
        $orderNo = 'RC' . date('YmdHis') . str_pad($userId, 6, '0', STR_PAD_LEFT) . rand(1000, 9999);

        Db::startTrans();
        try {
            // 创建充值订单（状态为待支付）
            $orderData = [
                'order_no' => $orderNo,
                'user_id' => $userId,
                'amount' => $amount,
                'payment_type' => 'online', // 线上支付
                'company_account_id' => $companyAccountId,
                'payment_screenshot' => '', // 线上支付无需截图
                'user_remark' => $userRemark,
                'status' => 0, // 待支付
                'audit_admin_id' => 0,
                'audit_time' => 0,
                'audit_remark' => '',
                'create_time' => time(),
                'update_time' => time(),
            ];

            $orderId = Db::name('recharge_order')->insertGetId($orderData);

            Db::commit();

            // 调用第三方支付接口
            $paymentResult = \app\api\controller\Payment::startPayment($companyAccountId, $orderId, 'recharge');

            if ($paymentResult['code'] == 0) {
                $this->success('充值订单创建成功，请完成支付', [
                    'order_no' => $orderNo,
                    'order_id' => $orderId,
                    'pay_url' => $paymentResult['data'],
                ]);
            } else {
                // 记录具体错误到日志，但对外返回友好提示，避免泄露内部异常或堆栈信息
                try {
                    Log::error('startPayment failed', [
                        'company_account_id' => $companyAccountId,
                        'order_id' => $orderId,
                        'payment_result' => $paymentResult,
                    ]);
                } catch (Throwable $logEx) {
                    // 写日志失败时不影响对外返回
                }

                $this->error('获取支付链接失败，请稍后重试');
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            // 记录异常详情到日志，不在接口返回中暴露异常信息
            try {
                Log::error('handleOnlinePayment exception', [
                    'company_account_id' => $companyAccountId,
                    'user_id' => $userId,
                    'amount' => $amount,
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            } catch (Throwable $logEx) {
                // 忽略日志写入异常
            }

            $this->error('提交失败，请稍后再试');
        }
    }

    /**
     * 处理离线支付（传统上传截图方式）
     */
    private function handleOfflinePayment($userId, $amount, $paymentType, $companyAccountId, $paymentScreenshotFile, $paymentScreenshotId, $paymentScreenshotUrl, $userRemark = '')
    {
        // 处理付款截图：支持三种方式（文件上传、附件ID、文件路径）
        $screenshotUrl = '';

        // 方式1：直接上传文件
        if (!empty($paymentScreenshotFile)) {
            try {
                $upload = new Upload();
                $attachment = $upload
                    ->setFile($paymentScreenshotFile)
                    ->setDriver('local')
                    ->setTopic('recharge')
                    ->upload(null, 0, $userId);
                $screenshotUrl = $attachment['url'] ?? '';
            } catch (Throwable $e) {
                $this->error('上传付款截图失败：' . $e->getMessage());
            }
        }
        // 方式2：通过附件ID获取
        elseif ($paymentScreenshotId > 0) {
            $attachment = Db::name('attachment')
                ->where('id', $paymentScreenshotId)
                ->where('user_id', $userId)
                ->find();
            if (!$attachment) {
                $this->error('付款截图附件不存在或不属于当前用户');
            }
            $screenshotUrl = $attachment['url'] ?? '';
        }
        // 方式3：直接使用文件路径
        elseif (!empty($paymentScreenshotUrl)) {
            $screenshotUrl = $paymentScreenshotUrl;
        }
        else {
            $this->error('请上传付款截图或提供付款截图附件ID/路径');
        }

        if (empty($screenshotUrl)) {
            $this->error('付款截图路径不能为空');
        }

        // 生成订单号
        $orderNo = 'RC' . date('YmdHis') . str_pad($userId, 6, '0', STR_PAD_LEFT) . rand(1000, 9999);

        Db::startTrans();
        try {
            // 创建充值订单
            $orderData = [
                'order_no' => $orderNo,
                'user_id' => $userId,
                'amount' => $amount,
                'payment_type' => $paymentType,
                'company_account_id' => $companyAccountId,
                'payment_screenshot' => $screenshotUrl,
                'user_remark' => $userRemark,
                'status' => 0, // 待审核
                'audit_admin_id' => 0,
                'audit_time' => 0,
                'audit_remark' => '',
                'create_time' => time(),
                'update_time' => time(),
            ];

            $orderId = Db::name('recharge_order')->insertGetId($orderData);

            Db::commit();
            $this->success('充值订单提交成功，请等待审核', [
                'order_no' => $orderNo,
                'order_id' => $orderId,
            ]);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $this->error('提交失败：' . $e->getMessage());
        }
    }

    #[
        Apidoc\Title("更新充值订单备注"),
        Apidoc\Tag("充值,订单"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/Recharge/updateOrderRemark"),
        Apidoc\Param(name:"order_id", type: "int", require: true, desc: "订单ID"),
        Apidoc\Param(name:"user_remark", type: "string", require: true, desc: "用户备注(最多500字)"),
        Apidoc\Returned("success", type: "bool", desc: "是否成功"),
    ]
    /**
     * 更新充值订单备注
     */
    public function updateOrderRemark(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error(__('Please login first'));
        }

        $userInfo = $this->auth->getUserInfo();
        $userId = $userInfo['id'];

        // 获取参数
        $orderId = $this->request->post('order_id/d', 0);
        $userRemark = $this->request->post('user_remark', '');

        // 验证参数
        if ($orderId <= 0) {
            $this->error('订单ID无效');
        }

        // 验证备注长度
        if (mb_strlen($userRemark) > 500) {
            $this->error('备注内容不能超过500字');
        }

        // 查询订单是否存在且属于当前用户
        $order = Db::name('recharge_order')
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->find();

        if (!$order) {
            $this->error('订单不存在或无权限操作');
        }

        // 更新备注
        try {
            $result = Db::name('recharge_order')
                ->where('id', $orderId)
                ->where('user_id', $userId)
                ->update([
                    'user_remark' => $userRemark,
                    'update_time' => time(),
                ]);

            if ($result !== false) {
                $this->success('备注更新成功');
            } else {
                $this->error('备注更新失败');
            }
        } catch (Throwable $e) {
            $this->error('更新失败：' . $e->getMessage());
        }
    }

    #[
        Apidoc\Title("提交提现申请"),
        Apidoc\Tag("提现,订单"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/Recharge/submitWithdraw"),
        Apidoc\Param(name:"amount", type: "float", require: true, desc: "提现金额(元)", mock: "@float(10, 5000, 2)"),
        Apidoc\Param(name:"payment_account_id", type: "int", require: true, desc: "用户绑定的收款账户ID"),
        Apidoc\Param(name:"pay_password", type: "string", require: true, desc: "支付密码"),
        Apidoc\Param(name:"remark", type: "string", require: false, desc: "提现备注"),
        Apidoc\Returned("withdraw_id", type: "int", desc: "提现记录ID"),
        Apidoc\Returned("status", type: "int", desc: "提现状态:0=待审核"),
        Apidoc\Returned("fee", type: "float", desc: "手续费"),
        Apidoc\Returned("actual_amount", type: "float", desc: "预计到账金额"),
    ]
    /**
     * 提交提现申请
     */
    public function submitWithdraw(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error(__('Please login first'));
        }

        $userInfo = $this->auth->getUserInfo();
        $userId = $userInfo['id'];

        $amount = $this->request->post('amount/f', 0);
        $paymentAccountId = $this->request->post('payment_account_id/d', 0);
        $payPassword = $this->request->post('pay_password', '');
        $remark = trim((string)$this->request->post('remark', ''));

        if ($amount <= 0) {
            $this->error('提现金额必须大于0');
        }
        if (!$paymentAccountId) {
            $this->error('请选择提现账户');
        }
        if ($payPassword === '') {
            $this->error('请输入支付密码');
        }

        // 活动模式下，从活动配置读取提现规则
        $activity = \app\common\model\SignInActivity::getActiveActivity();
        if ($activity && $activity->isActive()) {
            $minAmount = (float)$activity->withdraw_min_amount;
            $dailyLimit = (int)$activity->withdraw_daily_limit;
        } else {
            $minAmount = (float)(get_sys_config('withdraw_min_amount') ?? 0);
            $dailyLimit = (int)(get_sys_config('withdraw_daily_limit') ?? 0);
        }
        
        if ($minAmount > 0 && $amount < $minAmount) {
            $this->error('提现金额不能小于' . number_format($minAmount, 2) . '元');
        }
        $maxAmount = (float)(get_sys_config('withdraw_max_amount') ?? 0);
        if ($maxAmount > 0 && $amount > $maxAmount) {
            $this->error('提现金额不能大于' . number_format($maxAmount, 2) . '元');
        }

        $feeRate = (float)(get_sys_config('withdraw_fee_rate') ?? 0);
        $fixedFee = (float)(get_sys_config('withdraw_fixed_fee') ?? 0);
        $fee = round(($feeRate > 0 ? $amount * $feeRate / 100 : 0) + max($fixedFee, 0), 2);
        if ($fee < 0) {
            $fee = 0;
        }
        if ($fee >= $amount) {
            $this->error('手续费不能大于或等于提现金额');
        }
        $actualAmount = round($amount - $fee, 2);

        if ($remark !== '') {
            $remark = filter($remark);
        }

        Db::startTrans();
        try {
            $user = Db::name('user')
                ->where('id', $userId)
                ->lock(true)
                ->field('money,withdrawable_money,pay_password,real_name_status,real_name,username,nickname')
                ->find();
            if (!$user) {
                $this->error('用户不存在');
            }
            if ((int)$user['real_name_status'] !== 2) {
                $this->error('请先完成实名认证再发起提现');
            }
            if (empty($user['pay_password'])) {
                $this->error('请先设置支付密码');
            }
            if ($payPassword !== $user['pay_password']) {
                $this->error('支付密码错误');
            }
            
            // 检查每日提现次数限制
            if ($dailyLimit > 0) {
                $today = date('Y-m-d');
                $todayStart = strtotime($today . ' 00:00:00');
                $todayEnd = strtotime($today . ' 23:59:59');
                $todayWithdrawCount = Db::name('user_withdraw')
                    ->where('user_id', $userId)
                    ->where('create_time', '>=', $todayStart)
                    ->where('create_time', '<=', $todayEnd)
                    ->count();
                if ($todayWithdrawCount >= $dailyLimit) {
                    $limitText = $dailyLimit == 1 ? '1次' : $dailyLimit . '次';
                    $this->error('每人每天限提' . $limitText . '，您今日已提现' . $todayWithdrawCount . '次，请明天再试');
                }
            }
            $userWithdrawable = (float)$user['withdrawable_money'];
            // 使用 round 处理精度问题，确保比较准确
            $amount = round($amount, 2);
            $userWithdrawable = round($userWithdrawable, 2);
            
            // 检查可提现余额（提现应该使用可提现余额）
            if ($userWithdrawable < $amount) {
                $this->error('可提现余额不足，当前可提现余额：' . number_format($userWithdrawable, 2) . '元，提现金额：' . number_format($amount, 2) . '元');
            }

            $account = Db::name('user_payment_account')
                ->where('id', $paymentAccountId)
                ->where('user_id', $userId)
                ->where('status', 1)
                ->lock(true)
                ->find();
            if (!$account) {
                $this->error('提现账户不存在或已禁用');
            }
            if ($account['account_type'] === 'company' && (int)$account['audit_status'] !== 1) {
                $this->error('该账户尚未通过审核，无法提现');
            }
            if (!in_array($account['type'], ['bank_card', 'alipay', 'wechat', 'usdt'])) {
                $this->error('不支持的提现账户类型');
            }

            // 提现只从可提现余额扣除，总余额不变
            $afterWithdrawable = round($userWithdrawable - $amount, 2);
            $now = time();

            Db::name('user')
                ->where('id', $userId)
                ->update([
                    'withdrawable_money' => $afterWithdrawable,
                    'update_time' => $now,
                ]);

            Db::name('user_money_log')->insert([
                'user_id' => $userId,
                'money' => -$amount,
                'before' => $userWithdrawable,
                'after' => $afterWithdrawable,
                'memo' => '用户提现申请',
                'create_time' => $now,
            ]);

            $withdrawId = Db::name('user_withdraw')->insertGetId([
                'user_id' => $userId,
                'payment_account_id' => $account['id'],
                'amount' => $amount, // 提现金额字段
                'fee' => $fee,
                'actual_amount' => $actualAmount,
                'account_type' => $account['type'],
                'account_name' => $account['account_name'],
                'account_number' => $account['account_number'],
                'bank_name' => $account['bank_name'],
                'bank_branch' => $account['bank_branch'],
                'status' => 0,
                'audit_reason' => '',
                'pay_reason' => '',
                'remark' => $remark,
                'create_time' => $now,
                'update_time' => $now,
            ]);

            // 创建提现审核记录
            $applicantName = !empty($user['nickname']) ? $user['nickname'] : $user['username'];
            Db::name('withdraw_review')->insert([
                'applicant_type' => 'user',
                'applicant_id' => $userId,
                'applicant_name' => $applicantName,
                'amount' => $amount,
                'status' => 0, // 待审核
                'apply_reason' => $remark,
                'audit_admin_id' => 0,
                'audit_time' => 0,
                'audit_remark' => '',
                'create_time' => $now,
                'update_time' => $now,
            ]);

            // 记录提现活动日志
            UserActivityLog::create([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'withdraw',
                'change_field' => 'withdrawable_money',
                'change_value' => -$amount,
                'before_value' => $userWithdrawable,
                'after_value' => $afterWithdrawable,
                'remark' => '提现申请：' . number_format($amount, 2) . '元',
                'extra' => [
                    'withdraw_id' => $withdrawId,
                    'fee' => $fee,
                    'actual_amount' => $actualAmount,
                    'account_type' => $account['type'],
                    'account_name' => $account['account_name'],
                    'account_number' => $account['account_number'],
                ],
            ]);

            Db::commit();
            $this->success('提现申请已提交，请等待审核', [
                'withdraw_id' => $withdrawId,
                'status' => 0,
                'fee' => $fee,
                'actual_amount' => $actualAmount,
            ]);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $this->error('提现申请失败：' . $e->getMessage());
        }
    }

    #[
        Apidoc\Title("提交拓展提现申请"),
        Apidoc\Tag("拓展提现,订单"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/Recharge/submitStaticIncomeWithdraw"),
        Apidoc\Param(name:"amount", type: "float", require: true, desc: "提现金额(元)", mock: "@float(10, 5000, 2)"),
        Apidoc\Param(name:"payment_account_id", type: "int", require: true, desc: "用户绑定的收款账户ID"),
        Apidoc\Param(name:"pay_password", type: "string", require: true, desc: "支付密码"),
        Apidoc\Param(name:"remark", type: "string", require: false, desc: "提现备注"),
        Apidoc\Returned("withdraw_id", type: "int", desc: "提现记录ID"),
        Apidoc\Returned("status", type: "int", desc: "提现状态:0=待审核"),
        Apidoc\Returned("fee", type: "float", desc: "手续费"),
        Apidoc\Returned("actual_amount", type: "float", desc: "预计到账金额"),
    ]
    /**
     * 提交拓展提现申请
     * 从拓展提现（static_income）中提现
     */
    public function submitStaticIncomeWithdraw(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error(__('Please login first'));
        }

        $userInfo = $this->auth->getUserInfo();
        $userId = $userInfo['id'];

        $amount = $this->request->post('amount/f', 0);
        $paymentAccountId = $this->request->post('payment_account_id/d', 0);
        $payPassword = $this->request->post('pay_password', '');
        $remark = trim((string)$this->request->post('remark', ''));

        if ($amount <= 0) {
            $this->error('提现金额必须大于0');
        }
        if (!$paymentAccountId) {
            $this->error('请选择提现账户');
        }
        if ($payPassword === '') {
            $this->error('请输入支付密码');
        }

        // 活动模式下，从活动配置读取提现规则
        $activity = \app\common\model\SignInActivity::getActiveActivity();
        if ($activity && $activity->isActive()) {
            $minAmount = (float)$activity->withdraw_min_amount;
            $dailyLimit = (int)$activity->withdraw_daily_limit;
        } else {
            $minAmount = (float)(get_sys_config('withdraw_min_amount') ?? 0);
            $dailyLimit = (int)(get_sys_config('withdraw_daily_limit') ?? 0);
        }
        
        if ($minAmount > 0 && $amount < $minAmount) {
            $this->error('提现金额不能小于' . number_format($minAmount, 2) . '元');
        }
        $maxAmount = (float)(get_sys_config('withdraw_max_amount') ?? 0);
        if ($maxAmount > 0 && $amount > $maxAmount) {
            $this->error('提现金额不能大于' . number_format($maxAmount, 2) . '元');
        }

        $feeRate = (float)(get_sys_config('withdraw_fee_rate') ?? 0);
        $fixedFee = (float)(get_sys_config('withdraw_fixed_fee') ?? 0);
        $fee = round(($feeRate > 0 ? $amount * $feeRate / 100 : 0) + max($fixedFee, 0), 2);
        if ($fee < 0) {
            $fee = 0;
        }
        if ($fee >= $amount) {
            $this->error('手续费不能大于或等于提现金额');
        }
        $actualAmount = round($amount - $fee, 2);

        if ($remark !== '') {
            $remark = filter($remark);
        }

        Db::startTrans();
        try {
            $user = Db::name('user')
                ->where('id', $userId)
                ->lock(true)
                ->field('money,static_income,pay_password,real_name_status,real_name,username,nickname')
                ->find();
            if (!$user) {
                $this->error('用户不存在');
            }
            if ((int)$user['real_name_status'] !== 2) {
                $this->error('请先完成实名认证再发起提现');
            }
            if (empty($user['pay_password'])) {
                $this->error('请先设置支付密码');
            }
            if ($payPassword !== $user['pay_password']) {
                $this->error('支付密码错误');
            }
            
            // 检查每日提现次数限制（拓展提现和普通提现共用每日限制）
            if ($dailyLimit > 0) {
                $today = date('Y-m-d');
                $todayStart = strtotime($today . ' 00:00:00');
                $todayEnd = strtotime($today . ' 23:59:59');
                $todayWithdrawCount = Db::name('user_withdraw')
                    ->where('user_id', $userId)
                    ->where('create_time', '>=', $todayStart)
                    ->where('create_time', '<=', $todayEnd)
                    ->count();
                if ($todayWithdrawCount >= $dailyLimit) {
                    $limitText = $dailyLimit == 1 ? '1次' : $dailyLimit . '次';
                    $this->error('每人每天限提' . $limitText . '，您今日已提现' . $todayWithdrawCount . '次，请明天再试');
                }
            }
            $userStaticIncome = (float)$user['static_income'];
            // 使用 round 处理精度问题，确保比较准确
            $amount = round($amount, 2);
            $userStaticIncome = round($userStaticIncome, 2);
            
            // 检查拓展提现余额
            if ($userStaticIncome < $amount) {
                $this->error('拓展提现余额不足，当前拓展提现余额：' . number_format($userStaticIncome, 2) . '元，提现金额：' . number_format($amount, 2) . '元');
            }

            $account = Db::name('user_payment_account')
                ->where('id', $paymentAccountId)
                ->where('user_id', $userId)
                ->where('status', 1)
                ->lock(true)
                ->find();
            if (!$account) {
                $this->error('提现账户不存在或已禁用');
            }
            if ($account['account_type'] === 'company' && (int)$account['audit_status'] !== 1) {
                $this->error('该账户尚未通过审核，无法提现');
            }
            if (!in_array($account['type'], ['bank_card', 'alipay', 'wechat', 'usdt'])) {
                $this->error('不支持的提现账户类型');
            }

            // 从拓展提现余额扣除
            $afterStaticIncome = round($userStaticIncome - $amount, 2);
            $now = time();

            Db::name('user')
                ->where('id', $userId)
                ->update([
                    'static_income' => $afterStaticIncome,
                    'update_time' => $now,
                ]);

            // 记录拓展提现变更活动日志
            UserActivityLog::create([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'static_income',
                'change_field' => 'static_income',
                'change_value' => -$amount,
                'before_value' => $userStaticIncome,
                'after_value' => $afterStaticIncome,
                'remark' => '拓展提现申请：' . number_format($amount, 2) . '元',
                'extra' => [
                    'withdraw_type' => 'static_income',
                    'withdraw_amount' => $amount,
                    'fee' => $fee,
                    'actual_amount' => $actualAmount,
                    'payment_account_id' => $paymentAccountId,
                    'account_type' => $account['type'],
                    'account_name' => $account['account_name'],
                ],
            ]);

            $withdrawId = Db::name('user_withdraw')->insertGetId([
                'user_id' => $userId,
                'payment_account_id' => $account['id'],
                'amount' => $amount, // 提现金额字段
                'fee' => $fee,
                'actual_amount' => $actualAmount,
                'account_type' => $account['type'],
                'account_name' => $account['account_name'],
                'account_number' => $account['account_number'],
                'bank_name' => $account['bank_name'],
                'bank_branch' => $account['bank_branch'],
                'status' => 0,
                'audit_reason' => '',
                'pay_reason' => '',
                'remark' => $remark ? $remark : '拓展提现申请',
                'create_time' => $now,
                'update_time' => $now,
            ]);

            // 创建提现审核记录
            $applicantName = !empty($user['nickname']) ? $user['nickname'] : $user['username'];
            Db::name('withdraw_review')->insert([
                'applicant_type' => 'user',
                'applicant_id' => $userId,
                'applicant_name' => $applicantName,
                'amount' => $amount,
                'status' => 0, // 待审核
                'apply_reason' => $remark ? $remark : '拓展提现申请',
                'audit_admin_id' => 0,
                'audit_time' => 0,
                'audit_remark' => '',
                'create_time' => $now,
                'update_time' => $now,
            ]);

            Db::commit();
            $this->success('拓展提现申请已提交，请等待审核', [
                'withdraw_id' => $withdrawId,
                'status' => 0,
                'fee' => $fee,
                'actual_amount' => $actualAmount,
            ]);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $this->error('拓展提现申请失败：' . $e->getMessage());
        }
    }

    #[
        Apidoc\Title("获取我的充值订单列表（包含余额划转记录）"),
        Apidoc\Tag("充值,订单"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Recharge/getMyOrderList"),
        Apidoc\Query(name:"page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name:"limit", type: "int", require: false, desc: "每页数量", default: "10"),
        Apidoc\Query(name:"payment_type", type: "string", require: false, desc: "充值方式:alipay=支付宝,wechat=微信,bank_card=银行卡,usdt=USDT,online=线上支付（仅对充值订单有效）"),
        Apidoc\Query(name:"status", type: "int", require: false, desc: "充值状态:0=待审核,1=已通过,2=已拒绝（仅对充值订单有效）"),
        Apidoc\Returned("total", type: "int", desc: "总记录数"),
        Apidoc\Returned("per_page", type: "int", desc: "每页数量"),
        Apidoc\Returned("current_page", type: "int", desc: "当前页码"),
        Apidoc\Returned("last_page", type: "int", desc: "最后一页"),
        Apidoc\Returned("data", type: "array", desc: "记录列表"),
        Apidoc\Returned("data[].record_type", type: "string", desc: "记录类型:recharge=充值订单,transfer=余额划转"),
        Apidoc\Returned("data[].id", type: "int", desc: "记录ID"),
        Apidoc\Returned("data[].order_no", type: "string", desc: "订单号"),
        Apidoc\Returned("data[].amount", type: "float", desc: "金额"),
        Apidoc\Returned("data[].payment_type", type: "string", desc: "支付方式"),
        Apidoc\Returned("data[].payment_type_text", type: "string", desc: "支付方式文本"),
        Apidoc\Returned("data[].status", type: "int", desc: "状态"),
        Apidoc\Returned("data[].status_text", type: "string", desc: "状态文本"),
        Apidoc\Returned("data[].payment_screenshot", type: "string", desc: "付款截图URL（充值订单）"),
        Apidoc\Returned("data[].audit_remark", type: "string", desc: "审核备注"),
        Apidoc\Returned("data[].create_time", type: "int", desc: "创建时间戳"),
        Apidoc\Returned("data[].create_time_text", type: "string", desc: "创建时间文本"),
        Apidoc\Returned("data[].audit_time", type: "int", desc: "审核时间戳（充值订单）"),
        Apidoc\Returned("data[].audit_time_text", type: "string", desc: "审核时间文本"),
        Apidoc\Returned("has_more", type: "boolean", desc: "是否还有更多数据"),
    ]
    /**
     * 获取我的充值订单列表（包含余额划转记录）
     */
    public function getMyOrderList(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error(__('Please login first'));
        }

        $userInfo = $this->auth->getUserInfo();
        $userId = $userInfo['id'];

        $page = $this->request->get('page/d', 1);
        $limit = $this->request->get('limit/d', 10);
        $paymentType = $this->request->get('payment_type', '');
        $status = $this->request->get('status', '');

        // 查询充值订单
        $rechargeWhere = [['user_id', '=', $userId]];

        // 按充值方式筛选（只对充值订单生效）
        if ($paymentType !== '') {
            $rechargeWhere[] = ['payment_type', '=', $paymentType];
        }

        // 按充值状态筛选（只对充值订单生效）
        if ($status !== '') {
            $rechargeWhere[] = ['status', '=', (int)$status];
        }

        $rechargeOrders = Db::name('recharge_order')
            ->where($rechargeWhere)
            ->select()
            ->toArray();

        // 查询余额划转记录
        $transferWhere = [
            ['user_id', '=', $userId],
            ['action_type', '=', 'balance_transfer']
        ];

        $balanceTransfers = Db::name('user_activity_log')
            ->where($transferWhere)
            ->select()
            ->toArray();

        // 合并数据并格式化
        $combinedList = [];

        // 处理充值订单
        foreach ($rechargeOrders as $order) {
            // 获取具体的支付方式
            $companyAccount = Db::name('company_payment_account')
                ->where('id', $order['company_account_id'])
                ->field('type, account_name')
                ->find();

            if ($companyAccount) {
                $accountTypeMap = [
                    'alipay' => '支付宝',
                    'wechat' => '微信',
                    'bank_card' => '银行卡',
                    'usdt' => 'USDT',
                ];
                $order['payment_type_text'] = $accountTypeMap[$companyAccount['type']] ?? '在线支付';
            } else {
                $order['payment_type_text'] = '在线支付';
            }

            // 订单状态文本
            $statusMap = [
                0 => '待审核',
                1 => '已通过',
                2 => '已拒绝',
            ];
            $order['status_text'] = $statusMap[$order['status']] ?? '未知';

            // 格式化时间
            $order['create_time_text'] = date('Y-m-d H:i:s', $order['create_time']);
            $order['audit_time_text'] = $order['audit_time'] ? date('Y-m-d H:i:s', $order['audit_time']) : '';

            // 添加记录类型标识
            $order['record_type'] = 'recharge'; // 充值记录
            $order['sort_time'] = $order['create_time']; // 用于排序的时间戳

            $combinedList[] = $order;
        }

        // 处理余额划转记录
        foreach ($balanceTransfers as $transfer) {
            $extra = json_decode($transfer['extra'], true);

            $formattedTransfer = [
                'id' => $transfer['id'],
                'record_type' => 'transfer', // 划转记录
                'order_no' => 'BT' . date('YmdHis', $transfer['create_time']) . str_pad($transfer['id'], 6, '0', STR_PAD_LEFT),
                'amount' => $extra['transfer_amount'] ?? 0,
                'payment_type' => 'balance_transfer',
                'payment_type_text' => '余额划转',
                'status' => 1, // 划转记录默认为已完成状态
                'status_text' => '已完成',
                'payment_screenshot' => '',
                'audit_remark' => $transfer['remark'] ?? '',
                'create_time' => $transfer['create_time'],
                'create_time_text' => date('Y-m-d H:i:s', $transfer['create_time']),
                'audit_time' => $transfer['create_time'],
                'audit_time_text' => date('Y-m-d H:i:s', $transfer['create_time']),
                'sort_time' => $transfer['create_time'], // 用于排序的时间戳
                'transfer_detail' => $extra // 保留原始划转详情
            ];

            $combinedList[] = $formattedTransfer;
        }

        // 按时间倒序排序
        usort($combinedList, function($a, $b) {
            return $b['sort_time'] <=> $a['sort_time'];
        });

        // 分页处理
        $total = count($combinedList);
        $offset = ($page - 1) * $limit;
        $paginatedData = array_slice($combinedList, $offset, $limit);

        $result = [
            'total' => $total,
            'per_page' => $limit,
            'current_page' => $page,
            'last_page' => ceil($total / $limit),
            'data' => $paginatedData,
            'has_more' => ($page * $limit) < $total
        ];

        $this->success('', $result);
    }

    #[
        Apidoc\Title("获取充值订单详情"),
        Apidoc\Tag("充值,订单"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Recharge/detail"),
        Apidoc\Param(name:"id", type: "int", require: true, desc: "订单ID"),
        Apidoc\Returned("id", type: "int", desc: "订单ID"),
        Apidoc\Returned("order_no", type: "string", desc: "订单号"),
        Apidoc\Returned("amount", type: "float", desc: "充值金额"),
        Apidoc\Returned("payment_type", type: "string", desc: "支付方式"),
        Apidoc\Returned("payment_type_text", type: "string", desc: "支付方式文本"),
        Apidoc\Returned("status", type: "int", desc: "订单状态:0=待审核,1=已通过,2=已拒绝"),
        Apidoc\Returned("status_text", type: "string", desc: "订单状态文本"),
        Apidoc\Returned("payment_screenshot", type: "string", desc: "付款截图URL"),
        Apidoc\Returned("audit_remark", type: "string", desc: "审核备注"),
        Apidoc\Returned("create_time", type: "int", desc: "创建时间戳"),
        Apidoc\Returned("create_time_text", type: "string", desc: "创建时间文本"),
        Apidoc\Returned("audit_time", type: "int", desc: "审核时间戳"),
        Apidoc\Returned("audit_time_text", type: "string", desc: "审核时间文本"),
    ]
    /**
     * 获取充值订单详情
     */
    public function detail(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error(__('Please login first'));
        }

        $userInfo = $this->auth->getUserInfo();
        $userId = $userInfo['id'];
        $id = $this->request->get('id/d');

        if (!$id) {
            $this->error('参数错误');
        }

        $order = Db::name('recharge_order')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->find();

        if (!$order) {
            $this->error('订单不存在');
        }

        // 获取具体的支付方式
        $companyAccount = Db::name('company_payment_account')
            ->where('id', $order['company_account_id'])
            ->field('type, account_name')
            ->find();

        if ($companyAccount) {
            $accountTypeMap = [
                'alipay' => '支付宝',
                'wechat' => '微信',
                'bank_card' => '银行卡',
                'usdt' => 'USDT',
            ];
            $order['payment_type_text'] = $accountTypeMap[$companyAccount['type']] ?? '在线支付';
        } else {
            $order['payment_type_text'] = '在线支付';
        }

        // 订单状态文本
        $statusMap = [
            0 => '待审核',
            1 => '已通过',
            2 => '已拒绝',
        ];
        $order['status_text'] = $statusMap[$order['status']] ?? '未知';

        // 格式化时间
        $order['create_time_text'] = date('Y-m-d H:i:s', $order['create_time']);
        $order['audit_time_text'] = $order['audit_time'] ? date('Y-m-d H:i:s', $order['audit_time']) : '';

        $this->success('', $order);
    }

    #[
        Apidoc\Title("获取我的提现记录列表"),
        Apidoc\Tag("提现,记录"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Recharge/getMyWithdrawList"),
        Apidoc\Query(name:"page", type: "int", require: false, desc: "页码", default: "1"),
        Apidoc\Query(name:"limit", type: "int", require: false, desc: "每页数量", default: "10"),
        Apidoc\Returned("total", type: "int", desc: "总记录数"),
        Apidoc\Returned("per_page", type: "int", desc: "每页数量"),
        Apidoc\Returned("current_page", type: "int", desc: "当前页码"),
        Apidoc\Returned("data", type: "array", desc: "提现记录列表"),
        Apidoc\Returned("data[].id", type: "int", desc: "记录ID"),
        Apidoc\Returned("data[].amount", type: "float", desc: "提现金额"),
        Apidoc\Returned("data[].fee", type: "float", desc: "手续费"),
        Apidoc\Returned("data[].actual_amount", type: "float", desc: "实际到账金额"),
        Apidoc\Returned("data[].account_type", type: "string", desc: "账户类型:bank_card=银行卡,alipay=支付宝,wechat=微信,usdt=USDT"),
        Apidoc\Returned("data[].account_type_text", type: "string", desc: "账户类型文本"),
        Apidoc\Returned("data[].account_name", type: "string", desc: "账户名称"),
        Apidoc\Returned("data[].account_number", type: "string", desc: "账户号码"),
        Apidoc\Returned("data[].bank_name", type: "string", desc: "银行名称（银行卡类型时）"),
        Apidoc\Returned("data[].status", type: "int", desc: "提现状态:0=待审核,1=审核通过,2=审核拒绝,3=已打款,4=打款失败"),
        Apidoc\Returned("data[].status_text", type: "string", desc: "提现状态文本"),
        Apidoc\Returned("data[].audit_reason", type: "string", desc: "审核原因"),
        Apidoc\Returned("data[].pay_reason", type: "string", desc: "打款原因"),
        Apidoc\Returned("data[].remark", type: "string", desc: "备注"),
        Apidoc\Returned("data[].create_time", type: "int", desc: "创建时间戳"),
        Apidoc\Returned("data[].create_time_text", type: "string", desc: "创建时间文本"),
        Apidoc\Returned("data[].audit_time", type: "int", desc: "审核时间戳"),
        Apidoc\Returned("data[].audit_time_text", type: "string", desc: "审核时间文本"),
        Apidoc\Returned("data[].pay_time", type: "int", desc: "打款时间戳"),
        Apidoc\Returned("data[].pay_time_text", type: "string", desc: "打款时间文本"),
    ]
    /**
     * 获取我的提现记录列表
     */
    public function getMyWithdrawList(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error(__('Please login first'));
        }

        $userInfo = $this->auth->getUserInfo();
        $userId = $userInfo['id'];

        $page = $this->request->get('page/d', 1);
        $limit = $this->request->get('limit/d', 10);

        $list = Db::name('user_withdraw')
            ->where('user_id', $userId)
            ->order('id desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page,
            ])
            ->toArray();

        foreach ($list['data'] as &$item) {
            // 账户类型文本
            $accountTypeMap = [
                'bank_card' => '银行卡',
                'alipay' => '支付宝',
                'wechat' => '微信',
                'usdt' => 'USDT',
            ];
            $item['account_type_text'] = $accountTypeMap[$item['account_type']] ?? '未知';
            
            // 提现状态文本
            $statusMap = [
                0 => '待审核',
                1 => '审核通过',
                2 => '审核拒绝',
                3 => '已打款',
                4 => '打款失败',
            ];
            $item['status_text'] = $statusMap[$item['status']] ?? '未知';
            
            // 账户号码解码显示（不脱敏，显示完整账号）
            if (!empty($item['account_number'])) {
                try {
                    $decrypted = base64_decode($item['account_number']);
                    if ($decrypted !== false) {
                        $item['account_number'] = $decrypted;
                    } else {
                        $item['account_number'] = '';
                    }
                } catch (Throwable $e) {
                    $item['account_number'] = '';
                }
            }
            
            // 格式化时间
            $item['create_time_text'] = date('Y-m-d H:i:s', $item['create_time']);
            $item['audit_time_text'] = $item['audit_time'] ? date('Y-m-d H:i:s', $item['audit_time']) : '';
            $item['pay_time_text'] = $item['pay_time'] ? date('Y-m-d H:i:s', $item['pay_time']) : '';
        }

        $this->success('', $list);
    }
}

