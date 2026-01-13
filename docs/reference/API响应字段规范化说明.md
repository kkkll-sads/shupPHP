# API 响应字段规范化说明

## 修改时间
2025-12-30

## 修改内容

### 1. 顶层响应字段统一

**修改位置**：`app/common/controller/Api.php`

所有API接口的返回格式现在包含以下字段：

```json
{
  "code": 1,
  "message": "操作成功",  // ✅ 主字段，规范名称
  "msg": "操作成功",      // ✅ 兼容字段，保留一段时间
  "time": 1703952000,
  "data": {...}
}
```

**变更说明**：
- 新增 `message` 字段作为**主要字段**
- 保留 `msg` 字段用于**向后兼容**（建议客户端逐步迁移到 `message`）
- 避免老版本客户端突然崩溃

### 2. 寄售接口审计字段完善

**修改位置**：`app/api/controller/CollectionItem.php::consign()`

寄售接口现在返回完整的审计字段：

#### 成功响应示例
```json
{
  "code": 1,
  "message": "寄售申请成功，已上架到寄售区",
  "data": {
    "consignment_id": 123,
    "consignment_price": 500.00,
    "service_fee": 15.00,
    
    // ✅ 审计字段
    "coupon_used": true,           // 是否使用了券（boolean）
    "coupon_remaining": 5,         // 剩余券数量
    "waive_type": "none",          // 豁免类型：none / system_resend / free_attempt
    "rollback_reason": null,       // 回滚原因（失败时才有）
    
    // 其他字段...
  }
}
```

#### 失败响应示例
```json
{
  "code": 0,
  "message": "确权金不足，无法支付寄售手续费",
  "data": {
    "rollback_reason": "确权金不足，无法支付寄售手续费（15.00元），当前确权金：10.50元",
    "error_code": 0
  }
}
```

### 3. 审计字段说明

| 字段名 | 类型 | 说明 | 可能的值 |
|--------|------|------|----------|
| `coupon_used` | boolean | 是否使用了券 | true / false |
| `coupon_remaining` | int | 剩余券数量 | ≥ 0 |
| `waive_type` | string | 豁免类型 | `none` - 无豁免（正常扣券）<br>`system_resend` - 流拍免费重发<br>`free_attempt` - 使用免费次数 |
| `rollback_reason` | string/null | 事务回滚原因 | 失败时包含详细错误信息，成功时为 null |

## 迁移建议

### 前端客户端
1. 优先读取 `message` 字段
2. 如果 `message` 不存在，fallback 到 `msg` 字段（兼容）
3. 建议在 2-3 个版本后完全移除对 `msg` 的依赖

### 示例代码（JavaScript）
```javascript
// 推荐方式
const message = response.message || response.msg;

// 或使用可选链
const message = response.message ?? response.msg ?? '操作成功';
```

## 兼容性承诺
- `msg` 字段将保留至少 **3个月**
- 在此期间，所有新老客户端均可正常工作
- 3个月后将在更新日志中通知移除计划
