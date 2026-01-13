<?php

namespace app\common\library;

use Exception;
use GuzzleHttp\Client;

class SmsBao
{
    protected Client $client;

    protected array $statusMap = [
        '0'  => '短信发送成功',
        '-1' => '参数不全',
        '-2' => '服务器空间不支持,请确认支持curl或者fsocket，联系您的空间商解决或者更换空间！',
        '30' => '密码错误',
        '40' => '账号不存在',
        '41' => '余额不足',
        '42' => '帐户已过期',
        '43' => 'IP地址限制',
        '50' => '内容含有敏感词',
        '51' => '手机号码不正确',
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

    public function send(string $phone, string $content, ?string $goodsId = null): array
    {
        if (empty($phone)) {
            throw new Exception('手机号不能为空');
        }
        if (empty($content)) {
            throw new Exception('短信内容不能为空');
        }

        $config = $this->getConfig();
        $apiUrl = trim($config['apiUrl']);
        if (empty($apiUrl)) {
            throw new Exception('短信发送接口地址未配置');
        }
        
        if (strpos($apiUrl, '?') !== false) {
            $url = $apiUrl . '&u=' . urlencode($config['username']) . '&p=' . urlencode($config['apiKey']) . '&m=' . urlencode($phone) . '&c=' . urlencode($content);
        } else {
            $url = $apiUrl . '?u=' . urlencode($config['username']) . '&p=' . urlencode($config['apiKey']) . '&m=' . urlencode($phone) . '&c=' . urlencode($content);
        }

        if ($goodsId !== null && $goodsId !== '') {
            $url .= '&g=' . urlencode($goodsId);
        }

        error_log('SmsBao Send URL: ' . $url);

        $response = $this->client->get($url);
        $statusCode = $response->getStatusCode();
        $body = trim((string)$response->getBody());

        error_log('SmsBao Send Status Code: ' . $statusCode);
        error_log('SmsBao Send Response: ' . $body);

        if ($statusCode !== 200) {
            throw new Exception('HTTP请求失败，状态码: ' . $statusCode);
        }

        if (strpos($body, '<!DOCTYPE') !== false || strpos($body, '<html') !== false) {
            throw new Exception('接口返回HTML错误页面，请检查API地址配置是否正确');
        }

        $result = $body;
        $status = $this->statusMap[$result] ?? '未知错误: ' . $result;

        if ($result === '0') {
            return [
                'code' => 0,
                'msg'  => $status,
                'data' => ['status' => $result],
            ];
        }

        return [
            'code' => (int)$result,
            'msg'  => $status,
            'data' => ['status' => $result],
        ];
    }

    public function queryBalance(): array
    {
        $config = $this->getConfig();
        $queryUrl = trim($config['queryUrl']);
        if (empty($queryUrl)) {
            throw new Exception('余额查询接口地址未配置');
        }
        
        $url = $queryUrl . '?u=' . urlencode($config['username']) . '&p=' . urlencode($config['apiKey']);

        error_log('SmsBao Query URL: ' . $url);

        $response = $this->client->get($url);
        $statusCode = $response->getStatusCode();
        $body = trim((string)$response->getBody());
        
        error_log('SmsBao Query Status Code: ' . $statusCode);
        error_log('SmsBao Query Response: ' . $body);

        if ($statusCode !== 200) {
            throw new Exception('HTTP请求失败，状态码: ' . $statusCode);
        }

        if (strpos($body, '<!DOCTYPE') !== false || strpos($body, '<html') !== false) {
            throw new Exception('接口返回HTML错误页面，请检查配置是否正确');
        }

        $lines = explode("\n", $body);

        if (empty($lines) || $lines[0] !== '0') {
            $status = $this->statusMap[$lines[0] ?? '-1'] ?? '查询失败: ' . ($lines[0] ?? '未知错误');
            return [
                'code' => (int)($lines[0] ?? -1),
                'msg'  => $status,
                'data' => null,
            ];
        }

        $balanceInfo = isset($lines[1]) ? explode(',', trim($lines[1])) : [];
        return [
            'code' => 0,
            'msg'  => '查询成功',
            'data' => [
                'sent_count'   => isset($balanceInfo[0]) ? (int)$balanceInfo[0] : 0,
                'remain_count' => isset($balanceInfo[1]) ? (int)$balanceInfo[1] : 0,
            ],
        ];
    }

    protected function getConfig(): array
    {
        $username = (string)get_sys_config('smsbao_username', '');
        $apiKey   = (string)get_sys_config('smsbao_api_key', '');
        $apiUrl   = (string)get_sys_config('smsbao_api_url', '');
        $queryUrl = (string)get_sys_config('smsbao_query_url', '');

        if ($username === '' || $apiKey === '') {
            throw new Exception('短信宝配置不完整：username或api_key未配置');
        }
        if ($apiUrl === '') {
            throw new Exception('短信宝配置不完整：api_url未配置');
        }
        if ($queryUrl === '') {
            throw new Exception('短信宝配置不完整：query_url未配置');
        }

        return [
            'username' => $username,
            'apiKey'   => $apiKey,
            'apiUrl'   => $apiUrl,
            'queryUrl' => $queryUrl,
        ];
    }
}

