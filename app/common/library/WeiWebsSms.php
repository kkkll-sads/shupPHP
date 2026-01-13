<?php

namespace app\common\library;

use Exception;
use GuzzleHttp\Client;

class WeiWebsSms
{
    protected Client $client;

    protected array $statusMap = [
        '0'   => '提交成功',
        '101' => '无此用户',
        '102' => '密码错',
        '103' => '提交过快（提交速度超过流速限制）',
        '104' => '系统忙（因平台侧原因，暂时无法处理提交的短信）',
        '105' => '敏感短信（短信内容包含敏感词）',
        '106' => '消息长度错（>700或<=0）',
        '107' => '包含错误的手机号码',
        '108' => '手机号码个数错（群发>50000或<=0;单发>200或<=0）',
        '109' => '无发送额度（该用户可用短信数已使用完）',
        '110' => '不在发送时间内',
        '111' => '超出该账户当月发送额度限制',
        '112' => '无此产品，用户没有订购该产品',
        '113' => 'extno格式错（非数字或者长度不对）',
        '115' => '自动审核驳回',
        '116' => '签名不合法，未带签名（用户必须带签名的前提下）',
        '117' => 'IP地址认证错,请求调用的IP地址不是系统登记的IP地址',
        '118' => '用户没有相应的发送权限',
        '119' => '用户已过期',
        '120' => '内容不在白名单模板中',
    ];

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client([
            'timeout'         => 15,
            'connect_timeout' => 5,
            'http_errors'     => false,
            'verify'          => false,
        ]);
    }

    public function send(string $mobile, string $msg, bool $needStatus = true, ?string $product = null, ?string $extno = null, bool $useTimestamp = false, bool $jsonResponse = false): array
    {
        if (empty($mobile)) {
            throw new Exception('手机号不能为空');
        }
        if (empty($msg)) {
            throw new Exception('短信内容不能为空');
        }

        $config = $this->getConfig();
        $apiUrl = trim($config['apiUrl']);
        if (empty($apiUrl)) {
            throw new Exception('短信发送接口地址未配置');
        }

        $params = [
            'account' => $config['account'],
            'mobile'  => $mobile,
            'msg'     => $msg,
            'needstatus' => $needStatus ? 'true' : 'false',
        ];

        if ($useTimestamp) {
            $ts = date('YmdHis');
            $params['ts'] = $ts;
            $params['pswd'] = md5($config['account'] . $config['password'] . $ts);
        } else {
            $params['pswd'] = $config['password'];
        }

        if ($product !== null && $product !== '') {
            $params['product'] = $product;
        }
        if ($extno !== null && $extno !== '') {
            $params['extno'] = $extno;
        }
        if ($jsonResponse) {
            $params['resptype'] = 'json';
        }

        $queryString = http_build_query($params);
        $url = $apiUrl . '?' . $queryString;

        error_log('WeiWebsSms Send URL: ' . $url);

        $response = $this->client->get($url);
        $statusCode = $response->getStatusCode();
        $body = trim((string)$response->getBody());

        error_log('WeiWebsSms Send Status Code: ' . $statusCode);
        error_log('WeiWebsSms Send Response: ' . $body);

        if ($statusCode !== 200) {
            throw new Exception('HTTP请求失败，状态码: ' . $statusCode);
        }

        if (strpos($body, '<!DOCTYPE') !== false || strpos($body, '<html') !== false) {
            throw new Exception('接口返回HTML错误页面，请检查API地址配置是否正确');
        }

        if ($jsonResponse) {
            $resultArray = json_decode($body, true);
            if (!is_array($resultArray)) {
                throw new Exception('JSON解析失败: ' . $body);
            }
            $result = (string)($resultArray['result'] ?? '');
            $msgid = $resultArray['msgid'] ?? '';
            $ts = $resultArray['ts'] ?? '';
        } else {
            $lines = explode("\n", $body);
            if (empty($lines)) {
                throw new Exception('响应格式错误');
            }
            $firstLine = trim($lines[0]);
            $parts = explode(',', $firstLine);
            $result = isset($parts[1]) ? trim($parts[1]) : '';
            $msgid = isset($lines[1]) ? trim($lines[1]) : '';
            $ts = isset($parts[0]) ? trim($parts[0]) : '';
        }

        $status = $this->statusMap[$result] ?? '未知错误: ' . $result;

        if ($result === '0') {
            return [
                'code' => 0,
                'msg'  => $status,
                'data' => [
                    'status' => $result,
                    'msgid'  => $msgid,
                    'ts'     => $ts,
                ],
            ];
        }

        return [
            'code' => (int)$result,
            'msg'  => $status,
            'data' => [
                'status' => $result,
                'ts'     => $ts,
            ],
        ];
    }

    protected function getConfig(): array
    {
        $account = (string)get_sys_config('weiwebs_account', '');
        $password = (string)get_sys_config('weiwebs_password', '');
        $apiUrl = (string)get_sys_config('weiwebs_api_url', '');

        if ($account === '' || $password === '') {
            throw new Exception('微网短信配置不完整：account或password未配置');
        }
        if ($apiUrl === '') {
            throw new Exception('微网短信配置不完整：api_url未配置');
        }

        return [
            'account'  => $account,
            'password' => $password,
            'apiUrl'   => $apiUrl,
        ];
    }
}

