<?php

namespace app\api\controller;

use Throwable;
use ba\Captcha;
use ba\ClickCaptcha;
use think\facade\Validate;
use app\common\model\User;
use app\common\library\SmsSender;
use app\common\controller\Frontend;

use hg\apidoc\annotation as Apidoc;

class Sms extends Frontend
{
    protected array $noNeedLogin = ['send'];

    #[
        Apidoc\Title("发送短信验证码"),
        Apidoc\Tag("公共,短信"),
        Apidoc\Method("POST"),
        Apidoc\Url("/api/Sms/send"),
        Apidoc\Param(name: "mobile", type: "string", require: true, desc: "手机号", example: "13800138000"),
        Apidoc\Param(name: "event", type: "string", require: true, desc: "事件 user_register/user_retrieve_pwd/user_mobile_verify", example: "user_register"),
        Apidoc\Returned("msg", type: "string", desc: "提示信息", mock: "SMS sent successfully~")
    ]
    /**
     * 发送短信验证码
     * event：user_register|user_retrieve_pwd|user_mobile_verify
     * @throws Throwable
     */
    public function send(): void
    {
        $params = $this->request->post(['mobile', 'event']);

        $validate = Validate::rule([
            'mobile' => 'require|mobile',
            'event'  => 'require',
        ])->message([
            'mobile' => 'Mobile can not be empty',
            'event'  => 'Parameter error',
        ]);
        if (!$validate->check($params)) {
            $this->error(__($validate->getError()));
        }

        // 频率限制：60 秒内同一事件同一手机号只允许发送一次
        $captchaObj = new Captcha();
        $captcha    = $captchaObj->getCaptchaData($params['mobile'] . $params['event']);
        if ($captcha && time() - $captcha['create_time'] < 60) {
            $this->error('短信发送过于频繁');
        }

        // 事件场景校验
        $userInfo = User::where('mobile', $params['mobile'])->find();
        if ($params['event'] === 'user_register' && $userInfo) {
            $this->error(__('Mobile has been registered, please log in directly'));
        } elseif (in_array($params['event'], ['user_retrieve_pwd', 'user_mobile_verify']) && !$userInfo) {
            $this->error(__('Mobile not registered'));
        }

        // 生成验证码（限定为4位纯数字）
        $captchaObj->codeSet = '0123456789';
        $captchaObj->length  = 4;
        $code    = $captchaObj->create($params['mobile'] . $params['event']);
        $platform = (string)get_sys_config('sms_platform', 'smsbao');
        $template = (string)get_sys_config('sms_template_' . $platform, '');
        if ($template === '') {
            $this->error(__('SMS template not configured'));
        }

        // 替换占位符
        if (strpos($template, '%s') !== false) {
            $content = sprintf($template, $code);
        } elseif (strpos($template, '{code}') !== false) {
            $content = str_replace('{code}', $code, $template);
        } else {
            $content = str_replace('1234', $code, $template);
        }

        $sender = new SmsSender();
        $result = $sender->send($params['mobile'], $content);

        // 精简返回，仅保留平台与上游返回
        $responseData = [
            'platform'          => $platform,
            'provider_response' => $result,
        ];

        if (($result['code'] ?? 1) === 0) {
            $this->success($result['msg'] ?? __('SMS sent successfully~'), $responseData);
        }

        $this->error($result['msg'] ?? 'SMS send failed', $responseData, $result['code'] ?? 0);
    }
}

