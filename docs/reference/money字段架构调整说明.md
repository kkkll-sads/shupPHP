# money字段架构调整说明

## 调整概述

将 `money`（总资产）字段从可直接操作的余额字段改为**派生值（计算属性）**，不再参与业务入账和扣款。

---

## 字段概览（Field Overview）

| 字段名 | 中文名 | 简述 | 是否核心 | 计入总资产 |
|--------|--------|------|----------|-----------|
| `money` | 总资产展示值 | **派生值**：`balance_available + withdrawable_money + score + service_fee_balance` | ❌ 展示用 | - |
| `balance_available` | 可用余额（专项金） | 充值、退款流入。用于购买藏品、盲盒。优先扣款。 | ✅ 核心 | ✅ 是 |
| `withdrawable_money` | 可提现余额 | 成交回款、收益、奖励流入。可提现、可购买（补扣）。 | ✅ 核心 | ✅ 是 |
| `score` | 积分/消费金 | 利润50%、分红50%流入。商城消费、兑换算力。 | ✅ 核心 | ✅ 是 |
| `service_fee_balance` | 确权金/手续费余额 | 从其他余额划转。专门扣除寄售手续费（不可逆）。 | ✅ 核心 | ✅ 是 |
| `pending_activation_gold` | 待激活金 | 解锁旧资产包门槛资金（需≥1000），独立核算。 | ⚪ 独立 | ❌ 否 |
| `green_power` | 绿色算力 | 预约/撮合燃料，注册赠送或积分兑换。 | ⚪ 功能 | ❌ 否 |

### 已废弃字段
- ~~`static_income`~~ - 已合并至 `withdrawable_money`
- ~~`dynamic_income`~~ - 已合并至 `withdrawable_money`

---

## 核心原则

### money字段定义
```
money = balance_available + withdrawable_money + score + service_fee_balance
```

**重要说明：**
- `pending_activation_gold`（待激活金）**不计入** money
- `green_power`（绿色算力）**不计入** money
- `static_income`、`dynamic_income` 已废弃

### 四个真实余额池

| 字段名 | 中文名 | 用途 | 可提现 |
|--------|--------|------|--------|
| `balance_available` | 可用余额（专项金） | 充值、退款、购买/预约（优先扣除） | ❌ 否 |
| `withdrawable_money` | 可提现余额 | 成交回款、分红、佣金、奖励、提现 | ✅ 是 |
| `score` | 积分/消费金 | 利润分配、分红、商城抵扣、兑换算力 | ❌ 否 |
| `service_fee_balance` | 服务费余额（确权金） | 寄售手续费 | ❌ 否 |

## 业务规则

### 1. 充值
```php
balance_available += 充值金额
```

### 2. 购买/预约（混合支付）
```php
优先扣除: balance_available
不足时补扣: withdrawable_money
```

### 3. 退款（统一退回专项金）
```php
balance_available += 退款金额
```

### 4. 寄售成交
```php
// 卖家收款
withdrawable_money += 本金 + 利润 * 50%
score += 利润 * 50%
```

### 5. 分红收益
```php
withdrawable_money += 收益 * 50%
score += 收益 * 50%
```

### 6. 分润/佣金（全额进可提现余额）
```php
withdrawable_money += 佣金金额（100%）
```

**代理佣金体系：**
- **直推佣金**：卖家利润 × 10% → `withdrawable_money`
- **间推佣金**：卖家利润 × 5% → `withdrawable_money`
- **代理团队奖**：按级差分配（1-5级：9%-21%）→ `withdrawable_money`
- **同级奖**：同级代理拿固定10% → `withdrawable_money`

所有佣金全额发放到 `withdrawable_money`，可直接提现。

### 7. 提现
```php
withdrawable_money -= 提现金额
```

### 8. 划转确权金（不可逆）
```php
// 从可用余额划转
balance_available -= x
service_fee_balance += x

// 或从可提现余额划转
withdrawable_money -= y
service_fee_balance += y
```

## 代码实现

### User模型（自动计算money）

```php
/**
 * money字段访问器 - 总资产（派生值）
 */
public function getMoneyAttr($value): string
{
    $balanceAvailable = $this->getData('balance_available') ?? 0;
    $withdrawableMoney = $this->getData('withdrawable_money') ?? 0;
    $score = $this->getData('score') ?? 0;
    $serviceFeeBalance = $this->getData('service_fee_balance') ?? 0;
    
    $total = bcadd($balanceAvailable, $withdrawableMoney, 2);
    $total = bcadd($total, $score, 2);
    $total = bcadd($total, $serviceFeeBalance, 2);
    
    return $total;
}

/**
 * money字段修改器 - 禁止直接修改
 */
public function setMoneyAttr($value)
{
    return $value; // 不做任何处理
}
```

### 业务代码示例

#### ❌ 错误写法（不要这样做）
```php
// 错误：直接操作money字段
$user->money += 100;
$user->save();

// 错误：在数据库更新中操作money
Db::name('user')->where('id', $userId)->update([
    'money' => Db::raw('money + 100')
]);
```

#### ✅ 正确写法（应该这样做）
```php
// 正确：操作对应的真实余额池
$user->balance_available += 100;
$user->save();

// 正确：在数据库更新中操作真实余额池
Db::name('user')->where('id', $userId)->update([
    'balance_available' => Db::raw('balance_available + 100')
]);

// money字段会自动重新计算
```

## 数据库迁移

### 修改字段注释
```sql
ALTER TABLE `ba_user` 
MODIFY COLUMN `money` decimal(10,2) UNSIGNED NOT NULL DEFAULT 0.00 
COMMENT '总资产（派生值，自动计算=balance_available+withdrawable_money+score+service_fee_balance，不参与业务入账和扣款）';
```

### 同步现有数据（可选）
```sql
UPDATE `ba_user` 
SET `money` = `balance_available` + `withdrawable_money` + `score` + `service_fee_balance`;
```

### 验证数据一致性
```sql
SELECT 
    id,
    username,
    money as db_money,
    (balance_available + withdrawable_money + score + service_fee_balance) as calculated_money,
    (money - (balance_available + withdrawable_money + score + service_fee_balance)) as diff
FROM `ba_user`
WHERE ABS(money - (balance_available + withdrawable_money + score + service_fee_balance)) > 0.01
LIMIT 100;
```

## 注意事项

### 1. 性能考虑
- money字段每次读取都会重新计算
- 如有性能问题，可以考虑：
  - 添加缓存机制
  - 定期同步数据库中的money值（仅用于展示）
  - 在需要频繁读取的场景中，直接查询四个真实余额池

### 2. 兼容性
- 数据库中的money字段保留，但不再使用
- 所有读取money的地方会自动通过访问器计算
- 旧代码中直接写入money的地方需要逐步修改

### 3. 日志记录
- UserMoneyLog不再自动更新user表的money字段
- 业务逻辑需要在更新真实余额池后，手动插入日志记录
- 日志中的before/after字段记录对应真实余额池的变化

### 4. 管理后台
- 管理员无法直接修改money字段
- 应该修改对应的真实余额池（balance_available、withdrawable_money等）
- money字段会自动重新计算并展示

## 常见问题

### Q1: 为什么要改为派生值？
A: 避免money字段与四个真实余额池不一致的问题，确保数据准确性。

### Q2: 旧代码中直接操作money的地方怎么办？
A: 需要逐步修改，根据业务场景选择操作对应的真实余额池。

### Q3: 数据库中的money字段还需要吗？
A: 可以保留用于数据一致性检查，也可以定期同步用于展示。

### Q4: 性能会受影响吗？
A: 每次读取money都会重新计算，但计算开销很小。如有性能问题可以添加缓存。

### Q5: 如何确保数据一致性？
A: 使用提供的验证SQL定期检查，或者定期执行同步SQL更新数据库中的money值。

## 相关文档

- [财务架构与资金流向分析.md](./财务架构与资金流向分析.md)
- [系统架构文档.md](./系统架构文档.md)
- [更新日志_20251227.md](./更新日志_20251227.md)

---

**最后更新：** 2025-12-27  
**版本：** v1.0

