<?php
/**
 * 清除所有用户登录状态
 * 清空 ba_token 表
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use think\facade\Db;

$app = new think\App(dirname(__DIR__));
$app->initialize();

echo "开始清除所有用户登录状态 (Token)...\n";

try {
    $count = Db::name('token')->count();
    echo "当前活动 Token 数量: {$count}\n";
    
    Db::execute('TRUNCATE TABLE ba_token');
    echo "已清空 ba_token 表。\n";
    
} catch (\Exception $e) {
    echo "清除 ba_token 表失败: " . $e->getMessage() . "\n";
}

echo "操作完成。\n";
