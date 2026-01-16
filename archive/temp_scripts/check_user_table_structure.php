<?php
require __DIR__ . '/vendor/autoload.php';
$app = new think\App();
$app->initialize();

echo "=== 用户表字段结构（资金相关）===\n";
$columns = think\facade\Db::query('SHOW COLUMNS FROM ba_user');
foreach ($columns as $col) {
    $field = $col['Field'];
    if (stripos($field, 'money') !== false || 
        stripos($field, 'balance') !== false || 
        stripos($field, 'score') !== false || 
        stripos($field, 'power') !== false || 
        stripos($field, 'special') !== false ||
        stripos($field, 'withdraw') !== false) {
        echo "{$field} ({$col['Type']})\n";
    }
}
