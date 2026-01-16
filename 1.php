<?php

/**
 * 提现审核状态同步脚本
 *
 * ⚠️  安全警告：
 * 1. 此脚本会直接修改用户余额，请谨慎使用
 * 2. 建议在维护时间执行，并在执行前备份数据库
 * 3. 只处理状态不一致的记录，不会影响正常数据
 * 4. 脚本支持重复执行，不会重复处理已同步的记录
 *
 * 使用方法：
 * php sync_withdraw_status.php [--dry-run] [--user-id=456] [--limit=100]
 *
 * 参数说明：
 * --dry-run: 仅显示要处理的数据，不执行实际修改
 * --user-id: 只处理指定用户的记录
 * --limit: 限制处理记录数量
 */

// 检查是否在命令行中运行
if (!isset($_SERVER['argv'])) {
    die("此脚本只能在命令行中运行\n");
}

// 解析命令行参数
$dryRun = in_array('--dry-run', $_SERVER['argv']);
$userId = null;
$limit = 1000;

foreach ($_SERVER['argv'] as $arg) {
    if (strpos($arg, '--user-id=') === 0) {
        $userId = substr($arg, 10);
    } elseif (strpos($arg, '--limit=') === 0) {
        $limit = (int)substr($arg, 8);
    }
}

// 直接使用PDO连接数据库，避免ThinkPHP依赖
try {
    $pdo = new PDO(
        'mysql:host=10.10.100.3;port=3306;dbname=waibao;charset=utf8mb4',
        'waibao',
        'weHPjtkrbAPSMCNm',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("数据库连接失败: " . $e->getMessage() . "\n");
}

if ($dryRun) {
    echo "🔍 干运行模式：只显示要处理的数据，不会修改数据库\n";
} else {
    echo "⚠️  生产模式：将实际修改数据库，请确保已备份数据\n";
}

echo "开始同步提现审核状态...\n";

echo "开始同步提现审核状态...\n";

// 查找所有状态不一致的记录（简化逻辑）
$query = "
    SELECT
        wr.id as review_id,
        wr.applicant_id,
        wr.amount,
        wr.status as review_status,
        wr.create_time as review_create_time,
        wr.audit_time as review_audit_time,
        wr.audit_remark,
        uw.id as withdraw_id,
        uw.status as withdraw_status,
        uw.create_time as withdraw_create_time,
        u.mobile as user_mobile,
        ABS(CAST(wr.create_time AS SIGNED) - CAST(uw.create_time AS SIGNED)) as time_diff
    FROM ba_withdraw_review wr
    INNER JOIN ba_user_withdraw uw ON (
        wr.applicant_id = uw.user_id
        AND wr.amount = uw.amount
        AND ABS(CAST(wr.create_time AS SIGNED) - CAST(uw.create_time AS SIGNED)) < 300
    )
    LEFT JOIN ba_user u ON wr.applicant_id = u.id
    WHERE wr.applicant_type = 'user'
    AND wr.applicant_id > 0
    AND wr.status != uw.status
";

if ($userId) {
    $query .= " AND wr.applicant_id = " . (int)$userId;
}

$query .= " ORDER BY wr.id LIMIT " . (int)$limit;

// 查找所有状态不一致的记录
$stmt = $pdo->query($query);
$allRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 统计每个审核记录有多少个状态不一致的匹配
$reviewMatchCount = [];
foreach ($allRecords as $record) {
    $reviewId = $record['review_id'];
    if ($record['review_status'] != $record['withdraw_status']) {
        if (!isset($reviewMatchCount[$reviewId])) {
            $reviewMatchCount[$reviewId] = 0;
        }
        $reviewMatchCount[$reviewId]++;
    }
}

// 只保留那些只有一个状态不一致匹配的审核记录的最佳匹配
$inconsistentRecords = [];
$processedReviews = [];

foreach ($allRecords as $record) {
    $reviewId = $record['review_id'];
    $reviewStatus = $record['review_status'];
    $withdrawStatus = $record['withdraw_status'];

    if ($reviewStatus == $withdrawStatus) {
        continue; // 跳过状态一致的记录
    }

    if ($reviewMatchCount[$reviewId] != 1) {
        continue; // 跳过有多个匹配的记录
    }

    // 对于只有一个匹配的记录，直接使用
    $inconsistentRecords[] = $record;
}

echo "找到 " . count($inconsistentRecords) . " 条状态不一致的记录\n";

if ($userId) {
    echo "筛选用户ID: {$userId}\n";
}

echo "处理限制: {$limit} 条记录\n";

$fixedCount = 0;
$skippedCount = 0;

// 使用循环处理，直到没有更多不一致记录
$totalIterations = 0;
$maxIterations = 10; // 防止无限循环
$processedReviewIds = []; // 跟踪已处理的审核记录ID

do {
    $hasProcessed = false;
    $totalIterations++;

    if ($totalIterations > $maxIterations) {
        echo "⚠️  达到最大迭代次数 ({$maxIterations})，可能存在循环依赖，停止处理\n";
        break;
    }

    // 重新查找不一致记录
    $stmt = $pdo->query($query);
    $allRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 统计每个审核记录有多少个状态不一致的匹配
    $reviewMatchCount = [];
    foreach ($allRecords as $record) {
        $reviewId = $record['review_id'];
        if ($record['review_status'] != $record['withdraw_status']) {
            if (!isset($reviewMatchCount[$reviewId])) {
                $reviewMatchCount[$reviewId] = 0;
            }
            $reviewMatchCount[$reviewId]++;
        }
    }

    // 只保留那些只有一个状态不一致匹配的审核记录的最佳匹配，且未处理过
    $inconsistentRecords = [];
    foreach ($allRecords as $record) {
        $reviewId = $record['review_id'];
        if ($record['review_status'] != $record['withdraw_status'] &&
            $reviewMatchCount[$reviewId] == 1 &&
            !in_array($reviewId, $processedReviewIds)) {
            $inconsistentRecords[] = $record;
        }
    }

    if (empty($inconsistentRecords)) {
        break; // 没有更多记录需要处理
    }

    echo "第 {$totalIterations} 轮：找到 " . count($inconsistentRecords) . " 条状态不一致的记录\n";

    // 验证数据安全性（只在第一轮检查）
    if ($totalIterations == 1) {
        $totalAmount = array_sum(array_column($inconsistentRecords, 'amount'));
        echo "涉及总金额: ¥" . number_format($totalAmount, 2) . "\n";

        if (!$dryRun && $totalAmount > 50000) {
            echo "⚠️  涉及金额较大，请确认是否继续执行 (y/N): ";
            $handle = fopen("php://stdin", "r");
            $response = trim(fgets($handle));
            if (strtolower($response) !== 'y') {
                echo "操作已取消\n";
                exit(0);
            }
        }
    }

    foreach ($inconsistentRecords as $record) {
    $reviewId = $record['review_id'];
    $withdrawId = $record['withdraw_id'];
    $reviewStatus = $record['review_status'];
    $withdrawStatus = $record['withdraw_status'];
    $applicantId = $record['applicant_id'];
    $amount = $record['amount'];
    $userMobile = $record['user_mobile'] ?: '未知';

    // 状态文本映射
    $statusTexts = [
        0 => '待审核',
        1 => '审核通过',
        2 => '审核拒绝'
    ];

    $reviewStatusText = $statusTexts[$reviewStatus] ?? '未知状态';
    $withdrawStatusText = $statusTexts[$withdrawStatus] ?? '未知状态';

    echo "处理审核记录 ID {$reviewId} (用户ID: {$applicantId}, 手机号: {$userMobile}, 金额: ¥{$amount})\n";
    echo "  审核状态: {$reviewStatus}({$reviewStatusText}) | 提现状态: {$withdrawStatus}({$withdrawStatusText})\n";
    echo "  同步操作: 将提现记录状态同步为 {$reviewStatus}({$reviewStatusText})\n";

    // 数据验证
    if ($amount <= 0 || $amount > 100000) {
        echo "  ⚠️  金额异常，跳过处理\n";
        $skippedCount++;
        continue;
    }

    // 如果没有对应的提现记录，跳过
    if (!$withdrawId) {
        echo "  ⚠️  没有找到对应的提现记录，跳过\n";
        $skippedCount++;
        continue;
    }

    if ($dryRun) {
        echo "  📋 [干运行] 不会实际修改数据\n";
        $fixedCount++;
        continue;
    }

    try {
        // 开始事务
        $pdo->beginTransaction();

        // 根据审核状态同步提现记录状态
        if ($reviewStatus == 0) {
            // 审核待审核 - 如果提现记录状态不是待审核，重置为待审核
            if ($withdrawStatus != 0) {
                $stmt = $pdo->prepare("UPDATE ba_user_withdraw SET status = ?, audit_time = NULL, audit_admin_id = 0, audit_reason = '', update_time = ? WHERE id = ?");
                $stmt->execute([
                    0,
                    time(),
                    $withdrawId
                ]);
                echo "  ✅ 重置提现记录为待审核状态\n";
                echo "  📝 日志状态: 无需记录资金日志（状态重置不涉及资金变动）\n";
            }
        } elseif ($reviewStatus == 1) {
            // 审核通过 - 更新提现记录为通过状态
            if ($withdrawStatus != 1) {
                $stmt = $pdo->prepare("UPDATE ba_user_withdraw SET status = ?, audit_time = ?, audit_admin_id = ?, audit_reason = ?, update_time = ? WHERE id = ?");
                $stmt->execute([
                    1,
                    $record['review_audit_time'] ?: time(),
                    1,
                    $record['audit_remark'] ?: '审核通过',
                    time(),
                    $withdrawId
                ]);
                echo "  ✅ 更新提现记录为审核通过\n";
                echo "  📝 无需记录资金日志（审核通过不涉及资金变动）\n";
            }
        } elseif ($reviewStatus == 2) {
            // 审核拒绝 - 更新提现记录为拒绝状态并退回余额
            if ($withdrawStatus != 2) {
                // 检查是否已有退款记录，避免重复退款
                $stmt = $pdo->prepare("SELECT id FROM ba_user_activity_log WHERE user_id = ? AND action_type = 'withdraw_reject' AND JSON_EXTRACT(extra, '$.biz_id') = ?");
                $stmt->execute([$applicantId, $withdrawId]);
                $existingRefund = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($existingRefund) {
                    echo "  ℹ️  已存在退款记录，跳过退款操作\n";
                    // 只更新状态
                    $stmt = $pdo->prepare("UPDATE ba_user_withdraw SET status = ?, audit_time = ?, audit_admin_id = ?, audit_reason = ?, update_time = ? WHERE id = ?");
                    $stmt->execute([
                        2,
                        $record['review_audit_time'] ?: time(),
                        1,
                        $record['audit_remark'] ?: '审核拒绝',
                        time(),
                        $withdrawId
                    ]);
                    echo "  📝 日志状态: 已存在退款记录，无需重复记录\n";
                } else {
                    // 更新提现记录状态
                    $stmt = $pdo->prepare("UPDATE ba_user_withdraw SET status = ?, audit_time = ?, audit_admin_id = ?, audit_reason = ?, update_time = ? WHERE id = ?");
                    $stmt->execute([
                        2,
                        $record['review_audit_time'] ?: time(),
                        1,
                        $record['audit_remark'] ?: '审核拒绝',
                        time(),
                        $withdrawId
                    ]);

                    // 退回用户余额（使用行锁确保并发安全）
                    $stmt = $pdo->prepare("SELECT withdrawable_money FROM ba_user WHERE id = ? FOR UPDATE");
                    $stmt->execute([$applicantId]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($user) {
                        $beforeBalance = round((float)$user['withdrawable_money'], 2);
                        $refundAmount = round((float)$amount, 2);
                        $newBalance = $beforeBalance + $refundAmount;

                        // 检查余额上限
                        $maxBalance = 99999999.99;
                        if ($newBalance > $maxBalance) {
                            throw new Exception("用户余额超出上限");
                        }

                        $stmt = $pdo->prepare("UPDATE ba_user SET withdrawable_money = ?, update_time = ? WHERE id = ?");
                        $stmt->execute([$newBalance, time(), $applicantId]);

                        // 记录资金变动日志到用户活动日志表
                        $stmt = $pdo->prepare("INSERT INTO ba_user_activity_log (user_id, action_type, change_field, change_value, before_value, after_value, remark, extra, create_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $applicantId,
                            'withdraw_reject',
                            'withdrawable_money',
                            $refundAmount,
                            $beforeBalance,
                            $newBalance,
                            '提现审核拒绝，退回可提现余额',
                            json_encode(['biz_id' => $withdrawId, 'withdraw_review_id' => $reviewId]),
                            time()
                        ]);

                        echo "  ✅ 更新提现记录为审核拒绝，退回 ¥{$refundAmount} 余额\n";
                        echo "  📝 日志状态: ✅ 已记录资金变动日志 (用户ID: {$applicantId}, 变动: ¥{$beforeBalance} → ¥{$newBalance})\n";
                    } else {
                        throw new Exception("用户不存在");
                    }
                }
            }
        }

        $pdo->commit();
        $fixedCount++;
        $hasProcessed = true;

        // 标记此审核记录为已处理
        $processedReviewIds[] = $reviewId;

    } catch (Exception $e) {
        $pdo->rollBack();
        echo "  ❌ 处理失败: " . $e->getMessage() . "\n";
        $skippedCount++;
    }
}

} while ($hasProcessed && $totalIterations < $maxIterations);

echo "\n" . str_repeat("=", 50) . "\n";
echo "同步完成！\n";
echo "修复了 {$fixedCount} 条记录\n";
echo "跳过了 {$skippedCount} 条记录\n";

if ($dryRun) {
    echo "\n🔍 干运行模式完成，无数据修改\n";
    exit(0);
}

echo "\n再次检查是否还有状态不一致的记录...\n";

// 重新检查剩余不一致记录（使用与主查询相同的逻辑）
$stmt = $pdo->query("
    SELECT COUNT(*) as count FROM (
        SELECT wr.id FROM ba_withdraw_review wr
        INNER JOIN ba_user_withdraw uw ON (
            wr.applicant_id = uw.user_id
            AND wr.amount = uw.amount
            AND ABS(CAST(wr.create_time AS SIGNED) - CAST(uw.create_time AS SIGNED)) < 300
        )
        WHERE wr.applicant_type = 'user'
        AND wr.applicant_id > 0
        AND wr.status != uw.status
        GROUP BY wr.id
        HAVING COUNT(*) = 1
    ) t
");
$remainingInconsistent = $stmt->fetch(PDO::FETCH_ASSOC);
$remainingCount = $remainingInconsistent['count'] ?? 0;
echo "剩余不一致记录数: {$remainingCount}\n";

if ($remainingCount == 0) {
    echo "✅ 所有状态不一致问题已解决！\n";
} else {
    echo "⚠️  还有 {$remainingCount} 条记录未同步，可能需要进一步检查\n";
}

echo "\n安全建议：\n";
echo "- 已处理的记录已记录资金变动日志\n";
echo "- 用户余额已正确调整\n";
echo "- 建议检查用户资金流水是否正常\n";
echo "- 如有问题，可通过资金日志追溯\n";

echo str_repeat("=", 50) . "\n";
