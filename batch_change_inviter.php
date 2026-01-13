<?php
/**
 * 批量修改用户邀请人（上级）
 * 用法: php batch_change_inviter.php <新上级手机号> <下级手机号1> <下级手机号2> ...
 * 或者: php batch_change_inviter.php <新上级手机号> --file <文件路径>
 */

require __DIR__ . '/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use think\facade\Db;

// 解析参数
$args = $_SERVER['argv'];
if (count($args) < 3) {
    echo "用法:\n";
    echo "  php batch_change_inviter.php <新上级手机号> <下级手机号1> <下级手机号2> ...\n";
    echo "  php batch_change_inviter.php <新上级手机号> --file <文件路径>\n";
    echo "\n";
    echo "文件格式（每行一个手机号）:\n";
    echo "  13857930235\n";
    echo "  15957972631\n";
    exit(1);
}

$superiorMobile = $args[1];
$subordinateMobiles = [];

// 检查是否使用文件
if (isset($args[2]) && $args[2] === '--file' && isset($args[3])) {
    $filePath = $args[3];
    if (!file_exists($filePath)) {
        echo "错误: 文件不存在: {$filePath}\n";
        exit(1);
    }
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (preg_match('/\d{11}/', $line, $matches)) {
            $subordinateMobiles[] = $matches[0];
        }
    }
} else {
    $subordinateMobiles = array_slice($args, 2);
}

if (empty($subordinateMobiles)) {
    echo "错误: 没有提供下级用户手机号\n";
    exit(1);
}

echo "=== 批量修改用户邀请人 ===\n\n";
echo "新上级: {$superiorMobile}\n";
echo "下级用户数: " . count($subordinateMobiles) . " 个\n";
echo "下级手机号: " . implode(", ", $subordinateMobiles) . "\n\n";

// 1. 获取新上级信息
$superior = Db::name('user')->where('mobile', $superiorMobile)->find();
if (!$superior) {
    echo "错误: 未找到新上级用户 ({$superiorMobile})\n";
    exit(1);
}
$superiorId = (int)$superior['id'];
echo "新上级信息:\n";
echo "  用户ID: {$superiorId}\n";
echo "  手机号: {$superior['mobile']}\n";
echo "  用户名: " . ($superior['username'] ?? 'N/A') . "\n\n";

// 2. 逐个处理下级
$successCount = 0;
$skipCount = 0;
$failCount = 0;
$changedUsers = [];

foreach ($subordinateMobiles as $subMobile) {
    $subMobile = trim($subMobile);
    if (empty($subMobile)) {
        continue;
    }
    
    echo "处理: {$subMobile} ...\n";
    
    $sub = Db::name('user')->where('mobile', $subMobile)->find();
    if (!$sub) {
        echo "  ✗ 错误: 未找到用户\n";
        $failCount++;
        continue;
    }
    
    $subId = (int)$sub['id'];
    $currentInviterId = (int)($sub['inviter_id'] ?? 0);
    
    if ($subId == $superiorId) {
        echo "  ✗ 错误: 不能设置自己为上级\n";
        $failCount++;
        continue;
    }
    
    if ($currentInviterId == $superiorId) {
        echo "  ⊙ 提示: 上级已经是 {$superiorMobile}，跳过\n";
        $skipCount++;
        continue;
    }
    
    // 执行更新
    try {
        Db::startTrans();
        
        // 更新邀请人
        Db::name('user')->where('id', $subId)->update([
            'inviter_id' => $superiorId,
            'update_time' => time(),
        ]);
        
        Db::commit();
        
        $currentInviter = $currentInviterId > 0 ? Db::name('user')->where('id', $currentInviterId)->find() : null;
        $currentInviterMobile = $currentInviter ? $currentInviter['mobile'] : '无';
        
        echo "  ✓ 成功: 上级已从 {$currentInviterMobile} (ID: {$currentInviterId}) 修改为 {$superiorMobile} (ID: {$superiorId})\n";
        $successCount++;
        $changedUsers[] = [
            'mobile' => $subMobile,
            'user_id' => $subId,
            'old_inviter_id' => $currentInviterId,
            'new_inviter_id' => $superiorId,
        ];
    } catch (\Throwable $e) {
        Db::rollback();
        echo "  ✗ 错误: " . $e->getMessage() . "\n";
        $failCount++;
    }
}

echo "\n=== 处理完成 ===\n";
echo "成功: {$successCount} 个\n";
echo "跳过: {$skipCount} 个\n";
echo "失败: {$failCount} 个\n";

if (!empty($changedUsers)) {
    echo "\n修改详情:\n";
    foreach ($changedUsers as $item) {
        echo "  - {$item['mobile']} (ID: {$item['user_id']}): {$item['old_inviter_id']} -> {$item['new_inviter_id']}\n";
    }
}

echo "\n";
