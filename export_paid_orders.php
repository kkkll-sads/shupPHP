<?php
/**
 * 导出已付款未发货的商城订单信息
 * 使用方法: php export_paid_orders.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = new think\App();
$app->initialize();

use think\facade\Db;

echo "=== 导出已付款未发货的商城订单 ===\n\n";

// 起始订单ID（从429开始）
$startOrderId = 429;

echo "起始订单ID: {$startOrderId}\n\n";

// 查询已付款未发货的订单 (status = 'paid')，从指定订单ID开始
$orders = Db::name('shop_order')
    ->alias('o')
    ->leftJoin('user u', 'o.user_id = u.id')
    ->where('o.status', 'paid')
    ->where('o.id', '>=', $startOrderId)
    ->field([
        'o.id as 订单ID',
        'o.order_no as 订单号',
        'u.mobile as 用户手机号',
        'u.username as 用户名',
        'u.nickname as 用户昵称',
        'o.total_amount as 订单总金额',
        'o.total_score as 订单总消费金',
        'o.pay_type as 支付方式',
        'o.recipient_name as 收货人姓名',
        'o.recipient_phone as 收货人电话',
        'o.recipient_address as 收货地址',
        'o.remark as 订单备注',
        'o.admin_remark as 管理员备注',
        'o.pay_time as 支付时间',
        'o.create_time as 创建时间',
    ])
    ->order('o.id desc')
    ->select()
    ->toArray();

if (empty($orders)) {
    echo "没有找到已付款未发货的订单\n";
    exit;
}

echo "找到 " . count($orders) . " 条已付款未发货的订单\n\n";

// 获取订单商品明细
foreach ($orders as &$order) {
    $orderId = $order['订单ID'];
    
    // 获取订单商品
    $items = Db::name('shop_order_item')
        ->alias('oi')
        ->leftJoin('shop_product p', 'oi.product_id = p.id')
        ->where('oi.order_id', $orderId)
        ->field([
            'oi.product_name as 商品名称',
            'oi.quantity as 数量',
            'oi.price as 单价',
            'oi.subtotal as 小计',
        ])
        ->select()
        ->toArray();
    
    $order['商品明细'] = '';
    $order['商品数量'] = 0;
    if (!empty($items)) {
        $itemList = [];
        $totalQty = 0;
        foreach ($items as $item) {
            $itemList[] = $item['商品名称'] . ' x' . $item['数量'] . ' (¥' . $item['单价'] . ')';
            $totalQty += (int)$item['数量'];
        }
        $order['商品明细'] = implode('; ', $itemList);
        $order['商品数量'] = $totalQty;
    }
    
    // 格式化支付方式
    $order['支付方式'] = $order['支付方式'] == 'money' ? '余额支付' : ($order['支付方式'] == 'score' ? '消费金支付' : $order['支付方式']);
    
    // 格式化时间
    $order['支付时间'] = $order['支付时间'] ? date('Y-m-d H:i:s', $order['支付时间']) : '';
    $order['创建时间'] = $order['创建时间'] ? date('Y-m-d H:i:s', $order['创建时间']) : '';
}

// 生成CSV文件
$filename = '已付款未发货订单_' . date('YmdHis') . '.csv';
$filepath = __DIR__ . '/' . $filename;

$fp = fopen($filepath, 'w');

// 写入BOM，支持Excel正确显示中文
fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));

// 写入表头
$headers = [
    '订单ID',
    '订单号',
    '用户手机号',
    '用户名',
    '用户昵称',
    '订单总金额',
    '订单总消费金',
    '支付方式',
    '商品明细',
    '商品数量',
    '收货人姓名',
    '收货人电话',
    '收货地址',
    '订单备注',
    '管理员备注',
    '支付时间',
    '创建时间',
];

fputcsv($fp, $headers);

// 写入数据
foreach ($orders as $order) {
    $row = [];
    foreach ($headers as $header) {
        $row[] = $order[$header] ?? '';
    }
    fputcsv($fp, $row);
}

fclose($fp);

echo "✓ 导出完成！\n";
echo "文件路径: {$filepath}\n";
echo "文件大小: " . number_format(filesize($filepath) / 1024, 2) . " KB\n";
echo "订单数量: " . count($orders) . " 条\n\n";

// 显示统计信息
$totalAmount = array_sum(array_column($orders, '订单总金额'));
$totalScore = array_sum(array_column($orders, '订单总消费金'));

echo "=== 统计信息 ===\n";
echo "订单总数: " . count($orders) . " 条\n";
echo "订单总金额: ¥" . number_format($totalAmount, 2) . "\n";
echo "订单总消费金: " . number_format($totalScore, 0) . " 分\n";
echo "平均订单金额: ¥" . number_format($totalAmount / count($orders), 2) . "\n\n";
