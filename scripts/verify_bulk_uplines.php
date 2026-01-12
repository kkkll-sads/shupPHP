<?php
/**
 * 验证批量上级变更数据
 * 检查手机号是否存在，以及姓名是否匹配（如果在DB中有姓名的话）
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use think\facade\Db;

$app = new think\App(dirname(__DIR__));
$app->initialize();

// 数据结构: [SuperiorName, SuperiorMobile, SubordinateName, SubordinateMobile]
$data = [
    ['贺金鑫', '13505229038', '徐春红', '13914854405'],
    ['揭占地', '13981101716', '钱江波', '13881105799'],
    ['秦志慧', '15518817174', '陈勇', '18009082429'],
    ['秦志慧', '15518817174', '刘双华', '17313566277'],
    ['秦志慧', '15518817174', '鲁慧媛', '18161024106'], // 名字存疑
    ['朱峰云', '13934865283', '朱金峰', '15235912632'],
    ['陈俊容', '13890172438', '陈俊', '13689691739'],
    ['陈俊容', '13890172438', '秦志慧', '15518817174'],
    ['郭永军', '13857930235', '何香仙', '15957972631'],
    ['郭永军', '13857930235', '李玉红', '15126769121'],
    ['郭永军', '13857930235', '陈科达', '15868997943'],
];

echo "开始验证 " . count($data) . " 组关系...\n";

foreach ($data as $row) {
    list($supName, $supMobile, $subName, $subMobile) = $row;
    
    echo "------------------------------------------------\n";
    echo "检查: 上级 {$supName} ({$supMobile}) -> 下级 {$subName} ({$subMobile})\n";
    
    // 检查上级
    $sup = Db::name('user')->where('mobile', $supMobile)->find();
    if (!$sup) {
        echo "  [ERROR] 上级手机号 {$supMobile} 不存在!\n";
        continue;
    }
    // 模糊匹配姓名
    $dbSupName = $sup['username']; // 或者是 nickname, real_name
    $dbRealName = $sup['real_name'] ?? '';
    echo "  [OK] 上级存在: ID={$sup['id']}, User={$dbSupName}, Real={$dbRealName}\n";
    
    // 检查下级
    $sub = Db::name('user')->where('mobile', $subMobile)->find();
    if (!$sub) {
        echo "  [ERROR] 下级手机号 {$subMobile} 不存在!\n";
        continue;
    }
    $dbSubName = $sub['username'];
    $dbSubReal = $sub['real_name'] ?? '';
    echo "  [OK] 下级存在: ID={$sub['id']}, User={$dbSubName}, Real={$dbSubReal}\n";
    
}
