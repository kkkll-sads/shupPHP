# money字段快速参考指南

## 一句话总结

**money = 展示用的总资产（派生值），不能直接改，只能通过修改四个真实余额池来间接改变。**

**公式：** `money = balance_available + withdrawable_money + score + service_fee_balance`

---

## 完整字段速查表

| 字段 | 中文 | 用途 | 入账来源 | 可提现 | 计入总资产 |
|------|------|------|----------|--------|-----------|
| **money** | 总资产 | 展示用（只读） | 自动计算 | - | - |
| **balance_available** | 可用余额<br/>专项金 | 购买（优先扣） | 充值、退款 | ❌ | ✅ |
| **withdrawable_money** | 可提现余额 | 提现、购买（补扣） | 成交、收益、奖励 | ✅ | ✅ |
| **score** | 消费金 | 商城、兑换算力 | 利润、分红 | ❌ | ✅ |
| **service_fee_balance** | 确权金 | 寄售手续费 | 划转（不可逆） | ❌ | ✅ |
| **pending_activation_gold** | 待激活金 | 旧资产解锁 | 独立核算 | ❌ | ❌ |
| **green_power** | 绿色算力 | 预约/撮合燃料 | 赠送、兑换 | ❌ | ❌ |

---

## 常见业务场景代码

### 充值
```php
// ✅ 正确
$user->balance_available += $amount;
$user->save();

// ❌ 错误
$user->money += $amount;
```

### 注册奖励
```php
// ✅ 正确 - 发放到可提现余额
$user->withdrawable_money += $rewardAmount;
$user->save();

// ❌ 错误
$user->money += $rewardAmount;
```

### 购买/预约（混合支付）
```php
// ✅ 正确
$payFromBalance = min($user->balance_available, $price);
$payFromWithdrawable = $price - $payFromBalance;

$user->balance_available -= $payFromBalance;
$user->withdrawable_money -= $payFromWithdrawable;
$user->save();

// ❌ 错误
$user->money -= $price;
```

### 退款
```php
// ✅ 正确（统一退回可用余额）
$user->balance_available += $refundAmount;
$user->save();

// ❌ 错误
$user->money += $refundAmount;
```

### 寄售成交（卖家收款）
```php
// ✅ 正确
$user->withdrawable_money += $principal + $profit * 0.5;
$user->score += $profit * 0.5;
$user->save();

// ❌ 错误
$user->money += $principal + $profit;
```

### 提现
```php
// ✅ 正确
$user->withdrawable_money -= $withdrawAmount;
$user->save();

// ❌ 错误
$user->money -= $withdrawAmount;
```

### 代理佣金发放
```php
// ✅ 正确 - 佣金全额发放到可提现余额
$agent->withdrawable_money += $commission;
$agent->save();

// ❌ 错误
$agent->money += $commission;
```

**代理佣金说明：**
- 直推佣金：卖家利润 × 10%
- 间推佣金：卖家利润 × 5%
- 团队奖：按级差分配（1-5级：9%-21%）
- 同级奖：同级代理拿固定10%
- **所有佣金全额进 `withdrawable_money`**

---

## 数据库操作

### 查询用户余额
```php
// ✅ 正确 - money会自动计算
$user = Db::name('user')->where('id', $userId)->find();
echo $user['money']; // 自动计算的总资产

// ✅ 也可以直接查询真实余额池
$user = Db::name('user')
    ->field('balance_available,withdrawable_money,score,service_fee_balance')
    ->where('id', $userId)
    ->find();
```

### 更新用户余额
```php
// ✅ 正确 - 更新真实余额池
Db::name('user')->where('id', $userId)->update([
    'balance_available' => Db::raw('balance_available + ' . $amount),
]);

// ❌ 错误 - 不要更新money
Db::name('user')->where('id', $userId)->update([
    'money' => Db::raw('money + ' . $amount),
]);
```

---

## 检查清单

开发新功能时，请确认：

- [ ] 是否涉及用户余额变动？
- [ ] 确定应该操作哪个真实余额池？
- [ ] 是否正确记录了日志？
- [ ] 是否在事务中执行？
- [ ] 是否检查了余额是否充足？

---

## 记住这些

1. **money = 只读**：永远不要直接写入money字段
2. **四个池子**：所有余额操作都针对四个真实余额池
3. **混合支付**：购买时优先扣balance_available，不足时扣withdrawable_money
4. **退款统一**：所有退款统一退回balance_available
5. **分配规则**：成交和分红按50/50分配到withdrawable_money和score
6. **奖励发放**：注册、签到、邀请奖励发放到withdrawable_money（可提现）
7. **佣金发放**：代理佣金（直推、间推、团队奖）全额发放到withdrawable_money

---

**快速查看完整文档：** [money字段架构调整说明.md](./money字段架构调整说明.md)

