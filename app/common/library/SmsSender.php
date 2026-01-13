<?php

namespace app\common\library;

use Exception;
use think\facade\Db;

/**
 * 短信发送统一入口，根据配置的短信平台选择发送实现。
 */
class SmsSender
{
    /**
     * 发送短信
     *
     * @param string $mobile  手机号
     * @param string $content 短信内容
     * @param int $userId 用户ID（可选，0表示系统发送）
     * @return array {code:int,msg:string,data:mixed}
     * @throws Exception
     */
    public function send(string $mobile, string $content, int $userId = 0): array
    {
        $platform = (string)get_sys_config('sms_platform', 'smsbao');
        $now = time();
        
        // 创建日志记录
        $logId = Db::name('sms_log')->insertGetId([
            'user_id' => $userId,
            'mobile' => $mobile,
            'content' => $content,
            'platform' => $platform,
            'status' => 0, // 待发送
            'create_time' => $now,
        ]);

        try {
            switch ($platform) {
                case 'weiwebs':
                    $client = new WeiWebsSms();
                    $result = $client->send($mobile, $content, true, null, null, false, false);
                    break;

                case 'smsbao':
                default:
                    $client = new SmsBao();
                    $result = $client->send($mobile, $content);
                    break;
            }
            
            // 更新日志记录
            $status = ($result['code'] === 0) ? 1 : 2; // 1=成功, 2=失败
            Db::name('sms_log')->where('id', $logId)->update([
                'status' => $status,
                'result_code' => (string)($result['code'] ?? ''),
                'result_msg' => (string)($result['msg'] ?? ''),
                'send_time' => time(),
                'update_time' => time(),
            ]);
            
            return $result;
        } catch (Exception $e) {
            // 记录发送失败
            Db::name('sms_log')->where('id', $logId)->update([
                'status' => 2,
                'result_code' => 'exception',
                'result_msg' => $e->getMessage(),
                'update_time' => time(),
            ]);
            throw $e;
        }
    }
}
