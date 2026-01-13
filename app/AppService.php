<?php
declare (strict_types=1);

namespace app;

use think\Service;
use hg\apidoc\providers\ThinkPHPService;

/**
 * 应用服务类
 */
class AppService extends Service
{
    public function register()
    {
        // 服务注册
        // 注册apidoc服务
        $this->app->register(ThinkPHPService::class);

        // Custom routes will be registered in boot


    }

    public function boot()
    {
        // 服务启动
        // 注册自定义apidoc路由 (在所有路由之后注册以覆盖)
        $this->registerCustomApidocRoutes();
    }

    protected function registerCustomApidocRoutes()
    {
        $config = config('apidoc');
        $routePrefix = $config['route_prefix'] ?? '/apidoc';

        // 重新注册apiDetail路由以确保它工作
        \think\facade\Route::any($routePrefix . '/apiDetail', '\hg\apidoc\Controller@getApiDetail')
            ->middleware([\hg\apidoc\middleware\ThinkPHPMiddleware::class]);
    }
}
