<?php
/**
 * 执行批量上级变更
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use think\facade\Db;

$app = new think\App(dirname(__DIR__));
$app->initialize();

$data = [
    ['13505229038', '13914854405'],
    ['13981101716', '13881105799'],
    ['15518817174', '18009082429'],
    ['15518817174', '17313566277'],
    ['15518817174', '18161024106'], 
    ['13934865283', '15235912632'],
    ['13890172438', '13689691739'],
    ['13890172438', '15518817174'],
    ['13857930235', '15957972631'],
    ['13857930235', '15126769121'],
    ['13857930235', '15868997943'],
];

echo "开始修改 " . count($data) . " 组关系...\n";

foreach ($data as $row) {
    list($supMobile, $subMobile) = $row;
    
    // 获取上级ID
    $sup = Db::name('user')->where('mobile', $supMobile)->find();
    $sub = Db::name('user')->where('mobile', $subMobile)->find();
    
    if (!$sup || !$sub) {
        echo "[SKIP] 无法找到用户: {$supMobile} or {$subMobile}\n";
        continue;
    }
    
    $supId = $sup['id'];
    $subId = $sub['id'];
    
    if ($sub['inviter_id'] == $supId) {
        echo "[SKIP] 用户 {$subMobile} 的上级已经是 {$supId}\n";
        continue;
    }

    Db::name('user')->where('id', $subId)->update(['inviter_id' => $supId]);
    echo "[OK] 已将 {$subMobile} ({$sub['username']}) 的上级修改为 {$supMobile} (ID: {$supId})\n";
}

echo "全部完成。\n";
