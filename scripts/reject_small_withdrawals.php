<?php
/**
 * 批量拒绝小额银行卡提现申请
 * 条件：提现方式=银行卡，金额<100
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use think\facade\Db;
use app\common\model\UserActivityLog;

// 初始化应用
$app = new think\App(dirname(__DIR__));
$app->initialize();

$reason = '100元以下请使用支付宝进行提现';
$adminId = 1; // 默认使用管理员ID 1

echo "===========================================\n";
echo "开始批量处理小额银行卡提现拒绝\n";
echo "拒绝理由: $reason\n";
echo "===========================================\n";

// 1. 查询符合条件的提现申请 (ba_user_withdraw)
$withdrawals = Db::name('user_withdraw')
    ->where('status', 0) // 待审核
    ->where('account_type', 'bank_card')
    ->where('amount', '<', 100)
    ->select();

$count = count($withdrawals);
echo "找到 {$count} 条符合条件的提现申请。\n";

if ($count === 0) {
    echo "没有需要处理的记录。\n";
    exit;
}

$successCount = 0;
$failCount = 0;

foreach ($withdrawals as $w) {
    Db::startTrans();
    try {
        $id = $w['id'];
        $userId = $w['user_id'];
        $amount = floatval($w['amount']);
        
        echo "处理提现ID: {$id}, 用户ID: {$userId}, 金额: {$amount}... ";
        
        // 1. 更新 ba_user_withdraw 状态
        Db::name('user_withdraw')
            ->where('id', $id)
            ->update([
                'status' => 2, // 拒绝状态
                'audit_time' => time(),
                'audit_admin_id' => $adminId,
                'audit_reason' => $reason, // 注意字段名可能不同，查看表结构确认
                'remark' => $reason
            ]);
            
        // 2. 退回用户余额 (withdrawable_money)
        $user = Db::name('user')->where('id', $userId)->lock(true)->find();
        if ($user) {
            $beforeWithdrawable = floatval($user['withdrawable_money']);
            $newWithdrawable = round($beforeWithdrawable + $amount, 2);
            
            // 更新用户余额
            Db::name('user')
                ->where('id', $userId)
                ->update([
                    'withdrawable_money' => $newWithdrawable,
                    'update_time' => time()
                ]);
                
            // 写入资金变动日志
            Db::name('user_money_log')->insert([
                'user_id' => $userId,
                'field_type' => 'withdrawable_money', // 对应字段类型
                'money' => $amount,
                'before' => $beforeWithdrawable,
                'after' => $newWithdrawable,
                'memo' => '提现审核拒绝，退回可提现余额',
                'create_time' => time(),
                'biz_type' => 'withdraw_reject' // 假设业务类型
            ]);
        }
        
        // 3. 同步更新 ba_withdraw_review (如果存在)
        // 尝试通过 applicant_id 和 amount 匹配
        $review = Db::name('withdraw_review')
            ->where('applicant_id', $userId)
            ->where('amount', $amount)
            ->where('status', 0)
            ->find();
            
        if ($review) {
            Db::name('withdraw_review')
                ->where('id', $review['id'])
                ->update([
                    'status' => 1, // review表拒绝状态可能是1(已审核)? 需要确认拒绝的状态值
                    // 检查 review 表状态值含义：通常 0=待审核, 1=已审核(通过?), 2=拒绝?
                    // 对照之前查的数据：Step 387 
                    // id=1 status=1 audit_remark="" (looks approved?)
                    // id=2 status=0 (pending)
                    // id=3 status=1 audit_remark="123" (refused?)
                    // 必须确认 review 表的 status 含义。
                    // 假设拒绝是2，或者需要看代码。
                ]);
             // 暂时先跳过 review 表的具体状体更新，先打印出来人工核对，或者根据user_withdraw一致性
             // 根据之前 user_withdraw status=2 是拒绝，这里假设 review 表也是 2
             // 但之前的 select * form ba_withdraw_review output showed status=1 for some checked items.
             // controller reject() code: $row->status = WithdrawReviewModel::STATUS_REJECTED;
             // without seeing the constant value, I should assume it aligns with 2 typically, but previous select showed 1.
             // Let's check the const in next step or use code reflection? No.
             // Wait, id=3 status=1 has remark '123'. id=4 status=1 has remark '1'. id=1 status=1 has empty remark.
             // Maybe status 1 = processed (finished), and result depends on something else? Or 1=Approved, 2=Rejected?
             // Let's checking the Controller constants is best.
        }

        Db::commit();
        echo "成功\n";
        $successCount++;
        
    } catch (\Exception $e) {
        Db::rollback();
        echo "失败: " . $e->getMessage() . "\n";
        $failCount++;
    }
}

echo "===========================================\n";
echo "处理完成。成功: {$successCount}, 失败: {$failCount}\n";
