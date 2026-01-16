<?php
/**
 * 自动导入物流单号脚本
 * 从Excel文件读取订单ID、物流公司、物流单号，批量更新到数据库
 */

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use think\facade\Db;

// 引导 ThinkPHP 应用
$app = new think\App();
$app->initialize();

$excelFile = __DIR__ . '/1.14日九块二出单236条共6108.8元 (2).xlsx';

if (!file_exists($excelFile)) {
    echo "❌ Excel文件不存在: {$excelFile}\n";
    exit(1);
}

echo "=== 开始自动导入物流单号 ===\n";
echo "Excel文件: {$excelFile}\n";
echo "时间: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // 读取 Excel 文件
    echo "正在读取Excel文件...\n";
    $spreadsheet = IOFactory::load($excelFile);
    $sheet = $spreadsheet->getActiveSheet();
    $highestRow = $sheet->getHighestRow();
    
    echo "总行数: {$highestRow}（包含标题行）\n";
    echo "数据行数: " . ($highestRow - 1) . "\n\n";
    
    echo "=== 数据格式 ===\n";
    echo "第1列: 物流公司\n";
    echo "第2列: 物流单号\n";
    echo "第3列: 订单ID\n";
    echo "从第2行开始导入（跳过标题行）\n\n";
    
    $successCount = 0;
    $failCount = 0;
    $skipCount = 0;
    $errors = [];
    $updates = [];
    
    Db::startTrans();
    try {
        // 从第2行开始（跳过标题行）
        for ($row = 2; $row <= $highestRow; $row++) {
            // 读取数据
            $shippingCompany = trim((string)$sheet->getCell([1, $row])->getValue());
            $trackingNo = trim((string)$sheet->getCell([2, $row])->getValue());
            $orderId = trim((string)$sheet->getCell([3, $row])->getValue());
            
            // 跳过空行
            if (empty($orderId) || empty($trackingNo)) {
                $skipCount++;
                echo "⊘ 行{$row}: 跳过空数据（订单ID={$orderId}, 物流单号={$trackingNo}）\n";
                continue;
            }
            
            // 确保订单ID是数字
            if (!is_numeric($orderId)) {
                $failCount++;
                $errors[] = "行{$row}: 订单ID '{$orderId}' 不是有效的数字";
                echo "❌ 行{$row}: 订单ID '{$orderId}' 不是有效的数字\n";
                continue;
            }
            
            $orderId = (int)$orderId;
            
            // 查询订单
            $order = Db::name('shop_order')
                ->where('id', $orderId)
                ->find();
            
            if (!$order) {
                $failCount++;
                $errors[] = "行{$row}: 订单ID {$orderId} 不存在";
                echo "❌ 行{$row}: 订单ID {$orderId} 不存在\n";
                continue;
            }
            
            // 检查订单状态
            if ($order['status'] === 'shipped') {
                // 已经发货，只更新物流信息
                $updateData = [
                    'shipping_no' => $trackingNo,
                    'shipping_company' => $shippingCompany,
                    'update_time' => time(),
                ];
                echo "ⓘ 行{$row}: 订单 {$orderId} (订单号:{$order['order_no']}) 已是发货状态，仅更新物流信息\n";
            } elseif ($order['status'] === 'paid') {
                // 已支付，更新为已发货
                $updateData = [
                    'shipping_no' => $trackingNo,
                    'shipping_company' => $shippingCompany,
                    'status' => 'shipped',
                    'ship_time' => time(),
                    'update_time' => time(),
                ];
                echo "✓ 行{$row}: 订单 {$orderId} (订单号:{$order['order_no']}) 标记为已发货\n";
            } else {
                $failCount++;
                $errors[] = "行{$row}: 订单ID {$orderId} 状态为 '{$order['status']}'，不是已支付或已发货状态";
                echo "⚠️  行{$row}: 订单 {$orderId} (订单号:{$order['order_no']}) 状态为 '{$order['status']}'，跳过\n";
                continue;
            }
            
            // 执行更新
            $result = Db::name('shop_order')
                ->where('id', $orderId)
                ->update($updateData);
            
            if ($result !== false) {
                $successCount++;
                $updates[] = [
                    'order_id' => $orderId,
                    'order_no' => $order['order_no'],
                    'tracking_no' => $trackingNo,
                    'company' => $shippingCompany,
                    'old_status' => $order['status'],
                    'new_status' => $updateData['status'] ?? $order['status'],
                ];
            } else {
                $failCount++;
                $errors[] = "行{$row}: 订单ID {$orderId} 更新失败";
                echo "❌ 行{$row}: 订单 {$orderId} 更新失败\n";
            }
        }
        
        Db::commit();
        
        echo "\n=== 导入完成 ===\n";
        echo "✓ 成功: {$successCount} 条\n";
        echo "✗ 失败: {$failCount} 条\n";
        echo "⊘ 跳过: {$skipCount} 条\n";
        echo "总计: " . ($successCount + $failCount + $skipCount) . " 条\n";
        
        if ($successCount > 0) {
            echo "\n=== 成功更新的订单（前10条）===\n";
            foreach (array_slice($updates, 0, 10) as $update) {
                echo "订单ID: {$update['order_id']}, 订单号: {$update['order_no']}, 物流单号: {$update['tracking_no']}, 状态: {$update['old_status']} → {$update['new_status']}\n";
            }
            if (count($updates) > 10) {
                echo "... 还有 " . (count($updates) - 10) . " 条\n";
            }
        }
        
        if (!empty($errors)) {
            echo "\n=== 错误详情 ===\n";
            foreach ($errors as $error) {
                echo "{$error}\n";
            }
        }
        
        // 生成导入报告
        $reportFile = __DIR__ . '/import_tracking_report_' . date('YmdHis') . '.txt';
        $reportContent = "物流单号导入报告\n";
        $reportContent .= "==========================================\n";
        $reportContent .= "导入时间: " . date('Y-m-d H:i:s') . "\n";
        $reportContent .= "Excel文件: {$excelFile}\n";
        $reportContent .= "成功: {$successCount} 条\n";
        $reportContent .= "失败: {$failCount} 条\n";
        $reportContent .= "跳过: {$skipCount} 条\n";
        $reportContent .= "==========================================\n\n";
        
        $reportContent .= "成功更新的订单:\n";
        foreach ($updates as $update) {
            $reportContent .= "订单ID: {$update['order_id']}, 订单号: {$update['order_no']}, 物流: {$update['company']} {$update['tracking_no']}, 状态: {$update['old_status']} → {$update['new_status']}\n";
        }
        
        if (!empty($errors)) {
            $reportContent .= "\n错误详情:\n";
            foreach ($errors as $error) {
                $reportContent .= "{$error}\n";
            }
        }
        
        file_put_contents($reportFile, $reportContent);
        echo "\n📄 导入报告已保存: {$reportFile}\n";
        
    } catch (\Exception $e) {
        Db::rollback();
        echo "\n❌ 导入失败，事务已回滚: " . $e->getMessage() . "\n";
        echo "文件: " . $e->getFile() . "\n";
        echo "行号: " . $e->getLine() . "\n";
        exit(1);
    }
    
} catch (\Exception $e) {
    echo "❌ 读取Excel文件失败: " . $e->getMessage() . "\n";
    echo "文件: " . $e->getFile() . "\n";
    echo "行号: " . $e->getLine() . "\n";
    exit(1);
}

echo "\n✓ 脚本执行完成\n";
