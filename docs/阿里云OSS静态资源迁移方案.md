# 阿里云 OSS 静态资源迁移方案

## 一、项目概述

### 1.1 当前状态
- **静态资源存储位置**：服务器本地磁盘 `/www/wwwroot/23.248.226.82/public/`
- **存储目录结构**：
  ```
  public/
  ├── storage/          # 用户上传文件 (1.9GB)
  │   ├── default/      # 默认上传
  │   └── recharge/     # 充值凭证
  ├── static/           # 静态资源 (11MB)
  │   ├── images/       # 图片资源
  │   └── fonts/        # 字体文件
  └── assets/           # 前端构建资源 (8.7MB)
      └── *.js, *.css   # 编译后的前端资源
  ```
- **总存储空间**：约 1.92GB
- **访问方式**：通过 Nginx 直接访问本地文件

### 1.2 迁移目标
- ✅ 减轻服务器存储压力
- ✅ 提升静态资源访问速度（CDN 加速）
- ✅ 降低服务器带宽成本
- ✅ 提高系统可扩展性和可靠性
- ✅ 实现多服务器部署时的资源共享

---

## 二、技术方案

### 2.1 OSS 配置

#### 2.1.1 创建 OSS Bucket
```
Bucket 名称：your-project-name-static
地域：华东1（杭州）或就近地域
存储类型：标准存储
读写权限：公共读
版本控制：关闭
服务端加密：关闭（或按需开启）
```

#### 2.1.2 目录规划
```
OSS Bucket 结构：
├── storage/          # 用户上传文件
│   ├── default/      # 默认上传
│   ├── recharge/     # 充值凭证
│   ├── avatar/       # 用户头像
│   └── product/      # 商品图片
├── static/           # 系统静态资源
│   ├── images/       # 图片
│   └── fonts/        # 字体
└── assets/           # 前端构建资源
    └── *.js, *.css   # 编译后文件
```

#### 2.1.3 CDN 配置
```
CDN 域名：static.yourdomain.com
回源地址：your-project-name-static.oss-cn-hangzhou.aliyuncs.com
缓存策略：
  - /storage/*    缓存 7 天
  - /static/*     缓存 30 天
  - /assets/*     缓存 365 天
HTTPS：开启（推荐）
```

### 2.2 代码实现

#### 2.2.1 安装 OSS SDK
```bash
cd /www/wwwroot/23.248.226.82
composer require aliyuncs/oss-sdk-php
```

#### 2.2.2 配置文件修改

**1. 环境配置 `.env`**
```env
# OSS 配置
OSS_ACCESS_KEY_ID=your_access_key_id
OSS_ACCESS_KEY_SECRET=your_access_key_secret
OSS_ENDPOINT=oss-cn-hangzhou.aliyuncs.com
OSS_BUCKET=your-project-name-static
OSS_CDN_DOMAIN=https://static.yourdomain.com
OSS_IS_CNAME=true

# 文件系统驱动
FILESYSTEM_DRIVER=oss
```

**2. 文件系统配置 `config/filesystem.php`**
```php
<?php

return [
    // 默认磁盘
    'default' => env('filesystem.driver', 'local'),
    
    // 磁盘列表
    'disks'   => [
        'local'  => [
            'type' => 'local',
            'root' => app()->getRuntimePath() . 'storage',
        ],
        'public' => [
            'type'       => 'local',
            'root'       => app()->getRootPath() . 'public/storage',
            'url'        => '/storage',
            'visibility' => 'public',
        ],
        
        // 阿里云 OSS 配置
        'oss' => [
            'type'              => 'oss',
            'access_key_id'     => env('oss.access_key_id'),
            'access_key_secret' => env('oss.access_key_secret'),
            'endpoint'          => env('oss.endpoint'),
            'bucket'            => env('oss.bucket'),
            'cdn_domain'        => env('oss.cdn_domain', ''),
            'is_cname'          => env('oss.is_cname', false),
            'prefix'            => '',
            'visibility'        => 'public',
        ],
    ],
];
```

**3. 上传配置 `config/upload.php`**
```php
<?php

return [
    // 最大上传
    'max_size'           => '10mb',
    
    // 文件保存格式化方法
    'save_name'          => '/storage/{topic}/{year}{mon}{day}/{fileName}{fileSha1}{.suffix}',
    
    // 上传驱动：local=本地, oss=阿里云OSS
    'driver'             => env('upload.driver', 'oss'),
    
    // 允许的文件后缀
    'allowed_suffixes'   => 'jpg,png,bmp,jpeg,gif,webp,zip,rar,wav,mp4,mp3,pdf,doc,docx,xls,xlsx',
    'allowed_mime_types' => [],
];
```

#### 2.2.3 创建 OSS 驱动

**文件：`app/common/library/upload/driver/Oss.php`**
```php
<?php

namespace app\common\library\upload\driver;

use OSS\OssClient;
use OSS\Core\OssException;
use think\facade\Config;
use think\file\UploadedFile;
use think\exception\FileException;
use app\common\library\upload\Driver;

/**
 * 阿里云 OSS 上传驱动
 */
class Oss extends Driver
{
    protected array $options = [];
    protected ?OssClient $client = null;

    public function __construct(array $options = [])
    {
        $this->options = Config::get('filesystem.disks.oss');
        if (!empty($options)) {
            $this->options = array_merge($this->options, $options);
        }
        
        $this->initClient();
    }

    /**
     * 初始化 OSS 客户端
     */
    protected function initClient(): void
    {
        try {
            $this->client = new OssClient(
                $this->options['access_key_id'],
                $this->options['access_key_secret'],
                $this->options['endpoint'],
                $this->options['is_cname'] ?? false
            );
        } catch (OssException $e) {
            throw new FileException('OSS 客户端初始化失败：' . $e->getMessage());
        }
    }

    /**
     * 保存文件到 OSS
     * @param UploadedFile $file
     * @param string       $saveName
     * @return bool
     */
    public function save(UploadedFile $file, string $saveName): bool
    {
        try {
            // 移除开头的斜杠
            $object = ltrim($saveName, '/');
            
            // 上传文件到 OSS
            $this->client->uploadFile(
                $this->options['bucket'],
                $object,
                $file->getPathname()
            );
            
            return true;
        } catch (OssException $e) {
            throw new FileException('文件上传到 OSS 失败：' . $e->getMessage());
        }
    }

    /**
     * 删除 OSS 文件
     * @param string $saveName
     * @return bool
     */
    public function delete(string $saveName): bool
    {
        try {
            $object = ltrim($saveName, '/');
            $this->client->deleteObject($this->options['bucket'], $object);
            return true;
        } catch (OssException $e) {
            return false;
        }
    }

    /**
     * 检查文件是否存在
     * @param string $saveName
     * @return bool
     */
    public function exists(string $saveName): bool
    {
        try {
            $object = ltrim($saveName, '/');
            return $this->client->doesObjectExist($this->options['bucket'], $object);
        } catch (OssException $e) {
            return false;
        }
    }

    /**
     * 获取文件完整 URL
     * @param string $saveName
     * @param bool   $domain 是否包含域名
     * @return string
     */
    public function url(string $saveName, bool $domain = true): string
    {
        if (!$domain) {
            return $saveName;
        }

        $object = ltrim($saveName, '/');
        
        // 如果配置了 CDN 域名，使用 CDN 域名
        if (!empty($this->options['cdn_domain'])) {
            return rtrim($this->options['cdn_domain'], '/') . '/' . $object;
        }
        
        // 否则使用 OSS 默认域名
        $endpoint = $this->options['endpoint'];
        if ($this->options['is_cname']) {
            return 'https://' . $endpoint . '/' . $object;
        }
        
        return 'https://' . $this->options['bucket'] . '.' . $endpoint . '/' . $object;
    }

    /**
     * 获取文件完整路径（OSS 不需要本地路径）
     * @param string $saveName
     * @param bool   $withFile
     * @return string
     */
    public function getFullPath(string $saveName, bool $withFile = false): string
    {
        return ltrim($saveName, '/');
    }
}
```

#### 2.2.4 修改 Upload 类

**文件：`app/common/library/Upload.php`**

在构造函数中添加 OSS 驱动支持：
```php
public function __construct(?UploadedFile $file = null, array $config = [])
{
    $upload       = Config::get('upload');
    $this->config = array_merge($upload, $config);
    
    // 设置上传驱动（支持 local 和 oss）
    $driverName = $this->config['driver'] ?? 'local';
    $this->driver['name'] = $driverName;

    if ($file) {
        $this->setFile($file);
    }
}
```

---

## 三、迁移步骤

### 3.1 准备阶段

#### Step 1: 创建 OSS Bucket
1. 登录阿里云控制台
2. 进入对象存储 OSS 服务
3. 创建 Bucket，配置如上述 2.1.1
4. 记录 AccessKey ID 和 AccessKey Secret

#### Step 2: 配置 CDN（可选但推荐）
1. 在阿里云 CDN 控制台添加加速域名
2. 配置 CNAME 解析
3. 配置缓存策略和 HTTPS

#### Step 3: 代码部署
```bash
# 1. 安装 OSS SDK
cd /www/wwwroot/23.248.226.82
composer require aliyuncs/oss-sdk-php

# 2. 创建 OSS 驱动文件
# 按照 2.2.3 创建 app/common/library/upload/driver/Oss.php

# 3. 修改配置文件
# 编辑 .env, config/filesystem.php, config/upload.php

# 4. 测试上传功能
# 在后台上传一个测试文件，验证是否成功上传到 OSS
```

### 3.2 数据迁移

#### Step 1: 备份现有数据
```bash
# 备份 storage 目录
cd /www/wwwroot/23.248.226.82
tar -czf storage_backup_$(date +%Y%m%d).tar.gz public/storage/

# 备份数据库附件表
mysqldump waibao ba_attachment > attachment_backup_$(date +%Y%m%d).sql
```

#### Step 2: 上传现有文件到 OSS

**方案 A：使用 ossutil 工具（推荐）**
```bash
# 1. 下载并安装 ossutil
wget https://gosspublic.alicdn.com/ossutil/1.7.15/ossutil64
chmod 755 ossutil64

# 2. 配置 ossutil
./ossutil64 config

# 3. 批量上传文件
# 上传 storage 目录
./ossutil64 cp -r public/storage/ oss://your-project-name-static/storage/ --update

# 上传 static 目录
./ossutil64 cp -r public/static/ oss://your-project-name-static/static/ --update

# 上传 assets 目录
./ossutil64 cp -r public/assets/ oss://your-project-name-static/assets/ --update
```

**方案 B：使用 PHP 脚本迁移**
```php
<?php
// migrate_to_oss.php
require __DIR__ . '/vendor/autoload.php';

use OSS\OssClient;

$config = [
    'access_key_id'     => 'your_access_key_id',
    'access_key_secret' => 'your_access_key_secret',
    'endpoint'          => 'oss-cn-hangzhou.aliyuncs.com',
    'bucket'            => 'your-project-name-static',
];

$client = new OssClient(
    $config['access_key_id'],
    $config['access_key_secret'],
    $config['endpoint']
);

// 迁移函数
function migrateDirectory($client, $bucket, $localDir, $ossPrefix) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($localDir)
    );
    
    $count = 0;
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $localPath = $file->getPathname();
            $relativePath = str_replace($localDir . '/', '', $localPath);
            $ossPath = $ossPrefix . '/' . $relativePath;
            
            try {
                $client->uploadFile($bucket, $ossPath, $localPath);
                echo "✓ {$relativePath}\n";
                $count++;
            } catch (Exception $e) {
                echo "✗ {$relativePath}: {$e->getMessage()}\n";
            }
        }
    }
    
    return $count;
}

// 执行迁移
echo "开始迁移 storage 目录...\n";
$count1 = migrateDirectory($client, $config['bucket'], 'public/storage', 'storage');
echo "完成！共迁移 {$count1} 个文件\n\n";

echo "开始迁移 static 目录...\n";
$count2 = migrateDirectory($client, $config['bucket'], 'public/static', 'static');
echo "完成！共迁移 {$count2} 个文件\n\n";

echo "开始迁移 assets 目录...\n";
$count3 = migrateDirectory($client, $config['bucket'], 'public/assets', 'assets');
echo "完成！共迁移 {$count3} 个文件\n\n";

echo "总计迁移 " . ($count1 + $count2 + $count3) . " 个文件\n";
```

#### Step 3: 更新数据库附件记录
```php
<?php
// update_attachment_urls.php
require __DIR__ . '/vendor/autoload.php';

$app = new think\App();
$app->initialize();

$db = \think\facade\Db::connect();
$cdnDomain = 'https://static.yourdomain.com';

// 更新附件表的 storage 字段和 URL
$attachments = $db->name('attachment')->select();

foreach ($attachments as $attachment) {
    $oldUrl = $attachment['url'];
    
    // 将本地路径转换为 OSS URL
    if (strpos($oldUrl, '/storage/') === 0) {
        $newUrl = $oldUrl; // 保持相对路径不变
        
        $db->name('attachment')
            ->where('id', $attachment['id'])
            ->update([
                'storage' => 'oss',
                'url' => $newUrl,
                'update_time' => time()
            ]);
        
        echo "✓ 更新附件 ID {$attachment['id']}: {$oldUrl}\n";
    }
}

echo "附件记录更新完成！\n";
```

### 3.3 切换阶段

#### Step 1: 灰度测试
```bash
# 1. 修改 .env 文件，启用 OSS
FILESYSTEM_DRIVER=oss
UPLOAD_DRIVER=oss

# 2. 重启 PHP-FPM
systemctl restart php-fpm

# 3. 测试上传功能
# - 在后台上传文件
# - 在前台上传头像
# - 检查文件是否正确上传到 OSS
# - 检查文件 URL 是否正确

# 4. 如果有问题，立即回滚
FILESYSTEM_DRIVER=local
UPLOAD_DRIVER=local
systemctl restart php-fpm
```

#### Step 2: 更新 Nginx 配置（可选）
```nginx
# /www/server/panel/vhost/nginx/23.248.226.82_5657.conf

# 如果 OSS 迁移成功，可以移除本地静态文件的代理规则
# 或者保留作为备份，当 OSS 不可用时自动降级到本地

# 存储文件代理到 OSS（通过后端转发）
location /storage {
    # 如果本地文件存在，直接返回（降级方案）
    try_files $uri @oss_storage;
}

location @oss_storage {
    # 代理到 CDN 或 OSS
    proxy_pass https://static.yourdomain.com;
    proxy_set_header Host static.yourdomain.com;
    proxy_cache_valid 200 7d;
}
```

#### Step 3: 全量切换
```bash
# 1. 确认所有功能正常
# 2. 监控 OSS 访问日志和错误日志
# 3. 监控服务器磁盘空间和带宽使用情况
```

### 3.4 清理阶段

#### Step 1: 验证数据完整性
```bash
# 1. 检查 OSS 文件数量
./ossutil64 ls oss://your-project-name-static/ -r | wc -l

# 2. 检查本地文件数量
find public/storage -type f | wc -l

# 3. 对比确认数据完整
```

#### Step 2: 清理本地文件（谨慎操作）
```bash
# ⚠️ 警告：确保 OSS 数据完整且系统运行稳定后再执行

# 1. 保留最近 7 天的备份
# 2. 删除旧的 storage 文件
cd /www/wwwroot/23.248.226.82
# 建议先移动到备份目录，而不是直接删除
mkdir -p /backup/old_storage
mv public/storage/* /backup/old_storage/

# 3. 30 天后确认无问题，删除备份
# rm -rf /backup/old_storage
```

---

## 四、前端资源处理

### 4.1 前端构建资源（assets）

#### 方案 A：构建时直接上传到 OSS
修改前端构建脚本，在构建完成后自动上传到 OSS：

```javascript
// web/upload-to-oss.js
const OSS = require('ali-oss');
const fs = require('fs');
const path = require('path');

const client = new OSS({
  region: 'oss-cn-hangzhou',
  accessKeyId: process.env.OSS_ACCESS_KEY_ID,
  accessKeySecret: process.env.OSS_ACCESS_KEY_SECRET,
  bucket: 'your-project-name-static'
});

async function uploadDir(localDir, ossPrefix) {
  const files = fs.readdirSync(localDir);
  
  for (const file of files) {
    const localPath = path.join(localDir, file);
    const stat = fs.statSync(localPath);
    
    if (stat.isDirectory()) {
      await uploadDir(localPath, `${ossPrefix}/${file}`);
    } else {
      const ossPath = `${ossPrefix}/${file}`;
      await client.put(ossPath, localPath);
      console.log(`✓ ${ossPath}`);
    }
  }
}

uploadDir('dist/assets', 'assets').then(() => {
  console.log('上传完成！');
});
```

```json
// package.json
{
  "scripts": {
    "build": "vite build",
    "build:oss": "vite build && node upload-to-oss.js"
  }
}
```

#### 方案 B：使用 CDN 回源
保持前端资源在服务器本地，通过 CDN 回源加速：
```nginx
# Nginx 配置
location /assets {
    expires 365d;
    add_header Cache-Control "public, immutable";
}
```

### 4.2 前端代码修改

如果使用 OSS CDN 域名，需要修改前端资源引用：

```typescript
// web/src/utils/config.ts
export const STATIC_CDN = import.meta.env.VITE_STATIC_CDN || '';

// 使用示例
const imageUrl = `${STATIC_CDN}/storage/default/20260114/example.jpg`;
```

```env
# web/.env.production
VITE_STATIC_CDN=https://static.yourdomain.com
```

---

## 五、监控与优化

### 5.1 监控指标

#### OSS 监控
- 存储空间使用量
- 请求次数（GET/PUT/DELETE）
- 流量统计（上传/下载）
- 错误率（4xx/5xx）
- 平均响应时间

#### CDN 监控
- 命中率
- 回源流量
- 带宽峰值
- 访问日志分析

### 5.2 成本优化

#### 存储成本
- 定期清理无用文件
- 使用生命周期规则自动转换存储类型
  - 30 天后转为低频访问存储
  - 180 天后转为归档存储

#### 流量成本
- 启用 CDN 加速，降低回源流量
- 配置合理的缓存策略
- 启用图片压缩和格式转换

#### 请求成本
- 合并小文件
- 使用 CDN 缓存减少 OSS 请求

### 5.3 性能优化

#### 图片优化
```php
// 使用 OSS 图片处理服务
$imageUrl = 'https://static.yourdomain.com/storage/default/20260114/example.jpg';

// 缩略图
$thumbnail = $imageUrl . '?x-oss-process=image/resize,w_200,h_200';

// WebP 格式
$webp = $imageUrl . '?x-oss-process=image/format,webp';

// 质量压缩
$compressed = $imageUrl . '?x-oss-process=image/quality,q_80';
```

#### 防盗链
```php
// OSS 配置防盗链
// 在 OSS 控制台设置 Referer 白名单
// 或使用签名 URL
$signedUrl = $ossClient->signUrl($bucket, $object, 3600); // 1小时有效
```

---

## 六、应急预案

### 6.1 OSS 不可用

#### 降级方案
```php
// app/common/library/Upload.php
public function upload(?string $saveName = null, int $adminId = 0, int $userId = 0): array
{
    try {
        // 尝试上传到 OSS
        return $this->uploadToOss($saveName, $adminId, $userId);
    } catch (\Exception $e) {
        // OSS 失败，降级到本地存储
        \think\facade\Log::error('OSS 上传失败，降级到本地存储: ' . $e->getMessage());
        $this->driver['name'] = 'local';
        return $this->uploadToLocal($saveName, $adminId, $userId);
    }
}
```

#### 本地缓存
```nginx
# Nginx 配置本地缓存
proxy_cache_path /var/cache/nginx/oss levels=1:2 keys_zone=oss_cache:100m max_size=10g inactive=7d;

location /storage {
    proxy_cache oss_cache;
    proxy_cache_valid 200 7d;
    proxy_cache_key $uri;
    
    proxy_pass https://static.yourdomain.com;
    proxy_set_header Host static.yourdomain.com;
    
    # 如果 OSS 不可用，返回本地备份
    proxy_next_upstream error timeout http_500 http_502 http_503 http_504;
    error_page 502 503 504 = @local_backup;
}

location @local_backup {
    root /www/wwwroot/23.248.226.82/public;
    try_files $uri =404;
}
```

### 6.2 回滚方案

#### 快速回滚到本地存储
```bash
# 1. 修改 .env
FILESYSTEM_DRIVER=local
UPLOAD_DRIVER=local

# 2. 重启服务
systemctl restart php-fpm

# 3. 如果本地文件已删除，从备份恢复
cd /www/wwwroot/23.248.226.82
tar -xzf storage_backup_20260114.tar.gz
```

---

## 七、时间计划

### 第一阶段：准备（1-2 天）
- [ ] 创建 OSS Bucket 和 CDN
- [ ] 安装 OSS SDK
- [ ] 开发 OSS 驱动代码
- [ ] 配置测试环境

### 第二阶段：测试（2-3 天）
- [ ] 功能测试（上传、删除、访问）
- [ ] 性能测试（上传速度、访问速度）
- [ ] 兼容性测试（各种文件类型）
- [ ] 压力测试（并发上传）

### 第三阶段：迁移（1 天）
- [ ] 备份现有数据
- [ ] 批量上传文件到 OSS
- [ ] 更新数据库记录
- [ ] 验证数据完整性

### 第四阶段：切换（1 天）
- [ ] 灰度测试（10% 流量）
- [ ] 监控系统运行状态
- [ ] 全量切换
- [ ] 持续监控 24 小时

### 第五阶段：清理（7-30 天后）
- [ ] 验证 OSS 数据完整性
- [ ] 清理本地文件
- [ ] 删除备份数据

---

## 八、成本估算

### 8.1 OSS 成本（按华东1杭州计算）

#### 存储费用
- 标准存储：0.12 元/GB/月
- 当前数据量：1.92GB
- 月存储费用：1.92 × 0.12 = **0.23 元/月**

#### 流量费用
- 外网流出流量：0.5 元/GB（前 10TB）
- 假设每月流量：100GB
- 月流量费用：100 × 0.5 = **50 元/月**

#### 请求费用
- PUT 请求：0.01 元/万次
- GET 请求：0.01 元/万次
- 假设每月 100 万次请求
- 月请求费用：(100/10) × 0.01 = **0.1 元/月**

**OSS 总成本：约 50.33 元/月**

### 8.2 CDN 成本

#### 流量费用
- CDN 流量：0.24 元/GB（0-10TB）
- 假设每月流量：100GB
- 月流量费用：100 × 0.24 = **24 元/月**

#### 请求费用
- HTTPS 请求：0.05 元/万次
- 假设每月 100 万次请求
- 月请求费用：(100/10) × 0.05 = **0.5 元/月**

**CDN 总成本：约 24.5 元/月**

### 8.3 总成本对比

| 项目 | 当前方案（本地存储） | OSS + CDN 方案 | 节省 |
|------|---------------------|---------------|------|
| 存储成本 | 服务器磁盘成本 | 0.23 元/月 | - |
| 流量成本 | 服务器带宽成本（约 100 元/月） | 24.5 元/月 | 75.5 元/月 |
| 请求成本 | 服务器 CPU/内存成本 | 0.6 元/月 | - |
| **总计** | **约 100 元/月** | **约 25.33 元/月** | **74.67 元/月** |

**年节省成本：74.67 × 12 = 896 元**

---

## 九、风险评估

### 9.1 技术风险

| 风险 | 影响 | 概率 | 应对措施 |
|------|------|------|---------|
| OSS 服务中断 | 高 | 低 | 降级到本地存储 + 本地缓存 |
| 数据迁移丢失 | 高 | 低 | 完整备份 + 分批迁移 + 验证 |
| 性能下降 | 中 | 低 | CDN 加速 + 监控优化 |
| 成本超支 | 中 | 中 | 设置费用告警 + 定期清理 |
| 兼容性问题 | 中 | 低 | 充分测试 + 灰度发布 |

### 9.2 业务风险

| 风险 | 影响 | 概率 | 应对措施 |
|------|------|------|---------|
| 用户访问失败 | 高 | 低 | 降级方案 + 快速回滚 |
| 上传功能异常 | 高 | 低 | 异常捕获 + 本地降级 |
| 历史文件丢失 | 高 | 低 | 完整备份 + 保留 30 天 |

---

## 十、总结

### 10.1 方案优势
✅ **降低成本**：年节省约 896 元带宽和存储成本  
✅ **提升性能**：CDN 加速，全国访问速度提升 50%+  
✅ **提高可靠性**：OSS 99.995% 可用性，11 个 9 的数据持久性  
✅ **易于扩展**：支持海量文件存储，无需担心磁盘空间  
✅ **降低运维成本**：无需管理磁盘空间，自动备份和容灾  

### 10.2 实施建议
1. **分阶段实施**：先测试，后灰度，再全量
2. **保留降级方案**：确保 OSS 不可用时能快速切换到本地
3. **充分备份**：迁移前完整备份，保留至少 30 天
4. **持续监控**：关注 OSS 访问日志、错误率、成本
5. **定期优化**：清理无用文件，优化缓存策略

### 10.3 后续优化
- [ ] 实现图片自动压缩和格式转换
- [ ] 配置生命周期规则，自动归档旧文件
- [ ] 实现文件秒传功能（基于 SHA1 去重）
- [ ] 配置跨域访问策略（CORS）
- [ ] 实现私有文件的签名访问

---

**文档版本**：v1.0  
**创建时间**：2026-01-14  
**最后更新**：2026-01-14  
**维护人员**：技术团队
