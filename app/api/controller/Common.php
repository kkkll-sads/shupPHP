<?php

namespace app\api\controller;

use app\common\controller\Frontend;
use app\common\library\StatusDict;
use hg\apidoc\annotation as Apidoc;
use ba\ClickCaptcha;
use think\facade\Validate;
use think\facade\Db;

#[Apidoc\Title("通用接口")]
class Common extends Frontend
{
    protected array $noNeedLogin = ['statusDict', 'clickCaptcha', 'checkClickCaptcha', 'page'];

    public function initialize(): void
    {
        parent::initialize();
    }

    #[
        Apidoc\Title("获取状态映射字典"),
        Apidoc\Tag("通用,字典"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Common/statusDict"),
        Apidoc\Returned("dicts", type: "object", desc: "所有状态映射字典"),
    ]
    /**
     * 获取前后端统一的状态映射字典
     * 前端可以在应用启动时调用此接口获取所有状态映射，确保前后端一致
     */
    public function statusDict(): void
    {
        $this->success('', [
            'dicts' => StatusDict::getAllMaps(),
        ]);
    }

    /**
     * 生成点选验证码
     */
    public function clickCaptcha(): void
    {
        $id = $this->request->get('id', '');
        if (empty($id)) {
            $this->error(__('Captcha ID is required'));
        }

        $clickCaptcha = new ClickCaptcha();
        $data = $clickCaptcha->creat($id);

        $this->success('', $data);
    }

    /**
     * 验证点选验证码
     */
    public function checkClickCaptcha(): void
    {
        $params = $this->request->post(['id', 'info', 'unset']);
        $validate = Validate::rule([
            'id' => 'require',
            'info' => 'require',
        ])->message([
                    'id.require' => 'Captcha ID is required',
                    'info.require' => 'Captcha info is required',
                ]);

        if (!$validate->check($params)) {
            $this->error(__($validate->getError()));
        }

        $clickCaptcha = new ClickCaptcha();
        $result = $clickCaptcha->check($params['id'], $params['info'], $params['unset'] ?? true);

        if ($result) {
            $this->success(__('Captcha verification successful'));
        } else {
            $this->error(__('Captcha verification failed'));
        }
    }

    #[
        Apidoc\Title("获取页面内容"),
        Apidoc\Tag("通用,页面"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/Common/page"),
        Apidoc\Query(name: "type", type: "string", require: true, desc: "页面类型：user_agreement=用户协议, privacy_policy=隐私政策, about_us=关于我们"),
        Apidoc\Returned("title", type: "string", desc: "页面标题"),
        Apidoc\Returned("content", type: "string", desc: "页面内容"),
    ]
    /**
     * 获取页面内容（如用户协议、隐私政策等）
     */
    public function page(): void
    {
        // 添加调试日志
        error_log("PAGE API CALLED - Type: " . $this->request->get('type', 'NOT_SET'));

        $type = $this->request->get('type', '');

        if (empty($type)) {
            error_log("PAGE API ERROR - Type is empty");
            $this->error('页面类型不能为空');
        }

        // 定义页面类型映射
        $pageTypes = [
            'user_agreement' => 'site_user_agreement',
            'privacy_policy' => 'site_privacy_policy',
            'about_us' => 'site_about_us',
        ];

        if (!isset($pageTypes[$type])) {
            error_log("PAGE API ERROR - Unsupported type: " . $type);
            $this->error('不支持的页面类型');
        }

        $configName = $pageTypes[$type];
        error_log("PAGE API - Config name: " . $configName);

        try {
            // 首先测试数据库连接
            error_log("PAGE API - Testing database connection");
            try {
                $testResult = Db::query("SELECT 1 as test");
                error_log("PAGE API - Database connection test successful: " . json_encode($testResult));
            } catch (\Exception $dbError) {
                error_log("PAGE API - Database connection test failed: " . $dbError->getMessage());
                throw new \Exception('数据库连接失败: ' . $dbError->getMessage());
            }

            // 查询配置
            $config = Db::name('config')
                ->where('name', $configName)
                ->find();

            // 添加调试信息
            error_log("PAGE API - Config query result: " . (is_null($config) ? 'NULL' : (is_array($config) ? 'ARRAY' : gettype($config))));
            error_log("PAGE API - Config data: " . json_encode($config));

            if (!$config) {
                error_log("PAGE API - Config not found, checking available configs");
                try {
                    $allConfigs = Db::name('config')->limit(5)->select();
                    error_log("PAGE API - All configs query result: " . json_encode($allConfigs));
                    if ($allConfigs) {
                        $configNames = array_column($allConfigs->toArray(), 'name');
                        error_log("PAGE API - Available config names: " . implode(', ', $configNames));
                    } else {
                        error_log("PAGE API - No configs found at all");
                    }
                } catch (\Exception $e) {
                    error_log("PAGE API - Error querying all configs: " . $e->getMessage());
                }
                throw new \Exception('配置项不存在。可用配置项请查看日志');
            }

            // 解析配置值
            $value = $config['value'] ?? '';
            if (is_string($value) && json_decode($value, true) !== null) {
                // 如果是JSON字符串，尝试解析
                $value = json_decode($value, true);
            }

            $this->success('', [
                'title' => $config['title'] ?? '',
                'content' => $value,
                'update_time' => '',
            ]);
        } catch (\Throwable $e) {
            // 处理HttpResponseException（这是ThinkPHP正常响应机制）
            if ($e instanceof \think\exception\HttpResponseException) {
                throw $e; // 重新抛出，让ThinkPHP正常处理响应
            }

            // 处理其他异常
            $errorMessage = '获取页面内容失败：' . $e->getMessage();
            if (empty($e->getMessage())) {
                $errorMessage .= '[空错误消息] 错误代码:' . $e->getCode() . ' 文件:' . basename($e->getFile()) . ':' . $e->getLine();
            }

            $this->error($errorMessage, [
                'debug_info' => [
                    'error_type' => get_class($e),
                    'error_code' => $e->getCode(),
                    'error_file' => $e->getFile(),
                    'error_line' => $e->getLine(),
                    'type_param' => $type,
                    'config_name' => $configName,
                ]
            ]);
        }
    }
}
