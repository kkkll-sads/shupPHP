# BuildAdmin 后台管理页面开发指南

## 概述

BuildAdmin 是一个基于 Vue3 + ThinkPHP8 的后台管理系统框架。本文档介绍如何添加后台管理页面和操作按钮。

---

## 一、目录结构

```
app/admin/controller/          # 后台控制器
  └── collection/              # 业务模块目录
      └── AssetPackage.php     # 控制器文件

app/admin/model/               # 数据模型
  └── AssetPackage.php

app/admin/validate/            # 数据验证器
  └── AssetPackage.php

web/src/views/backend/         # 前端页面
  └── collection/              # 业务模块目录
      └── assetPackage/        # 页面目录
          ├── index.vue        # 列表页面
          └── popupForm.vue    # 表单弹窗
```

---

## 二、后端开发（PHP）

### 1. 创建控制器

**文件位置**：`app/admin/controller/collection/AssetPackage.php`

```php
<?php

namespace app\admin\controller\collection;

use Throwable;
use app\common\controller\Backend;
use app\admin\model\AssetPackage as AssetPackageModel;
use think\facade\Db;

/**
 * 资产包管理控制器
 */
class AssetPackage extends Backend
{
    /**
     * @var AssetPackageModel
     */
    protected object $model;

    // 快速搜索字段
    protected string|array $quickSearchField = ['name', 'id'];

    public function initialize(): void
    {
        parent::initialize();
        $this->model = new AssetPackageModel();
    }

    /**
     * 列表接口
     * @throws Throwable
     */
    public function index(): void
    {
        if ($this->request->param('select')) {
            $this->select();
        }

        // 构建查询条件
        [$where, $alias, $limit, $order] = $this->queryBuilder('id desc');

        // 查询数据
        $res = $this->model
            ->alias($alias)
            ->with(['session', 'zone'])  // 关联查询
            ->where($where)
            ->order($order)
            ->paginate($limit);

        // 处理列表数据
        $list = $res->items();
        foreach ($list as &$item) {
            $item['session_name'] = $item['session']['title'] ?? '未关联';
            $item['zone_name'] = $item['zone_id'] == 0 ? '通用包' : ($item['zone']['name'] ?? '未知分区');
        }

        $this->success('', [
            'list' => $list,
            'total' => $res->total(),
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 添加接口
     * @throws Throwable
     */
    public function add(): void
    {
        if ($this->request->isPost()) {
            // POST 请求：保存数据
            $data = $this->excludeFields($this->request->post());
            if (!$data) {
                $this->error(__('Parameter %s can not be empty', ['']));
            }

            unset($data['create_time'], $data['update_time']);

            $result = false;
            $this->model->startTrans();
            try {
                // 数据验证
                if ($this->modelValidate) {
                    $validate = str_replace("\\model\\", "\\validate\\", get_class($this->model));
                    if (class_exists($validate)) {
                        $validate = new $validate();
                        if ($this->modelSceneValidate) {
                            $validate->scene('add');
                        }
                        $validate->check($data);
                    }
                }

                // 业务逻辑处理
                $data['zone_id'] = 0;  // 统一设置为通用包

                $result = $this->model->save($data);
                $this->model->commit();
            } catch (Throwable $e) {
                $this->model->rollback();
                $this->error($e->getMessage());
            }

            if ($result !== false) {
                $this->success(__('Added successfully'));
            }
            $this->error(__('No rows were added'));
        }

        // GET 请求：返回表单数据（如下拉选项等）
        $sessions = Db::name('collection_session')
            ->where('status', '1')
            ->field('id, title')
            ->select()
            ->toArray();

        $this->success('', [
            'remark' => get_route_remark(),
            'sessions' => $sessions,
        ]);
    }

    /**
     * 编辑接口
     * @throws Throwable
     */
    public function edit(): void
    {
        $pk = $this->model->getPk();
        $id = $this->request->param($pk);
        $row = $this->model->find($id);
        if (!$row) {
            $this->error(__('Record not found'));
        }

        if ($this->request->isPost()) {
            // POST 请求：更新数据
            $data = $this->excludeFields($this->request->post());
            // ... 类似 add() 的逻辑
            $this->success(__('Updated successfully'));
        }

        // GET 请求：返回当前记录数据
        $this->success('', [
            'row' => $row,
            'remark' => get_route_remark(),
        ]);
    }

    /**
     * 删除接口
     * @throws Throwable
     */
    public function del(): void
    {
        $ids = $this->request->param('ids/a', []);
        $where[] = [$this->model->getPk(), 'in', $ids];
        $list = $this->model->where($where)->select();

        $count = 0;
        $this->model->startTrans();
        try {
            foreach ($list as $item) {
                // 业务检查
                $itemCount = Db::name('collection_item')
                    ->where('package_id', $item['id'])
                    ->count();
                if ($itemCount > 0) {
                    throw new \Exception("资产包【{$item['name']}】下有 {$itemCount} 个藏品，无法删除");
                }
                $count += $item->delete();
            }
            $this->model->commit();
        } catch (Throwable $e) {
            $this->model->rollback();
            $this->error($e->getMessage());
        }

        if ($count) {
            $this->success(__('Deleted successfully'));
        }
        $this->error(__('No rows were deleted'));
    }

    /**
     * 自定义操作：设为默认
     */
    public function setDefault(): void
    {
        $id = $this->request->param('id/d', 0);
        if ($id <= 0) {
            $this->error('参数错误');
        }

        $row = $this->model->find($id);
        if (!$row) {
            $this->error('记录不存在');
        }

        Db::startTrans();
        try {
            // 取消其他默认包
            Db::name('asset_package')
                ->where('name', $row['name'])
                ->where('session_id', $row['session_id'])
                ->where('is_default', 1)
                ->update(['is_default' => 0]);

            // 设置当前为默认
            $row->save(['is_default' => 1]);

            Db::commit();
            $this->success('设置成功');
        } catch (\Exception $e) {
            Db::rollback();
            $this->error($e->getMessage());
        }
    }
}
```

### 2. 创建模型

**文件位置**：`app/admin/model/AssetPackage.php`

```php
<?php

namespace app\admin\model;

use app\common\model\BaseModel;

class AssetPackage extends BaseModel
{
    // 关联场次
    public function session()
    {
        return $this->belongsTo(CollectionSession::class, 'session_id', 'id');
    }

    // 关联分区
    public function zone()
    {
        return $this->belongsTo(PriceZoneConfig::class, 'zone_id', 'id');
    }
}
```

### 3. 创建验证器（可选）

**文件位置**：`app/admin/validate/AssetPackage.php`

```php
<?php

namespace app\admin\validate;

use think\Validate;

class AssetPackage extends Validate
{
    protected $rule = [
        'name' => 'require|max:100',
        'session_id' => 'require|integer',
    ];

    protected $message = [
        'name.require' => '资产包名称不能为空',
        'name.max' => '资产包名称不能超过100个字符',
        'session_id.require' => '请选择关联场次',
    ];
}
```

---

## 三、前端开发（Vue3 + TypeScript）

### 1. 创建列表页面

**文件位置**：`web/src/views/backend/collection/assetPackage/index.vue`

```vue
<template>
    <div class="default-main ba-table-box">
        <!-- 提示信息 -->
        <el-alert 
            class="ba-table-alert" 
            v-if="baTable.table.remark" 
            :title="baTable.table.remark" 
            type="info" 
            show-icon 
        />

        <!-- 表格头部操作栏 -->
        <TableHeader
            :buttons="['refresh', 'add', 'edit', 'delete', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="'资产包名称搜索'"
        />

        <!-- 表格 -->
        <Table />

        <!-- 表单弹窗 -->
        <PopupForm :sessions="sessions" :zones="zones" />
    </div>
</template>

<script setup lang="ts">
import { provide, ref, onMounted } from 'vue'
import baTableClass from '/@/utils/baTable'
import PopupForm from './popupForm.vue'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import { defaultOptButtons } from '/@/components/table'
import { baTableApi } from '/@/api/common'
import { useI18n } from 'vue-i18n'

defineOptions({
    name: 'collection/assetPackage',
})

const { t } = useI18n()

const sessions = ref<any[]>([])
const zones = ref<any[]>([])

// 定义表格列
const columns = [
    { type: 'selection', align: 'center', operator: false },
    { label: 'ID', prop: 'id', align: 'center', operator: '=', width: 70 },
    {
        label: '资产包名称',
        prop: 'name',
        align: 'center',
        operator: 'LIKE',
        operatorPlaceholder: '模糊搜索',
        minWidth: 150,
    },
    {
        label: '关联场次',
        prop: 'session_name',
        align: 'center',
        operator: false,
        width: 120,
    },
    {
        label: '状态',
        prop: 'status',
        align: 'center',
        render: 'switch',  // 开关组件
        replaceValue: { 0: '禁用', 1: '启用' },
        width: 80,
    },
    {
        label: t('Create time'),
        prop: 'create_time',
        align: 'center',
        render: 'datetime',  // 日期时间渲染
        sortable: 'custom',
        operator: 'RANGE',
        width: 160,
    },
    {
        label: t('Operate'),
        prop: 'operate',
        align: 'center',
        width: 200,
        render: 'buttons',  // 操作按钮列
        buttons: [],
        operator: false,
    },
] as any[]

// 初始化 baTable 实例
const baTable = new baTableClass(
    new baTableApi('/admin/collection.AssetPackage/'),  // API 路径
    {
        column: columns as any,
    },
    {
        defaultItems: {  // 表单默认值
            status: 1,
            is_default: 0,
            zone_id: 0,
        },
    }
)

// ============================================================
// 添加自定义操作按钮
// ============================================================

// 1. 定义自定义按钮
const setDefaultBtn = {
    name: 'setDefault',
    render: 'tipButton',  // 提示按钮
    title: '设为默认',
    text: '',
    type: 'warning',
    icon: 'fa fa-star',
    class: 'table-row-setDefault',
    disabledTip: false,
    // 条件显示：只有非默认时才显示
    display: (row: any) => row.is_default != 1,
    // 点击事件
    click: (row: any, field: any) => {
        baTable.api.custom({
            url: '/admin/collection.AssetPackage/setDefault',
            data: { id: row.id },
        }).then(() => {
            baTable.onTableAction('refresh', {})  // 刷新表格
        })
    },
}

// 2. 合并默认按钮和自定义按钮
const optButtons = [...defaultOptButtons(['edit', 'delete']), setDefaultBtn]

// 3. 确保所有按钮都有 display 方法
optButtons.forEach((btn) => {
    btn.display = btn.display || (() => true)
})

// 4. 将按钮添加到表格列
baTable.table.column[baTable.table.column.length - 1].buttons = optButtons

// 5. 权限控制（可选）
baTable.auth = () => true  // 放行所有权限

// 挂载表格
baTable.mount()

// 页面加载时获取数据
onMounted(async () => {
    await baTable.getData()
    
    // 获取下拉选项数据
    try {
        const addRes = await baTable.api.custom({ 
            url: baTable.api.actionUrl.get('add') 
        })
        if (addRes.data) {
            sessions.value = addRes.data.sessions || []
            zones.value = addRes.data.zones || []
        }
    } catch (e) {
        console.error('Failed to load sessions/zones', e)
    }
})

provide('baTable', baTable)
</script>
```

### 2. 创建表单弹窗

**文件位置**：`web/src/views/backend/collection/assetPackage/popupForm.vue`

```vue
<template>
    <el-dialog
        v-model="baTable.form.operate"
        :title="baTable.form.operate === 'Add' ? '添加资产包' : '编辑资产包'"
        width="600px"
        destroy-on-close
    >
        <el-form
            ref="formRef"
            :model="baTable.form.items"
            label-width="100px"
            :rules="rules"
        >
            <el-form-item label="资产包名称" prop="name">
                <el-input v-model="baTable.form.items.name" placeholder="请输入资产包名称" />
            </el-form-item>

            <el-form-item label="关联场次" prop="session_id">
                <el-select v-model="baTable.form.items.session_id" placeholder="请选择场次">
                    <el-option
                        v-for="session in sessions"
                        :key="session.id"
                        :label="session.title"
                        :value="session.id"
                    />
                </el-select>
            </el-form-item>

            <el-form-item label="状态" prop="status">
                <el-radio-group v-model="baTable.form.items.status">
                    <el-radio :label="1">启用</el-radio>
                    <el-radio :label="0">禁用</el-radio>
                </el-radio-group>
            </el-form-item>
        </el-form>

        <template #footer>
            <el-button @click="baTable.form.operate = false">取消</el-button>
            <el-button type="primary" @click="onSubmit">确定</el-button>
        </template>
    </el-dialog>
</template>

<script setup lang="ts">
import { inject, ref } from 'vue'
import type { baTable } from '/@/utils/baTable'

const baTable = inject('baTable') as baTable

const props = defineProps<{
    sessions?: any[]
    zones?: any[]
}>()

const formRef = ref()

const rules = {
    name: [{ required: true, message: '请输入资产包名称', trigger: 'blur' }],
    session_id: [{ required: true, message: '请选择关联场次', trigger: 'change' }],
}

const onSubmit = () => {
    formRef.value?.validate((valid: boolean) => {
        if (valid) {
            baTable.onSubmit(formRef)
        }
    })
}
</script>
```

---

## 四、操作按钮类型

### 1. 默认按钮类型

BuildAdmin 提供了以下默认按钮类型：

| 按钮类型 | render 值 | 说明 |
|---------|----------|------|
| 编辑按钮 | `'tipButton'` | 带提示的按钮 |
| 删除按钮 | `'confirmButton'` | 带确认对话框的按钮 |
| 拖拽排序 | `'moveButton'` | 可拖拽的按钮 |

**使用示例**：

```typescript
import { defaultOptButtons } from '/@/components/table'

// 获取默认的编辑和删除按钮
const optButtons = defaultOptButtons(['edit', 'delete'])
```

### 2. 自定义按钮配置

```typescript
const customButton = {
    name: 'customAction',        // 按钮唯一标识
    render: 'tipButton',         // 渲染类型：tipButton | confirmButton | moveButton | basicButton
    title: '自定义操作',         // 按钮提示文字
    text: '',                    // 按钮显示文字（空则只显示图标）
    type: 'primary',             // 按钮类型：primary | success | warning | danger | info
    icon: 'fa fa-star',          // 图标类名
    class: 'table-row-custom',   // 自定义 CSS 类名
    disabledTip: false,          // 禁用时是否显示提示
    
    // 条件显示函数（可选）
    display: (row: any) => {
        return row.status === 1  // 只有状态为1时才显示
    },
    
    // 点击事件处理函数
    click: (row: any, field: any) => {
        // 调用自定义 API
        baTable.api.custom({
            url: '/admin/collection.AssetPackage/customAction',
            data: { id: row.id },
        }).then(() => {
            baTable.onTableAction('refresh', {})  // 刷新表格
        })
    },
    
    // 确认对话框配置（仅 confirmButton 需要）
    popconfirm: {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        confirmButtonType: 'danger',
        title: '确定要执行此操作吗？',
    },
}
```

### 3. 按钮渲染类型说明

#### `tipButton` - 提示按钮
- 鼠标悬停显示提示文字
- 适合：查看、编辑、设置等操作

#### `confirmButton` - 确认按钮
- 点击时弹出确认对话框
- 适合：删除、危险操作

#### `moveButton` - 拖拽按钮
- 可拖拽排序
- 适合：排序操作

#### `basicButton` - 基础按钮
- 普通按钮，无特殊功能
- 适合：自定义操作

---

## 五、表格头部按钮

在 `TableHeader` 组件中配置：

```vue
<TableHeader
    :buttons="[
        'refresh',      // 刷新
        'add',          // 添加
        'edit',         // 编辑（批量）
        'delete',       // 删除（批量）
        'comSearch',    // 高级搜索
        'quickSearch',  // 快速搜索
        'columnDisplay' // 列显示控制
    ]"
    :quick-search-placeholder="'资产包名称搜索'"
/>
```

---

## 六、API 路径规则

BuildAdmin 使用以下规则自动生成 API 路径：

```
/admin/{模块}.{控制器}/{方法}
```

**示例**：
- 控制器：`app/admin/controller/collection/AssetPackage.php`
- API 路径：`/admin/collection.AssetPackage/index`
- 添加接口：`/admin/collection.AssetPackage/add`
- 编辑接口：`/admin/collection.AssetPackage/edit`
- 删除接口：`/admin/collection.AssetPackage/del`
- 自定义接口：`/admin/collection.AssetPackage/setDefault`

---

## 七、完整示例：添加"设为默认"按钮

### 后端（PHP）

```php
// app/admin/controller/collection/AssetPackage.php

public function setDefault(): void
{
    $id = $this->request->param('id/d', 0);
    if ($id <= 0) {
        $this->error('参数错误');
    }

    $row = $this->model->find($id);
    if (!$row) {
        $this->error('记录不存在');
    }

    Db::startTrans();
    try {
        // 取消其他默认包
        Db::name('asset_package')
            ->where('name', $row['name'])
            ->where('session_id', $row['session_id'])
            ->where('is_default', 1)
            ->update(['is_default' => 0]);

        // 设置当前为默认
        $row->save(['is_default' => 1]);

        Db::commit();
        $this->success('设置成功');
    } catch (\Exception $e) {
        Db::rollback();
        $this->error($e->getMessage());
    }
}
```

### 前端（Vue）

```typescript
// web/src/views/backend/collection/assetPackage/index.vue

// 定义按钮
const setDefaultBtn = {
    name: 'setDefault',
    render: 'tipButton',
    title: '设为默认',
    text: '',
    type: 'warning',
    icon: 'fa fa-star',
    class: 'table-row-setDefault',
    disabledTip: false,
    display: (row: any) => row.is_default != 1,  // 只有非默认时才显示
    click: (row: any) => {
        baTable.api.custom({
            url: '/admin/collection.AssetPackage/setDefault',
            data: { id: row.id },
        }).then(() => {
            baTable.onTableAction('refresh', {})
        })
    },
}

// 合并到操作按钮列
const optButtons = [...defaultOptButtons(['edit', 'delete']), setDefaultBtn]
baTable.table.column[baTable.table.column.length - 1].buttons = optButtons
```

---

## 八、常见问题

### 1. 按钮不显示

**原因**：权限控制
**解决**：
```typescript
// 临时放行所有权限（开发阶段）
baTable.auth = () => true

// 或配置具体权限
baTable.auth = (action: string) => {
    return hasPermission(`collection.AssetPackage.${action}`)
}
```

### 2. 自定义按钮点击无反应

**检查**：
- `click` 函数是否正确定义
- API 路径是否正确
- 后端接口是否返回正确格式

### 3. 按钮条件显示不生效

**检查**：
- `display` 函数返回值是否为布尔值
- 函数中访问的 `row` 字段是否存在

---

## 九、参考资源

- **BuildAdmin 官方文档**：https://doc.buildadmin.com/
- **示例代码**：
  - `app/admin/controller/collection/AssetPackage.php`
  - `web/src/views/backend/collection/assetPackage/index.vue`
- **表格组件文档**：`web/src/utils/baTable.ts`

---

## 十、总结

1. **后端**：继承 `Backend` 基类，实现 `index`、`add`、`edit`、`del` 方法
2. **前端**：使用 `baTable` 类管理表格，通过 `buttons` 配置操作按钮
3. **自定义按钮**：定义按钮对象，配置 `display` 和 `click` 方法
4. **API 路径**：遵循 `/admin/{模块}.{控制器}/{方法}` 规则

按照以上步骤，即可快速添加后台管理页面和操作按钮。


<<<<<<< HEAD
=======

>>>>>>> 392e607a6782491114a0aee7408a7d620ecf394f

