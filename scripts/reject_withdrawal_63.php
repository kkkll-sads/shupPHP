<?php
/**
 * 拒绝特定提现申请并退还余额
 * 记录：Review ID 67, Withdraw ID 63, User 285, Amount 20.00
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use think\facade\Db;
use app\common\model\UserActivityLog;

$app = new think\App(dirname(__DIR__));
$app->initialize();

$reviewId = 67;
$withdrawId = 63;
$userId = 285;
$amount = 20.00;
$remark = '请填写正确的支付宝收款账号！'; // 用户提供的详情中包含此备注

echo "开始处理提现拒绝：Review ID {$reviewId}, Withdraw ID {$withdrawId}, User ID {$userId}...\n";

Db::startTrans();
try {
    // 1. 验证记录
    $review = Db::name('withdraw_review')->where('id', $reviewId)->find();
    if (!$review) {
        throw new \Exception("Review ID {$reviewId} 不存在");
    }
    if ($review['applicant_id'] != $userId || (float)$review['amount'] != $amount) {
        throw new \Exception("Review 信息不匹配");
    }

    $withdraw = Db::name('user_withdraw')->where('id', $withdrawId)->find();
    if (!$withdraw) {
        throw new \Exception("Withdraw ID {$withdrawId} 不存在");
    }

    // 2. 更新状态为拒绝 (2)
    Db::name('withdraw_review')->where('id', $reviewId)->update([
        'status' => 2,
        'audit_remark' => $remark,
        'audit_time' => time(),
        'audit_admin_id' => 1, // 控制台操作，假设 ID 1
        'update_time' => time()
    ]);

    Db::name('user_withdraw')->where('id', $withdrawId)->update([
        'status' => 2,
        'audit_reason' => $remark,
        'audit_time' => time(),
        'audit_admin_id' => 1,
        'update_time' => time()
    ]);

    // 3. 退还余额到 withdrawable_money
    $user = Db::name('user')->where('id', $userId)->lock(true)->find();
    if (!$user) {
        throw new \Exception("用户 ID {$userId} 不存在");
    }

    $beforeWithdrawable = (float)$user['withdrawable_money'];
    $afterWithdrawable = round($beforeWithdrawable + $amount, 2);

    Db::name('user')->where('id', $userId)->update([
        'withdrawable_money' => $afterWithdrawable,
        'update_time' => time()
    ]);

    // 4. 记录资金日志
    Db::name('user_money_log')->insert([
        'user_id' => $userId,
        'money' => $amount,
        'before' => $beforeWithdrawable,
        'after' => $afterWithdrawable,
        'memo' => '提现审核拒绝，退回可提现余额（后台操作）',
        'biz_type' => 'withdraw_reject',
        'biz_id' => $withdrawId,
        'create_time' => time(),
    ]);

    // 5. 记录活动日志
    UserActivityLog::create([
        'user_id' => $userId,
        'related_user_id' => 0,
        'action_type' => 'withdrawable_money',
        'change_field' => 'withdrawable_money',
        'change_value' => (string)$amount,
        'before_value' => (string)$beforeWithdrawable,
        'after_value' => (string)$afterWithdrawable,
        'remark' => '提现审核拒绝，退回可提现余额，提现审核ID：' . $reviewId,
        'extra' => [
            'withdraw_id' => $withdrawId,
            'review_id' => $reviewId,
            'refund_amount' => $amount,
            'audit_remark' => $remark,
        ],
    ]);

    Db::commit();
    echo "操作成功！已拒绝申请并退回 {$amount} 元到用户 {$userId} 的可提现余额。\n";
} catch (\Exception $e) {
    Db::rollback();
    echo "操作失败: " . $e->getMessage() . "\n";
}
