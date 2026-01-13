# 待处理文件清单 - money字段改造

## ✅ 已完成修改（12个文件）

### 核心模型
1. ✅ `app/common/model/User.php` - 添加money计算属性
2. ✅ `app/admin/model/User.php` - 添加money计算属性
3. ✅ `app/admin/model/UserMoneyLog.php` - 禁用自动更新

### 业务逻辑
4. ✅ `app/api/controller/Account.php` - 账户相关操作
5. ✅ `app/command/CollectionMatching.php` - 藏品撮合
6. ✅ `app/api/controller/CollectionItem.php` - 藏品交易
7. ✅ `app/listener/UserRegisterSuccess.php` - 注册奖励发放到withdrawable_money
8. ✅ `app/admin/controller/user/User.php` - 管理后台
9. ✅ `app/command/CollectionDailyDividend.php` - 每日分红（已验证正确）
10. ✅ `app/command/CollectionMiningDividend.php` - 矿机分红
11. ✅ `app/api/controller/FinanceProduct.php` - 理财产品购买
12. ✅ `app/command/FinanceIncomeDaily.php` - 理财每日返息

### 已验证无需修改（5个文件）
13. ✅ `app/common/library/SignIn.php` - 签到奖励（已正确发放到withdrawable_money）
14. ✅ `app/api/controller/SignIn.php` - 签到控制器（只读money用于展示）
15. ✅ `app/api/controller/Team.php` - 团队功能（只读money用于展示）
16. ✅ `app/admin/controller/finance/WithdrawReview.php` - 提现审核（日志记录正确）
17. ✅ `app/listener/UserRegisterSuccess.php` - 邀请人奖励（已正确发放到withdrawable_money）

---

## ⚠️ 需要修改（3个理财文件）

这三个文件需要相同的修改：将money字段改为withdrawable_money

### 理财周期返息
**文件：** `app/command/FinanceIncomePeriod.php`

**需要修改的地方：**
- 第112行：`'money' => $afterMoney` → `'withdrawable_money' => $afterWithdrawable`
- 第134行：`'money' => $incomeAmount` → 改为记录withdrawable_money变化
- 变量名：`$beforeMoney/$afterMoney` → `$beforeWithdrawable/$afterWithdrawable`

### 理财阶段返息
**文件：** `app/command/FinanceIncomeStage.php`

**需要修改的地方：**
- 第139行：`'money' => $afterMoney` → `'withdrawable_money' => $afterWithdrawable`
- 第161行：`'money' => $incomeAmount` → 改为记录withdrawable_money变化
- 变量名：`$beforeMoney/$afterMoney` → `$beforeWithdrawable/$afterWithdrawable`

### 理财订单结算
**文件：** `app/command/FinanceOrderSettle.php`

**需要修改的地方：**
- 第180、191、204行：`'money' => $afterMoney` → `'withdrawable_money' => $afterWithdrawable`
- 第210行：`'money' => $totalReturn` → 改为记录withdrawable_money变化
- 变量名：`$beforeMoney/$afterMoney` → `$beforeWithdrawable/$afterWithdrawable`

**修改原则：**
- 理财收益（本金+利息）统一进入 `withdrawable_money`
- 用户可以提现理财收益
- 更新活动日志的change_field从'money'改为'withdrawable_money'

---

## 💡 建议调整（积分商城）

### 商城订单
**文件：** `app/api/controller/ShopOrder.php`

**建议调整：**
- 积分商城应该只支持 `score`（积分/消费金）支付
- 移除money余额支付选项
- 简化支付验证逻辑：只检查score是否充足
- 扣款时只扣除score字段

**影响范围：**
- create() 方法 - 创建订单
- buy() 方法 - 直接购买
- pay() 方法 - 支付订单

### 商品管理
**文件：** `app/api/controller/ShopProduct.php`

**建议调整：**
- 配合ShopOrder调整
- purchase_type字段说明需要更新
- 可能需要移除对money购买方式的支持

---

## 📝 建议优化（奖励发放）

### 签到奖励
**文件：** `app/common/library/SignIn.php`

**状态：** ✅ 已验证正确
- 活动模式（money类型）：已正确发放到 `withdrawable_money`（可提现余额）
- 系统配置模式（score类型）：发放到 `score`（消费金）
- 无需修改

### 邀请好友奖励
**文件：** `app/listener/UserRegisterSuccess.php`

**状态：** ✅ 已验证正确
- 新用户注册奖励：发放到 `withdrawable_money`（可提现余额）
- 邀请人奖励：发放到 `withdrawable_money`（可提现余额）
- 实现完全正确，无需修改

**代码位置：**
- 新用户奖励：第102-118行（修改后）
- 邀请人奖励：第224-227行（handleInviteReward方法）

---

## ❌ 不修改（废弃功能）

### 抽奖功能
**文件：** `app/api/controller/LuckyDraw.php`

**状态：** 标记为未使用，不做修改

---

## 📊 修改进度统计

- ✅ 已完成：12个文件
- ⚠️ 需要修改：3个文件（理财相关）
- 💡 建议调整：2个文件（积分商城）
- 📝 建议优化：2个文件（奖励发放）
- ❌ 不修改：1个文件（废弃功能）

**总计：** 20个文件需要关注

---

## 🔧 快速修改指南

### 理财收益文件的通用修改模式

```php
// 修改前
$beforeMoney = $user['money'];
$afterMoney = $beforeMoney + $incomeAmount;
Db::name('user')->where('id', $userId)->update([
    'money' => $afterMoney,
]);
Db::name('user_money_log')->insert([
    'money' => $incomeAmount,
    'before' => $beforeMoney,
    'after' => $afterMoney,
]);

// 修改后
$beforeWithdrawable = $user['withdrawable_money'];
$afterWithdrawable = $beforeWithdrawable + $incomeAmount;
Db::name('user')->where('id', $userId)->update([
    'withdrawable_money' => $afterWithdrawable,
]);
Db::name('user_money_log')->insert([
    'money' => $incomeAmount,
    'before' => $beforeWithdrawable,
    'after' => $afterWithdrawable,
]);
```

### 活动日志也需要同步修改

```php
// 修改前
'change_field' => 'money',
'before_value' => $beforeMoney,
'after_value' => $afterMoney,

// 修改后
'change_field' => 'withdrawable_money',
'before_value' => $beforeWithdrawable,
'after_value' => $afterWithdrawable,
```

---

**最后更新：** 2025-12-27

