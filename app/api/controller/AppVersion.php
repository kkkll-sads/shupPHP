<?php

namespace app\api\controller;

use Throwable;
use app\common\controller\Frontend;
use app\admin\model\AppVersion as AppVersionModel;
use hg\apidoc\annotation as Apidoc;

#[Apidoc\Title("APP版本更新管理")]
class AppVersion extends Frontend
{
    protected array $noNeedLogin = ['checkUpdate', 'getLatestVersion'];

    public function initialize(): void
    {
        parent::initialize();
    }

    #[
        Apidoc\Title("检查APP更新"),
        Apidoc\Tag("APP版本"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/AppVersion/checkUpdate"),
        Apidoc\Query(name: "platform", type: "string", require: true, desc: "平台:android=安卓,ios=苹果", mock: "@pick(['android','ios'])"),
        Apidoc\Query(name: "current_version", type: "string", require: true, desc: "当前版本号", mock: "@string('lower', 5)"),
        Apidoc\Returned("need_update", type: "bool", desc: "是否需要更新"),
        Apidoc\Returned("message", type: "string", desc: "提示信息"),
        Apidoc\Returned("data", type: "object", desc: "更新信息（需要更新时返回）"),
        Apidoc\Returned("data.app_name", type: "string", desc: "软件名称"),
        Apidoc\Returned("data.version_code", type: "string", desc: "最新版本号"),
        Apidoc\Returned("data.download_url", type: "string", desc: "下载链接"),
    ]
    /**
     * 检查APP更新
     * @throws Throwable
     */
    public function checkUpdate(): void
    {
        $platform = $this->request->get('platform', '');
        $currentVersion = $this->request->get('current_version', '');

        if (!$platform || !in_array($platform, ['android', 'ios'])) {
            $this->error('平台参数错误，必须是 android 或 ios');
        }

        if (!$currentVersion) {
            $this->error('当前版本号不能为空');
        }

        $model = new AppVersionModel();
        $version = $model->where('platform', $platform)->find();

        if (!$version) {
            $this->success('暂无版本信息', [
                'need_update' => false,
                'message' => '暂无版本信息',
            ]);
        }

        // 比较版本号
        $needUpdate = $this->compareVersion($currentVersion, $version->version_code);

        if ($needUpdate) {
            // 需要更新
            $this->success('发现新版本', [
                'need_update' => true,
                'message' => '发现新版本，请及时更新',
                'data' => [
                    'app_name' => $version->app_name,
                    'version_code' => $version->version_code,
                    'download_url' => $version->download_url,
                ],
            ]);
        } else {
            // 已是最新版本
            $this->success('已是最新版本', [
                'need_update' => false,
                'message' => '已是最新版本',
            ]);
        }
    }

    /**
     * 比较版本号
     * 如果当前版本小于服务器版本，返回true（需要更新）
     * 如果当前版本大于等于服务器版本，返回false（不需要更新）
     * 
     * @param string $currentVersion 当前版本号
     * @param string $serverVersion 服务器版本号
     * @return bool
     */
    private function compareVersion(string $currentVersion, string $serverVersion): bool
    {
        // 移除可能的 'v' 前缀
        $currentVersion = ltrim($currentVersion, 'vV');
        $serverVersion = ltrim($serverVersion, 'vV');

        // 将版本号转换为数组（支持 1.0.0 格式）
        $currentParts = array_map('intval', explode('.', $currentVersion));
        $serverParts = array_map('intval', explode('.', $serverVersion));

        // 补齐数组长度，确保比较时数组长度一致
        $maxLength = max(count($currentParts), count($serverParts));
        $currentParts = array_pad($currentParts, $maxLength, 0);
        $serverParts = array_pad($serverParts, $maxLength, 0);

        // 逐位比较
        for ($i = 0; $i < $maxLength; $i++) {
            if ($currentParts[$i] < $serverParts[$i]) {
                return true; // 需要更新
            } elseif ($currentParts[$i] > $serverParts[$i]) {
                return false; // 不需要更新
            }
        }

        // 版本号完全相同，不需要更新
        return false;
    }

    #[
        Apidoc\Title("获取最新版本信息"),
        Apidoc\Tag("APP版本"),
        Apidoc\Method("GET"),
        Apidoc\Url("/api/AppVersion/getLatestVersion"),
        Apidoc\Query(name: "platform", type: "string", require: true, desc: "平台:android=安卓,ios=苹果", mock: "@pick(['android','ios'])"),
        Apidoc\Returned("app_name", type: "string", desc: "软件名称"),
        Apidoc\Returned("version_code", type: "string", desc: "最新版本号"),
        Apidoc\Returned("download_url", type: "string", desc: "下载链接"),
    ]
    /**
     * 获取最新版本信息（不检查是否需要更新）
     * @throws Throwable
     */
    public function getLatestVersion(): void
    {
        $platform = $this->request->get('platform', '');

        if (!$platform || !in_array($platform, ['android', 'ios'])) {
            $this->error('平台参数错误，必须是 android 或 ios');
        }

        $model = new AppVersionModel();
        $version = $model->where('platform', $platform)->find();

        if (!$version) {
            $this->error('暂无版本信息');
        }

        $this->success('获取成功', [
            'app_name' => $version->app_name,
            'version_code' => $version->version_code,
            'download_url' => $version->download_url,
        ]);
    }
}

