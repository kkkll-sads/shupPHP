<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <TableHeader
            :buttons="['refresh', 'comSearch', 'quickSearch', 'columnDisplay']"
            quick-search-placeholder="快速搜索：手机号/昵称/藏品名称/确权编号"
        />

        <Table />

        <!-- 用户统计弹窗 -->
        <el-dialog v-model="statsDialog.visible" title="用户藏品统计" width="80%" top="5vh">
            <div v-loading="statsDialog.loading">
                <template v-if="statsDialog.data">
                    <!-- 用户信息 -->
                    <el-descriptions :column="4" border style="margin-bottom: 20px;">
                        <el-descriptions-item label="用户ID">{{ statsDialog.data.user?.id }}</el-descriptions-item>
                        <el-descriptions-item label="手机号">{{ statsDialog.data.user?.mobile }}</el-descriptions-item>
                        <el-descriptions-item label="昵称">{{ statsDialog.data.user?.nickname }}</el-descriptions-item>
                        <el-descriptions-item label="注册时间">{{ statsDialog.data.user?.create_time }}</el-descriptions-item>
                    </el-descriptions>

                    <!-- 统计数据 -->
                    <el-row :gutter="20" style="margin-bottom: 20px;">
                        <el-col :span="4">
                            <el-statistic title="藏品总数" :value="statsDialog.data.stats?.total_count || 0" />
                        </el-col>
                        <el-col :span="4">
                            <el-statistic title="总价值" :value="statsDialog.data.stats?.total_value || 0" :precision="2" prefix="¥" />
                        </el-col>
                        <el-col :span="4">
                            <el-statistic title="平均价格" :value="statsDialog.data.stats?.avg_price || 0" :precision="2" prefix="¥" />
                        </el-col>
                        <el-col :span="4">
                            <el-statistic title="持有中" :value="statsDialog.data.stats?.holding || 0" />
                        </el-col>
                        <el-col :span="4">
                            <el-statistic title="寄售中" :value="statsDialog.data.stats?.consigning || 0" />
                        </el-col>
                        <el-col :span="4">
                            <el-statistic title="已售出" :value="statsDialog.data.stats?.sold || 0" />
                        </el-col>
                        <el-col :span="4">
                            <el-statistic title="矿机中" :value="statsDialog.data.stats?.mining || 0" />
                        </el-col>
                    </el-row>

                    <!-- 藏品列表 -->
                    <el-table :data="statsDialog.data.collections || []" stripe border max-height="400">
                        <el-table-column prop="id" label="记录ID" width="80" align="center" />
                        <el-table-column prop="item_id" label="藏品ID" width="80" align="center" />
                        <el-table-column prop="title" label="藏品名称" min-width="150" align="center" />
                        <el-table-column prop="buy_price" label="购买价格" width="100" align="center">
                            <template #default="{ row }">¥{{ row.buy_price }}</template>
                        </el-table-column>
                        <el-table-column prop="current_price" label="当前价格" width="100" align="center">
                            <template #default="{ row }">¥{{ row.current_price }}</template>
                        </el-table-column>
                        <el-table-column prop="appreciation" label="增值" width="100" align="center">
                            <template #default="{ row }">
                                <span :style="{ color: row.appreciation >= 0 ? '#67c23a' : '#f56c6c' }">
                                    {{ row.appreciation >= 0 ? '+' : '' }}{{ row.appreciation }}
                                </span>
                            </template>
                        </el-table-column>
                        <el-table-column prop="consignment_status_text" label="寄售状态" width="100" align="center">
                            <template #default="{ row }">
                                <el-tag 
                                    :type="row.consignment_status === 0 ? 'info' : row.consignment_status === 1 ? 'warning' : 'success'"
                                    size="small"
                                >
                                    {{ row.consignment_status_text }}
                                </el-tag>
                            </template>
                        </el-table-column>
                        <el-table-column prop="mining_status_text" label="矿机状态" width="100" align="center">
                            <template #default="{ row }">
                                <el-tag 
                                    :type="row.mining_status === 1 ? 'danger' : 'info'"
                                    size="small"
                                    effect="plain"
                                >
                                    {{ row.mining_status_text }}
                                </el-tag>
                            </template>
                        </el-table-column>
                        <el-table-column prop="session_title" label="所属专场" width="120" align="center" />
                        <el-table-column prop="buy_time_text" label="购买时间" width="160" align="center" />
                    </el-table>
                </template>
            </div>
        </el-dialog>

        <!-- 详情弹窗 -->
        <el-dialog v-model="detailDialog.visible" title="藏品详情" width="70%">
            <div v-loading="detailDialog.loading">
                <template v-if="detailDialog.data">
                    <el-descriptions :column="2" border>
                        <el-descriptions-item label="记录ID">{{ detailDialog.data.row?.id }}</el-descriptions-item>
                        <el-descriptions-item label="用户ID">{{ detailDialog.data.row?.user_id }}</el-descriptions-item>
                        <el-descriptions-item label="手机号">{{ detailDialog.data.row?.mobile }}</el-descriptions-item>
                        <el-descriptions-item label="昵称">{{ detailDialog.data.row?.nickname }}</el-descriptions-item>
                        <el-descriptions-item label="藏品ID">{{ detailDialog.data.row?.item_id }}</el-descriptions-item>
                        <el-descriptions-item label="藏品名称">{{ detailDialog.data.row?.title }}</el-descriptions-item>
                        <el-descriptions-item label="购买价格">¥{{ detailDialog.data.row?.price }}</el-descriptions-item>
                        <el-descriptions-item label="当前价格">¥{{ detailDialog.data.row?.current_price }}</el-descriptions-item>
                        <el-descriptions-item label="发行价格">¥{{ detailDialog.data.row?.issue_price }}</el-descriptions-item>
                        <el-descriptions-item label="所属专场">{{ detailDialog.data.row?.session_title }}</el-descriptions-item>
                        <el-descriptions-item label="资产包">{{ detailDialog.data.row?.package_name || '-' }}</el-descriptions-item>
                        <el-descriptions-item label="订单号">{{ detailDialog.data.row?.order_no || '-' }}</el-descriptions-item>
                        <el-descriptions-item label="确权编号" :span="2">{{ detailDialog.data.row?.asset_code || '-' }}</el-descriptions-item>
                        <el-descriptions-item label="购买时间">{{ detailDialog.data.row?.buy_time_text }}</el-descriptions-item>
                        <el-descriptions-item label="创建时间">{{ detailDialog.data.row?.create_time_text }}</el-descriptions-item>
                        <el-descriptions-item label="矿机状态">
                            <el-tag :type="detailDialog.data.row?.mining_status === 1 ? 'danger' : 'info'">{{ detailDialog.data.row?.mining_status_text }}</el-tag>
                        </el-descriptions-item>
                        <el-descriptions-item label="矿机启动时间">{{ detailDialog.data.row?.mining_start_time_text || '-' }}</el-descriptions-item>
                        <el-descriptions-item label="上次分红时间">{{ detailDialog.data.row?.last_dividend_time_text || '-' }}</el-descriptions-item>
                    </el-descriptions>

                    <!-- 寄售记录 -->
                    <div style="margin-top: 20px;" v-if="detailDialog.data.consignments?.length">
                        <h4>寄售记录</h4>
                        <el-table :data="detailDialog.data.consignments" stripe border>
                            <el-table-column prop="id" label="寄售ID" width="80" align="center" />
                            <el-table-column prop="price" label="寄售价格" width="100" align="center">
                                <template #default="{ row }">¥{{ row.price }}</template>
                            </el-table-column>
                            <el-table-column prop="status_text" label="状态" width="100" align="center">
                                <template #default="{ row }">
                                    <el-tag 
                                        :type="row.status === 2 ? 'success' : row.status === 1 ? 'warning' : 'info'"
                                        size="small"
                                    >
                                        {{ row.status_text }}
                                    </el-tag>
                                </template>
                            </el-table-column>
                            <el-table-column prop="create_time_text" label="创建时间" width="160" align="center" />
                            <el-table-column prop="update_time_text" label="更新时间" width="160" align="center" />
                        </el-table>
                    </div>
                </template>
            </div>
        </el-dialog>
    </div>
</template>

<script setup lang="ts">
import { provide, reactive } from 'vue'
import baTableClass from '/@/utils/baTable'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import { defaultOptButtons } from '/@/components/table'
import { baTableApi } from '/@/api/common'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'

defineOptions({
    name: 'collection/userCollection',
})

const { t } = useI18n()

// 自定义操作按钮
const optButtons = defaultOptButtons([])
optButtons.push(
    {
        render: 'tipButton',
        name: 'detail',
        title: '详情',
        text: '',
        type: 'primary',
        icon: 'fa fa-eye',
        class: 'table-row-info',
        disabledTip: false,
        click: (row: TableRow) => {
            handleViewDetail(row)
        },
    },
    {
        render: 'tipButton',
        name: 'toMining',
        title: '转矿机',
        text: '',
        type: 'danger',
        icon: 'fa fa-cog',
        class: 'table-row-edit',
        disabledTip: false,
        click: (row: TableRow) => {
            handleToMining(row)
        },
        display: (row: TableRow) => {
            return row.mining_status === 0 && row.consignment_status !== 2 && row.delivery_status === 0
        },
    },
    {
        render: 'tipButton',
        name: 'userStats',
        title: '用户统计',
        text: '',
        type: 'success',
        icon: 'fa fa-user',
        class: 'table-row-info',
        disabledTip: false,
        click: (row: TableRow) => {
            handleUserStats(row)
        },
    }
)

const baTable = new baTableClass(
    new baTableApi('/admin/collection.UserCollection/'),
    {
        pk: 'id',
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', operator: '=', operatorPlaceholder: 'ID', width: 70 },
            {
                label: '用户ID',
                prop: 'user_id',
                align: 'center',
                operator: '=',
                width: 80,
            },
            {
                label: '手机号',
                prop: 'mobile',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '手机号',
                width: 130,
            },
            {
                label: '昵称',
                prop: 'nickname',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '昵称',
                width: 120,
            },
            {
                label: '藏品ID',
                prop: 'item_id',
                align: 'center',
                operator: '=',
                width: 80,
            },
            {
                label: '藏品名称',
                prop: 'item_title',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '藏品名称',
                minWidth: 150,
            },
            {
                label: '购买价格',
                prop: 'buy_price',
                align: 'center',
                operator: false,
                width: 100,
                render: (row: any) => {
                    return `<span style="color: #f56c6c; font-weight: bold;">¥${Number(row.buy_price).toFixed(2)}</span>`
                },
            },
            {
                label: '当前价格',
                prop: 'current_price',
                align: 'center',
                operator: false,
                width: 100,
                render: (row: any) => {
                    return `¥${Number(row.current_price || 0).toFixed(2)}`
                },
            },
            {
                label: '增值',
                prop: 'appreciation',
                align: 'center',
                operator: false,
                width: 100,
                render: (row: any) => {
                    const appreciation = Number(row.appreciation || 0)
                    const color = appreciation >= 0 ? '#67c23a' : '#f56c6c'
                    return `<span style="color: ${color};">${appreciation >= 0 ? '+' : ''}${appreciation.toFixed(2)}</span>`
                },
            },
            {
                label: '寄售状态',
                prop: 'consignment_status_text',
                align: 'center',
                operator: 'select',
                operatorOptions: [
                    { label: '未寄售', value: 0 },
                    { label: '寄售中', value: 1 },
                    { label: '已售出', value: 2 },
                ],
                width: 100,
                render: 'tag',
                replaceValue: {
                    0: '未寄售',
                    1: '寄售中',
                    2: '已售出',
                },
                custom: {
                    0: 'info',
                    1: 'warning',
                    2: 'success',
                },
            },
            {
                label: '矿机状态',
                prop: 'mining_status_text',
                align: 'center',
                operator: 'select',
                operatorOptions: [
                    { label: '未转矿机', value: 0 },
                    { label: '矿机运行中', value: 1 },
                ],
                width: 100,
                render: 'tag',
                replaceValue: {
                    0: '未转为矿机',
                    1: '矿机运行中',
                },
                custom: {
                    0: 'info',
                    1: 'danger',
                },
            },
            {
                label: '所属专场',
                prop: 'session_title',
                align: 'center',
                width: 120,
            },
            {
                label: '确权编号',
                prop: 'asset_code',
                align: 'center',
                width: 160,
            },
            {
                label: '购买时间',
                prop: 'buy_time',
                align: 'center',
                render: 'datetime',
                operator: 'RANGE',
                width: 160,
            },
            {
                label: '创建时间',
                prop: 'create_time',
                align: 'center',
                render: 'datetime',
                operator: 'RANGE',
                width: 160,
            },
            {
                label: t('Operate'),
                prop: 'operate',
                align: 'center',
                width: 200,
                render: 'buttons',
                buttons: optButtons,
                operator: false,
            },
        ],
        defaultOrder: { 'id': 'desc' },
    },
    {
        defaultItems: {},
    }
)

provide('baTable', baTable)

// 用户统计弹窗
const statsDialog = reactive({
    visible: false,
    loading: false,
    data: null as any,
})

const handleUserStats = async (row?: TableRow) => {
    const userId = row?.user_id || 0
    const mobile = row?.mobile || ''
    
    if (!userId && !mobile) {
        ElMessage.warning('请先选择一条记录')
        return
    }
    
    statsDialog.visible = true
    statsDialog.loading = true
    statsDialog.data = null
    
    try {
        const api = baTable.api
        const params: any = {}
        if (mobile) params.mobile = mobile
        if (userId) params.user_id = userId
        
        const res = await api.postData('userStats', params)
        if (res.code === 1) {
            statsDialog.data = res.data
        } else {
            ElMessage.error(res.msg || '获取统计失败')
        }
    } catch (error: any) {
        ElMessage.error(error.message || '获取统计失败')
    } finally {
        statsDialog.loading = false
    }
}

// 详情弹窗
const detailDialog = reactive({
    visible: false,
    loading: false,
    data: null as any,
})

const handleViewDetail = async (row: TableRow) => {
    detailDialog.visible = true
    detailDialog.loading = true
    detailDialog.data = null
    
    try {
        const api = baTable.api
        const res = await api.postData('detail', { id: row.id })
        if (res.code === 1) {
            detailDialog.data = res.data
        } else {
            ElMessage.error(res.msg || '获取详情失败')
        }
    } catch (error: any) {
        ElMessage.error(error.message || '获取详情失败')
    } finally {
        detailDialog.loading = false
    }
}

// 转为矿机
const handleToMining = async (row: TableRow) => {
    try {
        await ElMessageBox.confirm(
            '确定要将此藏品转为矿机吗？转为矿机后将强制锁仓分红，且不可逆！',
            '提示',
            {
                confirmButtonText: '确定',
                cancelButtonText: '取消',
                type: 'warning',
            }
        )
        
        const api = baTable.api
        const res = await api.postData('toMining', { user_collection_id: row.id })
        if (res.code === 1) {
            ElMessage.success(res.msg || '操作成功')
            baTable.refresh()
        } else {
            ElMessage.error(res.msg || '操作失败')
        }
    } catch (error: any) {
        if (error !== 'cancel') {
            ElMessage.error(error.message || '操作失败')
        }
    }
}
</script>

<style scoped lang="scss">
</style>
