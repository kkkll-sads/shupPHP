<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <!-- 统计卡片 -->
        <el-row :gutter="20" class="statistics-row" v-if="statistics">
            <el-col :span="6">
                <el-card shadow="hover">
                    <div class="stat-item">
                        <div class="stat-label">订单总数</div>
                        <div class="stat-value">{{ statistics.total_summary.total_orders || 0 }}</div>
                    </div>
                </el-card>
            </el-col>
            <el-col :span="6">
                <el-card shadow="hover">
                    <div class="stat-item">
                        <div class="stat-label">订单总额</div>
                        <div class="stat-value primary">¥{{ (statistics.total_summary.total_amount || 0).toFixed(2) }}</div>
                    </div>
                </el-card>
            </el-col>
            <el-col :span="6">
                <el-card shadow="hover">
                    <div class="stat-item">
                        <div class="stat-label">收益中金额</div>
                        <div class="stat-value success">¥{{ (statistics.total_summary.earning_amount || 0).toFixed(2) }}</div>
                    </div>
                </el-card>
            </el-col>
            <el-col :span="6">
                <el-card shadow="hover">
                    <div class="stat-item">
                        <div class="stat-label">已完成金额</div>
                        <div class="stat-value info">¥{{ (statistics.total_summary.completed_amount || 0).toFixed(2) }}</div>
                    </div>
                </el-card>
            </el-col>
        </el-row>

        <TableHeader
            :buttons="['refresh', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="
                t('Quick search placeholder', {
                    fields: t('finance.order.Order No') + '/' + t('Id'),
                })
            "
        />

        <Table />

        <PopupForm />
    </div>
</template>

<script setup lang="ts">
import { ref, provide } from 'vue'
import baTableClass from '/@/utils/baTable'
import PopupForm from './popupForm.vue'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import { defaultOptButtons } from '/@/components/table'
import { baTableApi } from '/@/api/common'
import { useI18n } from 'vue-i18n'
import { ElMessage, ElMessageBox } from 'element-plus'

defineOptions({
    name: 'finance/order',
})

const { t } = useI18n()
const statistics = ref<any>(null)

// 自定义操作按钮
const optButtons: any[] = [
    {
        render: 'tipButton',
        name: 'info',
        title: '查看详情',
        text: '详情',
        type: 'primary',
        icon: 'fa fa-eye',
        class: 'table-row-detail',
        disabledTip: false,
        display: () => true,
        click: (row: any) => {
            baTable.toggleForm('Edit', [row.id])
        },
    },
    {
        render: 'tipButton',
        name: 'settle',
        title: '手动结算',
        text: '结算',
        type: 'success',
        icon: 'fa fa-check-circle',
        class: 'table-row-settle',
        disabledTip: false,
        display: (row: any) => row.status === 'earning',
        click: (row: any) => {
            ElMessageBox.confirm('确认要手动结算此订单吗？将立即返还本金和收益给用户。', '提示', {
                confirmButtonText: '确定',
                cancelButtonText: '取消',
                type: 'warning',
            })
                .then(() => {
                    baTable.api
                        .postData('settle', { id: row.id })
                        .then((res: any) => {
                            ElMessage.success(res.msg || '结算成功')
                            baTable.onTableHeaderAction('refresh', {})
                        })
                        .catch((err: any) => {
                            ElMessage.error(err.msg || '结算失败')
                        })
                })
                .catch(() => {})
        },
    },
    {
        render: 'tipButton',
        name: 'cancel',
        title: '取消订单',
        text: '取消',
        type: 'danger',
        icon: 'fa fa-times',
        class: 'table-row-cancel',
        disabledTip: false,
        display: (row: any) => ['pending', 'paying', 'earning'].includes(row.status),
        click: (row: any) => {
            const tips = row.status === 'earning' ? '取消订单将退还本金给用户，确认继续吗？' : '确认要取消此订单吗？'
            ElMessageBox.confirm(tips, '提示', {
                confirmButtonText: '确定',
                cancelButtonText: '取消',
                type: 'warning',
            })
                .then(() => {
                    baTable.api
                        .postData('cancel', { id: row.id })
                        .then((res: any) => {
                            ElMessage.success(res.msg || '取消成功')
                            baTable.onTableHeaderAction('refresh', {})
                        })
                        .catch((err: any) => {
                            ElMessage.error(err.msg || '取消失败')
                        })
                })
                .catch(() => {})
        },
    },
]

const baTable = new baTableClass(
    new baTableApi('/admin/finance.Order/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: t('Id'), prop: 'id', align: 'center', operator: '=', operatorPlaceholder: t('Id'), width: 70 },
            {
                label: '订单号',
                prop: 'order_no',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: t('Fuzzy query'),
            },
            {
                label: '用户',
                prop: 'user_nickname',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '用户昵称',
                render: (row: any) => {
                    return row.user_nickname || '--'
                },
            },
            {
                label: '产品名称',
                prop: 'product_name',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: t('Fuzzy query'),
            },
            {
                label: '购买金额',
                prop: 'amount',
                align: 'center',
                operator: 'BETWEEN',
                render: (row: any) => {
                    const amount = Number(row.amount)
                    return isNaN(amount) ? row.amount : '¥' + amount.toFixed(2)
                },
            },
            {
                label: '收益率',
                prop: 'yield_rate',
                align: 'center',
                operator: 'BETWEEN',
                width: 90,
                render: (row: any) => `${row.yield_rate}%`,
            },
            {
                label: '预期收益',
                prop: 'expected_income',
                align: 'center',
                operator: false,
                render: (row: any) => {
                    const income = Number(row.expected_income || 0)
                    return '¥' + income.toFixed(2)
                },
            },
            {
                label: '支付方式',
                prop: 'payment_channel',
                align: 'center',
                operator: 'select',
                operatorOptions: [
                    { label: '余额支付', value: 'balance' },
                    { label: '支付宝', value: 'alipay' },
                    { label: '微信支付', value: 'wechat' },
                    { label: '银联支付', value: 'union' },
                ],
                width: 100,
                render: (row: any) => row.payment_channel_text || '--',
            },
            {
                label: '订单状态',
                prop: 'status',
                align: 'center',
                render: 'tag',
                operator: 'select',
                operatorOptions: [
                    { label: '待支付', value: 'pending' },
                    { label: '支付中', value: 'paying' },
                    { label: '支付成功', value: 'paid' },
                    { label: '收益中', value: 'earning' },
                    { label: '已完成', value: 'completed' },
                    { label: '已取消', value: 'cancelled' },
                    { label: '已退款', value: 'refunded' },
                ],
                width: 100,
                custom: {
                    pending: 'info',
                    paying: 'warning',
                    paid: 'primary',
                    earning: 'success',
                    completed: '',
                    cancelled: 'danger',
                    refunded: 'warning',
                },
                replaceValue: {
                    pending: '待支付',
                    paying: '支付中',
                    paid: '支付成功',
                    earning: '收益中',
                    completed: '已完成',
                    cancelled: '已取消',
                    refunded: '已退款',
                },
            },
            {
                label: '到期时间',
                prop: 'expire_time_text',
                align: 'center',
                operator: false,
                width: 160,
            },
            {
                label: '支付时间',
                prop: 'pay_time',
                align: 'center',
                render: 'datetime',
                sortable: 'custom',
                operator: 'RANGE',
                width: 160,
                show: false,
            },
            {
                label: t('Create time'),
                prop: 'create_time',
                align: 'center',
                render: 'datetime',
                sortable: 'custom',
                operator: 'RANGE',
                width: 160,
            },
            {
                label: t('Operate'),
                align: 'center',
                width: 200,
                render: 'buttons',
                buttons: optButtons,
                operator: false,
            },
        ],
    },
    {
        defaultItems: {},
    }
)

// 重写getData方法，获取统计信息
const originalGetData = baTable.getData.bind(baTable)
baTable.getData = async () => {
    await originalGetData()
    // 从响应中获取统计信息
    if (baTable.table.extend && baTable.table.extend.statistics) {
        statistics.value = baTable.table.extend.statistics
    }
}

// 监听数据加载完成
baTable.onTableDone = (res: any) => {
    if (res && res.data && res.data.statistics) {
        statistics.value = res.data.statistics
    }
}

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

<style scoped lang="scss">
.statistics-row {
    margin-bottom: 20px;
}

.stat-item {
    text-align: center;
    
    .stat-label {
        font-size: 14px;
        color: #909399;
        margin-bottom: 10px;
    }
    
    .stat-value {
        font-size: 24px;
        font-weight: bold;
        color: #303133;
        
        &.primary {
            color: #409EFF;
        }
        
        &.success {
            color: #67C23A;
        }
        
        &.info {
            color: #909399;
        }
    }
}
</style>

