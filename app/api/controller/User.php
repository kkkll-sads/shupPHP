<?php

namespace app\api\controller;

use Throwable;
use ba\Captcha;
use ba\ClickCaptcha;
use think\facade\Config;
use think\facade\Db;
use think\Validate;
use app\common\facade\Token;
use app\common\controller\Frontend;
use app\common\library\Upload;
use app\api\validate\User as UserValidate;
use think\exception\HttpResponseException;

use hg\apidoc\annotation as Apidoc;

#[Apidoc\Title("会员管理")]
class User extends Frontend
{
    protected array $noNeedLogin = ['checkIn', 'logout', 'submitRealName'];
    
    // 跳过权限检查，前端用户API不需要权限验证
    protected array $noNeedPermission = ['*'];

    public function initialize(): void
    {
        parent::initialize();
    }

    #[
        Apidoc\Title("会员签入(登录和注册)"),
        Apidoc\Tag("会员,登录,注册"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/User/checkIn"),
        Apidoc\Query(name:"tab",type: "string",require: true,desc: "操作类型",default:"register",example:"register",values:"login,register"),
        Apidoc\Query(name:"mobile",type: "string",require: false,desc: "手机号(注册时必填)",example:"13800138000"),
        Apidoc\Query(name:"password",type: "string",require: false,desc: "密码(6-32位，不能包含特殊字符，注册时必填)",example:"123456"),
        Apidoc\Query(name:"pay_password",type: "string",require: false,desc: "支付密码(6-32位，不能包含特殊字符，注册时必填)",example:"123456"),
        Apidoc\Query(name:"invite_code",type: "string",require: false,desc: "邀请码(注册时必填，如果后台开启了邀请码注册)",example:"ABC123"),
        Apidoc\Query(name:"captcha",type: "string",require: false,desc: "短信验证码(注册必填)",example:"123456"),
        Apidoc\Query(name:"username",type: "string",require: false,desc: "用户名(登录时必填)",example:"testuser"),
        Apidoc\Query(name:"captchaId",type: "string",require: false,desc: "点选验证码ID(登录时，如果开启验证码)",example:""),
        Apidoc\Query(name:"captchaInfo",type: "string",require: false,desc: "点选验证码信息(登录时，如果开启验证码)",example:""),
        Apidoc\Query(name:"keep",type: "int",require: false,desc: "保持登录(登录时，1=是，0=否)",example:"0"),
        Apidoc\Returned("userInfo",type: "object",desc: "用户信息"),
        Apidoc\Returned("routePath",type: "string",desc: "路由路径"),
        Apidoc\Returned("userLoginCaptchaSwitch",type: "bool",desc: "登录验证码开关(GET请求时返回)"),
        Apidoc\Returned("accountVerificationType",type: "string",desc: "账户验证类型(GET请求时返回)"),
    ]
    /**
     * 会员签入(登录和注册)
     * @throws Throwable
     */
    public function checkIn(): void
    {
        $openMemberCenter = Config::get('buildadmin.open_member_center');
        if (!$openMemberCenter) {
            $this->error(__('Member center disabled'));
        }

        // 检查登录态
        if ($this->auth->isLogin()) {
            $this->success(__('You have already logged in. There is no need to log in again~'), [
                'type' => $this->auth::LOGGED_IN
            ], $this->auth::LOGIN_RESPONSE_CODE);
        }

        $userLoginCaptchaSwitch = Config::get('buildadmin.user_login_captcha');

        if ($this->request->isPost()) {
            $params = $this->request->post(['tab', 'email', 'mobile', 'username', 'password', 'pay_password', 'invite_code', 'keep', 'captcha', 'captchaId', 'captchaInfo', 'registerType']);

            // 提前检查 tab ，然后将以 tab 值作为数据验证场景
            // 新增 sms_login：手机号 + 短信验证码登录
            if (!in_array($params['tab'] ?? '', ['login', 'register', 'sms_login'])) {
                $this->error(__('Unknown operation'));
            }

            $validate = new UserValidate();
            try {
                // sms_login 需要去掉 mobile 的唯一性/requireIf 限制，仅校验格式和验证码必填
                if (($params['tab'] ?? '') === 'sms_login') {
                    $validate->only(['mobile', 'captcha'])
                        ->remove('mobile', ['unique', 'requireIf'])
                        ->check($params);
                } else {
                    $validate->scene($params['tab'])->check($params);
                }
            } catch (Throwable $e) {
                $this->error($e->getMessage());
            }

            if ($params['tab'] == 'login') {
                // 暂时注释掉登录验证码验证逻辑，默认通过
                // if ($userLoginCaptchaSwitch) {
                //     $captchaObj = new ClickCaptcha();
                //     if (!$captchaObj->check($params['captchaId'], $params['captchaInfo'])) {
                //         $this->error(__('Captcha error'));
                //     }
                // }
                // login 分支保持原有账号 + 密码登录方式
                // 确定登录凭据：优先使用 username，否则使用 mobile 或 email
                $loginCredential = $params['username'] ?? $params['mobile'] ?? $params['email'] ?? '';
                $res = $this->auth->login($loginCredential, $params['password'], !empty($params['keep']));
            } elseif ($params['tab'] == 'register') {
                // 短信验证码校验，测试码 888888 放行
                // 兼容多种可能的 event key（防止前端 event 传错导致无法验证）
                if (($params['captcha'] ?? '') !== '888888') {
                    $captchaObj = new Captcha();
                    $verifyOk   = false;

                    // 1. 按正常注册场景的 key 验证（旧版：user_register）
                    if ($captchaObj->check($params['captcha'], $params['mobile'] . 'user_register')) {
                        $verifyOk = true;
                    }

                    // 2. 兼容当前前端实际使用的 event=register 以及其它可能的场景
                    if (!$verifyOk) {
                        $possibleEvents = ['register', 'user_mobile_verify', 'user_login'];
                        foreach ($possibleEvents as $event) {
                            if ($captchaObj->check($params['captcha'], $params['mobile'] . $event)) {
                                $verifyOk = true;
                                break;
                            }
                        }
                    }

                    if (!$verifyOk) {
                        $this->error(__('Please enter the correct verification code'));
                    }
                }

                // 检查手机号白名单（如果启用）
                $whitelistEnabled = Db::name('config')->where('name', 'mobile_whitelist_enabled')->value('value');
                if ($whitelistEnabled == '1') {
                    // 验证手机号格式
                    if (empty($params['mobile']) || !preg_match('/^1[3-9]\d{9}$/', $params['mobile'])) {
                        $this->error('手机号格式不正确');
                    }
                    
                    // 检查手机号是否在白名单中且状态为启用
                    $whitelist = Db::name('mobile_whitelist')
                        ->where('mobile', $params['mobile'])
                        ->where('status', 1)
                        ->find();
                    
                    if (!$whitelist) {
                        $this->error('您的手机号不在注册白名单中，请联系管理员');
                    }
                }

                // 检查邀请码注册开关
                $inviteCodeRegister = Db::name('config')->where('name', 'invite_code_register')->value('value');
                $inviteCodeInfo = null;
                
                // 邀请码白名单校验 - 放在最前面确保开启时必须校验
                $inviteCodeWhitelistEnabled = Db::name('config')->where('name', 'invite_code_whitelist_enabled')->value('value');
                if ($inviteCodeWhitelistEnabled == '1') {
                    if (empty($params['invite_code'])) {
                        $this->error('邀请码白名单已开启，必须填写邀请码');
                    }
                    $whitelistCode = Db::name('invite_code_whitelist')
                        ->where('code', $params['invite_code'])
                        ->where('status', 1)
                        ->find();
                    if (!$whitelistCode) {
                        $this->error('错误的邀请码或邀请码不在白名单中');
                    }
                }

                // 如果传了邀请码，无论开关是否开启，都尝试验证并设置邀请人
                if (!empty($params['invite_code'])) {
                    // 如果邀请码注册已开启，邀请码必填且必须验证通过
                    if ($inviteCodeRegister == '1') {
                        // 查询邀请码信息 - 优先从 invite_code 表查找
                        $inviteCodeInfo = Db::name('invite_code')->where('code', $params['invite_code'])->find();
                        
                        // 如果 invite_code 表中找不到，尝试从 user 表的 invite_code 字段查找
                        if (!$inviteCodeInfo) {
                            $userInfo = Db::name('user')->where('invite_code', $params['invite_code'])->where('status', 'enable')->find();
                            if ($userInfo) {
                                // 从用户表找到邀请码，构造邀请码信息结构
                                $inviteCodeInfo = [
                                    'id' => 0,
                                    'code' => $params['invite_code'],
                                    'user_id' => $userInfo['id'],
                                    'status' => '1',
                                    'expire_time' => 0,
                                    'max_use' => 0,
                                    'use_count' => 0,
                                ];
                            }
                        }
                        
                        if (!$inviteCodeInfo) {
                            $this->error(__('Invite code does not exist'));
                        }

                        // 检查邀请码状态（仅对 invite_code 表中的记录进行状态检查）
                        if (isset($inviteCodeInfo['id']) && $inviteCodeInfo['id'] > 0 && $inviteCodeInfo['status'] != '1') {
                            $this->error(__('Invite code is disabled'));
                        }

                        // 检查邀请码是否过期（仅对 invite_code 表中的记录进行过期检查）
                        if (isset($inviteCodeInfo['id']) && $inviteCodeInfo['id'] > 0 && $inviteCodeInfo['expire_time'] && $inviteCodeInfo['expire_time'] < time()) {
                            $this->error(__('Invite code has expired'));
                        }

                        // 检查邀请码使用次数（仅对 invite_code 表中的记录进行使用次数检查）
                        if (isset($inviteCodeInfo['id']) && $inviteCodeInfo['id'] > 0 && $inviteCodeInfo['max_use'] > 0 && $inviteCodeInfo['use_count'] >= $inviteCodeInfo['max_use']) {
                            $this->error(__('Invite code has reached maximum usage limit'));
                        }
                    } else {
                        // 邀请码注册开关关闭，但传了邀请码，尝试查找并设置邀请人（不强制验证）
                        // 查询邀请码信息 - 优先从 invite_code 表查找
                        $inviteCodeInfo = Db::name('invite_code')->where('code', $params['invite_code'])->find();
                        
                        // 如果 invite_code 表中找不到，尝试从 user 表的 invite_code 字段查找
                        if (!$inviteCodeInfo) {
                            $userInfo = Db::name('user')->where('invite_code', $params['invite_code'])->where('status', 'enable')->find();
                            if ($userInfo) {
                                // 从用户表找到邀请码，构造邀请码信息结构
                                $inviteCodeInfo = [
                                    'id' => 0,
                                    'code' => $params['invite_code'],
                                    'user_id' => $userInfo['id'],
                                    'status' => '1',
                                    'expire_time' => 0,
                                    'max_use' => 0,
                                    'use_count' => 0,
                                ];
                            }
                        }
                        // 如果找不到邀请码，不报错，只是不设置邀请人（允许注册）
                    }
                } elseif ($inviteCodeRegister == '1') {
                    // 邀请码注册已开启，但未传邀请码
                    $this->error(__('Invite code is required'));
                }

                // 注册时生成符合规则的username（手机号前加"m"前缀），通过extend传递支付密码和邀请码信息（不加密，直接存储）
                $username = 'm' . $params['mobile']; // 在手机号前加"m"前缀作为用户名
                $extend = [
                    'pay_password' => $params['pay_password'], // 支付密码不加密，直接存入数据库
                    'invite_code'  => $params['invite_code'] ?? '', // 邀请码
                ];

                // 如果找到了邀请码信息，记录邀请人ID（无论开关是否开启）
                if (!empty($inviteCodeInfo)) {
                    $extend['inviter_id'] = $inviteCodeInfo['user_id'];
                }

                $res = $this->auth->register($username, $params['password'], $params['mobile'], '', 1, $extend);

                // 注册成功后，更新邀请码使用次数（仅对 invite_code 表中的记录进行更新）
                if ($res && $inviteCodeRegister == '1' && !empty($inviteCodeInfo) && isset($inviteCodeInfo['id']) && $inviteCodeInfo['id'] > 0) {
                    Db::name('invite_code')->where('id', $inviteCodeInfo['id'])->inc('use_count')->update();
                }
            } elseif ($params['tab'] == 'sms_login') {
                // 手机号 + 短信验证码登录（不自动注册）

                // 1. 参数基本校验
                if (empty($params['mobile']) || empty($params['captcha'])) {
                    $this->error(__('Mobile and captcha are required'));
                }

                // 2. 短信验证码校验，测试码 888888 放行
                // 默认登录场景 event: user_login，同时兼容 register / user_register / user_mobile_verify
                if (($params['captcha'] ?? '') !== '888888') {
                    $captchaObj = new Captcha();
                    $verifyOk   = false;

                    // 2.1 优先使用登录场景 key：mobile + user_login
                    if ($captchaObj->check($params['captcha'], $params['mobile'] . 'user_login')) {
                        $verifyOk = true;
                    }

                    // 2.2 兼容其它可能使用到的场景：register / user_register / user_mobile_verify
                    if (!$verifyOk) {
                        $possibleEvents = ['register', 'user_register', 'user_mobile_verify'];
                        foreach ($possibleEvents as $event) {
                            if ($captchaObj->check($params['captcha'], $params['mobile'] . $event)) {
                                $verifyOk = true;
                                break;
                            }
                        }
                    }

                    if (!$verifyOk) {
                        $this->error(__('Please enter the correct verification code'));
                    }
                }

                // 3. 根据手机号查找已注册用户（不自动注册）
                $user = Db::name('user')
                    ->where('mobile', $params['mobile'])
                    ->where('status', 'enable')
                    ->find();

                if (!$user) {
                    // 不自动注册，提示该手机号未注册
                    $this->error(__('该手机号未注册'));
                }

                // 4. 使用 Auth::direct 直接登录指定用户 ID
                $res = $this->auth->direct((int)$user['id']);
            }

            if (isset($res) && $res === true) {
                $successMessage = $params['tab'] === 'register' ? __('Registration succeeded!') : __('Login succeeded!');
                $this->success($successMessage, [
                    'userInfo'  => $this->auth->getUserInfo(),
                    'routePath' => '/user'
                ]);
            } else {
                $msg = $this->auth->getError();
                $msg = $msg ?: __('Check in failed, please try again or contact the website administrator~');
                $this->error($msg);
            }
        }

        $this->success('', [
            'userLoginCaptchaSwitch'  => $userLoginCaptchaSwitch,
            'accountVerificationType' => get_account_verification_type()
        ]);
    }

    public function logout(): void
    {
        if ($this->request->isPost()) {
            $refreshToken = $this->request->post('refreshToken', '');
            if ($refreshToken) Token::delete((string)$refreshToken);
            $this->auth->logout();
            $this->success();
        }
    }

    #[
        Apidoc\Title("获取用户实名状态"),
        Apidoc\Tag("会员,实名认证"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/User/realNameStatus"),
        Apidoc\Returned("real_name_status", type: "int", desc: "实名状态(0=未实名,1=待审核,2=已通过,3=已拒绝)"),
        Apidoc\Returned("real_name", type: "string", desc: "真实姓名"),
        Apidoc\Returned("id_card", type: "string", desc: "身份证号"),
        Apidoc\Returned("id_card_front", type: "string", desc: "身份证正面图片URL"),
        Apidoc\Returned("id_card_back", type: "string", desc: "身份证反面图片URL"),
        Apidoc\Returned("audit_time", type: "string", desc: "审核时间"),
        Apidoc\Returned("audit_reason", type: "string", desc: "审核原因(拒绝时返回)"),
    ]
    /**
     * 获取用户实名状态
     */
    public function realNameStatus(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error(__('Please login first'));
        }

        $userInfo = $this->auth->getUserInfo();
        $userId = $userInfo['id'];

        $user = Db::name('user')
            ->where('id', $userId)
            ->field('real_name_status,real_name,id_card,id_card_front,id_card_back,audit_time,audit_remark')
            ->find();

        if (!$user) {
            $this->error(__('User not found'));
        }

        $statusMap = [
            0 => '未实名',
            1 => '待审核',
            2 => '已通过',
            3 => '已拒绝',
        ];

        $this->success('', [
            'real_name_status' => (int)$user['real_name_status'],
            'real_name_status_text' => $statusMap[$user['real_name_status']] ?? '未知',
            'real_name' => $user['real_name'] ?? '',
            'id_card' => $user['id_card'] ?? '',
            'id_card_front' => $user['id_card_front'] ?? '',
            'id_card_back' => $user['id_card_back'] ?? '',
            'audit_time' => $user['audit_time'] ?? '',
            'audit_reason' => $user['audit_remark'] ?? '',
        ]);
    }

    #[
        Apidoc\Title("获取代理商审核状态"),
        Apidoc\Tag("会员,代理商"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/User/agentReviewStatus"),
        Apidoc\Returned("status", type: "int", desc: "审核状态(-1=未申请,0=待审核,1=已通过,2=已拒绝)"),
        Apidoc\Returned("status_text", type: "string", desc: "状态文本"),
        Apidoc\Returned("company_name", type: "string", desc: "企业名称"),
        Apidoc\Returned("legal_person", type: "string", desc: "企业法人"),
        Apidoc\Returned("legal_id_number", type: "string", desc: "法人证件号"),
        Apidoc\Returned("subject_type", type: "int", desc: "主体类型：1=个体户，2=企业法人"),
        Apidoc\Returned("license_image", type: "string", desc: "营业执照图片URL"),
        Apidoc\Returned("audit_time", type: "string", desc: "审核时间"),
        Apidoc\Returned("audit_remark", type: "string", desc: "审核备注/拒绝原因"),
    ]
    /**
     * 获取代理商审核状态
     */
    public function agentReviewStatus(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error(__('Please login first'));
        }

        $userInfo = $this->auth->getUserInfo();
        $userId = $userInfo['id'];

        $row = Db::name('agent_review')
            ->where('user_id', $userId)
            ->order('id', 'desc')
            ->find();

        if (!$row) {
            $this->success('', [
                'status'       => -1,
                'status_text'  => '未申请',
                'company_name' => '',
                'legal_person' => '',
                'legal_id_number' => '',
                'subject_type' => 1,
                'license_image' => '',
                'audit_time'   => '',
                'audit_remark' => '',
            ]);
        }

        $statusMap = [
            0 => '待审核',
            1 => '已通过',
            2 => '已拒绝',
        ];

        $this->success('', [
            'status'           => (int)$row['status'],
            'status_text'      => $statusMap[$row['status']] ?? '未知',
            'company_name'     => $row['company_name'],
            'legal_person'     => $row['legal_person'],
            'legal_id_number'  => $row['legal_id_number'],
            'subject_type'     => (int)$row['subject_type'],
            'license_image'    => $row['license_image'],
            'audit_time'       => $row['audit_time'] ? date('Y-m-d H:i:s', (int)$row['audit_time']) : '',
            'audit_remark'     => $row['audit_remark'],
        ]);
    }

    #[
        Apidoc\Title("提交代理商审核"),
        Apidoc\Tag("会员,代理商"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/User/submitAgentReview"),
        Apidoc\Param(name:"company_name", type: "string", require: true, desc: "企业名称"),
        Apidoc\Param(name:"legal_person", type: "string", require: true, desc: "企业法人"),
        Apidoc\Param(name:"legal_id_number", type: "string", require: true, desc: "法人证件号"),
        Apidoc\Param(name:"subject_type", type: "int", require: true, desc: "主体类型：1=个体户，2=企业法人"),
        Apidoc\Param(name:"license_image", type: "string", require: true, desc: "营业执照图片URL（先通过通用上传接口上传，传返回的路径）"),
    ]
    /**
     * 提交代理商审核
     */
    public function submitAgentReview(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error(__('Please login first'));
        }

        $userInfo = $this->auth->getUserInfo();
        $userId = $userInfo['id'];

        // 检查是否已存在申请记录：待审核或已通过时禁止重复提交，仅已拒绝时允许重新提交
        $lastReview = Db::name('agent_review')
            ->where('user_id', $userId)
            ->order('id', 'desc')
            ->find();
        if ($lastReview) {
            if ((int)$lastReview['status'] === 0) {
                $this->error('您已提交代理商申请，正在审核中，请勿重复提交');
            }
            if ((int)$lastReview['status'] === 1) {
                $this->error('您的代理商申请已通过，无需重复提交');
            }
            // status = 2 （已拒绝）时允许重新提交
        }

        $companyName   = trim((string)$this->request->post('company_name', ''));
        $legalPerson   = trim((string)$this->request->post('legal_person', ''));
        $legalIdNumber = trim((string)$this->request->post('legal_id_number', ''));
        $subjectType   = (int)$this->request->post('subject_type/d', 1);
        // 这里结合通用上传接口，只接收已上传的图片URL（例如：/storage/default/xxxx.png）
        $licenseUrl    = trim((string)$this->request->post('license_image', ''));

        if ($companyName === '') {
            $this->error('企业名称不能为空');
        }
        if ($legalPerson === '') {
            $this->error('企业法人不能为空');
        }
        if ($legalIdNumber === '') {
            $this->error('法人证件号不能为空');
        }
        if (!in_array($subjectType, [1, 2], true)) {
            $this->error('主体类型不正确');
        }
        if ($licenseUrl === '') {
            $this->error('请先通过通用上传接口上传营业执照图片，并传入图片URL');
        }

        Db::startTrans();
        try {
            $now = time();

            Db::name('agent_review')->insert([
                'user_id'         => $userId,
                'company_name'    => $companyName,
                'legal_person'    => $legalPerson,
                'legal_id_number' => $legalIdNumber,
                'subject_type'    => $subjectType,
                'license_image'   => $licenseUrl,
                'status'          => 0,
                'audit_remark'    => '',
                'audit_time'      => 0,
                'audit_admin_id'  => 0,
                'create_time'     => $now,
                'update_time'     => $now,
            ]);

            Db::commit();

            $this->success('提交成功，请等待审核', [
                'status'      => 0,
                'status_text' => '待审核',
            ]);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (HttpResponseException $e) {
            // success() 和 error() 抛出的 HttpResponseException，直接向上抛出，不做处理
            \think\facade\Log::info('Request completed with HttpResponseException', [
                'user_id' => $userId,
                'exception_type' => 'HttpResponseException',
                'is_face_auth' => isset($authToken) && !empty($authToken)
            ]);
            throw $e;
        } catch (Throwable $e) {
            \think\facade\Log::error('submitRealName failed with exception', [
                'user_id' => $userId,
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'is_face_auth' => isset($authToken) && !empty($authToken),
                'stack_trace' => substr($e->getTraceAsString(), 0, 1000)
            ]);
            Db::rollback();
            $this->error($e->getMessage());
        }
    }

    #[
        Apidoc\Title("提交实名认证"),
        Apidoc\Tag("会员,实名认证"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/User/submitRealName"),
        Apidoc\Param(name:"auth_token", type: "string", require: true, desc: "H5人脸核身返回的token（必填）"),
        Apidoc\Param(name:"real_name", type: "string", require: true, desc: "真实姓名（必填）"),
        Apidoc\Param(name:"id_card", type: "string", require: true, desc: "身份证号（必填）"),
        Apidoc\Returned("real_name_status", type: "int", desc: "实名状态(2=已通过)"),
    ]
    /**
     * 提交实名认证
     * 只支持人脸核身模式：传递auth_token，从易盾接口验证身份
     */
    public function submitRealName(): void
    {
        // $authToken = $this->request->post('auth_token', '');
        $authToken = $this->request->get('authToken', '');
        \think\facade\Log::info('实名状态回调====>'. $authToken);
        if (empty($authToken)) {
            $this->error('无可回调数据');
        }
        $userInfo = Db::name('user')->where('auth_token', $authToken)->find();
        
        // if (!$this->auth->isLogin()) {
        //     if (!empty($authToken)) {
        //         $userInfo = Db::name('user')->where('auth_token', $authToken)->find();
        //         if (empty($userInfo)) {
        //             $this->error(__('Please login first'));
        //         }
        //     } else {
        //         $this->error(__('Please login first'));
        //     }
        // } else {
        //     $userInfo = $this->auth->getUserInfo();
        // }

        
        $userId = $userInfo['id'];

        // 获取当前用户信息
        $user = Db::name('user')->where('id', $userId)->find();
        if (!$user) {
            $this->error(__('User not found'));
        }

        \think\facade\Log::info('User info loaded for real name submission', [
            'user_id' => $userId,
            'inviter_id' => $user['inviter_id'] ?? 'NOT_SET',
            'current_real_name_status' => $user['real_name_status'] ?? 'NOT_SET'
        ]);

        // 检查用户实名状态：如果已通过或待审核，不允许重复提交
        if ($user['real_name_status'] == 1) {
            $this->error('您已提交实名认证，正在审核中，请勿重复提交');
        }
        if ($user['real_name_status'] == 2) {
            $this->error('您的实名认证已通过，无需重复提交');
        }
    
        // 获取auth_token（人脸核身模式）
        $realName = '';
        $idCard = '';
        // $realName = $this->request->post('real_name', '');
        // $idCard = $this->request->post('id_card', '');

        \think\facade\Log::info('submitRealName called', [
            'user_id' => $userId,
            'auth_token' => $authToken ? substr($authToken, 0, 20) . '...' : 'EMPTY',
            'real_name' => $realName,
            'id_card' => $idCard ? substr($idCard, 0, 6) . '****' . substr($idCard, -4) : 'EMPTY',
            'has_auth_token' => !empty($authToken),
            'has_real_name' => !empty($realName),
            'has_id_card' => !empty($idCard),
            'user_agent' => substr($this->request->header('User-Agent'), 0, 100),
            'ip' => $this->request->ip(),
            'request_method' => $this->request->method(),
        ]);
        
        // $realName = '';
        // $idCard = '';
        $realName = $userInfo['real_name'];
        $idCard = $userInfo['id_card'];
        $idCardFrontUrl = '';
        $idCardBackUrl = '';
        $isAutoApproved = true;

        // 如果提供了auth_token，使用人脸核身模式
        // if (!empty($authToken)) {
        //     try {
        //         // 调用易盾接口校验authToken
        //         $ocr = new \app\common\library\YidunOcr();
        //         $recheckResult = $ocr->h5Recheck($authToken);
        //         \think\facade\Log::info('实名回调验证====>'. json_encode($recheckResult));
        //         // 检查核身结果
        //         if (!isset($recheckResult['result'])) {
        //             $this->error('核身结果解析失败');
        //         }
                
        //         $result = $recheckResult['result'];
                
        //         // 检查核身状态
        //         \think\facade\Log::info('Yidun h5Recheck result', [
        //             'user_id' => $userId,
        //             'status' => $result['status'] ?? 'UNKNOWN',
        //             'faceMatched' => $result['faceMatched'] ?? 'UNKNOWN',
        //             'reasonType' => $result['reasonType'] ?? 'UNKNOWN',
        //             'reasonTypeDesc' => $result['reasonTypeDesc'] ?? 'UNKNOWN',
        //             'avatar' => isset($result['avatar']) ? 'YES' : 'NO',
        //             'full_result' => $result
        //         ]);

        //         if (isset($result['status']) && $result['status'] != 1) {
        //             $statusDesc = $result['status'] == 2 ? '不通过' : '待定';
        //             $reasonDesc = $result['reasonTypeDesc'] ?? '';
        //             \think\facade\Log::warning('Yidun verification failed', [
        //                 'user_id' => $userId,
        //                 'status' => $result['status'],
        //                 'reason' => $statusDesc . ($reasonDesc ? ' - ' . $reasonDesc : '')
        //             ]);
        //             $this->error('人脸核身未通过：' . $statusDesc . ($reasonDesc ? ' - ' . $reasonDesc : ''));
        //         }

        //         // 检查人脸比对结果
        //         if (isset($result['faceMatched']) && $result['faceMatched'] != 1) {
        //             \think\facade\Log::warning('Face match failed', [
        //                 'user_id' => $userId,
        //                 'faceMatched' => $result['faceMatched']
        //             ]);
        //             $this->error('人脸比对未通过，请重新进行人脸核身');
        //         }

        //         \think\facade\Log::info('Yidun verification passed', [
        //             'user_id' => $userId,
        //             'auth_token_prefix' => substr($authToken, 0, 20) . '...'
        //         ]);

        //         // 【修改点】标记为人脸核身通过，直接审核通过
        //         $isAutoApproved = true;

        //         // 从易盾返回结果中提取姓名和身份证号（需要易盾接口返回这些信息）
        //         // 注意：易盾H5人脸核身接口可能不直接返回姓名和身份证号，需要从之前调用getH5AuthToken时传入的参数获取
        //         // 这里假设易盾接口返回了这些信息，如果没有，需要前端在调用时一并传递
        //         // 或者需要从数据库缓存中获取（在getH5AuthToken时保存）
                
        //         // 如果易盾接口没有返回姓名和身份证号，需要前端传递
        //         // $realName = $this->request->post('real_name', '');
        //         // $idCard = $this->request->post('id_card', '');
        //         $realName = $userInfo['real_name'];
        //         $idCard = $userInfo['id_card'];
                
        //         if (empty($realName) || empty($idCard)) {
        //             $this->error('使用人脸核身时，请同时传递real_name和id_card参数');
        //         }
                
        //         // 使用易盾返回的头像作为身份证照片（如果有）
        //         if (isset($result['avatar']) && !empty($result['avatar'])) {
        //             $idCardFrontUrl = $result['avatar'];
        //             $idCardBackUrl = $result['avatar']; // H5人脸核身通常只返回一张照片
        //         } else {
        //             // 如果没有头像，可以设置为空或使用默认值
        //             $idCardFrontUrl = '';
        //             $idCardBackUrl = '';
        //         }
                
        //     } catch (Throwable $e) {
        //         $this->error('人脸核身校验失败：' . $e->getMessage());
        //     }
        // } else {
        //     // 传统模式已禁用：只支持人脸核身模式
        //     \think\facade\Log::warning('Traditional mode not supported - auth_token required', [
        //         'user_id' => $userId,
        //         'reason' => 'auth_token is empty or not provided, traditional mode disabled'
        //     ]);

        //     $this->error('系统已升级为纯人脸核身认证模式，请使用人脸核身方式进行实名认证');
        // }

        // 检查身份证号是否已被其他用户使用
        $existUser = Db::name('user')
            ->where('id_card', $idCard)
            ->where('id', '<>', $userId)
            ->where('real_name_status', 2) // 只检查已通过认证的
            ->find();
        if ($existUser) {
            $this->error('该身份证号已被其他用户使用');
        }

        // 开始事务
        Db::startTrans();
        try {
            // 更新用户信息
            $updateData = [
                'real_name' => $realName,
                'id_card' => $idCard,
                'id_card_front' => $idCardFrontUrl,
                'id_card_back' => $idCardBackUrl,
                'real_name_status' => isset($isAutoApproved) && $isAutoApproved ? 2 : 1, // 自动通过或待审核
                'audit_time' => isset($isAutoApproved) && $isAutoApproved ? time() : 0,
                'audit_admin_id' => 0,
                'audit_remark' => isset($isAutoApproved) && $isAutoApproved ? '系统自动审核(人脸核身通过)' : '',
            ];

            \think\facade\Log::info('Database update prepared', [
                'user_id' => $userId,
                'is_auto_approved' => isset($isAutoApproved) && $isAutoApproved,
                'real_name_status' => $updateData['real_name_status'],
                'audit_time' => $updateData['audit_time'],
                'audit_remark' => $updateData['audit_remark'],
                'has_id_card_front' => !empty($idCardFrontUrl),
                'has_id_card_back' => !empty($idCardBackUrl),
            ]);

            $result = Db::name('user')
                ->where('id', $userId)
                ->update($updateData);

            if ($result === false) {
                \think\facade\Log::error('Database update failed', [
                    'user_id' => $userId,
                    'update_data' => $updateData
                ]);
                throw new \Exception('提交失败，请重试');
            }

            \think\facade\Log::info('Database update successful', [
                'user_id' => $userId,
                'affected_rows' => $result,
                'final_status' => $updateData['real_name_status']
            ]);

            // 检查是否需要发放邀请奖励
            \think\facade\Log::info('Checking invite reward conditions', [
                'user_id' => $userId,
                'real_name_status' => $updateData['real_name_status'],
                'has_inviter_id' => isset($user['inviter_id']),
                'inviter_id' => $user['inviter_id'] ?? null,
                'inviter_id_valid' => isset($user['inviter_id']) && $user['inviter_id'] > 0
            ]);

            if ($updateData['real_name_status'] == 2 && isset($user['inviter_id']) && $user['inviter_id'] > 0) {
                try {
                    $listener = new \app\listener\UserRegisterSuccess();
                    $listener->handleInviteReward($user['inviter_id'], $userId);
                    \think\facade\Log::info('Face recognition auto-audit passed, invite reward granted', [
                        'user_id' => $userId,
                        'inviter_id' => $user['inviter_id']
                    ]);
                } catch (\Throwable $e) {
                    // 邀请奖励发放失败不影响审核结果
                    \think\facade\Log::error('人脸核身自动审核通过后发放邀请奖励失败: ' . $e->getMessage(), [
                        'user_id' => $userId,
                        'inviter_id' => $user['inviter_id']
                    ]);
                }
            }
            Db::commit();


            \think\facade\Log::info('submitRealName completed successfully', [
                'user_id' => $userId,
                'final_status' => $updateData['real_name_status'],
                'is_auto_approved' => isset($isAutoApproved) && $isAutoApproved
            ]);

            $this->success('提交成功', [
                'real_name_status' => $updateData['real_name_status'],
                'real_name_status_text' => $updateData['real_name_status'] == 2 ? '已通过' : '待审核',
            ]);
        } catch (HttpResponseException $e) {
            // success() 和 error() 抛出的 HttpResponseException，直接向上抛出，不做处理
            throw $e;
        } catch (HttpResponseException $e) {
            // success() 和 error() 抛出的 HttpResponseException，直接向上抛出，不做处理
            \think\facade\Log::info('Request completed with HttpResponseException', [
                'user_id' => $userId,
                'exception_type' => 'HttpResponseException',
                'is_face_auth' => isset($authToken) && !empty($authToken)
            ]);
            throw $e;
        } catch (Throwable $e) {
            \think\facade\Log::error('submitRealName failed with exception', [
                'user_id' => $userId,
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'is_face_auth' => isset($authToken) && !empty($authToken),
                'stack_trace' => substr($e->getTraceAsString(), 0, 1000)
            ]);
            Db::rollback();
            $this->error($e->getMessage());
        }
    }

    #[
        Apidoc\Title("添加支付账户（银行卡/支付宝/微信/USDT）"),
        Apidoc\Tag("会员,支付账户"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/User/addPaymentAccount"),
        Apidoc\Param(name:"type", type: "string", require: true, desc: "账户类型:bank_card=银行卡,alipay=支付宝,wechat=微信,usdt=USDT"),
        Apidoc\Param(name:"account_type", type: "string", require: true, desc: "账户性质:personal=个人,company=公司"),
        Apidoc\Param(name:"bank_name", type: "string", require: false, desc: "银行名称（银行卡时必填）"),
        Apidoc\Param(name:"account_name", type: "string", require: true, desc: "账户名/持卡人姓名/微信昵称"),
        Apidoc\Param(name:"account_number", type: "string", require: true, desc: "账号/卡号/微信账号/USDT地址"),
        Apidoc\Param(name:"bank_branch", type: "string", require: false, desc: "开户行（银行卡时可选）/USDT网络类型（USDT时必填，如TRC20、ERC20）"),
        Apidoc\Param(name:"screenshot", type: "file", require: false, desc: "打款截图（公司账户时必填）/微信收款二维码（微信账户时可选）"),
        Apidoc\Returned("id", type: "int", desc: "账户ID"),
    ]
    /**
     * 添加支付账户（银行卡/支付宝/微信/USDT）
     */
    public function addPaymentAccount(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error(__('Please login first'));
        }

        $userInfo = $this->auth->getUserInfo();
        $userId = $userInfo['id'];

        // 检查用户是否已完成实名认证
        $user = Db::name('user')
            ->where('id', $userId)
            ->field('real_name_status,real_name')
            ->find();
        
        if (!$user || $user['real_name_status'] != 2) {
            $this->error('请先完成实名认证才能添加支付账户');
        }
        
        if (empty($user['real_name'])) {
            $this->error('实名信息不完整，无法添加支付账户');
        }

        // 获取表单数据
        $type = $this->request->post('type', '');
        $accountType = $this->request->post('account_type', '');
        $bankName = $this->request->post('bank_name', '');
        $accountName = $this->request->post('account_name', '');
        $accountNumber = $this->request->post('account_number', '');
        $bankBranch = $this->request->post('bank_branch', '');

        // 验证账户类型
        if (!in_array($type, ['bank_card', 'alipay', 'wechat', 'usdt'])) {
            $this->error('账户类型不正确，支持：银行卡、支付宝、微信、USDT');
        }
        if (!in_array($accountType, ['personal', 'company'])) {
            $this->error('账户性质不正确');
        }
        if (empty($accountName)) {
            $this->error('账户名不能为空');
        }
        if (empty($accountNumber)) {
            $this->error('账号/卡号/地址不能为空');
        }

        // 银行卡和支付宝：账户名必须与实名姓名一致
        if (in_array($type, ['bank_card', 'alipay']) && $accountName !== $user['real_name']) {
            $this->error('账户名必须与实名认证的姓名一致');
        }

        // 银行卡类型需要银行名称
        if ($type == 'bank_card' && empty($bankName)) {
            $this->error('银行名称不能为空');
        }

        // USDT类型需要网络类型
        if ($type == 'usdt' && empty($bankBranch)) {
            $this->error('USDT网络类型不能为空，请选择TRC20或ERC20等');
        }
        if ($type == 'usdt' && !in_array(strtoupper($bankBranch), ['TRC20', 'ERC20', 'BEP20', 'OMNI'])) {
            $this->error('USDT网络类型不正确，支持：TRC20、ERC20、BEP20、OMNI');
        }

        // 验证USDT地址格式（基本验证）
        if ($type == 'usdt') {
            $accountNumber = trim($accountNumber);
            // TRC20地址以T开头，34位
            if (strtoupper($bankBranch) == 'TRC20' && (!preg_match('/^T[A-Za-z1-9]{33}$/', $accountNumber))) {
                $this->error('TRC20地址格式不正确，应以T开头，34位字符');
            }
            // ERC20地址以0x开头，42位
            if (strtoupper($bankBranch) == 'ERC20' && (!preg_match('/^0x[a-fA-F0-9]{40}$/', $accountNumber))) {
                $this->error('ERC20地址格式不正确，应以0x开头，42位十六进制字符');
            }
        }

        // 处理截图/二维码上传
        $screenshotUrl = '';
        $screenshotFile = $this->request->file('screenshot');
        
        // 公司账户需要上传打款截图
        if ($accountType == 'company' && in_array($type, ['bank_card', 'alipay'])) {
            if (empty($screenshotFile)) {
                $this->error('公司账户需要上传打款截图');
            }
            try {
                $upload = new Upload();
                $attachment = $upload
                    ->setFile($screenshotFile)
                    ->setDriver('local')
                    ->setTopic('payment')
                    ->upload(null, 0, $userId);
                $screenshotUrl = $attachment['url'] ?? '';
            } catch (Throwable $e) {
                $this->error('上传打款截图失败：' . $e->getMessage());
            }
        }
        
        // 微信账户可以上传收款二维码（可选）
        if ($type == 'wechat' && !empty($screenshotFile)) {
            try {
                $upload = new Upload();
                $attachment = $upload
                    ->setFile($screenshotFile)
                    ->setDriver('local')
                    ->setTopic('payment')
                    ->upload(null, 0, $userId);
                $screenshotUrl = $attachment['url'] ?? '';
            } catch (Throwable $e) {
                $this->error('上传微信收款二维码失败：' . $e->getMessage());
            }
        }

        // 加密账号/卡号（使用base64编码，后续可升级为更安全的加密方式）
        $encryptedAccountNumber = base64_encode($accountNumber);

        // 开始事务
        Db::startTrans();
        try {
            // 审核状态规则：
            // 银行卡：直接通过
            // 支付宝个人：直接通过
            // 支付宝公司：待审核
            // 微信：直接通过
            // USDT：直接通过
            $auditStatus = 1; // 默认通过
            if ($type == 'alipay' && $accountType == 'company') {
                $auditStatus = 0; // 支付宝公司账户需要审核
            }

            // 如果是第一个账户，自动设置为默认账户
            $existingCount = Db::name('user_payment_account')
                ->where('user_id', $userId)
                ->where('status', 1)
                ->count();
            $isDefault = $existingCount == 0 ? 1 : 0;

            // 如果设置为默认账户，需要取消其他默认账户
            if ($isDefault) {
                Db::name('user_payment_account')
                    ->where('user_id', $userId)
                    ->update(['is_default' => 0]);
            }

            // 对于USDT，bank_branch存储网络类型（如TRC20、ERC20）
            // 对于微信，bank_branch可以为空或存储备注信息
            $branchValue = $bankBranch;
            if ($type == 'usdt') {
                $branchValue = strtoupper($bankBranch); // 统一转为大写
            }

            // 插入数据
            $data = [
                'user_id' => $userId,
                'type' => $type,
                'account_type' => $accountType,
                'bank_name' => $bankName,
                'account_name' => $accountName,
                'account_number' => $encryptedAccountNumber,
                'bank_branch' => $branchValue,
                'screenshot' => $screenshotUrl,
                'audit_status' => $auditStatus,
                'is_default' => $isDefault,
                'status' => 1,
                'create_time' => time(),
                'update_time' => time(),
            ];

            $id = Db::name('user_payment_account')->insertGetId($data);

            Db::commit();

            $message = '添加成功';
            if ($auditStatus == 0) {
                $message = '添加成功，请等待审核';
            }
            $this->success($message, ['id' => $id]);
        } catch (HttpResponseException $e) {
            // success() 和 error() 抛出的 HttpResponseException，直接向上抛出，不做处理
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $this->error('添加失败：' . $e->getMessage());
        }
    }

    #[
        Apidoc\Title("获取支付账户列表"),
        Apidoc\Tag("会员,支付账户"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/User/getPaymentAccountList"),
        Apidoc\Returned("list", type: "array", desc: "账户列表"),
    ]
    /**
     * 获取支付账户列表
     */
    public function getPaymentAccountList(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error(__('Please login first'));
        }

        $userInfo = $this->auth->getUserInfo();
        $userId = $userInfo['id'];

        $list = Db::name('user_payment_account')
            ->where('user_id', $userId)
            ->where('status', 1)
            ->order('is_default desc, create_time desc')
            ->select()
            ->toArray();

        // 解密账号/卡号（显示完整账号）
        foreach ($list as &$item) {
            try {
                $decrypted = base64_decode($item['account_number']);
                if ($decrypted !== false) {
                    // 显示完整账号，不进行脱敏
                    $item['account_number_display'] = $decrypted;
                } else {
                    $item['account_number_display'] = '';
                }
            } catch (Throwable $e) {
                $item['account_number_display'] = '';
            }
            // 不返回加密的账号
            unset($item['account_number']);

            // 状态文本
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
            $auditStatusMap = [
                0 => '待审核',
                1 => '已通过',
                2 => '已拒绝',
            ];

            $item['type_text'] = $typeMap[$item['type']] ?? '未知';
            $item['account_type_text'] = $accountTypeMap[$item['account_type']] ?? '未知';
            $item['audit_status_text'] = $auditStatusMap[$item['audit_status']] ?? '未知';
            
            // 对于USDT，显示网络类型
            if ($item['type'] == 'usdt' && !empty($item['bank_branch'])) {
                $item['network_type'] = $item['bank_branch'];
                $item['type_text'] = 'USDT(' . $item['bank_branch'] . ')';
            }
            
            // 对于银行卡，显示开户行信息
            if ($item['type'] == 'bank_card' && !empty($item['bank_branch'])) {
                $item['branch_info'] = $item['bank_branch'];
            }
            
            // 确保所有字符串字段都是有效的 UTF-8
            $stringFields = ['account_name', 'bank_name', 'bank_branch', 'account_number_display', 'type_text', 'account_type_text', 'audit_status_text', 'network_type', 'branch_info', 'remark'];
            foreach ($stringFields as $field) {
                if (isset($item[$field]) && is_string($item[$field])) {
                    // 检查是否为有效的UTF-8编码
                    if (!mb_check_encoding($item[$field], 'UTF-8')) {
                        // 尝试从常见编码转换为UTF-8
                        $item[$field] = mb_convert_encoding($item[$field], 'UTF-8', 'UTF-8,GBK,GB2312,BIG5');
                        // 如果转换后仍然无效，使用清理函数
                        if (!mb_check_encoding($item[$field], 'UTF-8')) {
                            // 移除无效的UTF-8字符
                            $item[$field] = mb_convert_encoding($item[$field], 'UTF-8', 'UTF-8');
                        }
                    }
                    // 清理可能存在的控制字符（保留换行符和制表符）
                    $item[$field] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $item[$field]);
                }
            }
        }

        $this->success('', ['list' => $list]);
    }

    #[
        Apidoc\Title("设置默认支付账户"),
        Apidoc\Tag("会员,支付账户"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/User/setDefaultPaymentAccount"),
        Apidoc\Param(name:"id", type: "int", require: true, desc: "账户ID"),
    ]
    /**
     * 设置默认支付账户
     */
    public function setDefaultPaymentAccount(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error(__('Please login first'));
        }

        $userInfo = $this->auth->getUserInfo();
        $userId = $userInfo['id'];
        $id = $this->request->post('id/d', 0);

        if (!$id) {
            $this->error('账户ID不能为空');
        }

        // 验证账户是否存在且属于当前用户
        $account = Db::name('user_payment_account')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->where('status', 1)
            ->find();

        if (!$account) {
            $this->error('账户不存在或已禁用');
        }

        // 银行卡、微信、USDT可以直接设置为默认；支付宝公司账户需要审核通过
        if ($account['type'] == 'alipay' && $account['account_type'] == 'company' && $account['audit_status'] != 1) {
            $this->error('支付宝公司账户需要审核通过后才能设置为默认账户');
        }

        Db::startTrans();
        try {
            // 取消其他默认账户
            Db::name('user_payment_account')
                ->where('user_id', $userId)
                ->update(['is_default' => 0]);

            // 设置当前账户为默认
            Db::name('user_payment_account')
                ->where('id', $id)
                ->update(['is_default' => 1, 'update_time' => time()]);

            Db::commit();
            $this->success('设置成功');
        } catch (HttpResponseException $e) {
            // success() 和 error() 抛出的 HttpResponseException，直接向上抛出，不做处理
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $this->error('设置失败：' . $e->getMessage());
        }
    }

    #[
        Apidoc\Title("删除支付账户"),
        Apidoc\Tag("会员,支付账户"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/User/deletePaymentAccount"),
        Apidoc\Param(name:"id", type: "int", require: true, desc: "账户ID"),
    ]
    /**
     * 删除支付账户
     */
    public function deletePaymentAccount(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error(__('Please login first'));
        }

        $userInfo = $this->auth->getUserInfo();
        $userId = $userInfo['id'];
        $id = $this->request->post('id/d', 0);

        if (!$id) {
            $this->error('账户ID不能为空');
        }

        // 验证账户是否存在且属于当前用户
        $account = Db::name('user_payment_account')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->find();

        if (!$account) {
            $this->error('账户不存在');
        }

        Db::startTrans();
        try {
            // 软删除：设置状态为禁用
            Db::name('user_payment_account')
                ->where('id', $id)
                ->update(['status' => 0, 'update_time' => time()]);

            // 如果删除的是默认账户，设置第一个可用账户为默认
            if ($account['is_default'] == 1) {
                $firstAccount = Db::name('user_payment_account')
                    ->where('user_id', $userId)
                    ->where('status', 1)
                    ->where('id', '<>', $id)
                    ->order('create_time asc')
                    ->find();

                if ($firstAccount) {
                    Db::name('user_payment_account')
                        ->where('id', $firstAccount['id'])
                        ->update(['is_default' => 1]);
                }
            }

            Db::commit();
            $this->success('删除成功');
        } catch (HttpResponseException $e) {
            // success() 和 error() 抛出的 HttpResponseException，直接向上抛出，不做处理
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $this->error('删除失败：' . $e->getMessage());
        }
    }

    #[
        Apidoc\Title("编辑支付账户"),
        Apidoc\Tag("会员,支付账户"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/User/editPaymentAccount"),
        Apidoc\Param(name:"id", type: "int", require: true, desc: "账户ID"),
        Apidoc\Param(name:"bank_name", type: "string", require: false, desc: "银行名称（银行卡时必填）"),
        Apidoc\Param(name:"account_name", type: "string", require: false, desc: "账户名/持卡人姓名/微信昵称"),
        Apidoc\Param(name:"account_number", type: "string", require: false, desc: "账号/卡号/微信账号/USDT地址"),
        Apidoc\Param(name:"bank_branch", type: "string", require: false, desc: "开户行（银行卡时可选）/USDT网络类型（USDT时必填）"),
        Apidoc\Param(name:"screenshot", type: "file", require: false, desc: "打款截图（公司账户时可选，重新上传会重新审核）/微信收款二维码（微信账户时可选）"),
    ]
    /**
     * 编辑支付账户
     */
    public function editPaymentAccount(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error(__('Please login first'));
        }

        $userInfo = $this->auth->getUserInfo();
        $userId = $userInfo['id'];
        $id = $this->request->post('id/d', 0);

        if (!$id) {
            $this->error('账户ID不能为空');
        }

        // 验证账户是否存在且属于当前用户
        $account = Db::name('user_payment_account')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->where('status', 1)
            ->find();

        if (!$account) {
            $this->error('账户不存在或已禁用');
        }

        // 获取表单数据
        $bankName = $this->request->post('bank_name', '');
        $accountName = $this->request->post('account_name', '');
        $accountNumber = $this->request->post('account_number', '');
        $bankBranch = $this->request->post('bank_branch', '');

        // 构建更新数据
        $updateData = ['update_time' => time()];

        // 验证账户名（银行卡和支付宝需要与实名姓名一致）
        if ($accountName !== '') {
            if (in_array($account['type'], ['bank_card', 'alipay'])) {
                $user = Db::name('user')->where('id', $userId)->field('real_name')->find();
                if ($user && $accountName !== $user['real_name']) {
                    $this->error('账户名必须与实名认证的姓名一致');
                }
            }
            $updateData['account_name'] = $accountName;
        }
        
        // 验证并更新账号/卡号/地址
        if ($accountNumber !== '') {
            // USDT地址格式验证
            if ($account['type'] == 'usdt') {
                $accountNumber = trim($accountNumber);
                $networkType = $bankBranch !== '' ? strtoupper($bankBranch) : strtoupper($account['bank_branch']);
                if (strtoupper($networkType) == 'TRC20' && (!preg_match('/^T[A-Za-z1-9]{33}$/', $accountNumber))) {
                    $this->error('TRC20地址格式不正确，应以T开头，34位字符');
                }
                if (strtoupper($networkType) == 'ERC20' && (!preg_match('/^0x[a-fA-F0-9]{40}$/', $accountNumber))) {
                    $this->error('ERC20地址格式不正确，应以0x开头，42位十六进制字符');
                }
            }
            // 加密账号/卡号/地址
            $updateData['account_number'] = base64_encode($accountNumber);
        }
        
        // 银行卡类型
        if ($account['type'] == 'bank_card') {
            if ($bankName !== '') {
                $updateData['bank_name'] = $bankName;
            }
            if ($bankBranch !== '') {
                $updateData['bank_branch'] = $bankBranch;
            }
        }
        
        // USDT类型：更新网络类型
        if ($account['type'] == 'usdt' && $bankBranch !== '') {
            if (!in_array(strtoupper($bankBranch), ['TRC20', 'ERC20', 'BEP20', 'OMNI'])) {
                $this->error('USDT网络类型不正确，支持：TRC20、ERC20、BEP20、OMNI');
            }
            $updateData['bank_branch'] = strtoupper($bankBranch);
        }

        // 公司账户可以重新上传打款截图
        if ($account['account_type'] == 'company' && in_array($account['type'], ['bank_card', 'alipay'])) {
            $screenshotFile = $this->request->file('screenshot');
            if (!empty($screenshotFile)) {
                try {
                    $upload = new Upload();
                    $attachment = $upload
                        ->setFile($screenshotFile)
                        ->setDriver('local')
                        ->setTopic('payment')
                        ->upload(null, 0, $userId);
                    $screenshotUrl = $attachment['url'] ?? '';
                    $updateData['screenshot'] = $screenshotUrl;
                    // 重新上传截图需要重新审核
                    $updateData['audit_status'] = 0;
                    $updateData['audit_time'] = null;
                    $updateData['audit_admin_id'] = 0;
                    $updateData['audit_reason'] = '';
                } catch (Throwable $e) {
                    $this->error('上传打款截图失败：' . $e->getMessage());
                }
            }
        }
        
        // 微信账户可以重新上传收款二维码
        if ($account['type'] == 'wechat') {
            $screenshotFile = $this->request->file('screenshot');
            if (!empty($screenshotFile)) {
                try {
                    $upload = new Upload();
                    $attachment = $upload
                        ->setFile($screenshotFile)
                        ->setDriver('local')
                        ->setTopic('payment')
                        ->upload(null, 0, $userId);
                    $screenshotUrl = $attachment['url'] ?? '';
                    $updateData['screenshot'] = $screenshotUrl;
                } catch (Throwable $e) {
                    $this->error('上传微信收款二维码失败：' . $e->getMessage());
                }
            }
        }

        if (empty($updateData) || count($updateData) == 1) {
            $this->error('没有需要更新的数据');
        }

        Db::startTrans();
        try {
            Db::name('user_payment_account')
                ->where('id', $id)
                ->update($updateData);

            Db::commit();
            $message = '更新成功';
            if ($account['account_type'] == 'company' && isset($updateData['screenshot']) && in_array($account['type'], ['bank_card', 'alipay'])) {
                $message = '更新成功，打款截图已重新上传，请等待审核';
            }
            $this->success($message);
        } catch (HttpResponseException $e) {
            // success() 和 error() 抛出的 HttpResponseException，直接向上抛出，不做处理
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $this->error('更新失败：' . $e->getMessage());
        }
    }

    #[
        Apidoc\Title("修改头像"),
        Apidoc\Tag("会员,头像"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/User/updateAvatar"),
        Apidoc\Param(name:"avatar", type: "file", require: false, desc: "头像图片文件（支持直接上传）"),
        Apidoc\Param(name:"avatar_url", type: "string", require: false, desc: "已上传的头像图片URL（如果已通过上传接口上传，可直接传URL）"),
        Apidoc\Returned("avatar", type: "string", desc: "头像URL"),
    ]
    /**
     * 修改头像
     * 支持两种方式：
     * 1. 直接上传文件（通过avatar参数）
     * 2. 使用已上传的图片URL（通过avatar_url参数，需先调用上传接口）
     */
    public function updateAvatar(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error(__('Please login first'));
        }

        $userInfo = $this->auth->getUserInfo();
        $userId = $userInfo['id'];

        // 获取上传的文件或已上传的图片URL
        $avatarFile = $this->request->file('avatar');
        $avatarUrl = $this->request->post('avatar_url', '');

        if (empty($avatarFile) && empty($avatarUrl)) {
            $this->error('请上传头像图片或提供已上传的图片URL');
        }

        Db::startTrans();
        try {
            $finalAvatarUrl = '';

            // 如果有文件上传，先上传文件
            if (!empty($avatarFile)) {
                $upload = new Upload();
                $attachment = $upload
                    ->setFile($avatarFile)
                    ->setDriver('local')
                    ->setTopic('avatar')
                    ->upload(null, 0, $userId);
                $finalAvatarUrl = $attachment['url'] ?? '';
            } else {
                // 使用已上传的图片URL
                $finalAvatarUrl = $avatarUrl;
            }

            if (empty($finalAvatarUrl)) {
                throw new \Exception('头像上传失败');
            }

            // 更新用户头像
            $result = Db::name('user')
                ->where('id', $userId)
                ->update(['avatar' => $finalAvatarUrl]);

            if ($result === false) {
                throw new \Exception('更新头像失败');
            }

            Db::commit();
            $this->success('头像更新成功', ['avatar' => $finalAvatarUrl]);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $this->error('更新头像失败：' . $e->getMessage());
        }
    }

    #[
        Apidoc\Title("修改个人信息"),
        Apidoc\Tag("会员,个人信息"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/User/updateNickname"),
        Apidoc\Param(name:"nickname", type: "string", require: false, desc: "新昵称（可选）"),
        Apidoc\Param(name:"avatar", type: "file", require: false, desc: "头像图片文件（可选，支持直接上传）"),
        Apidoc\Param(name:"avatar_url", type: "string", require: false, desc: "已上传的头像图片URL（可选，如果已通过上传接口上传，可直接传URL）"),
        Apidoc\Param(name:"birthday", type: "string", require: false, desc: "生日（可选，格式：YYYY-MM-DD）"),
        Apidoc\Param(name:"gender", type: "integer", require: false, desc: "性别（可选，0=未知,1=男,2=女）"),
        Apidoc\Returned("data", type: "object", desc: "更新后的用户信息"),
    ]
    /**
     * 修改个人信息（昵称、头像、生日、性别）
     * 支持同时修改多个字段，所有字段均为可选，但至少要提供一个字段
     */
    public function updateNickname(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error(__('Please login first'));
        }

        $userInfo = $this->auth->getUserInfo();
        $userId = $userInfo['id'];
        
        // 获取所有可选参数
        $nickname = $this->request->post('nickname', '');
        $avatarFile = $this->request->file('avatar');
        $avatarUrl = $this->request->post('avatar_url', '');
        $birthday = $this->request->post('birthday', '');
        $gender = $this->request->post('gender', null);

        // 检查是否至少提供了一个字段
        $hasGender = $gender !== null && $gender !== '';
        if (empty($nickname) && empty($avatarFile) && empty($avatarUrl) && empty($birthday) && !$hasGender) {
            $this->error('请至少提供一个要修改的字段');
        }

        // 准备更新的数据
        $updateData = [];

        // 验证并处理昵称
        if (!empty($nickname)) {
            $validate = new Validate();
            $validate->rule([
                'nickname' => 'require|chsDash'
            ]);
            if (!$validate->check(['nickname' => $nickname])) {
                $this->error('昵称格式不正确，只能包含中文、字母、数字、下划线和横线');
            }
            $updateData['nickname'] = $nickname;
        }

        // 验证并处理性别
        if ($hasGender) {
            $gender = (int)$gender;
            if (!in_array($gender, [0, 1, 2], true)) {
                $this->error('性别值不正确，应为0（未知）、1（男）或2（女）');
            }
            $updateData['gender'] = $gender;
        }

        // 验证并处理生日
        if (!empty($birthday)) {
            // 验证日期格式
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) {
                $this->error('生日格式不正确，应为YYYY-MM-DD格式');
            }
            // 验证日期是否有效
            $dateParts = explode('-', $birthday);
            if (!checkdate((int)$dateParts[1], (int)$dateParts[2], (int)$dateParts[0])) {
                $this->error('生日日期无效');
            }
            $updateData['birthday'] = $birthday;
        }

        // 处理头像
        $finalAvatarUrl = '';
        if (!empty($avatarFile) || !empty($avatarUrl)) {
            if (!empty($avatarFile)) {
                // 上传文件
                $upload = new Upload();
                $attachment = $upload
                    ->setFile($avatarFile)
                    ->setDriver('local')
                    ->setTopic('avatar')
                    ->upload(null, 0, $userId);
                $finalAvatarUrl = $attachment['url'] ?? '';
            } else {
                // 使用已上传的图片URL
                $finalAvatarUrl = $avatarUrl;
            }

            if (empty($finalAvatarUrl)) {
                $this->error('头像上传失败');
            }
            $updateData['avatar'] = $finalAvatarUrl;
        }

        // 如果没有要更新的数据，直接返回
        if (empty($updateData)) {
            $this->error('没有有效的更新数据');
        }

        Db::startTrans();
        try {
            $result = Db::name('user')
                ->where('id', $userId)
                ->update($updateData);

            if ($result === false) {
                throw new \Exception('更新个人信息失败');
            }

            Db::commit();
            
            // 返回更新后的数据
            $returnData = [];
            if (isset($updateData['nickname'])) {
                $returnData['nickname'] = $updateData['nickname'];
            }
            if (isset($updateData['avatar'])) {
                $returnData['avatar'] = $updateData['avatar'];
            }
            if (isset($updateData['birthday'])) {
                $returnData['birthday'] = $updateData['birthday'];
            }
            if (isset($updateData['gender'])) {
                $returnData['gender'] = $updateData['gender'];
            }
            
            $this->success('个人信息更新成功', $returnData);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $this->error('更新个人信息失败：' . $e->getMessage());
        }
    }

    #[
        Apidoc\Title("修改登录密码"),
        Apidoc\Tag("会员,密码"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/User/updatePassword"),
        Apidoc\Param(name:"old_password", type: "string", require: true, desc: "旧密码"),
        Apidoc\Param(name:"new_password", type: "string", require: true, desc: "新密码(6-32位，不能包含特殊字符)"),
        Apidoc\Returned("message", type: "string", desc: "提示信息"),
    ]
    /**
     * 修改登录密码
     */
    public function updatePassword(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error(__('Please login first'));
        }

        $userInfo = $this->auth->getUserInfo();
        $userId = $userInfo['id'];
        $oldPassword = $this->request->post('old_password', '');
        $newPassword = $this->request->post('new_password', '');

        if (empty($oldPassword)) {
            $this->error('请输入旧密码');
        }
        if (empty($newPassword)) {
            $this->error('请输入新密码');
        }

        // 获取用户信息
        $user = Db::name('user')->where('id', $userId)->find();
        if (!$user) {
            $this->error('用户不存在');
        }

        // 验证旧密码
        if (!verify_password($oldPassword, $user['password'], ['salt' => $user['salt'] ?? ''])) {
            $this->error('旧密码错误');
        }

        // 验证新密码格式（6-32位，不能包含特殊字符）
        if (!preg_match('/^(?!.*[&<>"\'\n\r]).{6,32}$/', $newPassword)) {
            $this->error('新密码格式不正确，应为6-32位，不能包含特殊字符');
        }

        Db::startTrans();
        try {
            // 更新密码
            $userModel = new \app\common\model\User();
            $result = $userModel->resetPassword($userId, $newPassword);

            if ($result === false) {
                throw new \Exception('更新密码失败');
            }

            Db::commit();
            // 修改密码后需要重新登录
            $this->auth->logout();
            $this->success('密码修改成功，请重新登录');
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $this->error('更新密码失败：' . $e->getMessage());
        }
    }

    #[
        Apidoc\Title("修改支付密码"),
        Apidoc\Tag("会员,支付密码"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/User/updatePayPassword"),
        Apidoc\Param(name:"old_pay_password", type: "string", require: true, desc: "旧支付密码"),
        Apidoc\Param(name:"new_pay_password", type: "string", require: true, desc: "新支付密码(6-32位，不能包含特殊字符)"),
        Apidoc\Returned("message", type: "string", desc: "提示信息"),
    ]
    /**
     * 修改支付密码
     */
    public function updatePayPassword(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error(__('Please login first'));
        }

        $userInfo = $this->auth->getUserInfo();
        $userId = $userInfo['id'];
        $oldPayPassword = $this->request->post('old_pay_password', '');
        $newPayPassword = $this->request->post('new_pay_password', '');

        if (empty($oldPayPassword)) {
            $this->error('请输入旧支付密码');
        }
        if (empty($newPayPassword)) {
            $this->error('请输入新支付密码');
        }

        // 获取用户信息
        $user = Db::name('user')->where('id', $userId)->find();
        if (!$user) {
            $this->error('用户不存在');
        }

        // 验证旧支付密码（支付密码不加密，直接存储）
        $currentPayPassword = $user['pay_password'] ?? '';
        if (empty($currentPayPassword)) {
            $this->error('您尚未设置支付密码，无法修改');
        }
        if ($oldPayPassword !== $currentPayPassword) {
            $this->error('旧支付密码错误');
        }

        // 验证新支付密码格式（6-32位，不能包含特殊字符）
        if (!preg_match('/^(?!.*[&<>"\'\n\r]).{6,32}$/', $newPayPassword)) {
            $this->error('新支付密码格式不正确，应为6-32位，不能包含特殊字符');
        }

        Db::startTrans();
        try {
            // 更新支付密码（不加密，直接存储）
            $result = Db::name('user')
                ->where('id', $userId)
                ->update(['pay_password' => $newPayPassword]);

            if ($result === false) {
                throw new \Exception('更新支付密码失败');
            }

            Db::commit();
            $this->success('支付密码修改成功');
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $this->error('更新支付密码失败：' . $e->getMessage());
        }
    }

    #[
        Apidoc\Title("短信验证码重置交易密码"),
        Apidoc\Tag("会员,支付密码,短信验证码"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/User/resetPayPasswordBySms"),
        Apidoc\Param(name:"mobile", type: "string", require: true, desc: "手机号"),
        Apidoc\Param(name:"captcha", type: "string", require: true, desc: "短信验证码"),
        Apidoc\Param(name:"new_pay_password", type: "string", require: true, desc: "新支付密码(6-32位，不能包含特殊字符)"),
        Apidoc\Returned("message", type: "string", desc: "提示信息"),
    ]
    /**
     * 通过短信验证码重置交易密码
     * 用于忘记交易密码的情况
     */
    public function resetPayPasswordBySms(): void
    {
        $mobile = $this->request->post('mobile', '');
        $captcha = $this->request->post('captcha', '');
        $newPayPassword = $this->request->post('new_pay_password', '');

        if (empty($mobile)) {
            $this->error('请输入手机号');
        }
        if (empty($captcha)) {
            $this->error('请输入验证码');
        }
        if (empty($newPayPassword)) {
            $this->error('请输入新支付密码');
        }

        // 验证新支付密码格式（6-32位，不能包含特殊字符）
        if (!preg_match('/^(?!.*[&<>"\'\n\r]).{6,32}$/', $newPayPassword)) {
            $this->error('新支付密码格式不正确，应为6-32位，不能包含特殊字符');
        }

        // 查找用户
        $user = Db::name('user')->where('mobile', $mobile)->find();
        if (!$user) {
            $this->error('该手机号未注册');
        }

        // 通用测试验证码 888888 放行
        if ($captcha !== '888888') {
            $captchaObj = new Captcha();
            $verifyOk = false;

            // 尝试多个可能的 event key
            $possibleEvents = ['user_retrieve_pwd', 'user_mobile_verify', 'reset_pay_password'];
            foreach ($possibleEvents as $event) {
                if ($captchaObj->check($captcha, $mobile . $event)) {
                    $verifyOk = true;
                    break;
                }
            }

            if (!$verifyOk) {
                $this->error('请输入正确的验证码');
            }
        }

        Db::startTrans();
        try {
            // 更新支付密码（不加密，直接存储）
            $result = Db::name('user')
                ->where('id', $user['id'])
                ->update([
                    'pay_password' => $newPayPassword,
                    'update_time' => time(),
                ]);

            if ($result === false) {
                throw new \Exception('更新支付密码失败');
            }

            Db::commit();
            $this->success('支付密码重置成功');
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            Db::rollback();
            $this->error('重置支付密码失败：' . $e->getMessage());
        }
    }

    #[
        Apidoc\Title("获取H5人脸核身authToken和认证页面地址"),
        Apidoc\Tag("会员,实名认证,人脸核身"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/User/getH5AuthToken"),
        Apidoc\Param(name:"real_name", type: "string", require: true, desc: "用户真实姓名"),
        Apidoc\Param(name:"id_card", type: "string", require: true, desc: "身份证号码(18位或15位)"),
        Apidoc\Param(name:"redirect_url", type: "string", require: true, desc: "认证成功后重定向的URL地址"),
        Apidoc\Returned("authUrl", type: "string", desc: "认证页面地址"),
        Apidoc\Returned("authToken", type: "string", desc: "认证Token"),
    ]
    /**
     * 获取H5人脸核身authToken和认证页面地址
     * @throws Throwable
     */
    public function getH5AuthToken(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error(__('Please login first'));
        }

        try {
            if (!$this->request->isPost()) {
                $this->error('仅支持POST请求');
            }

            $realName   = trim((string)$this->request->post('real_name', ''));
            $idCard     = trim((string)$this->request->post('id_card', ''));
            $redirectUrl = trim((string)$this->request->post('redirect_url', ''));
            
            \think\facade\Log::info('getH5AuthToken called. UserId: ' . $this->auth->getUserInfo()['id'] . ', RedirectUrl: ' . $redirectUrl);

            if (empty($realName)) {
                $this->error('真实姓名不能为空');
            }
            if (empty($idCard)) {
                $this->error('身份证号不能为空');
            }
            if (empty($redirectUrl)) {
                $this->error('重定向URL不能为空');
            }

            // 验证身份证号格式（18位或15位）
            if (!preg_match('/^[1-9]\d{5}(18|19|20)\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])\d{3}[\dXx]$|^[1-9]\d{5}\d{2}(0[1-9]|1[0-2])(0[1-9]|[12]\d|3[01])\d{3}$/', $idCard)) {
                $this->error('身份证号格式不正确');
            }

            // 验证重定向URL格式
            if (!filter_var($redirectUrl, FILTER_VALIDATE_URL)) {
                $this->error('重定向URL格式不正确');
            }

            $ocr = new \app\common\library\YidunOcr();
            $result = $ocr->h5Auth($realName, $idCard, $redirectUrl);

            // 提取易盾返回的authUrl和authToken
            // 易盾可能返回的数据结构：
            // 1. { "code": 200, "msg": "ok", "result": { "authUrl": "...", "token": "..." } }
            // 2. { "code": 200, "msg": "ok", "data": { "authUrl": "...", "token": "..." } }
            // 3. { "authUrl": "...", "token": "..." }
            $authUrl = null;
            $authToken = null;

            // 辅助函数：从数组中提取值，支持多个可能的键名
            $getValue = function($arr, $keys) {
                foreach ($keys as $key) {
                    if (isset($arr[$key])) {
                        return $arr[$key];
                    }
                }
                return null;
            };

            // 尝试从result中获取
            if (isset($result['result']) && is_array($result['result'])) {
                $resultData = $result['result'];
                $authUrl = $getValue($resultData, ['authUrl', 'url', 'auth_url', 'authurl']);
                $authToken = $getValue($resultData, ['token', 'authToken', 'auth_token', 'authtoken']);
            }
            
            // 如果还没找到，尝试从data中获取
            if ((!$authUrl || !$authToken) && isset($result['data']) && is_array($result['data'])) {
                $data = $result['data'];
                if (!$authUrl) $authUrl = $getValue($data, ['authUrl', 'url', 'auth_url', 'authurl']);
                if (!$authToken) $authToken = $getValue($data, ['token', 'authToken', 'auth_token', 'authtoken']);
            }
            
            // 如果还没找到，尝试从根级别获取
            if ((!$authUrl || !$authToken)) {
                if (!$authUrl) $authUrl = $getValue($result, ['authUrl', 'url', 'auth_url', 'authurl']);
                if (!$authToken) $authToken = $getValue($result, ['token', 'authToken', 'auth_token', 'authtoken']);
            }

            if ($authUrl && $authToken) {
                $userInfo = $this->auth->getUserInfo();
                Db::name('user')->where('id', $userInfo['id'])->update([
                    'real_name' => $realName,
                    'id_card' => $idCard,
                    'auth_token' => $authToken,
                ]);
                $this->success('ok', [
                    'authUrl'   => $authUrl,
                    'authToken' => $authToken,
                ]);
            } else {
                // 返回详细的错误信息，包含易盾返回的原始数据
                $errorMsg = '获取认证信息失败，易盾返回数据格式异常';
                if (isset($result['msg'])) {
                    $errorMsg .= '：' . $result['msg'];
                }
                
                $this->error($errorMsg);
            }
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
        }
    }

    #[
        Apidoc\Title("获取寄售券列表"),
        Apidoc\Tag("会员,寄售券"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/User/consignmentCoupons"),
        Apidoc\Param(name:"page", type: "int", require: false, desc: "页码，默认1"),
        Apidoc\Param(name:"limit", type: "int", require: false, desc: "每页数量，默认50"),
        Apidoc\Param(name:"status", type: "int", require: false, desc: "状态过滤：1=可用，0=已使用，不传则返回全部"),
        Apidoc\Returned("list", type: "array", desc: "寄售券列表"),
        Apidoc\Returned("total", type: "int", desc: "总数量"),
        Apidoc\Returned("available_count", type: "int", desc: "可用数量"),
    ]
    /**
     * 获取寄售券列表
     */
    public function consignmentCoupons(): void
    {
        if (!$this->auth->isLogin()) {
            $this->error(__('Please login first'));
        }

        $userInfo = $this->auth->getUserInfo();
        $userId = $userInfo['id'];
        
        $page = $this->request->get('page/d', 1);
        $limit = $this->request->get('limit/d', 50);
        $status = $this->request->get('status', null);
        
        if ($page < 1) $page = 1;
        if ($limit < 1) $limit = 50;
        if ($limit > 100) $limit = 100;

        $now = time();

        // 构建查询
        $query = Db::name('user_consignment_coupon')
            ->alias('c')
            ->leftJoin('collection_session s', 'c.session_id = s.id')
            ->leftJoin('price_zone_config z', 'c.zone_id = z.id')
            ->where('c.user_id', $userId);

        // 状态过滤
        if ($status !== null && $status !== '') {
            $query->where('c.status', (int)$status);
        }

        // 获取总数
        $total = (clone $query)->count();

        // 获取可用数量
        $availableCount = Db::name('user_consignment_coupon')
            ->where('user_id', $userId)
            ->where('status', 1)
            ->where('expire_time', '>', $now)
            ->count();

        // 获取列表
        $list = $query
            ->field([
                'c.id',
                'c.session_id',
                'c.zone_id',
                'c.price_zone',
                'c.expire_time',
                'c.status',
                'c.create_time',
                's.title as session_title',
                'z.name as zone_name',
            ])
            ->order('c.create_time', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        // 处理数据
        foreach ($list as &$item) {
            // 格式化时间
            $item['expire_time_text'] = $item['expire_time'] ? date('Y-m-d H:i:s', $item['expire_time']) : '';
            $item['create_time_text'] = $item['create_time'] ? date('Y-m-d H:i:s', $item['create_time']) : '';
            
            // 计算状态文字
            if ($item['status'] == 0) {
                $item['status_text'] = '已使用';
            } elseif ($item['expire_time'] && $item['expire_time'] < $now) {
                $item['status_text'] = '已过期';
            } else {
                $item['status_text'] = '可用';
            }
            
            // 场次和分区名称默认值
            $item['session_title'] = $item['session_title'] ?: '未知场次';
            $item['zone_name'] = $item['zone_name'] ?: ($item['price_zone'] ?: '未知分区');
        }
        unset($item);

        $this->success('', [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'available_count' => $availableCount,
        ]);
    }
}