<?php
/**
 * 给指定藏品增加免费寄售次数
 */

require __DIR__ . '/vendor/autoload.php';

use think\facade\Db;

// 初始化 ThinkPHP 应用
$app = new think\App();
$app->initialize();

$collectionId = 3783;
$addCount = 1;

echo "=== 补充免费寄售次数 ===\n";
echo "执行时间: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // 查询当前信息
    $collection = Db::name('user_collection')
        ->alias('uc')
        ->leftJoin('user u', 'uc.user_id = u.id')
        ->where('uc.id', $collectionId)
        ->field([
            'uc.id',
            'uc.user_id',
            'u.mobile',
            'u.nickname',
            'uc.item_id',
            'uc.title',
            'uc.price',
            'uc.free_consign_attempts',
            'uc.consignment_status',
            'uc.mining_status',
        ])
        ->find();
    
    if (!$collection) {
        echo "❌ 未找到持仓ID: {$collectionId}\n";
        exit(1);
    }
    
    echo "【修改前】\n";
    echo "持仓ID: {$collection['id']}\n";
    echo "用户: {$collection['mobile']} ({$collection['nickname']})\n";
    echo "藏品: {$collection['title']}\n";
    echo "当前价格: ¥{$collection['price']}\n";
    echo "寄售状态: {$collection['consignment_status']}\n";
    echo "矿机状态: {$collection['mining_status']}\n";
    echo "免费寄售次数: {$collection['free_consign_attempts']}\n\n";
    
    // 更新免费寄售次数
    $newCount = $collection['free_consign_attempts'] + $addCount;
    
    $result = Db::name('user_collection')
        ->where('id', $collectionId)
        ->update([
            'free_consign_attempts' => $newCount,
            'update_time' => time(),
        ]);
    
    if ($result === false) {
        echo "❌ 更新失败\n";
        exit(1);
    }
    
    echo "【修改后】\n";
    echo "免费寄售次数: {$newCount} (+{$addCount})\n\n";
    
    // 记录日志
    Db::name('user_activity_log')->insert([
        'user_id' => $collection['user_id'],
        'related_user_id' => 0,
        'action_type' => 'admin_add_free_consignment',
        'change_field' => 'free_consign_attempts',
        'change_value' => (string)$addCount,
        'before_value' => (string)$collection['free_consign_attempts'],
        'after_value' => (string)$newCount,
        'remark' => "管理员补充免费寄售次数：持仓ID={$collectionId}，藏品={$collection['title']}",
        'extra' => json_encode([
            'collection_id' => $collectionId,
            'item_id' => $collection['item_id'],
            'title' => $collection['title'],
            'add_count' => $addCount,
        ], JSON_UNESCAPED_UNICODE),
        'create_time' => time(),
        'update_time' => time(),
    ]);
    
    echo "✓ 操作成功！\n";
    echo "✓ 已记录到用户活动日志\n";
    
} catch (\Exception $e) {
    echo "❌ 操作失败: " . $e->getMessage() . "\n";
    echo "文件: " . $e->getFile() . "\n";
    echo "行号: " . $e->getLine() . "\n";
    exit(1);
}
