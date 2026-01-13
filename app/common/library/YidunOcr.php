<?php

namespace app\common\library;

use Exception;
use GuzzleHttp\Client;

class YidunOcr
{
    protected Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client([
            'timeout'         => 15,
            'connect_timeout' => 5,
            'http_errors'     => false,
            'verify'          => false,
        ]);
    }

    /**
     * 身份证 OCR 校验
     */
    public function check(int $picType, ?string $frontPicture = null, ?string $backPicture = null, ?string $dataId = null): array
    {
        if (!in_array($picType, [1, 2], true)) {
            throw new Exception('picType 必须为 1 或 2');
        }
        if (($frontPicture === null || $frontPicture === '') && ($backPicture === null || $backPicture === '')) {
            throw new Exception('frontPicture 和 backPicture 至少传一项');
        }

        $config = $this->getConfig();
        $params = [
            'secretId'   => $config['secretId'],
            'businessId' => $config['businessId'],
            'version'    => 'v1',
            'timestamp'  => (int)(microtime(true) * 1000),
            'nonce'      => $this->generateNonce(),
            'picType'    => (string)$picType,
        ];

        if ($dataId !== null && $dataId !== '') {
            $params['dataId'] = $dataId;
        }
        if ($frontPicture !== null && $frontPicture !== '') {
            $params['frontPicture'] = $this->normalizeUrl($frontPicture);
        }
        if ($backPicture !== null && $backPicture !== '') {
            $params['backPicture'] = $this->normalizeUrl($backPicture);
        }

        $params = $this->toUtf8($params);
        $params['signature'] = $this->genSignature($config['secretKey'], $params);

        $apiUrl = $config['apiUrl'];
        if (strpos($apiUrl, 'http://') === 0) {
            $apiUrl = str_replace('http://', 'https://', $apiUrl);
        }

        $response = $this->client->post($apiUrl, [
            'form_params' => $params,
        ]);

        $body        = (string)$response->getBody();
        $resultArray = json_decode($body, true);
        
        if (!is_array($resultArray)) {
            throw new Exception('接口响应异常: ' . $body);
        }

        if (isset($resultArray['result']['status'])) {
            $status = (int)$resultArray['result']['status'];
            $resultArray['result']['statusDesc'] = $this->getOcrStatusDesc($status);
            if ($status !== 1) {
                $resultArray['result']['reasonTypeDesc'] = $this->getOcrStatusDesc($status);
                if ($status === 6) {
                    $resultArray['result']['suggestion'] = '图片下载失败，可能原因：1)易盾服务器无法访问图片URL；2)图片URL需要公网可访问；3)建议使用BASE64方式(picType=2)传输图片内容';
                }
            }
        }

        return $resultArray;
    }

    /**
     * 实人认证
     */
    public function realPersonCheck(string $name, string $cardNo, int $picType, string $avatar, ?string $dataId = null, ?string $encryptType = null): array
    {
        if (!in_array($picType, [1, 2], true)) {
            throw new Exception('picType 必须为 1 或 2');
        }
        if ($name === '' || $cardNo === '' || $avatar === '') {
            throw new Exception('name、cardNo、avatar 不能为空');
        }

        $config = $this->getRealPersonConfig();
        $params = [
            'secretId'   => $config['secretId'],
            'businessId' => $config['businessId'],
            'version'    => 'v1',
            'timestamp'  => (int)(microtime(true) * 1000),
            'nonce'      => $this->generateNonce(),
            'name'       => $name,
            'cardNo'     => $cardNo,
            'picType'    => (string)$picType,
            'avatar'     => $picType === 1 ? $this->normalizeUrl($avatar) : $avatar,
        ];

        if ($dataId !== null && $dataId !== '') {
            $params['dataId'] = $dataId;
        }
        if ($encryptType !== null && $encryptType !== '') {
            $params['encryptType'] = $encryptType;
        }

        $params = $this->toUtf8($params);
        $params['signature'] = $this->genSignature($config['secretKey'], $params);

        $apiUrl = $config['apiUrl'];
        if (strpos($apiUrl, 'http://') === 0) {
            $apiUrl = str_replace('http://', 'https://', $apiUrl);
        }

        $response = $this->client->post($apiUrl, [
            'form_params' => $params,
        ]);

        $body        = (string)$response->getBody();
        $resultArray = json_decode($body, true);

        if (!is_array($resultArray)) {
            throw new Exception('接口响应异常: ' . $body);
        }

        if (isset($resultArray['result']['status'])) {
            $status = (int)$resultArray['result']['status'];
            $resultArray['result']['statusDesc'] = $this->getRealPersonStatusDesc($status);
            if (isset($resultArray['result']['reasonType'])) {
                $reasonType = (int)$resultArray['result']['reasonType'];
                $resultArray['result']['reasonTypeDesc'] = $this->getRealPersonReasonTypeDesc($reasonType);
            }
        }

        return $resultArray;
    }

    /**
     * 交互式人脸核身（活体+实人认证）
     */
    public function livePersonCheck(string $name, string $cardNo, string $token, ?string $needAvatar = null, ?int $picType = null, ?string $dataId = null): array
    {
        if ($name === '' || $cardNo === '' || $token === '') {
            throw new Exception('name、cardNo、token 不能为空');
        }

        $config = $this->getLivePersonConfig();
        $params = [
            'secretId'   => $config['secretId'],
            'businessId' => $config['businessId'],
            'version'    => 'v1',
            'timestamp'  => (int)(microtime(true) * 1000),
            'nonce'      => $this->generateNonce(),
            'name'       => $name,
            'cardNo'     => $cardNo,
            'token'      => $token,
        ];

        if ($needAvatar !== null && $needAvatar !== '') {
            $params['needAvatar'] = $needAvatar;
        }
        if ($picType !== null) {
            if (!in_array($picType, [1, 2], true)) {
                throw new Exception('picType 必须为 1 或 2');
            }
            $params['picType'] = (string)$picType;
        }
        if ($dataId !== null && $dataId !== '') {
            $params['dataId'] = $dataId;
        }

        $params = $this->toUtf8($params);
        $params['signature'] = $this->genSignature($config['secretKey'], $params);

        $apiUrl = $config['apiUrl'];
        if (strpos($apiUrl, 'http://') === 0) {
            $apiUrl = str_replace('http://', 'https://', $apiUrl);
        }

        $response = $this->client->post($apiUrl, [
            'form_params' => $params,
        ]);

        $body        = (string)$response->getBody();
        $resultArray = json_decode($body, true);

        if (!is_array($resultArray)) {
            throw new Exception('接口响应异常: ' . $body);
        }

        if (isset($resultArray['result']['status'])) {
            $status = (int)$resultArray['result']['status'];
            $resultArray['result']['statusDesc'] = $this->getLivePersonStatusDesc($status);
            if (isset($resultArray['result']['reasonType'])) {
                $reasonType = (int)$resultArray['result']['reasonType'];
                $resultArray['result']['reasonTypeDesc'] = $this->getLivePersonReasonTypeDesc($reasonType);
            }
        }

        return $resultArray;
    }

    protected function getConfig(): array
    {
        $businessId = (string)get_sys_config('yidun_ocr_business_id', '');
        $secretId   = (string)get_sys_config('yidun_ocr_secret_id', '');
        $secretKey  = (string)get_sys_config('yidun_ocr_secret_key', '');
        $apiUrl     = (string)get_sys_config('yidun_ocr_api_url', 'http://verify.dun.163.com/v1/ocr/check');

        if ($businessId === '' || $secretId === '' || $secretKey === '') {
            throw new Exception('易盾 OCR 配置不完整');
        }
        if ($apiUrl === '') {
            $apiUrl = 'http://verify.dun.163.com/v1/ocr/check';
        }

        return [
            'businessId' => $businessId,
            'secretId'   => $secretId,
            'secretKey'  => $secretKey,
            'apiUrl'     => $apiUrl,
        ];
    }

    protected function getRealPersonConfig(): array
    {
        $businessId = (string)get_sys_config('yidun_real_person_business_id', '');
        $secretId   = (string)get_sys_config('yidun_ocr_secret_id', '');
        $secretKey  = (string)get_sys_config('yidun_ocr_secret_key', '');
        $apiUrl     = (string)get_sys_config('yidun_real_person_api_url', 'http://verify.dun.163.com/v1/rp/check');

        if ($businessId === '' || $secretId === '' || $secretKey === '') {
            throw new Exception('易盾实人认证配置不完整');
        }
        if ($apiUrl === '') {
            $apiUrl = 'http://verify.dun.163.com/v1/rp/check';
        }

        return [
            'businessId' => $businessId,
            'secretId'   => $secretId,
            'secretKey'  => $secretKey,
            'apiUrl'     => $apiUrl,
        ];
    }

    protected function getLivePersonConfig(): array
    {
        $businessId = (string)get_sys_config('yidun_live_person_business_id', '');
        $secretId   = (string)get_sys_config('yidun_ocr_secret_id', '');
        $secretKey  = (string)get_sys_config('yidun_ocr_secret_key', '');
        $apiUrl     = (string)get_sys_config('yidun_live_person_api_url', 'https://verify.dun.163.com/v1/liveperson/audit');

        if ($businessId === '' || $secretId === '' || $secretKey === '') {
            throw new Exception('易盾交互式人脸核身配置不完整');
        }
        if ($apiUrl === '') {
            $apiUrl = 'https://verify.dun.163.com/v1/liveperson/audit';
        }

        return [
            'businessId' => $businessId,
            'secretId'   => $secretId,
            'secretKey'  => $secretKey,
            'apiUrl'     => $apiUrl,
        ];
    }

    /**
     * 单活体检测
     */
    public function livePersonRecheck(string $token, ?string $needAvatar = null, ?int $picType = null, ?string $dataId = null): array
    {
        if ($token === '') {
            throw new Exception('token 不能为空');
        }

        $config = $this->getLivePersonRecheckConfig();
        $params = [
            'secretId'   => $config['secretId'],
            'businessId' => $config['businessId'],
            'version'    => 'v1',
            'timestamp'  => (int)(microtime(true) * 1000),
            'nonce'      => $this->generateNonce(),
            'token'      => $token,
        ];

        if ($needAvatar !== null && $needAvatar !== '') {
            $params['needAvatar'] = $needAvatar;
        }
        if ($picType !== null) {
            if (!in_array($picType, [1, 2], true)) {
                throw new Exception('picType 必须为 1 或 2');
            }
            $params['picType'] = (string)$picType;
        }
        if ($dataId !== null && $dataId !== '') {
            $params['dataId'] = $dataId;
        }

        $params = $this->toUtf8($params);
        $params['signature'] = $this->genSignature($config['secretKey'], $params);

        $apiUrl = $config['apiUrl'];
        if (strpos($apiUrl, 'http://') === 0) {
            $apiUrl = str_replace('http://', 'https://', $apiUrl);
        }

        $response = $this->client->post($apiUrl, [
            'form_params' => $params,
        ]);

        $body        = (string)$response->getBody();
        $resultArray = json_decode($body, true);

        if (!is_array($resultArray)) {
            throw new Exception('接口响应异常: ' . $body);
        }

        if (isset($resultArray['result']['lpCheckStatus'])) {
            $status = (int)$resultArray['result']['lpCheckStatus'];
            $resultArray['result']['lpCheckStatusDesc'] = $this->getLivePersonRecheckStatusDesc($status);
            if (isset($resultArray['result']['reasonType'])) {
                $reasonType = (int)$resultArray['result']['reasonType'];
                $resultArray['result']['reasonTypeDesc'] = $this->getLivePersonRecheckReasonTypeDesc($reasonType);
            }
        }

        return $resultArray;
    }

    protected function getLivePersonRecheckConfig(): array
    {
        $businessId = (string)get_sys_config('yidun_live_recheck_biz', '');
        $secretId   = (string)get_sys_config('yidun_ocr_secret_id', '');
        $secretKey  = (string)get_sys_config('yidun_ocr_secret_key', '');
        $apiUrl     = (string)get_sys_config('yidun_live_recheck_api', 'https://verify.dun.163.com/v1/liveperson/recheck');

        if ($businessId === '' || $secretId === '' || $secretKey === '') {
            throw new Exception('易盾单活体检测配置不完整');
        }
        if ($apiUrl === '') {
            $apiUrl = 'https://verify.dun.163.com/v1/liveperson/recheck';
        }

        return [
            'businessId' => $businessId,
            'secretId'   => $secretId,
            'secretKey'  => $secretKey,
            'apiUrl'     => $apiUrl,
        ];
    }

    protected function genSignature(string $secretKey, array $params): string
    {
        $signParams = $params;
        unset($signParams['signature']);
        ksort($signParams);
        $buff = '';
        foreach ($signParams as $key => $value) {
            $buff .= $key;
            $buff .= $value;
        }
        $buff .= $secretKey;

        return md5(mb_convert_encoding($buff, 'utf8', 'auto'));
    }

    protected function generateNonce(): string
    {
        return (string)mt_rand(1000000000, 9999999999);
    }

    protected function toUtf8(array $params): array
    {
        foreach ($params as $key => $value) {
            if (is_string($value)) {
                $params[$key] = mb_convert_encoding($value, 'UTF-8', 'auto');
            }
        }
        return $params;
    }

    protected function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $parsed = parse_url($url);
        if ($parsed === false) {
            return $url;
        }
        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';
        $host = $parsed['host'] ?? '';
        $path = $parsed['path'] ?? '';
        $path = preg_replace('#/+#', '/', $path);
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';
        return $scheme . $host . $path . $query . $fragment;
    }

    protected function getOcrStatusDesc(int $status): string
    {
        $statusMap = [
            1 => '识别成功，信息无误',
            2 => '非身份证照片或检测不出身份证信息',
            3 => '识别成功，但身份证号校验不通过，请复查用户证件是否作弊',
            4 => '识别成功，但姓名不合规范，请复查用户证件是否作弊',
            5 => '识别成功，但身份证有效期出现错误，请复查用户证件是否作弊',
            6 => '图片下载失败，请重试',
            7 => '检测异常',
            9 => '识别成功，但性别格式有误，请复查用户证件是否作弊',
            10 => '识别成功，但地址格式有误，请复查用户证件是否作弊',
            11 => '识别成功，但民族格式有误，请复查用户证件是否作弊',
            12 => '识别成功，但签发机关格式有误，请复查用户证件是否作弊',
        ];
        return $statusMap[$status] ?? '未知状态: ' . $status;
    }

    protected function getOcrReasonTypeDesc(int $reasonType): string
    {
        return $this->getOcrStatusDesc($reasonType);
    }

    protected function getRealPersonStatusDesc(int $status): string
    {
        $statusMap = [
            1 => '认证通过',
            2 => '认证不通过',
            0 => '待定',
        ];
        return $statusMap[$status] ?? '未知状态: ' . $status;
    }

    protected function getRealPersonReasonTypeDesc(int $reasonType): string
    {
        $reasonMap = [
            1 => '认证通过',
            2 => '输入姓名和身份证号不一致',
            3 => '查无此身份证',
            7 => '其他错误',
            8 => '人脸比对分数低于默认阈值',
            9 => '库中无此身份证照片',
            10 => '人像照过大',
            11 => '人像照不合规',
        ];
        return $reasonMap[$reasonType] ?? '未知原因: ' . $reasonType;
    }

    protected function getLivePersonStatusDesc(int $status): string
    {
        $statusMap = [
            1 => '通过（活体+姓名身份证号+人脸比对全部通过）',
            2 => '不通过',
            0 => '待定',
        ];
        return $statusMap[$status] ?? '未知状态: ' . $status;
    }

    protected function getLivePersonReasonTypeDesc(int $reasonType): string
    {
        $reasonMap = [
            1 => '全部通过',
            2 => '活体通过，姓名身份证号一致，人脸比对非同一人',
            3 => '活体通过，姓名身份证号不一致',
            4 => '活体不通过',
            5 => '活体检测超时或出现异常',
            6 => '活体通过，查无此身份证',
            7 => '活体通过，库中无此身份证照片',
            8 => '活体通过，人脸照过大',
            9 => '活体通过，权威数据源出现异常',
            10 => '疑似攻击，建议拦截',
            11 => '检测对象为未成年人',
        ];
        return $reasonMap[$reasonType] ?? '未知原因: ' . $reasonType;
    }

    protected function getLivePersonRecheckStatusDesc(int $status): string
    {
        $statusMap = [
            1 => '活体检测通过',
            2 => '活体检测不通过',
            0 => '待定',
        ];
        return $statusMap[$status] ?? '未知状态: ' . $status;
    }

    protected function getLivePersonRecheckReasonTypeDesc(int $reasonType): string
    {
        $reasonMap = [
            1 => '活体检测通过',
            2 => '活体检测不通过',
            3 => '活体检测超时',
            4 => '活体检测异常',
        ];
        return $reasonMap[$reasonType] ?? '未知原因: ' . $reasonType;
    }

    /**
     * H5人脸核身 - 获取authToken和认证页面地址
     */
    public function h5Auth(string $realName, string $idCard, string $redirectUrl): array
    {
        if ($realName === '' || $idCard === '' || $redirectUrl === '') {
            throw new Exception('realName、idCard、redirectUrl 不能为空');
        }

        $config = $this->getH5FaceConfig();
        $params = [
            'secretId'    => $config['secretId'],
            'businessId' => $config['businessId'],
            'version'    => 'v1',
            'timestamp'  => (int)(microtime(true) * 1000),
            'nonce'      => $this->generateNonce(),
            'name'       => $realName,
            'cardNo'     => $idCard,
            'redirectUrl' => $redirectUrl,
            'callBackUrl' => 'https://wap.dfahwk.cn/api/user/submitRealName',
        ];

        $params = $this->toUtf8($params);
        $params['signature'] = $this->genSignature($config['secretKey'], $params);

        $apiUrl = $config['apiUrl'];
        if (strpos($apiUrl, 'http://') === 0) {
            $apiUrl = str_replace('http://', 'https://', $apiUrl);
        }

        $response = $this->client->post($apiUrl, [
            'form_params' => $params,
        ]);

        $body        = (string)$response->getBody();
        $resultArray = json_decode($body, true);

        if (!is_array($resultArray)) {
            throw new Exception('接口响应异常: ' . $body);
        }

        // 检查HTTP状态码
        if ($response->getStatusCode() != 200) {
            $errorMsg = $resultArray['msg'] ?? '易盾接口调用失败，HTTP状态码：' . $response->getStatusCode();
            throw new Exception($errorMsg);
        }

        // 检查易盾返回的错误码
        // 易盾可能返回的code字段：200表示成功，其他值表示失败
        if (isset($resultArray['code'])) {
            if ($resultArray['code'] == 200) {
                // 成功，继续处理
            } elseif ($resultArray['code'] == 0) {
                // code=0 也可能表示成功（取决于易盾文档）
                // 需要检查是否有错误信息
                if (isset($resultArray['msg']) && !empty($resultArray['msg'])) {
                    throw new Exception($resultArray['msg']);
                }
            } else {
                // 其他错误码
                $errorMsg = $resultArray['msg'] ?? '易盾接口调用失败，错误码：' . $resultArray['code'];
                throw new Exception($errorMsg);
            }
        }

        return $resultArray;
    }

    /**
     * H5人脸核身 - authToken校验
     */
    public function h5Recheck(string $authToken): array
    {
        if ($authToken === '') {
            throw new Exception('authToken 不能为空');
        }

        $config = $this->getH5FaceConfig();
        $params = [
            'secretId'   => $config['secretId'],
            'businessId' => $config['businessId'],
            'version'    => 'v1',
            'timestamp'  => (int)(microtime(true) * 1000),
            'nonce'      => $this->generateNonce(),
            'token'      => $authToken,
        ];
        // \think\facade\Log::info('实名回调验证子方法1====>');
        $params = $this->toUtf8($params);
        $params['signature'] = $this->genSignature($config['secretKey'], $params);

        $apiUrl = $config['recheckApiUrl'];
        if (strpos($apiUrl, 'http://') === 0) {
            $apiUrl = str_replace('http://', 'https://', $apiUrl);
        }
        // \think\facade\Log::info('实名回调验证子方法2====>');
        $response = $this->client->post($apiUrl, [
            'form_params' => $params,
        ]);
        // \think\facade\Log::info('实名回调验证子方法3====>');
        $body        = (string)$response->getBody();
        $resultArray = json_decode($body, true);
        // \think\facade\Log::info('实名回调验证子方法4====>'.$body);
        if (!is_array($resultArray)) {
            throw new Exception('接口响应异常: ' . $body);
        }

        // 检查易盾返回的错误
        if (isset($resultArray['code']) && $resultArray['code'] != 200) {
            $errorMsg = $resultArray['msg'] ?? '易盾接口调用失败';
            throw new Exception($errorMsg);
        }

        return $resultArray;
    }

    protected function getH5FaceConfig(): array
    {
        $businessId = (string)get_sys_config('yidun_h5_face_business_id', '');
        $secretId   = (string)get_sys_config('yidun_ocr_secret_id', '');
        $secretKey  = (string)get_sys_config('yidun_ocr_secret_key', '');
        $apiUrl     = (string)get_sys_config('yidun_h5_face_api_url', 'https://verify.dun.163.com/v1/face/liveness/h5/auth');
        $recheckApiUrl = (string)get_sys_config('yidun_h5_face_recheck_api_url', 'https://verify.dun.163.com/v1/face/liveness/h5/recheck');

        if ($businessId === '' || $secretId === '' || $secretKey === '') {
            throw new Exception('易盾H5人脸核身配置不完整');
        }
        if ($apiUrl === '') {
            $apiUrl = 'https://verify.dun.163.com/v1/face/liveness/h5/auth';
        }
        if ($recheckApiUrl === '') {
            $recheckApiUrl = 'https://verify.dun.163.com/v1/face/liveness/h5/recheck';
        }

        return [
            'businessId'    => $businessId,
            'secretId'      => $secretId,
            'secretKey'     => $secretKey,
            'apiUrl'        => $apiUrl,
            'recheckApiUrl' => $recheckApiUrl,
        ];
    }
}

