<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <!-- 统计卡片 -->
        <el-row :gutter="16" style="margin-bottom: 15px;">
            <el-col :span="4">
                <el-card shadow="hover" class="stat-card">
                    <el-statistic title="总预约" :value="stats.total" />
                </el-card>
            </el-col>
            <el-col :span="4">
                <el-card shadow="hover" class="stat-card stat-info">
                    <el-statistic title="待处理" :value="stats.status?.[0]?.count || 0" />
                </el-card>
            </el-col>
            <el-col :span="4">
                <el-card shadow="hover" class="stat-card stat-success">
                    <el-statistic title="已中签" :value="stats.status?.[1]?.count || 0" />
                </el-card>
            </el-col>
            <el-col :span="4">
                <el-card shadow="hover" class="stat-card stat-danger">
                    <el-statistic title="未中签" :value="stats.status?.[2]?.count || 0" />
                </el-card>
            </el-col>
            <el-col :span="4">
                <el-card shadow="hover" class="stat-card">
                    <el-statistic title="今日新增" :value="stats.today_new || 0" />
                </el-card>
            </el-col>
            <el-col :span="4">
                <el-card shadow="hover" class="stat-card stat-success">
                    <el-statistic title="今日中签" :value="stats.today_win || 0" />
                </el-card>
            </el-col>
        </el-row>

        <!-- 金额统计 -->
        <el-row :gutter="16" style="margin-bottom: 15px;">
            <el-col :span="6">
                <el-card shadow="hover" class="stat-card stat-warning">
                    <el-statistic title="当前冻结金额" :value="stats.current_freeze_amount || 0" :precision="2" prefix="¥" value-style="color: #e6a23c" />
                </el-card>
            </el-col>
            <el-col :span="6">
                <el-card shadow="hover" class="stat-card">
                    <el-statistic title="历史总冻结" :value="stats.total_freeze_amount || 0" :precision="2" prefix="¥" value-style="color: #909399" />
                </el-card>
            </el-col>
            <el-col :span="6">
                <el-card shadow="hover" class="stat-card">
                    <el-statistic title="已取消退款" :value="stats.status?.[3]?.total_amount || 0" :precision="2" prefix="¥" value-style="color: #909399" />
                </el-card>
            </el-col>
            <el-col :span="6">
                <el-card shadow="hover" class="stat-card">
                    <el-statistic title="专场数" :value="stats.sessions?.length || 0" />
                </el-card>
            </el-col>
        </el-row>

        <!-- 资产包+分区组合统计表格 -->
        <el-card shadow="never" style="margin-bottom: 15px;" v-if="stats.package_zones?.length > 0">
            <template #header>
                <span style="font-weight: bold;">资产包分区统计 (TOP 30)</span>
            </template>
            <el-table :data="stats.package_zones" stripe size="small" max-height="300">
                <el-table-column prop="name" label="资产包+分区" min-width="200" show-overflow-tooltip />
                <el-table-column prop="count" label="总预约" align="center" width="70" />
                <el-table-column prop="pending_count" label="待处理" align="center" width="70">
                    <template #default="{ row }">
                        <el-tag type="info" size="small">{{ row.pending_count || 0 }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="win_count" label="已中签" align="center" width="70">
                    <template #default="{ row }">
                        <el-tag type="success" size="small">{{ row.win_count || 0 }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="total_amount" label="冻结总额" align="center" width="90">
                    <template #default="{ row }">
                        <span>¥{{ row.total_amount || 0 }}</span>
                    </template>
                </el-table-column>
                <el-table-column prop="win_rate" label="中签率" align="center" width="70">
                    <template #default="{ row }">
                        <span :style="{ color: row.win_rate > 50 ? '#67c23a' : row.win_rate > 20 ? '#e6a23c' : '#f56c6c' }">
                            {{ row.win_rate || 0 }}%
                        </span>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>

        <!-- 资产包统计表格 -->
        <el-card shadow="never" style="margin-bottom: 15px;" v-if="stats.packages?.length > 0">
            <template #header>
                <span style="font-weight: bold;">资产包预约统计 (TOP 20)</span>
            </template>
            <el-table :data="stats.packages" stripe size="small" max-height="200">
                <el-table-column prop="package_name" label="资产包" min-width="150" show-overflow-tooltip />
                <el-table-column prop="count" label="总预约" align="center" width="70" />
                <el-table-column prop="pending_count" label="待处理" align="center" width="70">
                    <template #default="{ row }">
                        <el-tag type="info" size="small">{{ row.pending_count || 0 }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="win_count" label="已中签" align="center" width="70">
                    <template #default="{ row }">
                        <el-tag type="success" size="small">{{ row.win_count || 0 }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="total_amount" label="冻结总额" align="center" width="90">
                    <template #default="{ row }">
                        <span>¥{{ row.total_amount || 0 }}</span>
                    </template>
                </el-table-column>
                <el-table-column prop="win_rate" label="中签率" align="center" width="70">
                    <template #default="{ row }">
                        <span :style="{ color: row.win_rate > 50 ? '#67c23a' : row.win_rate > 20 ? '#e6a23c' : '#f56c6c' }">
                            {{ row.win_rate || 0 }}%
                        </span>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>

        <TableHeader
            :buttons="['refresh', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="t('Quick search placeholder', { fields: '用户名/手机号' })"
        />

        <Table />

        <PopupForm />
    </div>
</template>

<script setup lang="ts">
import { provide, reactive, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import baTableClass from '/@/utils/baTable'
import { baTableApi } from '/@/api/common'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import PopupForm from './popupForm.vue'
import { defaultOptButtons } from '/@/components/table'
import { ElMessageBox, ElMessage } from 'element-plus'
import createAxios from '/@/utils/axios'

defineOptions({
    name: 'collection/tradeReservation',
})

const { t } = useI18n()

// 统计数据
const stats = reactive({
    total: 0,
    status: {} as any,
    today_new: 0,
    today_win: 0,
    current_freeze_amount: 0,
    total_freeze_amount: 0,
    package_zones: [] as any[],
    packages: [] as any[],
    session_options: [] as any[],
})

// 获取统计数据
const fetchStats = async () => {
    try {
        const res = await createAxios({
            url: '/admin/collection.TradeReservation/stats',
            method: 'get',
        })
        if (res.code === 1 && res.data?.stats) {
            const s = res.data.stats
            stats.total = s.total || 0
            stats.status = s.status || {}
            stats.today_new = s.today_new || 0
            stats.today_win = s.today_win || 0
            stats.current_freeze_amount = s.current_freeze_amount || 0
            stats.total_freeze_amount = s.total_freeze_amount || 0
            stats.package_zones = s.package_zones || []
            stats.packages = s.packages || []
            stats.session_options = s.session_options || []
        }
    } catch (error) {
        console.error('获取统计数据失败:', error)
    }
}

// 页面加载时获取统计
onMounted(() => {
    fetchStats()
})

const baTable = new baTableClass(
    new baTableApi('/admin/collection.TradeReservation/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', operator: '=', operatorPlaceholder: 'ID', width: 70 },
            { label: '用户ID', prop: 'user_id', align: 'center', operator: '=', width: 80 },
            { label: '用户名', prop: 'user.username', align: 'center', operator: 'LIKE', render: 'tags' },
            { label: '手机号', prop: 'user.mobile', align: 'center', operator: 'LIKE' },
            { label: '专场', prop: 'session.title', align: 'center', operator: 'LIKE' },
            { label: '价格分区', prop: 'zone.name', align: 'center', operator: 'LIKE' },
            { label: '冻结金额', prop: 'freeze_amount', align: 'center', operator: 'BETWEEN' },
            { label: '消耗算力', prop: 'power_used', align: 'center', operator: 'BETWEEN' },
            { label: '权重', prop: 'weight', align: 'center', operator: 'BETWEEN' },
            { 
                label: '状态', 
                prop: 'status', 
                align: 'center', 
                operator: '=',
                comSearchRender: 'select',
                comSearchOptions: [
                    { label: '全部', value: '' },
                    { label: '待处理', value: 0 },
                    { label: '已中签', value: 1 },
                    { label: '未中签', value: 2 },
                    { label: '已取消', value: 3 },
                ],
                render: 'tag', 
                custom: { 0: 'info', 1: 'success', 2: 'danger', 3: 'warning' }, 
                replaceValue: { 0: '待处理', 1: '已中签', 2: '未中签', 3: '已取消' } 
            },
            { label: '创建时间', prop: 'create_time', align: 'center', render: 'datetime', sortable: 'custom', operator: 'RANGE', width: 160 },
            { label: '操作', prop: 'operate', align: 'center', width: 100, render: 'buttons', buttons: ['delete'] },
        ],
        dblClickNotEditColumn: ['all'],
    },
    {
        defaultItems: {
            status: 0,
        },
    }
)


const cancelButton = {
    render: 'tipButton',
    name: 'cancel',
    text: '取消预约',
    type: 'danger',
    icon: 'fa fa-ban',
    title: '取消预约并退款',
    display: (row: any) => row.status === 0,
    class: 'table-row-cancel',
    click: (row: any) => {
        ElMessageBox.confirm('确定要取消该预约吗？冻结资金将自动退回。', '提示', {
            confirmButtonText: '确定',
            cancelButtonText: '取消',
            type: 'warning',
        })
            .then(() => {
                baTable.api
                    .postData('cancel', { ids: [row.id] })
                    .then((res) => {
                        baTable.onTableHeaderAction('refresh', {})
                        fetchStats() // 刷新统计
                    })
            })
            .catch(() => {})
    },
}

const optButtons = defaultOptButtons(['delete'])
optButtons.push(cancelButton)

baTable.table.column[baTable.table.column.length - 1].buttons = optButtons

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

<style scoped lang="scss">
.stat-card {
    :deep(.el-card__body) {
        padding: 15px;
    }
}
.stat-info {
    border-left: 3px solid #909399;
}
.stat-success {
    border-left: 3px solid #67c23a;
}
.stat-danger {
    border-left: 3px solid #f56c6c;
}
</style>
