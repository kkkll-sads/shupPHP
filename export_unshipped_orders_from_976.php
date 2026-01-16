<?php
/**
 * 导出订单ID>=976的已付款未发货订单（排除商品ID=60）
 * 执行方式：php export_unshipped_orders_from_976.php
 */

require __DIR__ . '/vendor/autoload.php';

use think\facade\Db;

// 初始化 ThinkPHP 应用
$app = new think\App();
$app->initialize();

echo "=== 导出已付款未发货订单（ID>=976，排除商品ID=60） ===\n";
echo "执行时间: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // 先查询符合条件的订单ID（排除包含商品ID=60的订单）
    $excludeOrderIds = Db::name('shop_order_item')
        ->where('product_id', 60)
        ->column('order_id');
    
    echo "排除包含商品ID=60的订单: " . count($excludeOrderIds) . " 笔\n";
    
    // 查询已付款未发货的订单
    $query = Db::name('shop_order')
        ->alias('o')
        ->leftJoin('user u', 'o.user_id = u.id')
        ->where('o.id', '>=', 976)
        ->where('o.status', 'paid')  // 已付款
        ->field([
            'o.id',
            'o.order_no',
            'o.user_id',
            'u.mobile',
            'u.nickname',
            'o.total_amount',
            'o.total_score',
            'o.pay_type',
            'o.recipient_name',
            'o.recipient_phone',
            'o.recipient_address',
            'o.shipping_company',
            'o.shipping_no',
            'o.remark',
            'o.admin_remark',
            'o.create_time',
            'o.pay_time',
            'o.ship_time',
        ]);
    
    // 排除包含商品ID=60的订单
    if (!empty($excludeOrderIds)) {
        $query->whereNotIn('o.id', $excludeOrderIds);
    }
    
    $orders = $query->order('o.id', 'asc')
        ->select()
        ->toArray();
    
    // 获取每个订单的商品信息
    $orderIds = array_column($orders, 'id');
    $orderItems = [];
    
    if (!empty($orderIds)) {
        $items = Db::name('shop_order_item')
            ->whereIn('order_id', $orderIds)
            ->select()
            ->toArray();
        
        foreach ($items as $item) {
            if (!isset($orderItems[$item['order_id']])) {
                $orderItems[$item['order_id']] = [];
            }
            $orderItems[$item['order_id']][] = $item;
        }
    }
    
    echo "查询到 " . count($orders) . " 条订单\n\n";
    
    if (empty($orders)) {
        echo "没有找到符合条件的订单\n";
        exit(0);
    }
    
    // 处理数据
    $exportData = [];
    foreach ($orders as $order) {
        // 获取订单商品信息
        $items = $orderItems[$order['id']] ?? [];
        
        // 商品列表
        $productList = [];
        $productIds = [];
        $totalQuantity = 0;
        
        foreach ($items as $item) {
            $productIds[] = $item['product_id'];
            $totalQuantity += $item['quantity'];
            
            $itemPrice = '';
            if ($item['price'] > 0 && $item['score_price'] > 0) {
                $itemPrice = "¥{$item['price']} + {$item['score_price']}消费金";
            } elseif ($item['price'] > 0) {
                $itemPrice = "¥{$item['price']}";
            } else {
                $itemPrice = "{$item['score_price']}消费金";
            }
            
            $productList[] = "{$item['product_name']} x{$item['quantity']} ({$itemPrice})";
        }
        
        // 支付方式映射
        $payTypeMap = [
            'money' => '现金支付',
            'score' => '消费金支付',
            'combined' => '混合支付',
        ];
        
        // 计算总价
        $totalPrice = $order['total_amount'];
        $totalScore = $order['total_score'] ?? 0;
        
        $priceDisplay = '';
        if ($order['pay_type'] === 'money') {
            $priceDisplay = '¥' . $totalPrice;
        } elseif ($order['pay_type'] === 'score') {
            $priceDisplay = $totalScore . '消费金';
        } else {
            $priceDisplay = '¥' . $totalPrice . ' + ' . $totalScore . '消费金';
        }
        
        $exportData[] = [
            '订单ID' => $order['id'],
            '订单号' => $order['order_no'],
            '用户ID' => $order['user_id'],
            '手机号' => $order['mobile'],
            '昵称' => $order['nickname'],
            '商品列表' => implode("\n", $productList),
            '商品ID列表' => implode(',', $productIds),
            '商品总件数' => $totalQuantity,
            '现金金额' => $order['total_amount'],
            '消费金' => $totalScore,
            '支付方式' => $payTypeMap[$order['pay_type']] ?? $order['pay_type'],
            '价格显示' => $priceDisplay,
            '收货人' => $order['recipient_name'],
            '收货电话' => $order['recipient_phone'],
            '收货地址' => $order['recipient_address'],
            '物流公司' => $order['shipping_company'] ?? '',
            '物流单号' => $order['shipping_no'] ?? '',
            '用户备注' => $order['remark'] ?? '',
            '管理员备注' => $order['admin_remark'] ?? '',
            '下单时间' => date('Y-m-d H:i:s', $order['create_time']),
            '支付时间' => $order['pay_time'] ? date('Y-m-d H:i:s', $order['pay_time']) : '',
            '发货时间' => $order['ship_time'] ? date('Y-m-d H:i:s', $order['ship_time']) : '',
        ];
    }
    
    // 导出CSV
    $csvFile = __DIR__ . '/已付款未发货订单_ID976起_' . date('YmdHis') . '.csv';
    $fp = fopen($csvFile, 'w');
    fwrite($fp, "\xEF\xBB\xBF"); // BOM for UTF-8
    
    // 写入表头
    fputcsv($fp, array_keys($exportData[0]));
    
    // 写入数据
    foreach ($exportData as $row) {
        fputcsv($fp, $row);
    }
    
    fclose($fp);
    
    // 统计信息
    $totalAmount = array_sum(array_column($orders, 'total_amount'));
    $totalScore = array_sum(array_column($exportData, '消费金'));
    
    // 按商品分组统计
    $productStats = [];
    foreach ($orders as $order) {
        $items = $orderItems[$order['id']] ?? [];
        
        foreach ($items as $item) {
            $productId = $item['product_id'];
            $productName = $item['product_name'];
            
            if (!isset($productStats[$productId])) {
                $productStats[$productId] = [
                    'name' => $productName,
                    'order_count' => 0,
                    'quantity' => 0,
                ];
            }
            
            $productStats[$productId]['order_count']++;
            $productStats[$productId]['quantity'] += $item['quantity'];
        }
    }
    
    echo "✓ 订单已导出: {$csvFile}\n";
    echo "  文件大小: " . round(filesize($csvFile) / 1024, 2) . " KB\n\n";
    
    echo "=== 统计信息 ===\n";
    echo "订单总数: " . count($orders) . " 条\n";
    echo "现金总额: ¥" . number_format($totalAmount, 2) . "\n";
    echo "消费金总额: " . number_format($totalScore, 2) . " 分\n\n";
    
    echo "=== 商品统计 ===\n";
    foreach ($productStats as $productId => $stat) {
        echo "商品ID {$productId} ({$stat['name']}): {$stat['order_count']} 笔订单，共 {$stat['quantity']} 件\n";
    }
    
    echo "\n=== 订单ID范围 ===\n";
    echo "最小订单ID: " . min(array_column($orders, 'id')) . "\n";
    echo "最大订单ID: " . max(array_column($orders, 'id')) . "\n";
    
} catch (\Exception $e) {
    echo "❌ 导出失败: " . $e->getMessage() . "\n";
    echo "文件: " . $e->getFile() . "\n";
    echo "行号: " . $e->getLine() . "\n";
    exit(1);
}

echo "\n✓ 导出完成\n";
