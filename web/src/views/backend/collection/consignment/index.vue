<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <!-- 统计卡片 -->
        <el-row :gutter="16" style="margin-bottom: 15px;">
            <el-col :span="4">
                <el-card shadow="hover" class="stat-card">
                    <el-statistic title="总记录" :value="stats.total" />
                </el-card>
            </el-col>
            <el-col :span="4">
                <el-card shadow="hover" class="stat-card stat-warning">
                    <el-statistic title="寄售中" :value="stats.status?.[1]?.count || 0" />
                </el-card>
            </el-col>
            <el-col :span="4">
                <el-card shadow="hover" class="stat-card stat-success">
                    <el-statistic title="已售出" :value="stats.status?.[2]?.count || 0" />
                </el-card>
            </el-col>
            <el-col :span="4">
                <el-card shadow="hover" class="stat-card stat-info">
                    <el-statistic title="流拍" :value="stats.status?.[3]?.count || 0" />
                </el-card>
            </el-col>
            <el-col :span="4">
                <el-card shadow="hover" class="stat-card">
                    <el-statistic title="今日新增" :value="stats.today_new || 0" />
                </el-card>
            </el-col>
            <el-col :span="4">
                <el-card shadow="hover" class="stat-card stat-success">
                    <el-statistic title="今日成交" :value="stats.today_sold || 0" />
                </el-card>
            </el-col>
        </el-row>

        <!-- 金额统计 -->
        <el-row :gutter="16" style="margin-bottom: 15px;">
            <el-col :span="8">
                <el-card shadow="hover" class="stat-card">
                    <el-statistic title="累计成交额" :value="stats.total_sold_amount || 0" :precision="2" prefix="¥" value-style="color: #67c23a" />
                </el-card>
            </el-col>
            <el-col :span="8">
                <el-card shadow="hover" class="stat-card">
                    <el-statistic title="今日成交额" :value="stats.today_sold_amount || 0" :precision="2" prefix="¥" value-style="color: #409eff" />
                </el-card>
            </el-col>
            <el-col :span="8">
                <el-card shadow="hover" class="stat-card">
                    <el-statistic title="资产包数" :value="stats.packages?.length || 0" />
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
                <el-table-column prop="count" label="总数" align="center" width="70" />
                <el-table-column prop="listing_count" label="寄售中" align="center" width="70">
                    <template #default="{ row }">
                        <el-tag type="warning" size="small">{{ row.listing_count || 0 }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="sold_count" label="已售出" align="center" width="70">
                    <template #default="{ row }">
                        <el-tag type="success" size="small">{{ row.sold_count || 0 }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="total_amount" label="总金额" align="center" width="90">
                    <template #default="{ row }">
                        <span>¥{{ row.total_amount || 0 }}</span>
                    </template>
                </el-table-column>
                <el-table-column prop="sold_amount" label="成交额" align="center" width="90">
                    <template #default="{ row }">
                        <span style="color: #67c23a; font-weight: bold;">¥{{ row.sold_amount || 0 }}</span>
                    </template>
                </el-table-column>
                <el-table-column prop="success_rate" label="成交率" align="center" width="70">
                    <template #default="{ row }">
                        <span :style="{ color: row.success_rate > 50 ? '#67c23a' : row.success_rate > 20 ? '#e6a23c' : '#f56c6c' }">
                            {{ row.success_rate || 0 }}%
                        </span>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>

        <!-- 资产包统计表格 -->
        <el-card shadow="never" style="margin-bottom: 15px;" v-if="stats.packages?.length > 0">
            <template #header>
                <span style="font-weight: bold;">资产包寄售详细统计 (TOP 30)</span>
            </template>
            <el-table :data="stats.packages" stripe size="small" max-height="300">
                <el-table-column prop="package_name" label="资产包名称" min-width="180" fixed />
                <el-table-column prop="count" label="总数" align="center" width="70" />
                <el-table-column prop="listing_count" label="寄售中" align="center" width="80">
                    <template #default="{ row }">
                        <el-tag type="warning" size="small">{{ row.listing_count || 0 }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="sold_count" label="已售出" align="center" width="80">
                    <template #default="{ row }">
                        <el-tag type="success" size="small">{{ row.sold_count || 0 }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="failed_count" label="流拍" align="center" width="70">
                    <template #default="{ row }">
                        <el-tag type="info" size="small">{{ row.failed_count || 0 }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="cancelled_count" label="已取消" align="center" width="80">
                    <template #default="{ row }">
                        <el-tag type="danger" size="small">{{ row.cancelled_count || 0 }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="total_amount" label="总金额" align="center" width="100">
                    <template #default="{ row }">
                        <span>¥{{ row.total_amount || 0 }}</span>
                    </template>
                </el-table-column>
                <el-table-column prop="sold_amount" label="成交额" align="center" width="100">
                    <template #default="{ row }">
                        <span style="color: #67c23a; font-weight: bold;">¥{{ row.sold_amount || 0 }}</span>
                    </template>
                </el-table-column>
                <el-table-column prop="success_rate" label="成交率" align="center" width="80">
                    <template #default="{ row }">
                        <span :style="{ color: row.success_rate > 50 ? '#67c23a' : row.success_rate > 20 ? '#e6a23c' : '#f56c6c' }">
                            {{ row.success_rate || 0 }}%
                        </span>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>

        <TableHeader
            :buttons="['refresh', 'comSearch', 'quickSearch', 'columnDisplay']"
            quick-search-placeholder="快速搜索：ID/资产包名称"
        />

        <Table />

        <!-- 详情对话框 -->
        <el-dialog
            v-model="detailDialog.visible"
            title="寄售详情"
            width="800px"
            :close-on-click-modal="false"
        >
            <el-descriptions v-if="detailDialog.data" :column="2" border>
                <el-descriptions-item label="寄售ID">{{ detailDialog.data.id }}</el-descriptions-item>
                <el-descriptions-item label="状态">
                    <el-tag :type="getStatusType(detailDialog.data.status)">
                        {{ detailDialog.data.status_text }}
                    </el-tag>
                </el-descriptions-item>
                
                <el-descriptions-item label="用户名">{{ detailDialog.data.username }}</el-descriptions-item>
                <el-descriptions-item label="手机号">{{ detailDialog.data.user_mobile }}</el-descriptions-item>
                
                <el-descriptions-item label="藏品标题" :span="2">{{ detailDialog.data.item_title }}</el-descriptions-item>
                
                <el-descriptions-item label="场次ID">{{ detailDialog.data.session_id || '无' }}</el-descriptions-item>
                <el-descriptions-item label="价格分区ID">{{ detailDialog.data.zone_id || '无' }}</el-descriptions-item>
                
                <el-descriptions-item label="资产包ID">{{ detailDialog.data.package_id || '无' }}</el-descriptions-item>
                <el-descriptions-item label="资产包名称">{{ detailDialog.data.package_name_display || '无' }}</el-descriptions-item>
                
                <el-descriptions-item label="寄售价格">¥{{ parseFloat(detailDialog.data.price).toFixed(2) }}</el-descriptions-item>
                <el-descriptions-item label="原价">¥{{ parseFloat(detailDialog.data.original_price).toFixed(2) }}</el-descriptions-item>
                
                <el-descriptions-item label="手续费">¥{{ parseFloat(detailDialog.data.service_fee).toFixed(2) }}</el-descriptions-item>
                <el-descriptions-item label="使用寄售券">{{ detailDialog.data.coupon_used ? '是' : '否' }}</el-descriptions-item>
                
                <el-descriptions-item label="成交价格">
                    {{ detailDialog.data.sold_price > 0 ? '¥' + parseFloat(detailDialog.data.sold_price).toFixed(2) : '-' }}
                </el-descriptions-item>
                <el-descriptions-item label="成交时间">{{ detailDialog.data.sold_time_text || '-' }}</el-descriptions-item>
                
                <el-descriptions-item label="创建时间">{{ detailDialog.data.create_time_text }}</el-descriptions-item>
                <el-descriptions-item label="更新时间">{{ detailDialog.data.update_time_text }}</el-descriptions-item>
            </el-descriptions>
        </el-dialog>
    </div>
</template>

<script setup lang="ts">
import { provide, ref, reactive, onMounted } from 'vue'
import baTableClass from '/@/utils/baTable'
import { baTableApi } from '/@/api/common'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import createAxios from '/@/utils/axios'
import { ElMessage } from 'element-plus'

defineOptions({
    name: 'collection/consignment',
})

// 统计数据
const stats = reactive({
    total: 0,
    status: {} as any,
    today_new: 0,
    today_sold: 0,
    total_sold_amount: 0,
    today_sold_amount: 0,
    packages: [] as any[],
    package_options: [] as string[],
    package_zones: [] as any[],
})

// 获取统计数据
const fetchStats = async () => {
    try {
        const res = await createAxios({
            url: '/admin/collection.Consignment/stats',
            method: 'get',
        })
        if (res.code === 1 && res.data?.stats) {
            const s = res.data.stats
            stats.total = s.total || 0
            stats.status = s.status || {}
            stats.today_new = s.today_new || 0
            stats.today_sold = s.today_sold || 0
            stats.total_sold_amount = s.total_sold_amount || 0
            stats.today_sold_amount = s.today_sold_amount || 0
            stats.packages = s.packages || []
            stats.package_options = s.package_options || []
            stats.package_zones = s.package_zones || []
        }
    } catch (error) {
        console.error('获取统计数据失败:', error)
    }
}

// 页面加载时获取统计
onMounted(() => {
    fetchStats()
})

// 详情对话框
const detailDialog = ref({
    visible: false,
    data: null as any,
})

// 查看详情
const viewDetail = async (row: any) => {
    try {
        const res = await createAxios({
            url: '/admin/collection.Consignment/detail',
            method: 'get',
            params: { id: row.id },
        })

        if (res.code === 1) {
            detailDialog.value.data = res.data.data
            detailDialog.value.visible = true
        } else {
            ElMessage.error(res.msg || '获取详情失败')
        }
    } catch (error: any) {
        console.error('获取详情失败:', error)
        ElMessage.error(error?.msg || error?.response?.data?.msg || '获取详情失败')
    }
}

// 状态标签类型
const getStatusType = (status: number): 'warning' | 'success' | 'info' | 'danger' => {
    const typeMap: Record<number, 'warning' | 'success' | 'info' | 'danger'> = {
        1: 'warning',
        2: 'success',
        3: 'info',
        4: 'danger',
    }
    return typeMap[status] || 'info'
}

const baTable = new baTableClass(
    new baTableApi('/admin/collection.Consignment/'),
    {
        pk: 'id',
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', width: 70, operator: 'RANGE', sortable: 'custom' },
            { label: '用户名', prop: 'username', align: 'center', operator: 'LIKE', operatorPlaceholder: '模糊查询' },
            { label: '手机号', prop: 'user_mobile', align: 'center', operator: 'LIKE', operatorPlaceholder: '模糊查询' },
            { label: '藏品标题', prop: 'item_title', align: 'center', operator: 'LIKE', operatorPlaceholder: '模糊查询', width: 200 },
            { label: '资产包', prop: 'package_name', align: 'center', operator: 'LIKE', operatorPlaceholder: '模糊查询', width: 150 },
            { 
                label: '价格分区', 
                prop: 'zone_id', 
                align: 'center', 
                operator: '=',
                comSearchRender: 'select',
                comSearchOptions: [
                    { label: '全部', value: '' },
                    { label: '分区1', value: 1 },
                    { label: '分区2', value: 2 },
                    { label: '分区3', value: 3 },
                    { label: '分区4', value: 4 },
                    { label: '分区5', value: 5 },
                ],
                width: 100
            },
            { 
                label: '寄售价格', 
                prop: 'price', 
                align: 'center', 
                operator: 'RANGE',
                render: 'customTemplate',
                customTemplate: (row: any) => {
                    return `¥${parseFloat(row.price).toFixed(2)}`
                },
                width: 100
            },
            { 
                label: '原价', 
                prop: 'original_price', 
                align: 'center', 
                operator: 'RANGE',
                render: 'customTemplate',
                customTemplate: (row: any) => {
                    return `¥${parseFloat(row.original_price).toFixed(2)}`
                },
                width: 100
            },
            { 
                label: '手续费', 
                prop: 'service_fee', 
                align: 'center',
                render: 'customTemplate',
                customTemplate: (row: any) => {
                    return `¥${parseFloat(row.service_fee).toFixed(2)}`
                },
                width: 90
            },
            { 
                label: '使用券', 
                prop: 'coupon_used', 
                align: 'center',
                render: 'tag',
                replaceValue: {
                    0: '否',
                    1: '是'
                },
                custom: {
                    0: 'info',
                    1: 'success'
                },
                width: 80
            },
            {
                label: '状态',
                prop: 'status',
                align: 'center',
                operator: '=',
                comSearchRender: 'select',
                comSearchOptions: [
                    { label: '全部', value: '' },
                    { label: '寄售中', value: 1 },
                    { label: '已售出', value: 2 },
                    { label: '流拍', value: 3 },
                    { label: '已取消', value: 4 },
                ],
                render: 'tag',
                replaceValue: {
                    1: '寄售中',
                    2: '已售出',
                    3: '流拍',
                    4: '已取消'
                },
                custom: {
                    1: 'warning',
                    2: 'success',
                    3: 'info',
                    4: 'danger'
                },
                width: 90
            },
            { label: '创建时间', prop: 'create_time', align: 'center', render: 'datetime', operator: 'RANGE', sortable: 'custom', width: 160 },
            { label: '成交时间', prop: 'sold_time', align: 'center', render: 'datetime', operator: 'RANGE', sortable: 'custom', width: 160 },
            {
                label: '操作',
                align: 'center',
                width: 100,
                render: 'buttons',
                buttons: ((row: any) => {
                    return [
                        {
                            name: 'detail',
                            text: '详情',
                            type: 'primary',
                            icon: 'fa fa-info-circle',
                            render: 'basicButton',
                            click: () => viewDetail(row),
                        },
                    ]
                }) as any,
                operator: false,
            },
        ],
        dblClickNotEditColumn: ['all'],
    },
    {
        defaultItems: {},
    }
)

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
.stat-warning {
    border-left: 3px solid #e6a23c;
}
.stat-success {
    border-left: 3px solid #67c23a;
}
.stat-info {
    border-left: 3px solid #909399;
}
</style>
