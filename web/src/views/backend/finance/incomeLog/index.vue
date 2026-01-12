<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <!-- 统计卡片 -->
        <el-row :gutter="20" class="statistics-row" v-if="statistics">
            <el-col :span="6">
                <el-card shadow="hover">
                    <div class="stat-item">
                        <div class="stat-label">今日返息总额</div>
                        <div class="stat-value primary">¥{{ (statistics.today_total || 0).toFixed(2) }}</div>
                    </div>
                </el-card>
            </el-col>
            <el-col :span="6">
                <el-card shadow="hover">
                    <div class="stat-item">
                        <div class="stat-label">今日返息笔数</div>
                        <div class="stat-value success">{{ statistics.today_count || 0 }}</div>
                    </div>
                </el-card>
            </el-col>
            <el-col :span="6">
                <el-card shadow="hover">
                    <div class="stat-item">
                        <div class="stat-label">累计返息总额</div>
                        <div class="stat-value info">¥{{ (statistics.total_amount || 0).toFixed(2) }}</div>
                    </div>
                </el-card>
            </el-col>
            <el-col :span="6">
                <el-card shadow="hover">
                    <div class="stat-item">
                        <div class="stat-label">累计返息笔数</div>
                        <div class="stat-value">{{ statistics.total_count || 0 }}</div>
                    </div>
                </el-card>
            </el-col>
        </el-row>

        <TableHeader
            :buttons="['refresh', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="
                t('Quick search placeholder', {
                    fields: t('Id') + '/订单ID',
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
import { baTableApi } from '/@/api/common'
import { useI18n } from 'vue-i18n'

defineOptions({
    name: 'finance/incomeLog',
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
]

const baTable = new baTableClass(
    new baTableApi('/admin/finance.IncomeLog/'),
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
                label: '返息类型',
                prop: 'income_type',
                align: 'center',
                operator: 'select',
                operatorOptions: [
                    { label: '每日返息', value: 'daily' },
                    { label: '周期返息', value: 'period' },
                    { label: '阶段返息', value: 'stage' },
                    { label: '本金返还', value: 'principal' },
                    { label: '复利返息', value: 'compound' },
                ],
                width: 100,
                render: (row: any) => row.income_type_text || '--',
            },
            {
                label: '返息金额',
                prop: 'income_amount',
                align: 'center',
                operator: 'BETWEEN',
                render: (row: any) => {
                    const amount = Number(row.income_amount)
                    return isNaN(amount) ? row.income_amount : '¥' + amount.toFixed(2)
                },
            },
            {
                label: '返息日期',
                prop: 'income_date',
                align: 'center',
                operator: 'RANGE',
                width: 120,
            },
            {
                label: '周期/阶段',
                prop: 'period_number',
                align: 'center',
                operator: false,
                width: 100,
                render: (row: any) => {
                    if (row.period_number) {
                        return `第${row.period_number}周期`
                    }
                    if (row.stage_info) {
                        return row.stage_info + '天'
                    }
                    return '--'
                },
            },
            {
                label: '状态',
                prop: 'status',
                align: 'center',
                render: 'tag',
                operator: 'select',
                operatorOptions: [
                    { label: '已发放', value: '1' },
                    { label: '未发放', value: '0' },
                ],
                width: 100,
                custom: {
                    '1': 'success',
                    '0': 'danger',
                },
                replaceValue: {
                    '1': '已发放',
                    '0': '未发放',
                },
            },
            {
                label: '发放时间',
                prop: 'settle_time_text',
                align: 'center',
                operator: false,
                width: 160,
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
                width: 120,
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

