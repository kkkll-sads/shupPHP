<?php
/**
 * 将藏品从矿机状态改为普通状态，以便寄售
 */

require __DIR__ . '/vendor/autoload.php';

use think\facade\Db;

// 初始化 ThinkPHP 应用
$app = new think\App();
$app->initialize();

$collectionId = 3783;

echo "=== 关闭矿机状态以便寄售 ===\n";
echo "执行时间: " . date('Y-m-d H:i:s') . "\n\n";

try {
    Db::startTrans();
    
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
            'uc.mining_status',
            'uc.mining_start_time',
            'uc.last_dividend_time',
            'uc.delivery_status',
            'uc.consignment_status',
            'uc.free_consign_attempts',
        ])
        ->find();
    
    if (!$collection) {
        echo "❌ 未找到持仓ID: {$collectionId}\n";
        Db::rollback();
        exit(1);
    }
    
    echo "【修改前】\n";
    echo "持仓ID: {$collection['id']}\n";
    echo "用户: {$collection['mobile']} ({$collection['nickname']})\n";
    echo "藏品: {$collection['title']}\n";
    echo "当前价格: ¥{$collection['price']}\n";
    echo "矿机状态: {$collection['mining_status']}\n";
    echo "交付状态: {$collection['delivery_status']}\n";
    echo "寄售状态: {$collection['consignment_status']}\n";
    echo "免费寄售次数: {$collection['free_consign_attempts']}\n";
    
    if ($collection['mining_start_time']) {
        echo "矿机开始时间: " . date('Y-m-d H:i:s', $collection['mining_start_time']) . "\n";
    }
    if ($collection['last_dividend_time']) {
        echo "最后分红时间: " . date('Y-m-d H:i:s', $collection['last_dividend_time']) . "\n";
    }
    echo "\n";
    
    // 检查是否是矿机状态
    if ($collection['mining_status'] != 1) {
        echo "⚠️  该藏品不是矿机状态，无需修改\n";
        Db::rollback();
        exit(0);
    }
    
    // 更新：关闭矿机状态
    $now = time();
    $result = Db::name('user_collection')
        ->where('id', $collectionId)
        ->update([
            'mining_status' => 0,
            'update_time' => $now,
        ]);
    
    if ($result === false) {
        echo "❌ 更新失败\n";
        Db::rollback();
        exit(1);
    }
    
    echo "【修改后】\n";
    echo "矿机状态: 0 (已关闭)\n";
    echo "免费寄售次数: {$collection['free_consign_attempts']}\n\n";
    
    // 记录日志
    Db::name('user_activity_log')->insert([
        'user_id' => $collection['user_id'],
        'related_user_id' => 0,
        'action_type' => 'admin_disable_mining',
        'change_field' => 'mining_status',
        'change_value' => '0',
        'before_value' => '1',
        'after_value' => '0',
        'remark' => "管理员关闭矿机状态以便寄售：持仓ID={$collectionId}，藏品={$collection['title']}",
        'extra' => json_encode([
            'collection_id' => $collectionId,
            'item_id' => $collection['item_id'],
            'title' => $collection['title'],
            'reason' => '用户需要寄售',
        ], JSON_UNESCAPED_UNICODE),
        'create_time' => $now,
        'update_time' => $now,
    ]);
    
    Db::commit();
    
    echo "✓ 操作成功！\n";
    echo "✓ 已记录到用户活动日志\n";
    echo "✓ 用户现在可以提交寄售了\n";
    
} catch (\Exception $e) {
    Db::rollback();
    echo "❌ 操作失败: " . $e->getMessage() . "\n";
    echo "文件: " . $e->getFile() . "\n";
    echo "行号: " . $e->getLine() . "\n";
    exit(1);
}
