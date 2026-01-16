<?php
/**
 * 导出商品ID 60的所有未发货订单
 */

require __DIR__ . '/vendor/autoload.php';

use think\facade\Db;

// 引导 ThinkPHP 应用
$app = new think\App();
$app->initialize();

$productId = 60;

echo "=== 导出商品ID {$productId} 的未发货订单 ===\n";
echo "查询时间: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // 首先查询商品信息
    $product = Db::name('shop_product')
        ->where('id', $productId)
        ->find();
    
    if (!$product) {
        echo "❌ 商品ID {$productId} 不存在\n";
        exit(1);
    }
    
    echo "=== 商品信息 ===\n";
    echo "商品ID: {$product['id']}\n";
    echo "商品名称: {$product['name']}\n";
    echo "商品价格: {$product['price']}\n";
    echo "积分价格: " . ($product['score_price'] ?? 0) . "\n\n";
    
    // 查询包含该商品的所有订单（通过订单明细表）
    $orderIds = Db::name('shop_order_item')
        ->where('product_id', $productId)
        ->column('order_id');
    
    if (empty($orderIds)) {
        echo "❌ 没有找到包含该商品的订单\n";
        exit(0);
    }
    
    echo "找到 " . count($orderIds) . " 个订单包含该商品\n";
    
    // 查询未发货的订单（已支付但未发货）
    $orders = Db::name('shop_order')
        ->alias('o')
        ->leftJoin('user u', 'o.user_id = u.id')
        ->whereIn('o.id', $orderIds)
        ->where('o.status', 'paid') // 已支付但未发货
        ->field([
            'o.id',
            'o.order_no',
            'o.user_id',
            'u.mobile as user_mobile',
            'u.nickname as user_nickname',
            'o.total_amount',
            'o.total_score',
            'o.pay_type',
            'o.recipient_name',
            'o.recipient_phone',
            'o.recipient_address',
            'o.remark',
            'o.admin_remark',
            'o.create_time',
            'o.pay_time',
        ])
        ->order('o.id', 'desc')
        ->select()
        ->toArray();
    
    if (empty($orders)) {
        echo "✓ 没有未发货的订单\n";
        exit(0);
    }
    
    echo "找到 " . count($orders) . " 个未发货订单\n\n";
    
    // 获取每个订单的商品详情
    foreach ($orders as &$order) {
        $order['create_time_text'] = date('Y-m-d H:i:s', $order['create_time']);
        $order['pay_time_text'] = $order['pay_time'] ? date('Y-m-d H:i:s', $order['pay_time']) : '';
        
        // 查询订单中商品ID 60的详情
        $item = Db::name('shop_order_item')
            ->where('order_id', $order['id'])
            ->where('product_id', $productId)
            ->find();
        
        $order['product_quantity'] = $item['quantity'] ?? 0;
        $order['product_price'] = $item['price'] ?? 0;
        $order['product_score_price'] = $item['score_price'] ?? 0;
        
        // 支付方式文本
        $payTypeMap = [
            'money' => '余额支付',
            'score' => '消费金支付',
            'combined' => '组合支付',
        ];
        $order['pay_type_text'] = $payTypeMap[$order['pay_type']] ?? $order['pay_type'];
    }
    
    // 导出为CSV
    $csvFile = __DIR__ . '/未发货订单_商品ID' . $productId . '_' . date('YmdHis') . '.csv';
    
    $fp = fopen($csvFile, 'w');
    
    // 写入 BOM 头，确保 Excel 正确识别 UTF-8
    fwrite($fp, "\xEF\xBB\xBF");
    
    // 写入表头
    $headers = [
        '订单ID',
        '订单号',
        '用户ID',
        '用户手机',
        '用户昵称',
        '商品数量',
        '商品单价',
        '商品积分价',
        '订单总金额',
        '订单总积分',
        '支付方式',
        '收货人',
        '收货电话',
        '收货地址',
        '订单备注',
        '管理员备注',
        '下单时间',
        '支付时间',
    ];
    fputcsv($fp, $headers);
    
    // 写入数据
    foreach ($orders as $order) {
        $row = [
            $order['id'],
            $order['order_no'],
            $order['user_id'],
            $order['user_mobile'],
            $order['user_nickname'],
            $order['product_quantity'],
            $order['product_price'],
            $order['product_score_price'],
            $order['total_amount'],
            $order['total_score'],
            $order['pay_type_text'],
            $order['recipient_name'],
            $order['recipient_phone'],
            $order['recipient_address'],
            $order['remark'] ?? '',
            $order['admin_remark'] ?? '',
            $order['create_time_text'],
            $order['pay_time_text'],
        ];
        fputcsv($fp, $row);
    }
    
    fclose($fp);
    
    echo "=== 导出成功 ===\n";
    echo "文件: {$csvFile}\n";
    echo "订单数量: " . count($orders) . "\n\n";
    
    // 显示前10条订单信息
    echo "=== 订单列表（前10条）===\n";
    foreach (array_slice($orders, 0, 10) as $index => $order) {
        echo ($index + 1) . ". 订单ID: {$order['id']}, 订单号: {$order['order_no']}, 用户: {$order['user_mobile']}, 数量: {$order['product_quantity']}, 收货人: {$order['recipient_name']}\n";
    }
    
    if (count($orders) > 10) {
        echo "... 还有 " . (count($orders) - 10) . " 条\n";
    }
    
    // 统计信息
    echo "\n=== 统计信息 ===\n";
    $totalQuantity = array_sum(array_column($orders, 'product_quantity'));
    $totalAmount = array_sum(array_column($orders, 'total_amount'));
    $totalScore = array_sum(array_column($orders, 'total_score'));
    
    echo "商品总数量: {$totalQuantity}\n";
    echo "订单总金额: ¥" . number_format($totalAmount, 2) . "\n";
    echo "订单总积分: {$totalScore}\n";
    
} catch (\Exception $e) {
    echo "❌ 导出失败: " . $e->getMessage() . "\n";
    echo "文件: " . $e->getFile() . "\n";
    echo "行号: " . $e->getLine() . "\n";
    exit(1);
}

echo "\n✓ 脚本执行完成\n";
