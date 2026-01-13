<?php
/**
 * 清除所有用户登录状态
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use think\facade\Db;

$app = new think\App(dirname(__DIR__));
$app->initialize();

echo "开始清除所有用户登录状态...\n";

// 1. 清除 ba_user 表中的 auth_token
try {
    $result = Db::name('user')->where('auth_token', '<>', '')->update(['auth_token' => '']);
    echo "已清除 ba_user 表中 {$result} 个用户的 auth_token。\n";
} catch (\Exception $e) {
    echo "清除 ba_user 表失败: " . $e->getMessage() . "\n";
}

// 2. 尝试清除 ba_user_token 表 (如果存在)
try {
    $tableExists = Db::query("SHOW TABLES LIKE 'ba_user_token'");
    if (!empty($tableExists)) {
        Db::execute('TRUNCATE TABLE ba_user_token');
        echo "已清空 ba_user_token 表。\n";
    } else {
        echo "ba_user_token 表不存在，跳过。\n";
    }
} catch (\Exception $e) {
    echo "清除 ba_user_token 表失败: " . $e->getMessage() . "\n";
}

echo "操作完成。\n";
