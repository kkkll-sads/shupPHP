# API接口：通过确权编号或MD5查询藏品

**创建日期**：2025-12-27  
**接口类型**：🆕 新增接口  
**认证要求**：无需登录

---

## 一、接口概述

### 1.1 功能说明
提供一个公开接口，允许用户通过**确权编号（asset_code）**或**MD5指纹（fingerprint）**查询藏品信息，用于防伪验证和溯源查询。

### 1.2 使用场景
- 📱 用户扫描藏品上的二维码，验证藏品真伪
- 🔍 通过确权编号查询藏品详细信息
- 🔐 通过MD5指纹验证藏品唯一性
- 📊 查看藏品的当前持有人信息（如果已交付且未售出）

---

## 二、接口详情

### 2.1 基本信息

| 项目 | 内容 |
|------|------|
| **接口路径** | `/api/collectionItem/queryByCode` |
| **请求方法** | `GET` |
| **认证要求** | 无需登录（公开接口） |
| **限流策略** | 建议：60次/分钟/IP |

---

### 2.2 请求参数

#### Query 参数

| 参数名 | 类型 | 必填 | 说明 | 示例 |
|--------|------|------|------|------|
| `code` | string | 是 | 确权编号或MD5指纹（精确查询） | `37-DATA-0001-000123` 或 `0x1a2b3c...` |

**参数说明**：
- 支持两种查询方式：
  1. **确权编号**：格式如 `37-DATA-0001-000123`
  2. **MD5指纹**：格式如 `0x1a2b3c4d5e6f...`（32字节十六进制）
- 查询为**精确匹配**，不支持模糊查询
- 大小写不敏感

---

### 2.3 返回参数

#### 成功响应（200）

```json
{
  "code": 1,
  "msg": "查询成功",
  "data": {
    "id": 123,
    "session_id": 1,
    "title": "富春山居图",
    "image": "https://domain.com/uploads/xxx.jpg",
    "price": 1000.00,
    "issue_price": 1000.00,
    "asset_code": "37-DATA-0001-000123",
    "fingerprint": "0x1a2b3c4d5e6f7890abcdef1234567890",
    "status": "1",
    "description": "藏品描述信息",
    "core_enterprise": "山东供应链管理有限公司",
    "farmer_info": "覆盖鲁西产业带 2000+ 户",
    "zone_id": 1,
    "holder": {
      "user_id": 456,
      "username": "user123",
      "nickname": "用户昵称",
      "mobile": "138****8000"
    }
  }
}
```

#### 字段说明

**藏品基本信息**：

| 字段名 | 类型 | 说明 | 示例 |
|--------|------|------|------|
| `id` | int | 藏品ID | `123` |
| `session_id` | int | 所属专场ID | `1` |
| `title` | string | 藏品标题 | `"富春山居图"` |
| `image` | string | 藏品图片URL（完整路径） | `"https://..."` |
| `price` | float | 当前价格 | `1000.00` |
| `issue_price` | float | 发行价格 | `1000.00` |
| `asset_code` | string | 确权编号 | `"37-DATA-0001-000123"` |
| `fingerprint` | string | MD5指纹 | `"0x1a2b..."` |
| `status` | string | 状态：0=已下架，1=上架中 | `"1"` |
| `description` | string | 藏品描述 | `"..."` |
| `core_enterprise` | string | 核心企业 | `"山东供应链..."` |
| `farmer_info` | string | 农户信息 | `"覆盖鲁西..."` |
| `zone_id` | int | 价格分区ID | `1` |

**持有人信息**（如果藏品已交付且未售出）：

| 字段名 | 类型 | 说明 | 示例 |
|--------|------|------|------|
| `holder` | object/null | 持有人信息对象 | `{...}` 或 `null` |
| `holder.user_id` | int | 持有人用户ID | `456` |
| `holder.username` | string | 持有人用户名 | `"user123"` |
| `holder.nickname` | string | 持有人昵称 | `"用户昵称"` |
| `holder.mobile` | string | 持有人手机号（脱敏） | `"138****8000"` |

**持有人信息说明**：
- 仅当藏品已交付给用户且未售出时返回
- 如果藏品未交付、已售出或在寄售中，`holder` 为 `null`
- 手机号自动脱敏（保留前3位和后4位）

---

#### 错误响应

**藏品不存在**：
```json
{
  "code": 0,
  "msg": "未找到匹配的藏品",
  "data": null
}
```

**参数错误**：
```json
{
  "code": 0,
  "msg": "请输入确权编号或MD5指纹",
  "data": null
}
```

**藏品已下架**：
```json
{
  "code": 0,
  "msg": "未找到匹配的藏品",
  "data": null
}
```
（注：已下架的藏品不返回详情，视为不存在）

---

## 三、代码示例

### 3.1 前端调用示例

#### JavaScript（原生）
```javascript
async function queryCollectionByCode(code) {
  try {
    const response = await fetch(`/api/collectionItem/queryByCode?code=${encodeURIComponent(code)}`);
    const result = await response.json();
    
    if (result.code === 1) {
      console.log('藏品信息:', result.data);
      
      // 显示持有人信息
      if (result.data.holder) {
        console.log('持有人:', result.data.holder.nickname);
        console.log('联系方式:', result.data.holder.mobile);
      } else {
        console.log('该藏品暂无持有人信息');
      }
    } else {
      console.error('查询失败:', result.msg);
    }
  } catch (error) {
    console.error('请求失败:', error);
  }
}

// 使用示例
queryCollectionByCode('37-DATA-0001-000123');
queryCollectionByCode('0x1a2b3c4d5e6f7890abcdef1234567890');
```

#### Vue 3
```vue
<template>
  <div class="collection-query">
    <input 
      v-model="queryCode" 
      placeholder="输入确权编号或MD5指纹"
      @keyup.enter="handleQuery"
    />
    <button @click="handleQuery">查询</button>
    
    <div v-if="collection" class="result">
      <h3>{{ collection.title }}</h3>
      <img :src="collection.image" :alt="collection.title" />
      <p>确权编号：{{ collection.asset_code }}</p>
      <p>价格：¥{{ collection.price }}</p>
      
      <div v-if="collection.holder" class="holder-info">
        <h4>当前持有人</h4>
        <p>昵称：{{ collection.holder.nickname }}</p>
        <p>手机：{{ collection.holder.mobile }}</p>
      </div>
      <p v-else class="no-holder">该藏品暂无持有人信息</p>
    </div>
    
    <p v-if="error" class="error">{{ error }}</p>
  </div>
</template>

<script setup>
import { ref } from 'vue';

const queryCode = ref('');
const collection = ref(null);
const error = ref('');

const handleQuery = async () => {
  if (!queryCode.value.trim()) {
    error.value = '请输入确权编号或MD5指纹';
    return;
  }
  
  error.value = '';
  collection.value = null;
  
  try {
    const response = await fetch(`/api/collectionItem/queryByCode?code=${encodeURIComponent(queryCode.value.trim())}`);
    const result = await response.json();
    
    if (result.code === 1) {
      collection.value = result.data;
    } else {
      error.value = result.msg;
    }
  } catch (err) {
    error.value = '查询失败，请稍后重试';
  }
};
</script>
```

---

### 3.2 二维码集成

#### 生成防伪二维码
```javascript
// 使用 QRCode.js 生成二维码
import QRCode from 'qrcode';

async function generateQRCode(assetCode) {
  // 构造查询URL
  const queryUrl = `https://your-domain.com/verify?code=${assetCode}`;
  
  try {
    // 生成二维码
    const qrCodeDataUrl = await QRCode.toDataURL(queryUrl, {
      width: 300,
      margin: 2,
      color: {
        dark: '#000000',
        light: '#FFFFFF'
      }
    });
    
    return qrCodeDataUrl;
  } catch (error) {
    console.error('生成二维码失败:', error);
  }
}

// 使用示例
const code = '37-DATA-0001-000123';
const qrCode = await generateQRCode(code);
// 将 qrCode 显示在页面上或打印到藏品证书上
```

#### 扫码验证页面
```html
<!DOCTYPE html>
<html>
<head>
  <title>藏品防伪验证</title>
</head>
<body>
  <div id="app">
    <h1>藏品防伪验证</h1>
    <div id="result"></div>
  </div>
  
  <script>
    // 从URL获取code参数
    const urlParams = new URLSearchParams(window.location.search);
    const code = urlParams.get('code');
    
    if (code) {
      // 自动查询
      fetch(`/api/collectionItem/queryByCode?code=${encodeURIComponent(code)}`)
        .then(res => res.json())
        .then(data => {
          const resultDiv = document.getElementById('result');
          
          if (data.code === 1) {
            const item = data.data;
            resultDiv.innerHTML = `
              <div class="success">
                <h2>✓ 验证成功</h2>
                <img src="${item.image}" alt="${item.title}" />
                <h3>${item.title}</h3>
                <p>确权编号：${item.asset_code}</p>
                <p>价格：¥${item.price}</p>
                <p>状态：${item.status === '1' ? '上架中' : '已下架'}</p>
                ${item.holder ? `
                  <div class="holder">
                    <h4>当前持有人</h4>
                    <p>${item.holder.nickname}</p>
                    <p>${item.holder.mobile}</p>
                  </div>
                ` : ''}
              </div>
            `;
          } else {
            resultDiv.innerHTML = `
              <div class="error">
                <h2>✗ 验证失败</h2>
                <p>${data.msg}</p>
              </div>
            `;
          }
        })
        .catch(err => {
          document.getElementById('result').innerHTML = `
            <div class="error">
              <h2>✗ 查询失败</h2>
              <p>请检查网络连接或稍后重试</p>
            </div>
          `;
        });
    } else {
      document.getElementById('result').innerHTML = `
        <p>请扫描藏品上的二维码进行验证</p>
      `;
    }
  </script>
</body>
</html>
```

---

## 四、实现说明

### 4.1 查询逻辑

```php
// 伪代码示例
public function queryByCode(): void
{
    $code = trim($this->request->param('code/s', ''));
    
    if (empty($code)) {
        $this->error('请输入确权编号或MD5指纹');
    }
    
    // 查询藏品（只查询上架中的）
    $item = Db::name('collection_item')
        ->where('status', '=', '1') // 只查询上架中的藏品
        ->where(function($query) use ($code) {
            $query->where('asset_code', '=', $code)  // 精确匹配确权编号
                  ->whereOr('fingerprint', '=', $code); // 或精确匹配MD5指纹
        })
        ->find();
    
    if (!$item) {
        $this->error('未找到匹配的藏品');
    }
    
    // 处理图片URL
    $item['image'] = full_url($item['image'], false);
    
    // 查询持有人信息
    $holder = Db::name('user_collection')
        ->alias('uc')
        ->leftJoin('user u', 'uc.user_id = u.id')
        ->where('uc.item_id', $item['id'])
        ->where('uc.delivery_status', '=', 0) // 已交付
        ->where('uc.consignment_status', '<>', 2) // 未售出
        ->field('uc.user_id, u.username, u.nickname, u.mobile')
        ->order('uc.buy_time desc')
        ->find();
    
    if ($holder) {
        // 手机号脱敏
        $mobile = $holder['mobile'] ?? '';
        if (strlen($mobile) >= 11) {
            $holder['mobile'] = substr($mobile, 0, 3) . '****' . substr($mobile, -4);
        }
        $item['holder'] = $holder;
    } else {
        $item['holder'] = null;
    }
    
    $this->success('查询成功', $item);
}
```

### 4.2 数据库索引建议

为了提升查询性能，建议添加索引：

```sql
-- 为 asset_code 添加索引
ALTER TABLE `ba_collection_item` 
ADD INDEX `idx_asset_code` (`asset_code`);

-- 为 fingerprint 添加索引
ALTER TABLE `ba_collection_item` 
ADD INDEX `idx_fingerprint` (`fingerprint`);

-- 复合索引（状态 + 确权编号）
ALTER TABLE `ba_collection_item` 
ADD INDEX `idx_status_asset_code` (`status`, `asset_code`);
```

---

## 五、安全建议

### 5.1 限流策略
```nginx
# Nginx 限流配置示例
limit_req_zone $binary_remote_addr zone=query_limit:10m rate=60r/m;

location /api/collectionItem/queryByCode {
    limit_req zone=query_limit burst=10 nodelay;
    proxy_pass http://backend;
}
```

### 5.2 防爬虫
- 添加图形验证码（连续失败多次后）
- 记录查询日志，监控异常行为
- 设置IP黑名单机制

### 5.3 数据脱敏
- ✅ 手机号脱敏（已实现）
- ✅ 只返回上架中的藏品
- ✅ 不返回敏感的用户信息（如真实姓名、身份证等）

---

## 六、测试用例

### 6.1 功能测试

#### 测试1：通过确权编号查询
```bash
curl -X GET "https://your-domain.com/api/collectionItem/queryByCode?code=37-DATA-0001-000123"
```

**预期结果**：返回藏品详情

#### 测试2：通过MD5指纹查询
```bash
curl -X GET "https://your-domain.com/api/collectionItem/queryByCode?code=0x1a2b3c4d5e6f7890abcdef1234567890"
```

**预期结果**：返回藏品详情

#### 测试3：查询不存在的编号
```bash
curl -X GET "https://your-domain.com/api/collectionItem/queryByCode?code=INVALID-CODE-123"
```

**预期结果**：返回错误 "未找到匹配的藏品"

#### 测试4：空参数
```bash
curl -X GET "https://your-domain.com/api/collectionItem/queryByCode?code="
```

**预期结果**：返回错误 "请输入确权编号或MD5指纹"

#### 测试5：查询已下架的藏品
```bash
# 假设某个藏品的 status = 0
curl -X GET "https://your-domain.com/api/collectionItem/queryByCode?code=37-DATA-0001-999999"
```

**预期结果**：返回错误 "未找到匹配的藏品"

---

### 6.2 性能测试

#### 并发测试
```bash
# 使用 Apache Bench 进行并发测试
ab -n 1000 -c 100 "https://your-domain.com/api/collectionItem/queryByCode?code=37-DATA-0001-000123"
```

**性能指标**：
- 响应时间：< 200ms（50%）
- 响应时间：< 500ms（95%）
- QPS：> 100

---

## 七、前端集成建议

### 7.1 UI展示

#### 查询结果卡片
```
┌──────────────────────────────────┐
│  ✓ 验证成功                     │
├──────────────────────────────────┤
│  [藏品图片]                      │
│                                  │
│  富春山居图                      │
│  ¥1000.00                        │
│                                  │
│  确权编号：37-DATA-0001-000123   │
│  MD5指纹：0x1a2b...              │
│  状态：上架中                    │
│                                  │
│  【当前持有人】                  │
│  昵称：用户昵称                  │
│  手机：138****8000               │
└──────────────────────────────────┘
```

### 7.2 错误处理

```javascript
function handleQueryError(error) {
  const errorMessages = {
    'network_error': '网络连接失败，请检查网络设置',
    'not_found': '未找到该藏品，请检查编号是否正确',
    'invalid_code': '编号格式不正确，请重新输入',
    'server_error': '服务器错误，请稍后重试'
  };
  
  // 显示友好的错误提示
  showToast(errorMessages[error] || '查询失败，请稍后重试');
}
```

---

## 八、常见问题

### Q1: 为什么查不到我的藏品？
**A**: 可能的原因：
1. 藏品已下架（`status = 0`）
2. 确权编号或MD5指纹输入错误
3. 藏品尚未生成确权编号或MD5指纹

### Q2: 为什么没有持有人信息？
**A**: 持有人信息仅在以下情况显示：
- 藏品已交付给用户
- 藏品未被售出或寄售中
- 如果藏品在商城中未售出，则无持有人信息

### Q3: 手机号为什么显示为 `****`？
**A**: 为保护用户隐私，手机号自动脱敏，只显示前3位和后4位。

### Q4: 接口有访问限制吗？
**A**: 是的，为防止滥用，接口设置了限流：
- 每分钟最多60次查询（每IP）
- 连续失败多次可能触发验证码

### Q5: 支持模糊查询吗？
**A**: 不支持。查询为精确匹配，以确保查询结果的准确性。

---

## 九、相关文档

| 文档名称 | 说明 |
|---------|------|
| `docs/API接口变更汇总_20251227.md` | API接口变更总览 |
| `docs/寄售业务逻辑说明.md` | 寄售业务流程 |
| `docs/资产确权与解锁逻辑说明.md` | 资产确权逻辑 |

---

**文档状态**：✅ 已实现  
**最后更新**：2025-12-27  
**版本**：v1.0

---

**备注**：本接口已实现，方法位于 `app/api/controller/CollectionItem.php` 中的 `queryByCode` 方法。

