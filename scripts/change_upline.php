<?php
/**
 * 修改用户上级
 * 用法: php scripts/change_upline.php <superior_mobile> <subordinate_mobile1> <subordinate_mobile2> ...
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use think\facade\Db;

$app = new think\App(dirname(__DIR__));
$app->initialize();

// 解析参数
$args = $_SERVER['argv'];
if (count($args) < 3) {
    echo "Usage: php scripts/change_upline.php <superior_mobile> <subordinate_mobile> [subordinate_mobile2 ...]\n";
    exit;
}

$superiorMobile = $args[1];
$subordinateMobiles = array_slice($args, 2);

echo "正在处理上级变更...\n";
echo "新上级: {$superiorMobile}\n";
echo "下级名单: " . implode(", ", $subordinateMobiles) . "\n";

// 1. 获取新上级信息
$superior = Db::name('user')->where('mobile', $superiorMobile)->find();
if (!$superior) {
    echo "错误: 未找到新上级用户 ({$superiorMobile})\n";
    exit;
}
$superiorId = $superior['id'];
echo "新上级ID: {$superiorId} (用户名: {$superior['username']})\n";

// 2. 逐个处理下级
foreach ($subordinateMobiles as $subMobile) {
    echo "\n正在处理下级: {$subMobile} ...\n";
    
    $sub = Db::name('user')->where('mobile', $subMobile)->find();
    if (!$sub) {
        echo "  - 错误: 未找到用户 {$subMobile}\n";
        continue;
    }
    
    if ($sub['id'] == $superiorId) {
        echo "  - 错误: 不能设置自己为上级\n";
        continue;
    }
    
    // 检查当前上级
    $currentPid = $sub['inviter_id'];
    echo "  - 当前上级ID: {$currentPid}\n";
    
    if ($currentPid == $superiorId) {
        echo "  - 提示: 上级已经是 {$superiorMobile}，无需修改。\n";
        continue;
    }
    
    // 执行更新
    Db::name('user')->where('id', $sub['id'])->update(['inviter_id' => $superiorId]);
    echo "  - 成功: 上级已修改为 {$superiorId} ({$superiorMobile})\n";
}

echo "\n全部处理完成。\n";
