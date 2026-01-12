<?php

namespace app\api\validate;

use think\Validate;
use think\facade\Config;

class User extends Validate
{
    protected $failException = true;

    protected $rule = [
        'username'     => 'require|regex:^[a-zA-Z][a-zA-Z0-9_]{2,15}$|unique:user',
        'password'     => 'require|regex:^(?!.*[&<>"\'\n\r]).{6,32}$',
        'pay_password' => 'require|regex:^(?!.*[&<>"\'\n\r]).{6,32}$',
        'invite_code'  => 'require|length:1,32',
        'registerType' => 'require|in:email,mobile',
        'email'        => 'email|unique:user|requireIf:registerType,email',
        'mobile'       => 'mobile|unique:user|requireIf:registerType,mobile',
        // 注册邮箱或手机验证码
        'captcha'      => 'require',
        // 登录点选验证码
        'captchaId'    => 'require',
        'captchaInfo'  => 'require',
        // 登录凭据验证（至少要有一个）
        'login_credential' => 'requireLoginCredential',
    ];

    /**
     * 验证场景
     */
    protected $scene = [
        // 注册场景：手机号、密码、支付密码（邀请码根据配置动态添加）
        'register'  => ['mobile', 'password', 'pay_password', 'captcha'],
        // 手机号 + 短信验证码登录场景：只需要手机号和验证码
        'sms_login' => ['mobile', 'captcha'],
    ];

    /**
     * 短信登录验证场景（去掉 mobile 的唯一性、registerType 限制）
     */
    public function sceneSmsLogin(): User
    {
        return $this->only(['mobile', 'captcha'])
            ->remove('mobile', ['unique', 'requireIf']);
    }

    /**
     * 注册验证场景（动态添加邀请码验证）
     */
    public function sceneRegister(): User
    {
        $fields = ['mobile', 'password', 'pay_password', 'captcha'];
        
        // 检查邀请码注册开关
        $inviteCodeRegister = \think\facade\Db::name('config')->where('name', 'invite_code_register')->value('value');
        if ($inviteCodeRegister == '1') {
            $fields[] = 'invite_code';
        }
        
        return $this->only($fields);
    }

    /**
     * 登录验证场景
     */
    public function sceneLogin(): User
    {
        $fields = ['password', 'login_credential'];

        // 支持多种登录方式：用户名、手机号、邮箱
        $loginFields = ['username', 'mobile', 'email'];
        $fields = array_merge($fields, $loginFields);

  

        // 只验证存在的字段，并移除一些不需要的验证规则
        return $this->only($fields)
            ->remove('username', ['require', 'regex', 'unique'])
            ->remove('mobile', ['require', 'unique'])
            ->remove('email', ['require', 'unique']);
    }

    public function __construct()
    {
        $this->field   = [
            'username'     => __('Username'),
            'email'        => __('Email'),
            'mobile'       => __('Mobile'),
            'password'     => __('Password'),
            'pay_password' => __('Pay password'),
            'invite_code'  => __('Invite code'),
            'captcha'      => __('captcha'),
            'captchaId'    => __('captchaId'),
            'captchaInfo'  => __('captcha'),
            'registerType' => __('Register type'),
            'login_credential' => __('Login credential'),
        ];
        $this->message = array_merge($this->message, [
            //改成中文  
            'username.regex' => __('请输入正确的用户名'),
            'password.regex' => __('请输入正确的密码'),
            'pay_password.regex' => __('请输入正确的支付密码'),
        ]);
        parent::__construct();
    }

    /**
     * 自定义验证：登录时至少要提供一种登录凭据（用户名、手机号或邮箱）
     */
    protected function requireLoginCredential($value, $rule, $data, $field)
    {
        if (empty($data['username']) && empty($data['mobile']) && empty($data['email'])) {
            return __('Please provide username, mobile or email');
        }
        return true;
    }
}