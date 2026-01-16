<?php
/**
 * 导出昨天的支付宝和银行卡提现订单（账号明文）
 * 执行方式：php export_yesterday_withdrawals_plaintext.php
 */

require __DIR__ . '/vendor/autoload.php';

use think\facade\Db;

// 初始化 ThinkPHP 应用
$app = new think\App();
$app->initialize();

echo "=== 导出昨天的提现订单（账号明文） ===\n";
echo "执行时间: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // 计算昨天的日期范围
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $yesterdayStart = strtotime($yesterday . ' 00:00:00');
    $yesterdayEnd = strtotime($yesterday . ' 23:59:59');
    
    echo "查询日期: {$yesterday}\n";
    echo "时间范围: " . date('Y-m-d H:i:s', $yesterdayStart) . " ~ " . date('Y-m-d H:i:s', $yesterdayEnd) . "\n\n";
    
    // 查询昨天的提现订单
    $withdrawals = Db::name('user_withdraw')
        ->alias('uw')
        ->leftJoin('user u', 'uw.user_id = u.id')
        ->where('uw.create_time', '>=', $yesterdayStart)
        ->where('uw.create_time', '<=', $yesterdayEnd)
        ->whereIn('uw.account_type', ['alipay', 'bank_card'])
        ->field([
            'uw.id',
            'uw.user_id',
            'u.mobile',
            'u.nickname',
            'uw.amount',
            'uw.fee',
            'uw.actual_amount',
            'uw.status',
            'uw.account_type',
            'uw.account_name',
            'uw.account_number',
            'uw.bank_name',
            'uw.bank_branch',
            'uw.audit_time',
            'uw.pay_time',
            'uw.create_time',
            'uw.remark',
            'uw.audit_reason',
            'uw.pay_reason',
        ])
        ->order('uw.create_time', 'asc')
        ->select()
        ->toArray();
    
    echo "查询到 " . count($withdrawals) . " 条提现订单\n\n";
    
    if (empty($withdrawals)) {
        echo "没有找到昨天的提现订单\n";
        exit(0);
    }
    
    // 按提现方式分类
    $alipayOrders = [];
    $bankCardOrders = [];
    
    foreach ($withdrawals as $order) {
        // 解密账号（如果是base64编码的）
        $accountNumber = $order['account_number'];
        if (!empty($accountNumber)) {
            // 尝试base64解码
            $decoded = base64_decode($accountNumber, true);
            if ($decoded !== false && mb_check_encoding($decoded, 'UTF-8')) {
                $accountNumber = $decoded;
            }
        }
        
        // 状态映射 (tinyint: 0=待审核, 1=审核通过, 2=审核拒绝, 3=已打款)
        $statusMap = [
            0 => '待审核',
            1 => '审核通过',
            2 => '审核拒绝',
            3 => '已打款',
        ];
        
        $orderData = [
            '订单ID' => $order['id'],
            '用户ID' => $order['user_id'],
            '手机号' => $order['mobile'],
            '昵称' => $order['nickname'],
            '提现金额' => $order['amount'],
            '手续费' => $order['fee'],
            '实际到账' => $order['actual_amount'],
            '状态' => $statusMap[$order['status']] ?? '未知',
            '账户类型' => $order['account_type'] === 'alipay' ? '支付宝' : '银行卡',
            '账户姓名' => $order['account_name'],
            '账号（明文）' => $accountNumber,
            '银行名称' => $order['bank_name'] ?? '',
            '支行' => $order['bank_branch'] ?? '',
            '创建时间' => date('Y-m-d H:i:s', $order['create_time']),
            '审核时间' => $order['audit_time'] ? date('Y-m-d H:i:s', $order['audit_time']) : '',
            '打款时间' => $order['pay_time'] ? date('Y-m-d H:i:s', $order['pay_time']) : '',
            '备注' => $order['remark'] ?? '',
            '审核理由' => $order['audit_reason'] ?? '',
            '打款备注' => $order['pay_reason'] ?? '',
        ];
        
        if ($order['account_type'] === 'alipay') {
            $alipayOrders[] = $orderData;
        } else {
            $bankCardOrders[] = $orderData;
        }
    }
    
    // 统计信息
    echo "=== 统计信息 ===\n";
    echo "支付宝提现订单: " . count($alipayOrders) . " 条\n";
    echo "银行卡提现订单: " . count($bankCardOrders) . " 条\n\n";
    
    // 导出支付宝提现订单
    if (!empty($alipayOrders)) {
        $alipayFile = __DIR__ . '/支付宝提现订单_' . $yesterday . '_明文.csv';
        $fp = fopen($alipayFile, 'w');
        fwrite($fp, "\xEF\xBB\xBF"); // BOM for UTF-8
        
        // 写入表头
        fputcsv($fp, array_keys($alipayOrders[0]));
        
        // 写入数据
        foreach ($alipayOrders as $order) {
            fputcsv($fp, $order);
        }
        
        fclose($fp);
        
        // 计算总金额
        $alipayTotalAmount = array_sum(array_column($alipayOrders, '提现金额'));
        $alipayTotalFee = array_sum(array_column($alipayOrders, '手续费'));
        $alipayTotalActual = array_sum(array_column($alipayOrders, '实际到账'));
        
        echo "✓ 支付宝提现订单已导出: {$alipayFile}\n";
        echo "  订单数量: " . count($alipayOrders) . " 条\n";
        echo "  提现金额总计: ¥" . number_format($alipayTotalAmount, 2) . "\n";
        echo "  手续费总计: ¥" . number_format($alipayTotalFee, 2) . "\n";
        echo "  实际到账总计: ¥" . number_format($alipayTotalActual, 2) . "\n\n";
    } else {
        echo "⚠ 昨天没有支付宝提现订单\n\n";
    }
    
    // 导出银行卡提现订单
    if (!empty($bankCardOrders)) {
        $bankCardFile = __DIR__ . '/银行卡提现订单_' . $yesterday . '_明文.csv';
        $fp = fopen($bankCardFile, 'w');
        fwrite($fp, "\xEF\xBB\xBF"); // BOM for UTF-8
        
        // 写入表头
        fputcsv($fp, array_keys($bankCardOrders[0]));
        
        // 写入数据
        foreach ($bankCardOrders as $order) {
            fputcsv($fp, $order);
        }
        
        fclose($fp);
        
        // 计算总金额
        $bankTotalAmount = array_sum(array_column($bankCardOrders, '提现金额'));
        $bankTotalFee = array_sum(array_column($bankCardOrders, '手续费'));
        $bankTotalActual = array_sum(array_column($bankCardOrders, '实际到账'));
        
        echo "✓ 银行卡提现订单已导出: {$bankCardFile}\n";
        echo "  订单数量: " . count($bankCardOrders) . " 条\n";
        echo "  提现金额总计: ¥" . number_format($bankTotalAmount, 2) . "\n";
        echo "  手续费总计: ¥" . number_format($bankTotalFee, 2) . "\n";
        echo "  实际到账总计: ¥" . number_format($bankTotalActual, 2) . "\n\n";
    } else {
        echo "⚠ 昨天没有银行卡提现订单\n\n";
    }
    
    // 总计
    if (!empty($alipayOrders) || !empty($bankCardOrders)) {
        $totalAmount = ($alipayTotalAmount ?? 0) + ($bankTotalAmount ?? 0);
        $totalFee = ($alipayTotalFee ?? 0) + ($bankTotalFee ?? 0);
        $totalActual = ($alipayTotalActual ?? 0) + ($bankTotalActual ?? 0);
        
        echo "=== 总计 ===\n";
        echo "订单总数: " . count($withdrawals) . " 条\n";
        echo "提现金额总计: ¥" . number_format($totalAmount, 2) . "\n";
        echo "手续费总计: ¥" . number_format($totalFee, 2) . "\n";
        echo "实际到账总计: ¥" . number_format($totalActual, 2) . "\n";
    }
    
} catch (\Exception $e) {
    echo "❌ 导出失败: " . $e->getMessage() . "\n";
    echo "文件: " . $e->getFile() . "\n";
    echo "行号: " . $e->getLine() . "\n";
    exit(1);
}

echo "\n✓ 导出完成\n";
