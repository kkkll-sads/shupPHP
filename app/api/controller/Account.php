<?php

namespace app\api\controller;

use ba\Date;
use Throwable;
use ba\Captcha;
use ba\Random;
use app\common\model\User;
use think\facade\Validate;
use think\facade\Db;
use app\common\facade\Token;
use app\common\model\UserScoreLog;
use app\common\model\UserMoneyLog;
use app\common\model\UserActivityLog;
use app\common\controller\Frontend;
use app\api\validate\Account as AccountValidate;

use hg\apidoc\annotation as Apidoc;

#[Apidoc\Title("个人中心")]
class Account extends Frontend
{
    protected array $noNeedLogin = ['retrievePassword'];

    protected array $noNeedPermission = ['verification', 'changeBind'];

    public function initialize(): void
    {
        parent::initialize();
    }

    #[
        Apidoc\Title("数据概览"),
        Apidoc\Tag("个人中心,概览"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Account/overview"),
        Apidoc\Returned("days",type: "array",desc: "日期数组(最近7天)"),
        Apidoc\Returned("score",type: "array",desc: "消费金数组(对应7天的消费金变动)"),
        Apidoc\Returned("money",type: "array",desc: "可用金额数组(对应7天的金额变动)"),
    ]
    /**
     * 数据概览
     * @throws Throwable
     */
    public function overview(): void
    {
        $sevenDays = Date::unixTime('day', -6);
        $score     = $money = $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[$i]    = date("Y-m-d", $sevenDays + ($i * 86400));
            $tempToday0  = strtotime($days[$i]);
            $tempToday24 = strtotime('+1 day', $tempToday0) - 1;
            $score[$i]   = UserScoreLog::where('user_id', $this->auth->id)
                ->where('create_time', 'BETWEEN', $tempToday0 . ',' . $tempToday24)
                ->sum('score');

            $userMoneyTemp = UserMoneyLog::where('user_id', $this->auth->id)
                ->where('create_time', 'BETWEEN', $tempToday0 . ',' . $tempToday24)
                ->sum('money');
            $money[$i]     = bcdiv($userMoneyTemp, 100, 2);
        }

        $this->success('', [
            'days'  => $days,
            'score' => $score,
            'money' => $money,
        ]);
    }

    #[
        Apidoc\Title("会员资料"),
        Apidoc\Tag("个人中心,资料"),
        Apidoc\Method("GET,POST"),
        Apidoc\Url("/api/Account/profile"),
        Apidoc\Query(name:"avatar",type: "string",require: false,desc: "头像(POST时)",example:""),
        Apidoc\Query(name:"username",type: "string",require: false,desc: "用户名(POST时)",example:""),
        Apidoc\Query(name:"nickname",type: "string",require: false,desc: "昵称(POST时)",example:""),
        Apidoc\Query(name:"gender",type: "string",require: false,desc: "性别(POST时)",example:"",values:"0,1,2"),
        Apidoc\Query(name:"birthday",type: "string",require: false,desc: "生日(POST时)",example:"2024-01-01"),
        Apidoc\Query(name:"motto",type: "string",require: false,desc: "个性签名(POST时)",example:""),
        Apidoc\Returned("userInfo",type: "object",desc: "用户信息(GET请求时返回)"),
        Apidoc\Returned("userInfo.id",type: "int",desc: "用户ID"),
        Apidoc\Returned("userInfo.username",type: "string",desc: "用户名"),
        Apidoc\Returned("userInfo.nickname",type: "string",desc: "昵称"),
        Apidoc\Returned("userInfo.email",type: "string",desc: "邮箱"),
        Apidoc\Returned("userInfo.mobile",type: "string",desc: "手机号"),
        Apidoc\Returned("userInfo.avatar",type: "string",desc: "头像"),
        Apidoc\Returned("userInfo.gender",type: "int",desc: "性别(0=未知,1=男,2=女)"),
        Apidoc\Returned("userInfo.birthday",type: "string",desc: "生日"),
        Apidoc\Returned("userInfo.money",type: "string",desc: "可用金额(兼容字段)"),
        Apidoc\Returned("userInfo.balance_available",type: "string",desc: "可用余额"),
        Apidoc\Returned("userInfo.service_fee_balance",type: "string",desc: "确权金"),
        Apidoc\Returned("userInfo.withdrawable_money",type: "string",desc: "可提现金额"),
        Apidoc\Returned("userInfo.usdt",type: "string",desc: "USDT"),
        Apidoc\Returned("userInfo.static_income",type: "string",desc: "拓展提现"),
        Apidoc\Returned("userInfo.dynamic_income",type: "string",desc: "服务金额"),
        Apidoc\Returned("userInfo.score",type: "int",desc: "消费金"),
        Apidoc\Returned("userInfo.green_power",type: "string",desc: "绿色算力"),
        Apidoc\Returned("userInfo.old_assets_status",type: "int",desc: "旧资产状态(0=未解锁,1=已解锁)"),
    
        Apidoc\Returned("userInfo.pending_activation_gold",type: "string",desc: "待激活金"),
        Apidoc\Returned("userInfo.consignment_coupon",type: "int",desc: "寄售券数量"),
        Apidoc\Returned("userInfo.frozen_amount",type: "string",desc: "已冻结专项金"),
        Apidoc\Returned("userInfo.last_login_time",type: "int",desc: "最后登录时间"),
        Apidoc\Returned("userInfo.last_login_ip",type: "string",desc: "最后登录IP"),
        Apidoc\Returned("userInfo.join_time",type: "int",desc: "注册时间"),
        Apidoc\Returned("userInfo.motto",type: "string",desc: "个性签名"),
        Apidoc\Returned("userInfo.user_type",type: "int",desc: "用户状态(0=新用户,1=普通用户,2=交易用户)"),
        Apidoc\Returned("userInfo.token",type: "string",desc: "用户Token"),
        Apidoc\Returned("userInfo.refresh_token",type: "string",desc: "刷新Token"),
        Apidoc\Returned("userInfo.invite_code",type: "string",desc: "邀请码"),
        Apidoc\Returned("userInfo.agent_review_status",type: "int",desc: "代理商审核状态(-1=未申请,0=待审核,1=已通过,2=已拒绝)"),
        Apidoc\Returned("accountVerificationType",type: "array",desc: "账户验证类型(GET请求时返回)"),
    ]
    /**
     * 会员资料
     * @throws Throwable
     */
    public function profile(): void
    {
        if ($this->request->isPost()) {
            $model = $this->auth->getUser();
            $data  = $this->request->only(['avatar', 'username', 'nickname', 'gender', 'birthday', 'motto']);

            $data['id'] = $this->auth->id;
            if (!isset($data['birthday'])) {
                $data['birthday'] = null;
            }

            try {
                $validate = new AccountValidate();
                $validate->scene('edit')->check($data);
            } catch (Throwable $e) {
                $this->error($e->getMessage());
            }

            $model->startTrans();
            try {
                $model->save($data);
                $model->commit();
            } catch (Throwable $e) {
                $model->rollback();
                $this->error($e->getMessage());
            }

            $this->success(__('Data updated successfully~'));
        }

        // 获取用户信息
        $userInfo = $this->auth->getUserInfo();

        // 获取用户的邀请码
        $inviteCodeInfo = Db::name('invite_code')->where('user_id', $this->auth->id)->find();
        $userInfo['invite_code'] = $inviteCodeInfo['code'] ?? '';

        // 获取代理商审核状态
        $agentReview = Db::name('agent_review')
            ->where('user_id', $this->auth->id)
            ->order('id', 'desc')
            ->find();
        $userInfo['agent_review_status'] = $agentReview ? (int)$agentReview['status'] : -1;

        // 计算当前未处理的冻结专项金总额（status = 0 表示待处理/冻结中）
        $frozen = (float)Db::name('trade_reservations')
            ->where('user_id', $this->auth->id)
            ->where('status', 0)
            ->sum('freeze_amount');
        $userInfo['frozen_amount'] = number_format($frozen, 2, '.', '');

        // 添加旧资产状态、确权金和待激活金字段
        $userInfo['old_assets_status'] = (int)($userInfo['old_assets_status'] ?? 0);
        $userInfo['service_fee_balance'] = number_format($userInfo['service_fee_balance'] ?? 0, 2, '.', '');
        $userInfo['pending_activation_gold'] = number_format($userInfo['pending_activation_gold'] ?? 0, 2, '.', '');

        // 实时计算寄售券数量
        $userInfo['consignment_coupon'] = (int)Db::name('user_consignment_coupon')
            ->where('user_id', $this->auth->id)
            ->where('status', 1)
            ->where('expire_time', '>', time())
            ->count();

        $params = [
            'room_id' => 100005,
            'username' => $userInfo['mobile'] ?? '访客',
            'nickname' => $userInfo['username'] ?? '访客',
            'timestamp' => time() * 1000,
        ];
        $params = array_filter($params);
        ksort($params);
        $tmp_string = http_build_query($params);
        $tmp_string = urldecode($tmp_string);
        $sign = md5($tmp_string . '897731001');
        $liveUrl = 'https://szb.dfahwk.cn/live/hls'.'?'.$tmp_string.'&sign='.$sign;

        $this->success('', [
            'liveUrl' => $liveUrl,
            'userInfo' => $userInfo,
            'accountVerificationType' => get_account_verification_type()
        ]);
    }

    #[
        Apidoc\Title("账户验证"),
        Apidoc\Tag("个人中心,验证"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/Account/verification"),
        Apidoc\Query(name:"type",type: "string",require: true,desc: "验证类型",example:"email",values:"email,mobile"),
        Apidoc\Query(name:"captcha",type: "string",require: true,desc: "验证码",example:""),
        Apidoc\Returned("type",type: "string",desc: "验证类型"),
        Apidoc\Returned("accountVerificationToken",type: "string",desc: "账户验证Token(用于修改绑定信息)"),
    ]
    /**
     * 通过手机号或邮箱验证账户
     * 此处检查的验证码是通过 api/Ems或api/Sms发送的
     * 验证成功后，向前端返回一个 email-pass Token或着 mobile-pass Token
     * 在 changBind 方法中，通过 pass Token来确定用户已经通过了账户验证（用户未绑定邮箱/手机时通过账户密码验证）
     * @throws Throwable
     */
    public function verification(): void
    {
        $captcha = new Captcha();
        $params  = $this->request->only(['type', 'captcha']);
        if ($captcha->check($params['captcha'], ($params['type'] == 'email' ? $this->auth->email : $this->auth->mobile) . "user_{$params['type']}_verify")) {
            $uuid = Random::uuid();
            Token::set($uuid, $params['type'] . '-pass', $this->auth->id, 600);
            $this->success('', [
                'type'                     => $params['type'],
                'accountVerificationToken' => $uuid,
            ]);
        }
        $this->error(__('Please enter the correct verification code'));
    }

    #[
        Apidoc\Title("修改绑定信息"),
        Apidoc\Tag("个人中心,绑定"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/Account/changeBind"),
        Apidoc\Query(name:"type",type: "string",require: true,desc: "绑定类型",example:"email",values:"email,mobile"),
        Apidoc\Query(name:"captcha",type: "string",require: true,desc: "新邮箱/手机号的验证码",example:""),
        Apidoc\Query(name:"email",type: "string",require: false,desc: "新邮箱(type为email时必填)",example:"test@example.com"),
        Apidoc\Query(name:"mobile",type: "string",require: false,desc: "新手机号(type为mobile时必填)",example:"13800138000"),
        Apidoc\Query(name:"accountVerificationToken",type: "string",require: false,desc: "账户验证Token(已绑定邮箱/手机时必填)",example:""),
        Apidoc\Query(name:"password",type: "string",require: false,desc: "账户密码(未绑定邮箱/手机时必填)",example:""),
    ]
    /**
     * 修改绑定信息（手机号、邮箱）
     * 通过 pass Token来确定用户已经通过了账户验证，也就是以上的 verification 方法，同时用户未绑定邮箱/手机时通过账户密码验证
     * @throws Throwable
     */
    public function changeBind(): void
    {
        $captcha = new Captcha();
        $params  = $this->request->only(['type', 'captcha', 'email', 'mobile', 'accountVerificationToken', 'password']);
        $user    = $this->auth->getUser();

        if ($user[$params['type']]) {
            if (!Token::check($params['accountVerificationToken'], $params['type'] . '-pass', $user->id)) {
                $this->error(__('You need to verify your account before modifying the binding information'));
            }
        } elseif (!isset($params['password']) || !verify_password($params['password'], $user->password, ['salt' => $user->salt])) {
            $this->error(__('Password error'));
        }

        // 检查验证码
        if ($captcha->check($params['captcha'], $params[$params['type']] . "user_change_{$params['type']}")) {
            if ($params['type'] == 'email') {
                $validate = Validate::rule(['email' => 'require|email|unique:user'])->message([
                    'email.require' => 'email format error',
                    'email.email'   => 'email format error',
                    'email.unique'  => 'email is occupied',
                ]);
                if (!$validate->check(['email' => $params['email']])) {
                    $this->error(__($validate->getError()));
                }
                $user->email = $params['email'];
            } elseif ($params['type'] == 'mobile') {
                $validate = Validate::rule(['mobile' => 'require|mobile|unique:user'])->message([
                    'mobile.require' => 'mobile format error',
                    'mobile.mobile'  => 'mobile format error',
                    'mobile.unique'  => 'mobile is occupied',
                ]);
                if (!$validate->check(['mobile' => $params['mobile']])) {
                    $this->error(__($validate->getError()));
                }
                $user->mobile = $params['mobile'];
            }
            Token::delete($params['accountVerificationToken']);
            $user->save();
            $this->success();
        }
        $this->error(__('Please enter the correct verification code'));
    }

    #[
        Apidoc\Title("服务费充值"),
        Apidoc\Tag("个人中心,服务费,充值"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/Account/rechargeServiceFee"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Query(name:"amount",type: "float",require: true,desc: "充值金额"),
        Apidoc\Query(name:"source",type: "string",require: false,desc: "充值来源",example:"balance_available",values:"balance_available,withdrawable_money",default:"balance_available"),
        Apidoc\Returned("balance_available",type: "float",desc: "充值后可用余额（使用余额充值时）"),
        Apidoc\Returned("withdrawable_money",type: "float",desc: "充值后可提现金额（使用提现金额充值时）"),
        Apidoc\Returned("service_fee_balance",type: "float",desc: "充值后服务费余额"),
    ]
    /**
     * 服务费充值
     * 支持使用可用余额或可提现金额充值服务费余额
     * @throws Throwable
     */
    public function rechargeServiceFee(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $amount = $this->request->param('amount/f', 0);
        $source = $this->request->param('source', 'balance_available');
        
        if ($amount <= 0) {
            $this->error('充值金额必须大于0');
        }
        
        if (!in_array($source, ['balance_available', 'withdrawable_money'])) {
            $this->error('充值来源参数错误，仅支持 balance_available 或 withdrawable_money');
        }

        Db::startTrans();
        try {
            // 只查询和操作四个真实余额池，不再查询或更新money字段（money是派生值）
            // 🔧 修复：使用悲观锁确保并发安全，并重新查询确保获取最新值
            $user = Db::name('user')
                ->where('id', $this->auth->id)
                ->lock(true)
                ->field('balance_available,service_fee_balance,withdrawable_money')
                ->find();

            if (!$user) {
                throw new \Exception('用户不存在');
            }

            // 🔧 修复：在锁定的情况下，重新查询一次确保获取最新的余额值（防止并发问题）
            $user = Db::name('user')
                ->where('id', $this->auth->id)
                ->lock(true)
                ->field('balance_available,service_fee_balance,withdrawable_money')
                ->find();

            // 验证对应余额是否充足（直接检查对应的真实余额池）
            if ($source == 'balance_available') {
                if ($user['balance_available'] < $amount) {
                    throw new \Exception('可用余额不足，当前余额：' . number_format($user['balance_available'], 2) . '元');
                }
            } else {
                if ($user['withdrawable_money'] < $amount) {
                    throw new \Exception('可提现金额不足，当前可提现金额：' . number_format($user['withdrawable_money'], 2) . '元');
                }
            }

            $beforeBalance = (float)$user['balance_available'];
            $beforeWithdrawable = (float)$user['withdrawable_money'];
            $beforeService = (float)$user['service_fee_balance'];
            
            $afterBalance = $beforeBalance;
            $afterWithdrawable = $beforeWithdrawable;
            $afterService = round($beforeService + $amount, 2);

            // 根据充值来源扣除对应余额（只操作真实余额池）
            if ($source == 'balance_available') {
                $afterBalance = round($beforeBalance - $amount, 2);
            } else {
                $afterWithdrawable = round($beforeWithdrawable - $amount, 2);
            }
            
            $now = time();
            
            // 生成流水号和批次号（使用SJS前缀）
            $flowNo1 = 'SJS' . date('YmdHis') . str_pad($this->auth->id, 6, '0', STR_PAD_LEFT) . mt_rand(1000, 9999);
            $flowNo2 = 'SJS' . date('YmdHis') . str_pad($this->auth->id, 6, '0', STR_PAD_LEFT) . mt_rand(1000, 9999);
            // 确保两个流水号不同
            while ($flowNo2 === $flowNo1) {
                $flowNo2 = 'SJS' . date('YmdHis') . str_pad($this->auth->id, 6, '0', STR_PAD_LEFT) . mt_rand(1000, 9999);
            }
            $batchNo = generateBatchNo('SERVICE_FEE_RECHARGE', $this->auth->id);
            
            $updateData = [
                'service_fee_balance' => $afterService,
                'update_time' => $now,
            ];
            
            // 只更新真实余额池，不更新money字段
            if ($source == 'balance_available') {
                $updateData['balance_available'] = $afterBalance;
            } else {
                $updateData['withdrawable_money'] = $afterWithdrawable;
            }

            Db::name('user')
                ->where('id', $this->auth->id)
                ->update($updateData);

            // 记录余额日志（如果使用可用余额充值）
            if ($source == 'balance_available') {
                Db::name('user_money_log')->insert([
                    'user_id' => $this->auth->id,
                    'flow_no' => $flowNo1,
                    'batch_no' => $batchNo,
                    'biz_type' => 'service_fee_recharge',
                    'biz_id' => $this->auth->id,
                    'field_type' => 'balance_available', // 可用余额变动
                    'money' => -$amount,
                    'before' => $beforeBalance,
                    'after' => $afterBalance,
                    'memo' => '可用余额充值服务费',
                    'create_time' => $now,
                ]);
            } else {
                // 如果使用可提现金额充值，记录可提现金额变动
                Db::name('user_money_log')->insert([
                    'user_id' => $this->auth->id,
                    'flow_no' => $flowNo1,
                    'batch_no' => $batchNo,
                    'biz_type' => 'service_fee_recharge',
                    'biz_id' => $this->auth->id,
                    'field_type' => 'withdrawable_money', // 可提现金额变动
                    'money' => -$amount,
                    'before' => $beforeWithdrawable,
                    'after' => $afterWithdrawable,
                    'memo' => '可提现金额充值服务费',
                    'create_time' => $now,
                ]);
            }

            // 记录服务费余额增加日志
            Db::name('user_money_log')->insert([
                'user_id' => $this->auth->id,
                'flow_no' => $flowNo2,
                'batch_no' => $batchNo,
                'biz_type' => 'service_fee_recharge',
                'biz_id' => $this->auth->id,
                'field_type' => 'service_fee_balance', // 服务费余额变动
                'money' => $amount,
                'before' => $beforeService,
                'after' => $afterService,
                'memo' => $source == 'balance_available' ? '可用余额充值服务费' : '可提现金额充值服务费',
                'create_time' => $now,
            ]);

            // 记录活动日志
            $sourceName = $source == 'balance_available' ? '可用余额' : '可提现金额';
            $changeField = $source == 'balance_available' ? 'balance_available_to_service_fee' : 'withdrawable_money_to_service_fee';
            
            $extraData = [
                'service_fee_increase' => $amount,
                'before_service_fee' => $beforeService,
                'after_service_fee' => $afterService,
            ];
            
            if ($source == 'balance_available') {
                $extraData['before_balance_available'] = $beforeBalance;
                $extraData['after_balance_available'] = $afterBalance;
            } else {
                $extraData['before_withdrawable_money'] = $beforeWithdrawable;
                $extraData['after_withdrawable_money'] = $afterWithdrawable;
            }

            Db::name('user_activity_log')->insert([
                'user_id' => $this->auth->id,
                'related_user_id' => 0,
                'action_type' => 'service_fee_recharge',
                'change_field' => $changeField,
                'change_value' => $source == 'balance_available' ? -$amount : -$amount,
                'before_value' => $source == 'balance_available' ? $beforeBalance : $beforeWithdrawable,
                'after_value' => $source == 'balance_available' ? $afterBalance : $afterWithdrawable,
                'remark' => $sourceName . '充值服务费',
                'extra' => json_encode($extraData),
                'create_time' => $now,
                'update_time' => $now,
            ]);

            Db::commit();
            
            $result = [
                'service_fee_balance' => $afterService,
            ];
            
            if ($source == 'balance_available') {
                $result['balance_available'] = $afterBalance;
            } else {
                $result['withdrawable_money'] = $afterWithdrawable;
            }
            
            $this->success('充值成功', $result);
        } catch (\think\exception\HttpResponseException $e) {
            Db::rollback();
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $msg = $e->getMessage();
            $this->error('充值失败：' . ($msg === '' ? '系统错误' : $msg));
        }
    }

    #[
        Apidoc\Title("修改密码"),
        Apidoc\Tag("个人中心,密码"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/Account/changePassword"),
        Apidoc\Query(name:"oldPassword",type: "string",require: true,desc: "旧密码",example:"123456"),
        Apidoc\Query(name:"newPassword",type: "string",require: true,desc: "新密码(6-32位，不能包含特殊字符)",example:"123456"),
    ]
    /**
     * 修改密码
     * @throws Throwable
     */
    public function changePassword(): void
    {
        if ($this->request->isPost()) {
            $model  = $this->auth->getUser();
            $params = $this->request->only(['oldPassword', 'newPassword']);

            if (!verify_password($params['oldPassword'], $model->password, ['salt' => $model->salt])) {
                $this->error(__('Old password error'));
            }

            $model->startTrans();
            try {
                $validate = new AccountValidate();
                $validate->scene('changePassword')->check(['password' => $params['newPassword']]);
                $model->resetPassword($this->auth->id, $params['newPassword']);
                $model->commit();
            } catch (Throwable $e) {
                $model->rollback();
                $this->error($e->getMessage());
            }

            $this->auth->logout();
            $this->success(__('Password has been changed, please login again~'));
        }
    }

    #[
        Apidoc\Title("注销账号"),
        Apidoc\Tag("个人中心,注销"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/Account/cancelAccount"),
        Apidoc\Query(name:"password",type: "string",require: true,desc: "账户密码，用于二次确认",example:"123456"),
        Apidoc\Query(name:"reason",type: "string",require: false,desc: "注销原因（可选）",example:"不再使用此账号"),
    ]
    /**
     * 注销当前登录账号（逻辑注销：禁用账号并清理登录状态）
     *
     * 注意：为保证安全，这里仅允许用户注销自己的账号，并要求输入账户密码进行二次验证。
     * 如需物理删除用户及其关联数据，请在后台结合回收站等功能谨慎操作。
     *
     * @throws Throwable
     */
    public function cancelAccount(): void
    {
        if (!$this->request->isPost()) {
            $this->error(__('Invalid request method'));
        }

        $user = $this->auth->getUser();
        if (!$user) {
            $this->error(__('You are not logged in'));
        }

        $params = $this->request->only(['password', 'reason']);

        // 校验密码，防止误操作或被他人操作
        if (
            !isset($params['password'])
            || !verify_password($params['password'], $user->password, ['salt' => $user->salt])
        ) {
            $this->error(__('Password error'));
        }

        $user->startTrans();
        try {
            // 1. 标记账号为禁用（逻辑注销）
            $user->status = 'disable';

            // 2. 可选：对高敏感信息做简单匿名化处理（根据业务需要增减字段）
            $user->email  = '';
            $user->mobile = '';
            $user->motto  = '';

            // 3. 记录最后一次更新时间
            $user->update_time = time();

            $user->save();
            $user->commit();
        } catch (Throwable $e) {
            $user->rollback();
            $this->error($e->getMessage());
        }

        // 清理当前用户的所有登录 token（包括刷新 token）
        Token::clear($this->auth::TOKEN_TYPE, $user->id);
        Token::clear($this->auth::TOKEN_TYPE . '-refresh', $user->id);

        // 退出当前会话
        $this->auth->logout();

        $this->success(__('您的账户已被注销，无法再用于登录'));
    }

    #[
        Apidoc\Title("消费金日志"),
        Apidoc\Tag("个人中心,消费金"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Account/integral"),
        Apidoc\Query(name:"limit",type: "int",require: false,desc: "每页数量",example:"10",default:"10"),
        Apidoc\Returned("list",type: "array",desc: "消费金日志列表"),
        Apidoc\Returned("list[].id",type: "int",desc: "日志ID"),
        Apidoc\Returned("list[].score",type: "int",desc: "变动数量"),
        Apidoc\Returned("list[].before",type: "int",desc: "变动前"),
        Apidoc\Returned("list[].after",type: "int",desc: "变动后"),
        Apidoc\Returned("list[].memo",type: "string",desc: "备注"),
        Apidoc\Returned("list[].create_time",type: "int",desc: "创建时间"),
        Apidoc\Returned("list[].flow_no",type: "string",desc: "流水号"),
        Apidoc\Returned("list[].batch_no",type: "string",desc: "批次号"),
        Apidoc\Returned("list[].biz_type",type: "string",desc: "业务类型"),
        Apidoc\Returned("list[].biz_id",type: "string",desc: "业务ID"),
        Apidoc\Returned("total",type: "int",desc: "总记录数"),
    ]
    /**
     * 消费金日志
     * @throws Throwable
     */
    public function integral(): void
    {
        $limit         = $this->request->request('limit');
        $integralModel = new UserScoreLog();
        $res           = $integralModel->where('user_id', $this->auth->id)
            ->order('create_time desc')
            ->paginate($limit);

        $this->success('', [
            'list'  => $res->items(),
            'total' => $res->total(),
        ]);
    }

    #[
        Apidoc\Title("资产明细"),
        Apidoc\Tag("个人中心,资产明细,资金明细"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Account/assetLog"),
        Apidoc\Query(name:"type",type: "string",require: false,desc: "资产类型:all=全部,balance_available=可用余额,withdrawable_money=可提现金额,service_fee_balance=服务费余额,score=消费金",example:"balance_available",default:"all"),
        Apidoc\Query(name:"page",type: "int",require: false,desc: "页码",example:"1",default:"1"),
        Apidoc\Query(name:"limit",type: "int",require: false,desc: "每页数量",example:"10",default:"10"),
        Apidoc\Returned("list",type: "array",desc: "资产明细列表"),
        Apidoc\Returned("list[].id",type: "int",desc: "日志ID"),
        Apidoc\Returned("list[].asset_type",type: "string",desc: "资产类型"),
        Apidoc\Returned("list[].asset_type_text",type: "string",desc: "资产类型文本"),
        Apidoc\Returned("list[].amount",type: "float",desc: "变动金额（正数为增加，负数为减少）"),
        Apidoc\Returned("list[].before_balance",type: "float",desc: "变动前余额"),
        Apidoc\Returned("list[].after_balance",type: "float",desc: "变动后余额"),
        Apidoc\Returned("list[].remark",type: "string",desc: "备注说明"),
        Apidoc\Returned("list[].create_time",type: "int",desc: "创建时间戳"),
        Apidoc\Returned("total",type: "int",desc: "总记录数"),
        Apidoc\Returned("per_page",type: "int",desc: "每页数量"),
        Apidoc\Returned("current_page",type: "int",desc: "当前页码"),
    ]

    #[
        Apidoc\Title("可用余额明细"),
        Apidoc\Tag("个人中心,可用余额,资金明细"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Account/balance"),
        Apidoc\Query(name:"page",type: "int",require: false,desc: "页码",example:"1",default:"1"),
        Apidoc\Query(name:"limit",type: "int",require: false,desc: "每页数量",example:"10",default:"10"),
        Apidoc\Returned("list",type: "array",desc: "可用余额明细列表"),
        Apidoc\Returned("list[].id",type: "int",desc: "日志ID"),
        Apidoc\Returned("list[].amount",type: "float",desc: "变动金额（正数为增加，负数为减少）"),
        Apidoc\Returned("list[].before_balance",type: "float",desc: "变动前可用余额"),
        Apidoc\Returned("list[].after_balance",type: "float",desc: "变动后可用余额"),
        Apidoc\Returned("list[].remark",type: "string",desc: "备注说明"),
        Apidoc\Returned("list[].create_time",type: "int",desc: "创建时间戳"),
        Apidoc\Returned("total",type: "int",desc: "总记录数"),
        Apidoc\Returned("per_page",type: "int",desc: "每页数量"),
        Apidoc\Returned("current_page",type: "int",desc: "当前页码"),
    ]
    /**
     * 资产明细
     * 支持查询所有类型的资产变化记录
     * @throws Throwable
     */
    public function assetLog(): void
    {
        $type = $this->request->get('type', 'all');
        $page = $this->request->get('page/d', 1);
        $limit = $this->request->get('limit/d', 10);

        $assetTypes = [
            'balance_available' => '可用余额',
            'withdrawable_money' => '可提现金额',
            'service_fee_balance' => '服务费余额',
            'score' => '消费金',
            'green_power' => '绿色算力'
        ];

        $allLogs = [];

        // 如果查询全部或特定类型，则查询对应的日志
        if ($type == 'all' || $type == 'score') {
            // 查询消费金日志
            $scoreLogs = Db::name('user_score_log')
                ->where('user_id', $this->auth->id)
                ->when($type != 'all', function($query) {
                    return $query->where('field_type', 'score');
                })
                ->field([
                    'id',
                    'score as amount',
                    'before',
                    'after',
                    'memo',
                    'create_time',
                    "'score' as asset_type",
                    "'消费金' as asset_type_text"
                ])
                ->select()
                ->toArray();

            $allLogs = array_merge($allLogs, $scoreLogs);
        }

        // 查询资金日志（余额相关）
        $moneyFields = ['balance_available', 'withdrawable_money', 'service_fee_balance', 'green_power'];
        if ($type == 'all' || in_array($type, $moneyFields)) {
            $moneyLogs = Db::name('user_money_log')
                ->where('user_id', $this->auth->id)
                ->when($type != 'all', function($query) use ($type) {
                    return $query->where('field_type', $type);
                })
                ->field([
                    'id',
                    'money as amount',
                    'before',
                    'after',
                    'memo',
                    'create_time',
                    'field_type as asset_type',
                    "CASE
                        WHEN field_type = 'balance_available' THEN '可用余额'
                        WHEN field_type = 'withdrawable_money' THEN '可提现金额'
                        WHEN field_type = 'service_fee_balance' THEN '服务费余额'
                        WHEN field_type = 'green_power' THEN '绿色算力'
                        ELSE '其他'
                    END as asset_type_text"
                ])
                ->select()
                ->toArray();

            $allLogs = array_merge($allLogs, $moneyLogs);
        }

        // 按创建时间倒序排序
        usort($allLogs, function($a, $b) {
            return $b['create_time'] <=> $a['create_time'];
        });

        // 分页处理
        $total = count($allLogs);
        $start = ($page - 1) * $limit;
        $logs = array_slice($allLogs, $start, $limit);

        // 格式化数据
        foreach ($logs as &$item) {
            $item['before_balance'] = (float)$item['before'];
            $item['after_balance'] = (float)$item['after'];
            unset($item['before'], $item['after']);
        }

        $this->success('', [
            'list' => $logs,
            'total' => $total,
            'per_page' => $limit,
            'current_page' => $page,
        ]);
    }

    /**
     * 可用余额明细
     * 只显示可用余额（balance_available）的变化记录
     * 包括所有可能影响可用余额的操作（充值、购买、消费等）
     * 同时也显示 money 字段的变化（如注册奖励、邀请奖励等）
     * @throws Throwable
     */
    public function balance(): void
    {
        $page = $this->request->get('page/d', 1);
        $limit = $this->request->get('limit/d', 10);
        
        // 查询可用余额相关的活动日志
        // 1. 直接记录 balance_available 变化的记录（最准确）
        // 2. 可能影响可用余额的操作（如购买藏品、购买理财产品、商城购物等）
        // 3. money 字段的变化（如注册奖励、邀请奖励等）
        $res = UserActivityLog::where('user_id', $this->auth->id)
            ->where(function($query) {
                $query->where('change_field', 'balance_available')
                    ->whereOr(function($q) {
                        // 购买藏品、购买理财产品、商城购物等操作，虽然 change_field 可能是 money，但实际影响了 balance_available
                        $q->where('action_type', 'collection_purchase')
                          ->whereOr('action_type', 'finance_purchase')
                          ->whereOr('action_type', 'shop_purchase')
                          ->whereOr('action_type', 'balance');
                    })
                    ->whereOr(function($q) {
                        // money 字段的变化（注册奖励、邀请奖励等）
                        $q->where('change_field', 'money')
                          ->where(function($subQ) {
                              $subQ->where('action_type', 'register_reward')
                                   ->whereOr('action_type', 'invite_reward')
                                   ->whereOr('action_type', 'lucky_draw_prize');
                          });
                    });
            })
            ->order('create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page,
            ]);

        $list = [];
        foreach ($res->items() as $item) {
            $amount = 0;
            $beforeBalance = 0;
            $afterBalance = 0;
            
            // 如果 change_field 是 balance_available，直接使用（最准确）
            if ($item->change_field == 'balance_available') {
                $amount = (float)$item->change_value;
                $beforeBalance = (float)$item->before_value;
                $afterBalance = (float)$item->after_value;
            } elseif ($item->change_field == 'money') {
                // 对于 money 字段的变化（如注册奖励、邀请奖励等），直接使用记录的值
                $amount = (float)$item->change_value;
                $beforeBalance = (float)$item->before_value;
                $afterBalance = (float)$item->after_value;
            } else {
                // 对于其他操作，尝试从 extra 中获取 balance_available 信息
                $extra = is_string($item->extra) ? json_decode($item->extra, true) : ($item->extra ?? []);
                
                // 检查 extra 中是否有 balance_available 相关信息
                if (isset($extra['before_balance_available']) && isset($extra['after_balance_available'])) {
                    $beforeBalance = (float)$extra['before_balance_available'];
                    $afterBalance = (float)$extra['after_balance_available'];
                    $amount = $afterBalance - $beforeBalance;
                } else {
                    // 对于购买藏品、购买理财产品等操作，虽然影响了 balance_available，但可能没有在 extra 中记录
                    // 使用 change_value 作为变化量（这些操作确实影响了 balance_available）
                    $amount = (float)$item->change_value;
                    
                    // 对于购买藏品、购买理财产品等操作，虽然影响了 balance_available，但可能没有在 extra 中记录
                    // 尝试从 UserMoneyLog 中获取准确的 before 和 after 值
                    // 因为购买藏品等操作会同时更新 balance_available 和 money，且 UserMoneyLog 的 before/after 可能更接近 balance_available
                    $extra = is_string($item->extra) ? json_decode($item->extra, true) : ($item->extra ?? []);
                    $orderNo = $extra['order_no'] ?? '';
                    
                    // 通过订单号或备注匹配 UserMoneyLog
                    $moneyLog = null;
                    if ($orderNo) {
                        $moneyLog = Db::name('user_money_log')
                            ->where('user_id', $this->auth->id)
                            ->where('memo', 'like', '%' . $orderNo . '%')
                            ->where('create_time', '>=', $item->create_time - 5) // 允许5秒误差
                            ->where('create_time', '<=', $item->create_time + 5)
                            ->find();
                    }
                    
                    if (!$moneyLog && !empty($item->remark)) {
                        // 如果通过订单号找不到，尝试通过备注匹配
                        $moneyLog = Db::name('user_money_log')
                            ->where('user_id', $this->auth->id)
                            ->where('memo', 'like', '%' . mb_substr($item->remark, 0, 10) . '%')
                            ->where('create_time', '>=', $item->create_time - 5)
                            ->where('create_time', '<=', $item->create_time + 5)
                            ->find();
                    }
                    
                    if ($moneyLog) {
                        // UserMoneyLog 的 before/after 记录的是 money 字段，但通常与 balance_available 同步
                        $beforeBalance = (float)$moneyLog['before'];
                        $afterBalance = (float)$moneyLog['after'];
                    } else {
                        // 如果找不到对应的 UserMoneyLog，只能显示变化量
                        // 前后值无法确定，设为0
                        $beforeBalance = 0;
                        $afterBalance = 0;
                    }
                }
            }
            
            // 简化备注信息，去掉括号内的详细信息
            $remark = $item->remark ?? '';
            // 去掉中文括号及其内容（支持嵌套和复杂内容）
            $remark = preg_replace('/（[^）]*）/u', '', $remark);
            // 去掉英文括号及其内容
            $remark = preg_replace('/\([^)]*\)/', '', $remark);
            // 清理多余空格
            $remark = trim($remark);
            
            $list[] = [
                'id' => $item->id,
                'amount' => $amount,
                'before_balance' => $beforeBalance,
                'after_balance' => $afterBalance,
                'remark' => $remark,
                'create_time' => $item->create_time,
            ];
        }

        $this->success('', [
            'list'  => $list,
            'total' => $res->total(),
            'per_page' => $res->listRows(),
            'current_page' => $res->currentPage(),
        ]);
    }

    #[
        Apidoc\Title("服务费余额明细"),
        Apidoc\Tag("个人中心,服务费,明细"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Account/serviceFeeLog"),
        Apidoc\Query(name:"page",type: "int",require: false,desc: "页码",example:"1",default:"1"),
        Apidoc\Query(name:"limit",type: "int",require: false,desc: "每页数量",example:"10",default:"10"),
        Apidoc\Returned("list",type: "array",desc: "服务费余额明细列表"),
        Apidoc\Returned("list[].id",type: "int",desc: "日志ID"),
        Apidoc\Returned("list[].amount",type: "float",desc: "变动金额（正数为增加，负数为减少）"),
        Apidoc\Returned("list[].before_service_fee",type: "float",desc: "变动前服务费余额"),
        Apidoc\Returned("list[].after_service_fee",type: "float",desc: "变动后服务费余额"),
        Apidoc\Returned("list[].remark",type: "string",desc: "备注说明"),
        Apidoc\Returned("list[].create_time",type: "int",desc: "创建时间戳"),
        Apidoc\Returned("total",type: "int",desc: "总记录数"),
        Apidoc\Returned("per_page",type: "int",desc: "每页数量"),
        Apidoc\Returned("current_page",type: "int",desc: "当前页码"),
    ]
    /**
     * 服务费余额明细
     * 显示所有服务费余额的变化记录（包括充值、消费等）
     * @throws Throwable
     */
    public function serviceFeeLog(): void
    {
        $page = $this->request->get('page/d', 1);
        $limit = $this->request->get('limit/d', 10);
        
        // 查询所有涉及服务费余额变化的记录
        // 包括：充值记录（service_fee_recharge）、划转记录（balance_transfer）等
        $res = UserActivityLog::where('user_id', $this->auth->id)
            ->where(function($query) {
                $query->where('action_type', 'service_fee_recharge')
                    ->whereOr(function($q) {
                        $q->where('action_type', 'balance_transfer')
                          ->where('change_field', 'like', '%service_fee%');
                    })
                    ->whereOr('change_field', 'service_fee_balance')
                    ->whereOr('change_field', 'like', '%to_service_fee%');
            })
            ->order('create_time desc')
            ->paginate([
                'list_rows' => $limit,
                'page' => $page,
            ]);

        $list = [];
        foreach ($res->items() as $item) {
            $extra = is_string($item->extra) ? json_decode($item->extra, true) : ($item->extra ?? []);
            
            // 优先从 extra 中获取服务费相关信息
            $amount = 0;
            $beforeServiceFee = 0;
            $afterServiceFee = 0;
            
            if (isset($extra['service_fee_increase'])) {
                // 充值记录
                $amount = (float)$extra['service_fee_increase'];
                $beforeServiceFee = isset($extra['before_service_fee']) ? (float)$extra['before_service_fee'] : 0;
                $afterServiceFee = isset($extra['after_service_fee']) ? (float)$extra['after_service_fee'] : 0;
            } elseif (isset($extra['before_service_fee']) && isset($extra['after_service_fee'])) {
                // 其他包含服务费信息的记录
                $beforeServiceFee = (float)$extra['before_service_fee'];
                $afterServiceFee = (float)$extra['after_service_fee'];
                $amount = $afterServiceFee - $beforeServiceFee;
            } elseif ($item->change_field == 'service_fee_balance') {
                // 直接记录服务费余额变化的记录
                $amount = (float)$item->change_value;
                $beforeServiceFee = (float)$item->before_value;
                $afterServiceFee = (float)$item->after_value;
            } else {
                // 如果无法从 extra 中获取，尝试从 change_value 计算
                // 这种情况可能不准确，但至少能显示记录
                $beforeServiceFee = isset($extra['before_service_fee']) ? (float)$extra['before_service_fee'] : 0;
                $afterServiceFee = isset($extra['after_service_fee']) ? (float)$extra['after_service_fee'] : 0;
                if ($beforeServiceFee == 0 && $afterServiceFee == 0) {
                    // 如果都没有，跳过这条记录
                    continue;
                }
                $amount = $afterServiceFee - $beforeServiceFee;
            }
            
            // 简化备注信息，去掉括号内的详细信息
            $remark = $item->remark ?? '';
            // 去掉中文括号及其内容（支持嵌套和复杂内容）
            $remark = preg_replace('/（[^）]*）/u', '', $remark);
            // 去掉英文括号及其内容
            $remark = preg_replace('/\([^)]*\)/', '', $remark);
            // 清理多余空格
            $remark = trim($remark);
            
            $list[] = [
                'id' => $item->id,
                'amount' => $amount,
                'before_service_fee' => $beforeServiceFee,
                'after_service_fee' => $afterServiceFee,
                'remark' => $remark,
                'create_time' => $item->create_time,
            ];
        }

        $this->success('', [
            'list'  => $list,
            'total' => $res->total(),
            'per_page' => $res->listRows(),
            'current_page' => $res->currentPage(),
        ]);
    }

    #[
        Apidoc\Title("全部明细"),
        Apidoc\Tag("个人中心,明细,全部"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Account/allLog"),
        Apidoc\Query(name:"page",type: "int",require: false,desc: "页码",example:"1",default:"1"),
        Apidoc\Query(name:"limit",type: "int",require: false,desc: "每页数量",example:"10",default:"10"),
        Apidoc\Query(name:"type",type: "string",require: false,desc: "明细类型",example:"all",values:"all,balance_available,withdrawable_money,service_fee_balance,static_income,score,green_power,pending_activation_gold",default:"all"),
        Apidoc\Query(name:"flow_direction",type: "string",require: false,desc: "资金流向(in=收入,out=支出)",example:"out",values:"in,out"),
        Apidoc\Query(name:"start_time",type: "int",require: false,desc: "开始时间戳"),
        Apidoc\Query(name:"end_time",type: "int",require: false,desc: "结束时间戳"),
        Apidoc\Returned("list",type: "array",desc: "明细列表"),
        Apidoc\Returned("list[].id",type: "int",desc: "日志ID"),
        Apidoc\Returned("list[].type",type: "string",desc: "明细类型"),
        Apidoc\Returned("list[].account_type",type: "string",desc: "账户类型"),
        Apidoc\Returned("list[].amount",type: "float",desc: "变动金额"),
        Apidoc\Returned("list[].before_value",type: "float",desc: "变动前金额"),
        Apidoc\Returned("list[].after_value",type: "float",desc: "变动后金额"),
        Apidoc\Returned("list[].remark",type: "string",desc: "备注说明"),
        Apidoc\Returned("list[].memo",type: "string",desc: "原备注"),
        Apidoc\Returned("list[].create_time",type: "int",desc: "创建时间戳"),
        Apidoc\Returned("list[].create_time_text",type: "string",desc: "创建时间文本"),
        Apidoc\Returned("list[].flow_no",type: "string",desc: "流水号"),
        Apidoc\Returned("list[].batch_no",type: "string",desc: "批次号"),
        Apidoc\Returned("list[].biz_type",type: "string",desc: "业务类型"),
        Apidoc\Returned("list[].biz_id",type: "string",desc: "业务ID"),
        Apidoc\Returned("list[].image_snapshot",type: "string",desc: "图片快照"),
        Apidoc\Returned("list[].title_snapshot",type: "string",desc: "标题快照"),
        Apidoc\Returned("list[].breakdown",type: "object",desc: "详细资金结构"),
        Apidoc\Returned("total",type: "int",desc: "总记录数"),
        Apidoc\Returned("per_page",type: "int",desc: "每页数量"),
        Apidoc\Returned("current_page",type: "int",desc: "当前页码"),
    ]
    /**
     * 全部明细
     * 支持查询可用余额、可提现金额、服务费余额、消费金等所有类型的明细
     * @throws Throwable
     */
    public function allLog(): void
    {
        $page = $this->request->get('page/d', 1);
        $limit = $this->request->get('limit/d', 10);
        $type = $this->request->get('type', 'all'); // all, withdrawable_money, score, service_fee_balance
        $flowDirection = $this->request->get('flow_direction', 'all'); // all, in, out
        $startTime = $this->request->get('start_time/d', 0);
        $endTime = $this->request->get('end_time/d', 0);
        
        $userId = $this->auth->id;

        // 构建 Money Log 查询
        $moneyQuery = Db::name('user_money_log')
            ->alias('m')
            ->where('m.user_id', $userId)
            ->field([
                'id',
                'flow_no',
                'batch_no',
                'biz_type',
                'biz_id',
                'field_type as account_type',
                'money as amount',
                'before',
                'after',
                'memo',
                'create_time',
                'title_snapshot',
                'image_snapshot',
                'extra_json',
                'user_collection_id',
                'item_id',
            ]);

        // 构建 Score Log 查询
        $scoreQuery = Db::name('user_score_log')
            ->alias('s')
            ->where('s.user_id', $userId)
            ->field([
                'id',
                'flow_no',
                'batch_no',
                'biz_type',
                'biz_id',
                '\'score\' as account_type',
                'score as amount',
                'before',
                'after',
                'memo',
                'create_time',
                'title_snapshot',
                'image_snapshot',
                'extra_json',
                'user_collection_id',
                'item_id',
            ]);

        // 构建 Activity Log 查询 (新增)
        $activityQuery = Db::name('user_activity_log')
            ->alias('a')
            ->where('a.user_id', $userId)
            // 只查询特定的奖励类型
            // 注意：排除已经在 user_money_log 中记录的业务类型，避免重复显示
            // 以下业务类型已经在 user_money_log 中完整记录，不应该再从 activity_log 中查询：
            // - invite_reward (邀请奖励)
            // - sign_in (签到奖励)
            // - register_reward (注册奖励)
            // - recharge_reward (充值奖励)
            // - gift_hashrate (赠送算力)
            // - compensation (补偿)
            // - score_exchange_green_power (积分兑换算力)
            // - balance_transfer (余额转账)
            // - old_assets_unlock (旧资产解锁)
            // - service_fee_recharge (服务费充值)
            ->whereIn('a.action_type', [
                'first_trade_reward',        // 首次交易奖励(只在activity_log中)
                'questionnaire_reward',      // 问卷奖励(只在activity_log中)
                'subordinate_first_trade_reward',  // 下级首次交易奖励(只在activity_log中)
                // agent_commission 不在列表中，因为实际使用 agent_direct_commission 和 agent_indirect_commission
            ])
            ->field([
                'id',
                "'' COLLATE utf8mb4_unicode_ci as flow_no",
                "'' COLLATE utf8mb4_unicode_ci as batch_no",
                'action_type COLLATE utf8mb4_unicode_ci as biz_type',
                "0 as biz_id",
                'change_field COLLATE utf8mb4_unicode_ci as account_type',
                'change_value as amount',
                'before_value as `before`',
                'after_value as `after`',
                'remark COLLATE utf8mb4_unicode_ci as memo',
                'create_time',
                "'' COLLATE utf8mb4_unicode_ci as title_snapshot",
                "'' COLLATE utf8mb4_unicode_ci as image_snapshot",
                'CAST(extra AS CHAR) COLLATE utf8mb4_unicode_ci as extra_json',
                "0 as user_collection_id",
                "0 as item_id",
            ]);

        // 应用筛选条件
        
        // 1. 对于 Money Log
        if ($type !== 'all' && $type !== 'score') {
             $safeType = addslashes($type);
             // 严格查询指定类型的记录（不再兼容 field_type = 'money'，因为 money 是派生值）
             $moneyQuery->whereRaw("m.field_type = '{$safeType}'");
        } elseif ($type === 'score') {
             $moneyQuery->whereRaw('1=0'); // 如果只查积分，忽略资金表
        }
        
        if ($startTime > 0) $moneyQuery->whereRaw("m.create_time >= {$startTime}");
        if ($endTime > 0) $moneyQuery->whereRaw("m.create_time <= {$endTime}");
        
        if ($flowDirection === 'in') {
            $moneyQuery->whereRaw("money >= 0");
        } elseif ($flowDirection === 'out') {
            $moneyQuery->whereRaw("money < 0");
        }
        
        // 2. 对于 Score Log
        if ($type !== 'all' && $type !== 'score') {
             $scoreQuery->whereRaw('1=0'); // 如果只查资金，忽略积分表
        }
        // 如果 type 是 all 或 score，则保留 score log

        if ($startTime > 0) $scoreQuery->whereRaw("s.create_time >= {$startTime}");
        if ($endTime > 0) $scoreQuery->whereRaw("s.create_time <= {$endTime}");
        
        if ($flowDirection === 'in') {
            $scoreQuery->whereRaw("score >= 0");
        } elseif ($flowDirection === 'out') {
            $scoreQuery->whereRaw("score < 0");
        }

        // 3. 对于 Activity Log (新增)
        if ($type !== 'all' && $type !== 'score') {
             // 如果指定了具体类型（不是all也不是score），可能需要Activity Log（如果未来支持按Activity类型筛选）
             // 目前假设 type 只支持 money_log 的类型和 score，所以非 all/score 时忽略 activity log
             // 除非我们想把 activity log 映射到某种 type
             $activityQuery->whereRaw('1=0');
        }
        
        if ($startTime > 0) $activityQuery->whereRaw("a.create_time >= {$startTime}");
        if ($endTime > 0) $activityQuery->whereRaw("a.create_time <= {$endTime}");
        
        if ($flowDirection === 'in') {
            $activityQuery->whereRaw("change_value >= 0");
        } elseif ($flowDirection === 'out') {
            $activityQuery->whereRaw("change_value < 0");
        }

        // 执行 Union
        // 注意：unionAll 需要传递 闭包/数组/字符串，不能直接传 Query 对象
        $unionSql = $moneyQuery
            ->unionAll($scoreQuery->buildSql())
            ->unionAll($activityQuery->buildSql())
            ->buildSql();
        
        // Debug logging
        file_put_contents(app()->getRootPath() . 'runtime/log/allLog_sql.log', date('Y-m-d H:i:s') . " User: {$userId} Type: {$type} SQL: {$unionSql}\n", FILE_APPEND);

        // UNION 子查询必须用括号包裹
        // 注意：使用子查询时，ThinkPHP 的 page() 方法可能无法正确处理字段列表
        // 使用原生 SQL 进行分页查询
        
        // 1. 计算总数
        $countSql = "SELECT COUNT(*) as total FROM ({$unionSql}) AS u";
        $countResult = Db::query($countSql);
        $count = $countResult[0]['total'] ?? 0;
        
        // 2. 分页查询数据
        $offset = ($page - 1) * $limit;
        $listSql = "SELECT * FROM ({$unionSql}) AS u ORDER BY create_time DESC, amount ASC LIMIT {$offset}, {$limit}";
        $list = Db::query($listSql);
        
        // 格式化输出
        foreach ($list as &$item) {
            $item['amount'] = (float)$item['amount'];
            $item['before_value'] = (float)$item['before'];
            $item['after_value'] = (float)$item['after'];
            $item['create_time_text'] = date('Y-m-d H:i:s', $item['create_time']);
            
            // 完整 URL 处理
            if (!empty($item['image_snapshot'])) {
                $item['image_snapshot'] = toFullUrl($item['image_snapshot']);
            }
            
            // 解析 extra_json
            if (!empty($item['extra_json'])) {
                $item['breakdown'] = json_decode($item['extra_json'], true);
            } else {
                $item['breakdown'] = null;
            }
            unset($item['extra_json']);
            
            // 兼容性字段：remark = memo
            $item['remark'] = $item['memo'];
            
            // 术语重构：Blind Box -> Rights
            // 针对盲盒/确权相关的特殊处理，覆盖默认 memo
            if (isset($item['biz_type']) && $item['biz_type'] == 'blind_box_reserve') {
                 // 盲盒预约冻结 -> 确权申请（冻结）
                 // 如果 memo 包含"盲盒预约"，替换为"确权申请"
                 $item['remark'] = str_replace('盲盒预约', '确权申请', $item['remark']);
                 $item['memo']   = str_replace('盲盒预约', '确权申请', $item['memo']);
            }
            if (strpos($item['remark'], '盲盒中签') !== false) {
                 $item['remark'] = str_replace('盲盒中签', '确权成功', $item['remark']);
                 $item['memo']   = str_replace('盲盒中签', '确权成功', $item['memo']);
            }

            // 兼容性字段：如果前端还在用 type，映射 account_type
            $item['type'] = $item['account_type'];
        }

        $this->success('', [
            'list' => $list,
            'total' => $count,
            'per_page' => $limit,
            'current_page' => $page,
        ]);
    }

    #[
        Apidoc\Title("资金明细详情"),
        Apidoc\Tag("个人中心,明细,详情"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Account/moneyLogDetail"),
        Apidoc\Header(name: "batoken", type: "string", require: true, desc: "用户登录Token"),
        Apidoc\Query(name: "id", type: "int", require: false, desc: "明细ID"),
        Apidoc\Query(name: "flow_no", type: "string", require: false, desc: "流水号"),
        Apidoc\Returned("id", type: "int", desc: "明细ID"),
        Apidoc\Returned("flow_no", type: "string", desc: "流水号"),
        Apidoc\Returned("batch_no", type: "string", desc: "批次号"),
        Apidoc\Returned("biz_type", type: "string", desc: "业务类型"),
        Apidoc\Returned("biz_id", type: "int", desc: "业务ID"),
        Apidoc\Returned("account_type", type: "string", desc: "账户类型"),
        Apidoc\Returned("amount", type: "float", desc: "变动金额"),
        Apidoc\Returned("before_value", type: "float", desc: "变动前金额"),
        Apidoc\Returned("after_value", type: "float", desc: "变动后金额"),
        Apidoc\Returned("memo", type: "string", desc: "备注说明"),
        Apidoc\Returned("create_time", type: "int", desc: "创建时间戳"),
        Apidoc\Returned("create_time_text", type: "string", desc: "创建时间文本"),
        Apidoc\Returned("title_snapshot", type: "string", desc: "商品标题快照"),
        Apidoc\Returned("image_snapshot", type: "string", desc: "商品图片快照"),
        Apidoc\Returned("user_collection_id", type: "int", desc: "用户藏品ID"),
        Apidoc\Returned("item_id", type: "int", desc: "商品ID"),
        Apidoc\Returned("breakdown", type: "object", desc: "详细资金结构"),
    ]
    public function moneyLogDetail(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $id = $this->request->param('id/d', 0);
        $flowNo = $this->request->param('flow_no', '');

        if (!$id && !$flowNo) {
            $this->error('请提供明细ID或流水号');
        }

        $userId = $this->auth->id;

        // 先尝试从 user_money_log 查询
        $where = [['user_id', '=', $userId]];
        if ($id > 0) {
            $where[] = ['id', '=', $id];
        } else {
            $where[] = ['flow_no', '=', $flowNo];
        }

        $log = Db::name('user_money_log')
            ->where($where)
            ->find();

        $isScoreLog = false;
        $isActivityLog = false;
        
        // 如果 money_log 中没找到，尝试从 score_log 查询
        if (!$log) {
            $log = Db::name('user_score_log')
                ->where($where)
                ->find();
            if ($log) {
                $isScoreLog = true;
            }
        }

        // 如果 score_log 也没找到，尝试从 user_activity_log 查询
        if (!$log && $id > 0) {
            // activity_log 没有 flow_no，只能通过 ID 查询
            $log = Db::name('user_activity_log')
                ->where('user_id', $userId)
                ->where('id', $id)
                ->find();
            if ($log) {
                $isActivityLog = true;
            }
        }

        if (!$log) {
            $this->error('明细不存在');
        }

        // 格式化数据
        if ($isActivityLog) {
             // Activity Log字段映射
             $log['amount'] = (float)$log['change_value'];
             $log['before_value'] = (float)$log['before_value'];
             $log['after_value'] = (float)$log['after_value'];
             $log['account_type'] = $log['change_field'];
             $log['memo'] = $log['remark'];
             $log['title_snapshot'] = '';
             $log['image_snapshot'] = '';
             $log['user_collection_id'] = 0;
             $log['item_id'] = 0;
             $log['flow_no'] = '';
             $log['batch_no'] = '';
             $log['biz_type'] = $log['action_type'];
             $log['biz_id'] = 0;
             $log['extra_json'] = is_string($log['extra']) ? $log['extra'] : json_encode($log['extra']);
        } else {
             // Money/Score Log字段处理
             $log['amount'] = $isScoreLog ? (float)$log['score'] : (float)$log['money'];
             $log['before_value'] = (float)$log['before'];
             $log['after_value'] = (float)$log['after'];
             $log['account_type'] = $isScoreLog ? 'score' : ($log['field_type'] ?? '');
             unset($log['money'], $log['score'], $log['before'], $log['after'], $log['field_type']);
        }

        $log['create_time_text'] = date('Y-m-d H:i:s', $log['create_time']);

        $this->success('', $log);
    }

    #[
        Apidoc\Title("找回密码"),
        Apidoc\Tag("个人中心,密码"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/Account/retrievePassword"),
        Apidoc\Query(name:"type",type: "string",require: true,desc: "账户类型",example:"email",values:"email,mobile"),
        Apidoc\Query(name:"account",type: "string",require: true,desc: "邮箱或手机号",example:"test@example.com"),
        Apidoc\Query(name:"captcha",type: "string",require: true,desc: "验证码",example:""),
        Apidoc\Query(name:"password",type: "string",require: true,desc: "新密码(6-32位，不能包含特殊字符)",example:"123456"),
    ]
    /**
     * 找回密码
     * @throws Throwable
     */
    public function retrievePassword(): void
    {
        $params = $this->request->only(['type', 'account', 'captcha', 'password']);
        try {
            $validate = new AccountValidate();
            $validate->scene('retrievePassword')->check($params);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }

        if ($params['type'] == 'email') {
            $user = User::where('email', $params['account'])->find();
        } else {
            $user = User::where('mobile', $params['account'])->find();
        }
        if (!$user) {
            $this->error(__('账户不存在~'));
        }

        // 通用测试验证码 888888 放行
        if (($params['captcha'] ?? '') !== '888888') {
            $captchaObj = new Captcha();
            if (!$captchaObj->check($params['captcha'], $params['account'] . 'user_retrieve_pwd')) {
                $this->error(__('请输入正确的验证码'));
            }
        }

        if ($user->resetPassword($user->id, $params['password'])) {
            $this->success(__('密码已修改~'));
        } else {
   
            $this->error(__('修改密码失败，请稍后重试~'));
        }
    }

    #[
        Apidoc\Title("检查旧资产解锁状态"),
        Apidoc\Tag("个人中心,旧资产,状态检查"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Account/checkOldAssetsUnlockStatus"),
        Apidoc\Returned("unlock_status",type: "int",desc: "解锁状态(0=未解锁,1=已解锁)"),
        Apidoc\Returned("unlock_conditions",type: "object",desc: "解锁条件详情"),
        Apidoc\Returned("unlock_conditions.has_transaction",type: "boolean",desc: "是否完成过交易"),
        Apidoc\Returned("unlock_conditions.transaction_count",type: "int",desc: "交易次数"),
        Apidoc\Returned("unlock_conditions.direct_referrals_count",type: "int",desc: "直推用户总数"),
        Apidoc\Returned("unlock_conditions.qualified_referrals",type: "int",desc: "有交易记录的直推用户数"),
        Apidoc\Returned("unlock_conditions.is_qualified",type: "boolean",desc: "是否满足解锁条件"),
        Apidoc\Returned("unlock_conditions.messages",type: "array",desc: "状态说明信息"),
        Apidoc\Returned("required_gold",type: "float",desc: "需要的待激活金"),
        Apidoc\Returned("current_gold",type: "float",desc: "当前待激活金余额"),
        Apidoc\Returned("can_unlock",type: "boolean",desc: "是否可以解锁"),
        Apidoc\Returned("required_transactions",type: "int",desc: "所需交易次数"),
        Apidoc\Returned("required_referrals",type: "int",desc: "所需直推用户数"),
        Apidoc\Returned("reward_value",type: "float",desc: "奖励价值"),
    ]
    /**
     * 检查旧资产解锁状态
     * 返回当前用户的解锁条件状态，不执行解锁操作
     * @throws Throwable
     */
    public function checkOldAssetsUnlockStatus(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $userId = $this->auth->id;

        // 检查用户是否已经解锁
        $user = Db::name('user')
            ->where('id', $userId)
            ->field('old_assets_status, pending_activation_gold')
            ->find();

        if (!$user) {
            $this->error('用户不存在');
        }

        // 获取解锁条件状态
        $unlockConditions = $this->checkUnlockConditions($userId);

        // 获取配置
        $requiredGold = (float)get_sys_config('old_assets_price') ?: 1000.00;
        $requiredReferrals = (int)get_sys_config('old_assets_condition_referrals') ?: 3;
        $requiredTransactions = 1; // 至少需要完成1笔交易

        // 检查是否可以解锁（支持多次解锁）
        $canUnlock = $unlockConditions['is_qualified'] && $user['pending_activation_gold'] >= $requiredGold;

        $this->success('', [
            'unlock_status' => (int)$user['old_assets_status'],  // 兼容旧版，保留字段
            'unlocked_count' => $unlockConditions['unlocked_count'],
            'available_quota' => $unlockConditions['available_quota'],
            'unlock_conditions' => $unlockConditions,
            'required_gold' => $requiredGold,
            'current_gold' => (float)$user['pending_activation_gold'],
            'can_unlock' => $canUnlock,
            // 配置化字段
            'required_transactions' => $requiredTransactions,
            'required_referrals' => $requiredReferrals,
            'reward_value' => $requiredGold,
        ]);
    }

    #[
        Apidoc\Title("确认解锁旧资产"),
        Apidoc\Tag("个人中心,旧资产,解锁"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/Account/unlockOldAssets"),
        Apidoc\Returned("unlock_status",type: "int",desc: "解锁状态(1=成功)"),
        Apidoc\Returned("consumed_gold",type: "float",desc: "消耗的待激活金"),
        Apidoc\Returned("reward_equity_package",type: "float",desc: "获得的权益资产包价值"),
        Apidoc\Returned("reward_consignment_coupon",type: "int",desc: "获得的寄售券数量"),
        Apidoc\Returned("unlock_conditions",type: "object",desc: "解锁条件详情"),
    ]
    /**
     * 确认解锁旧资产
     * 执行旧资产解锁操作，需要先通过checkOldAssetsUnlockStatus接口确认条件满足
     * 消耗1000待激活金，获得权益资产包¥1000和寄售券x1
     * @throws Throwable
     */
    public function unlockOldAssets(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $userId = $this->auth->id;

        Db::startTrans();
        try {
            // 检查用户数据（支持多次解锁）
            $user = Db::name('user')
                ->where('id', $userId)
                ->lock(true)
                ->field('old_assets_status, old_assets_unlock_count, pending_activation_gold')
                ->find();

            if (!$user) {
                throw new \Exception('用户不存在');
            }

            // 快速检查解锁条件（在事务中）
            $unlockConditions = $this->checkUnlockConditions($userId);

            if (!$unlockConditions['is_qualified']) {
                $msg = '解锁条件不满足';
                if ($unlockConditions['available_quota'] <= 0) {
                    $referralsRequired = (int)get_sys_config('old_assets_condition_referrals') ?: 3;
                    $msg .= '：暂无可用解锁资格（需要每'.$referralsRequired.'个交易直推获得1次资格）';
                }
                throw new \Exception($msg);
            }

            // 检查待激活金是否足够
            $requiredGold = (float)get_sys_config('old_assets_price') ?: 1000.00;
            if ($user['pending_activation_gold'] < $requiredGold) {
                throw new \Exception('待激活金不足，需要' . $requiredGold . '待激活金，当前余额：' . number_format($user['pending_activation_gold'], 2));
            }

            $now = time();
            $currentUnlockCount = (int)($user['old_assets_unlock_count'] ?? 0);
            $newUnlockCount = $currentUnlockCount + 1;

            // 扣除待激活金 + 增加解锁次数
            Db::name('user')
                ->where('id', $userId)
                ->update([
                    'old_assets_status' => 1,  // 兼容旧版，标记为已解锁
                    'old_assets_unlock_count' => $newUnlockCount,
                    'pending_activation_gold' => Db::raw('pending_activation_gold - ' . $requiredGold),
                    'update_time' => $now,
                ]);

            // 调用旧资产解锁专用服务（执行场次选择、SPU创建、寄售单生成等核心流程）
            $result = \app\common\service\LegacyAssetService::executeUnlock($userId, $requiredGold, $newUnlockCount);
            
            $rewardConsignmentCoupon = (int)get_sys_config('old_assets_reward_coupon_count') ?: 1; // 服务内部已发放

            // 创建解锁记录
            Db::name('user_old_assets_unlock')->insert([
                'user_id' => $userId,
                'unlock_count' => $newUnlockCount,
                'unlock_status' => 1,
                'unlock_time' => $now,
                'consumed_gold' => $requiredGold,
                'reward_equity_package' => $requiredGold,  // 记录价值
                'reward_consignment_coupon' => $rewardConsignmentCoupon,
                'unlock_conditions' => json_encode($unlockConditions),
                'create_time' => $now,
            ]);

            // 记录活动日志
            Db::name('user_activity_log')->insert([
                'user_id' => $userId,
                'related_user_id' => 0,
                'action_type' => 'old_assets_unlock',
                'change_field' => 'old_assets_unlock_count',
                'change_value' => $newUnlockCount,
                'before_value' => $currentUnlockCount,
                'after_value' => $newUnlockCount,
                'remark' => '解锁旧资产（第' . $newUnlockCount . '次）',
                'extra' => json_encode([
                    'consumed_gold' => $requiredGold,
                    'user_collection_id' => $result['user_collection_id'],
                    'item_id' => $result['item_id'],
                    'consignment_id' => $result['consignment_id'],
                ]),
                'create_time' => $now,
                'update_time' => $now,
            ]);

            // 记录待激活金变动日志
            $flowNo = generateSJSFlowNo($userId);
            $batchNo = generateBatchNo('OLD_ASSETS_UNLOCK', $userId);
            Db::name('user_money_log')->insert([
                'user_id' => $userId,
                'flow_no' => $flowNo,
                'batch_no' => $batchNo,
                'biz_type' => 'old_assets_unlock',
                'biz_id' => $userId,
                'field_type' => 'pending_activation_gold', // 待激活金变动
                'money' => -$requiredGold,
                'before' => $user['pending_activation_gold'],
                'after' => $user['pending_activation_gold'] - $requiredGold,
                'memo' => '解锁旧资产消耗待激活金（第' . $newUnlockCount . '次）',
                'create_time' => $now,
            ]);

            Db::commit();

            $this->success('旧资产解锁成功', [
                'unlock_count' => $newUnlockCount,
                'consumed_gold' => $requiredGold,
                'reward_item_id' => $result['item_id'],
                'reward_item_title' => '旧资产包',
                'reward_item_price' => $requiredGold,
                'user_collection_id' => $result['user_collection_id'],
                'reward_consignment_coupon' => $rewardConsignmentCoupon,
                'remaining_quota' => max(0, $unlockConditions['available_quota'] - 1),
                'unlock_conditions' => $unlockConditions,
                'message' => '已发放旧资产包（价值'.$requiredGold.'元）和寄售券x'.$rewardConsignmentCoupon.'，请前往"我的藏品"选择寄售变现或转矿机获得持续收益',
                'auto_consignment' => false, // 不再自动寄售
            ]);
        } catch (\think\exception\HttpResponseException $e) {
            Db::rollback();
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $msg = $e->getMessage();
            $this->error('解锁失败：' . ($msg === '' ? '系统错误' : $msg));
        }
    }

    /**
     * 检查旧资产解锁条件
     * @param int $userId 用户ID
     * @return array 解锁条件详情
     */
    private function checkUnlockConditions(int $userId): array
    {
        $conditions = [
            'has_transaction' => false,
            'direct_referrals_count' => 0,
            'qualified_referrals' => 0,
            'unlocked_count' => 0,           // 已解锁次数
            'available_quota' => 0,          // 可用解锁资格
            'is_qualified' => false,
            'transaction_count' => 0,
            'messages' => [],
        ];

        $referralsRequired = (int)get_sys_config('old_assets_condition_referrals') ?: 3;

        // 检查用户是否有交易记录（买入或卖出）
        $transactionCount = Db::name('collection_order')
            ->where('user_id', $userId)
            ->whereIn('status', ['paid', 'completed'])
            ->count();

        $conditions['has_transaction'] = $transactionCount > 0;
        $conditions['transaction_count'] = $transactionCount;

        if ($conditions['has_transaction']) {
            $conditions['messages'][] = '✓ 已完成交易（' . $transactionCount . '笔）';
        } else {
            $conditions['messages'][] = '✗ 未完成任何买入或卖出交易';
        }

        // 检查直推用户
        $directReferrals = Db::name('user')
            ->where('inviter_id', $userId)
            ->column('id');

        $conditions['direct_referrals_count'] = count($directReferrals);
        $conditions['messages'][] = '直推用户总数：' . $conditions['direct_referrals_count'] . '个';

        // 检查有多少个直推用户有交易记录
        if (!empty($directReferrals)) {
            $qualifiedReferrals = Db::name('collection_order')
                ->whereIn('user_id', $directReferrals)
                ->whereIn('status', ['paid', 'completed'])
                ->group('user_id')
                ->column('user_id');

            $conditions['qualified_referrals'] = count(array_unique($qualifiedReferrals));

            if ($conditions['qualified_referrals'] >= $referralsRequired) {
                $conditions['messages'][] = '✓ 有交易记录的直推用户：' . $conditions['qualified_referrals'] . '个';
            } else {
                $conditions['messages'][] = '✗ 有交易记录的直推用户：' . $conditions['qualified_referrals'] . '个（需要至少'.$referralsRequired.'个）';
            }
        } else {
            $conditions['messages'][] = '✗ 没有直推用户';
        }

        // 获取已解锁次数和额外资格
        $user = Db::name('user')
            ->where('id', $userId)
            ->field('old_assets_unlock_count, bonus_unlock_quota')
            ->find();
        $conditions['unlocked_count'] = (int)($user['old_assets_unlock_count'] ?? 0);
        $bonusQuota = (int)($user['bonus_unlock_quota'] ?? 0);

        // 计算可用解锁资格：每N个交易直推获得1次资格 + 额外资格
        // 可用资格 = floor(有效直推数 / N) + 额外资格 - 已解锁次数
        $earnedQuota = floor($conditions['qualified_referrals'] / $referralsRequired);
        $conditions['available_quota'] = max(0, $earnedQuota + $bonusQuota - $conditions['unlocked_count']);

        $conditions['messages'][] = '已解锁次数：' . $conditions['unlocked_count'] . '次';
        $conditions['messages'][] = '可获得资格：' . $earnedQuota . '次（每'.$referralsRequired.'个交易直推=1次）';
        
        if ($conditions['available_quota'] > 0) {
            $conditions['messages'][] = '✓ 剩余可用资格：' . $conditions['available_quota'] . '次';
        } else {
            $conditions['messages'][] = '✗ 暂无可用解锁资格（需要更多交易直推）';
        }

        // 判断是否满足解锁条件：自己是交易用户 + 有可用资格
        $conditions['is_qualified'] = $conditions['has_transaction'] && $conditions['available_quota'] > 0;

        return $conditions;
    }

    #[
        Apidoc\Title("消费金兑换绿色算力"),
        Apidoc\Tag("个人中心,兑换,消费金,绿色算力"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/Account/exchangeScoreToGreenPower"),
        Apidoc\Query(name:"score",type: "int",require: true,desc: "要兑换的消费金数量",example:"100"),
        Apidoc\Returned("score_consumed",type: "int",desc: "消耗的消费金"),
        Apidoc\Returned("green_power_gained",type: "float",desc: "获得的绿色算力"),
        Apidoc\Returned("before_score",type: "int",desc: "兑换前消费金"),
        Apidoc\Returned("after_score",type: "int",desc: "兑换后消费金"),
        Apidoc\Returned("before_green_power",type: "float",desc: "兑换前绿色算力"),
        Apidoc\Returned("after_green_power",type: "float",desc: "兑换后绿色算力"),
        Apidoc\Returned("exchange_rate",type: "float",desc: "当前兑换比例（消费金:绿色算力）"),
    ]
    /**
     * 消费金兑换绿色算力
     * 根据系统配置的兑换比例，将消费金兑换为绿色算力
     * 默认比例：2消费金=1算力（可在后台配置）
     * @throws Throwable
     */
    public function exchangeScoreToGreenPower(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error('请先登录', [], 401);
        }

        $score = $this->request->param('score/d', 0);
        
        if ($score <= 0) {
            $this->error('兑换的消费金数量必须大于0');
        }

        // 获取兑换比例
        $exchangeRate = get_score_exchange_green_power_rate();
        if ($exchangeRate <= 0) {
            $this->error('兑换比例未配置或配置错误，请联系管理员');
        }

        // 计算可获得的绿色算力
        $greenPowerGained = round($score / $exchangeRate, 2);
        
        if ($greenPowerGained <= 0) {
            $this->error('兑换数量过小，无法获得绿色算力');
        }

        Db::startTrans();
        try {
            // 查询用户并锁定
            $user = Db::name('user')
                ->where('id', $this->auth->id)
                ->lock(true)
                ->field('score,green_power')
                ->find();

            if (!$user) {
                throw new \Exception('用户不存在');
            }

            // 检查用户表是否有 green_power 字段
            $hasGreenPowerField = Db::query("SHOW COLUMNS FROM `ba_user` LIKE 'green_power'");
            if (empty($hasGreenPowerField)) {
                throw new \Exception('用户表不支持绿色算力字段');
            }

            // 验证消费金是否充足
            $beforeScore = (int)$user['score'];
            if ($beforeScore < $score) {
                throw new \Exception('消费金不足，当前消费金：' . $beforeScore . '，需要：' . $score);
            }

            // 计算兑换后的值
            $afterScore = $beforeScore - $score;
            $beforeGreenPower = (float)($user['green_power'] ?? 0);
            $afterGreenPower = round($beforeGreenPower + $greenPowerGained, 2);

            $now = time();

            // 更新用户数据
            Db::name('user')
                ->where('id', $this->auth->id)
                ->update([
                    'score' => $afterScore,
                    'green_power' => $afterGreenPower,
                    'update_time' => $now,
                ]);

            // 生成流水号和批次号
            $flowNo1 = generateSJSFlowNo($this->auth->id);
            $flowNo2 = generateSJSFlowNo($this->auth->id);
            // 确保两个流水号不同
            while ($flowNo2 === $flowNo1) {
                $flowNo2 = generateSJSFlowNo($this->auth->id);
            }
            $batchNo = generateBatchNo('SCORE_EXCHANGE_GREEN_POWER', $this->auth->id);
            
            // 记录消费金日志
            Db::name('user_score_log')->insert([
                'user_id' => $this->auth->id,
                'flow_no' => $flowNo1,
                'batch_no' => $batchNo,
                'biz_type' => 'score_exchange_green_power',
                'biz_id' => $this->auth->id,
                'score' => -$score,
                'before' => $beforeScore,
                'after' => $afterScore,
                'memo' => '消费金兑换绿色算力',
                'create_time' => $now,
            ]);

            // 记录活动日志
            Db::name('user_activity_log')->insert([
                'user_id' => $this->auth->id,
                'related_user_id' => 0,
                'action_type' => 'score_exchange_green_power',
                'change_field' => 'score',
                'change_value' => -$score,
                'before_value' => $beforeScore,
                'after_value' => $afterScore,
                'remark' => sprintf('消费金兑换绿色算力（消耗%d消费金，获得%.2f绿色算力）', $score, $greenPowerGained),
                'extra' => json_encode([
                    'score_consumed' => $score,
                    'green_power_gained' => $greenPowerGained,
                    'before_green_power' => $beforeGreenPower,
                    'after_green_power' => $afterGreenPower,
                    'exchange_rate' => $exchangeRate,
                ]),
                'create_time' => $now,
                'update_time' => $now,
            ]);

            // 记录绿色算力变更日志 (写入 user_money_log 以便在资金流水中展示)
            Db::name('user_money_log')->insert([
                'user_id' => $this->auth->id,
                'flow_no' => $flowNo2,
                'batch_no' => $batchNo,
                'biz_type' => 'score_exchange_green_power',
                'biz_id' => $this->auth->id,
                'field_type' => 'green_power', // 标记为绿色算力
                'money' => $greenPowerGained,   // 记录获得数量
                'before' => $beforeGreenPower,
                'after' => $afterGreenPower,
                'memo' => '消费金兑换绿色算力',
                'create_time' => $now,
                'extra_json' => json_encode([
                    'score_consumed' => $score,
                    'green_power_gained' => $greenPowerGained,
                    'exchange_rate' => $exchangeRate,
                ], JSON_UNESCAPED_UNICODE),
            ]);



            Db::commit();
            
            $this->success('兑换成功', [
                'score_consumed' => $score,
                'green_power_gained' => $greenPowerGained,
                'before_score' => $beforeScore,
                'after_score' => $afterScore,
                'before_green_power' => $beforeGreenPower,
                'after_green_power' => $afterGreenPower,
                'exchange_rate' => $exchangeRate,
            ]);
        } catch (\think\exception\HttpResponseException $e) {
            Db::rollback();
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $msg = $e->getMessage();
            $this->error('兑换失败：' . ($msg === '' ? '系统错误' : $msg));
        }
    }
}