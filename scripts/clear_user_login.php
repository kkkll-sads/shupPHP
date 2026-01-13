<?php
/**
 * 清除用户登录状态
 * 清空 auth_token
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use think\facade\Db;

$app = new think\App(dirname(__DIR__));
$app->initialize();

$mobile = '15978354645';

echo "正在清除用户 {$mobile} 的登录状态...\n";

$user = Db::name('user')->where('mobile', $mobile)->find();

if (!$user) {
    echo "用户不存在!\n";
    exit;
}

echo "用户 ID: {$user['id']}, 用户名: {$user['username']}\n";
echo "当前 Token: " . ($user['auth_token'] ? substr($user['auth_token'], 0, 10) . '...' : 'None') . "\n";

Db::name('user')->where('id', $user['id'])->update([
    'auth_token' => '',
    'update_time' => time()
]);

echo "登录状态已清除 (auth_token 置空)。\n";
