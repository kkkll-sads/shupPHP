<?php
/**
 * 修复重复盲盒订单问题
 * 
 * 问题原因：撮合脚本在12:04:15和12:04:52被并发执行了两次
 * 导致同一个预约被处理两次，创建了重复的订单
 * 
 * 使用方法：
 *   模拟运行: php scripts/fix_duplicate_blindbox_orders.php --dry-run
 *   实际执行: php scripts/fix_duplicate_blindbox_orders.php --execute
 */

require_once __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$app->initialize();

use think\facade\Db;

// 解析命令行参数
$dryRun = in_array('--dry-run', $argv);
$execute = in_array('--execute', $argv);

if (!$dryRun && !$execute) {
    echo "用法:\n";
    echo "  模拟运行: php scripts/fix_duplicate_blindbox_orders.php --dry-run\n";
    echo "  实际执行: php scripts/fix_duplicate_blindbox_orders.php --execute\n";
    exit(1);
}

$mode = $dryRun ? '模拟运行' : '实际执行';
echo "=== 修复重复盲盒订单 ({$mode}) ===\n";
echo "开始时间: " . date('Y-m-d H:i:s') . "\n\n";

$now = time();
$today = strtotime('today');

// 找出所有没有对应预约的BB订单（今天的），且状态不是cancelled（防止重复处理）
$excessOrders = Db::name('collection_order')
    ->alias('co')
    ->leftJoin('trade_reservations tr', 'co.id = tr.match_order_id')
    ->where('co.order_no', 'like', 'BB%')
    ->where('co.create_time', '>=', $today)
    ->where('co.status', '<>', 'cancelled') // 排除已取消的订单，防止重复处理
    ->whereNull('tr.id')
    ->field('co.*')
    ->select()
    ->toArray();

echo "找到多余订单: " . count($excessOrders) . " 个\n\n";

$stats = [
    'orders_cancelled' => 0,
    'collections_deleted' => 0,
    'refund_recovered' => 0,
    'refund_amount' => 0,
    'stock_restored' => 0,
    'errors' => 0,
];

foreach ($excessOrders as $index => $order) {
    $orderId = $order['id'];
    $userId = $order['user_id'];
    $orderAmount = (float)$order['total_amount'];
    
    echo "[" . ($index + 1) . "/" . count($excessOrders) . "] 处理订单 #{$orderId} (用户:{$userId}, 金额:{$orderAmount})\n";
    
    // 查找订单明细
    $orderItem = Db::name('collection_order_item')
        ->where('order_id', $orderId)
        ->find();
    
    if (!$orderItem) {
        echo "  ⚠️ 订单明细不存在，跳过\n";
        continue;
    }
    
    $itemId = $orderItem['item_id'];
    
    // 查找用户持仓记录
    $userCollection = Db::name('user_collection')
        ->where('order_id', $orderId)
        ->find();
    
    // 查找差价退还记录
    $refundLog = Db::name('user_money_log')
        ->where('user_id', $userId)
        ->where('create_time', '>=', $today)
        ->where('memo', 'like', '%商品ID：' . $itemId . '%')
        ->where('memo', 'like', '%退还差价%')
        ->find();
    
    $refundAmount = $refundLog ? (float)$refundLog['money'] : 0;
    
    echo "  商品ID: {$itemId}\n";
    echo "  持仓ID: " . ($userCollection ? $userCollection['id'] : '无') . "\n";
    echo "  差价退还: " . ($refundAmount > 0 ? "+{$refundAmount}" : "无") . "\n";
    
    if (!$execute) {
        echo "  [模拟] 将执行以下操作:\n";
        echo "    - 取消订单 #{$orderId}\n";
        if ($userCollection) {
            echo "    - 删除持仓 #{$userCollection['id']}\n";
        }
        if ($refundAmount > 0) {
            echo "    - 扣回差价 {$refundAmount} 元\n";
        }
        echo "    - 恢复商品 #{$itemId} 库存\n";
        
        $stats['orders_cancelled']++;
        if ($userCollection) $stats['collections_deleted']++;
        if ($refundAmount > 0) {
            $stats['refund_recovered']++;
            $stats['refund_amount'] += $refundAmount;
        }
        $stats['stock_restored']++;
        
        echo "\n";
        continue;
    }
    
    // 实际执行
    Db::startTrans();
    try {
        // 1. 取消订单
        Db::name('collection_order')
            ->where('id', $orderId)
            ->update([
                'status' => 'cancelled',
                'remark' => '系统自动取消：重复订单修复',
                'update_time' => $now,
            ]);
        $stats['orders_cancelled']++;
        echo "  ✓ 订单已取消\n";
        
        // 2. 删除持仓记录
        if ($userCollection) {
            Db::name('user_collection')
                ->where('id', $userCollection['id'])
                ->delete();
            $stats['collections_deleted']++;
            echo "  ✓ 持仓已删除\n";
        }
        
        // 3. 扣回差价
        if ($refundAmount > 0) {
            $user = Db::name('user')->where('id', $userId)->find();
            $beforeBalance = (float)($user['balance_available'] ?? $user['money'] ?? 0);
            $afterBalance = round($beforeBalance - $refundAmount, 2);
            
            // 确保余额不会变成负数
            if ($afterBalance < 0) {
                echo "  ⚠️ 用户余额不足，差价扣回金额调整: {$refundAmount} -> {$beforeBalance}\n";
                $refundAmount = $beforeBalance;
                $afterBalance = 0;
            }
            
            // 更新用户余额
            if (isset($user['balance_available'])) {
                Db::name('user')
                    ->where('id', $userId)
                    ->update([
                        'balance_available' => $afterBalance,
                        'update_time' => $now,
                    ]);
            } else {
                Db::name('user')
                    ->where('id', $userId)
                    ->update([
                        'money' => $afterBalance,
                        'update_time' => $now,
                    ]);
            }
            
            // 记录扣款日志
            Db::name('user_money_log')->insert([
                'user_id' => $userId,
                'money' => -$refundAmount,
                'before' => $beforeBalance,
                'after' => $afterBalance,
                'memo' => "系统修复：扣回重复订单#{$orderId}错误退还的差价",
                'create_time' => $now,
            ]);
            
            $stats['refund_recovered']++;
            $stats['refund_amount'] += $refundAmount;
            echo "  ✓ 差价已扣回: -{$refundAmount} (余额: {$beforeBalance} -> {$afterBalance})\n";
        }
        
        // 4. 恢复商品库存（如果是官方商品）
        $item = Db::name('collection_item')->where('id', $itemId)->find();
        if ($item && $item['status'] == 0) {
            // 商品已下架，恢复库存
            Db::name('collection_item')
                ->where('id', $itemId)
                ->update([
                    'stock' => Db::raw('stock + 1'),
                    'status' => 1, // 重新上架
                    'update_time' => $now,
                ]);
            $stats['stock_restored']++;
            echo "  ✓ 商品库存已恢复\n";
        }
        
        Db::commit();
        echo "  ✅ 处理完成\n\n";
        
    } catch (\Throwable $e) {
        Db::rollback();
        $stats['errors']++;
        echo "  ❌ 处理失败: " . $e->getMessage() . "\n\n";
    }
}

echo "=== 处理完成 ===\n";
echo "取消订单: {$stats['orders_cancelled']} 个\n";
echo "删除持仓: {$stats['collections_deleted']} 个\n";
echo "扣回差价: {$stats['refund_recovered']} 笔，共 " . number_format($stats['refund_amount'], 2) . " 元\n";
echo "恢复库存: {$stats['stock_restored']} 个\n";
if ($stats['errors'] > 0) {
    echo "错误: {$stats['errors']} 个\n";
}
echo "\n完成时间: " . date('Y-m-d H:i:s') . "\n";

if ($dryRun) {
    echo "\n⚠️ 这是模拟运行，实际数据未修改。\n";
    echo "确认无误后请使用 --execute 参数执行实际修复。\n";
}
