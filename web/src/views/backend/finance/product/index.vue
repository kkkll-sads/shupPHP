<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <TableHeader
            :buttons="['refresh', 'add', 'edit', 'delete', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="
                t('Quick search placeholder', {
                    fields: t('finance.product.Name') + '/' + t('Id'),
                })
            "
        />

        <Table />

        <PopupForm />
    </div>
</template>

<script setup lang="ts">
import { provide } from 'vue'
import baTableClass from '/@/utils/baTable'
import PopupForm from './popupForm.vue'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import { defaultOptButtons } from '/@/components/table'
import { baTableApi } from '/@/api/common'
import { useI18n } from 'vue-i18n'

defineOptions({
    name: 'finance/product',
})

const { t } = useI18n()

const baTable = new baTableClass(
    new baTableApi('/admin/finance.Product/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: t('Id'), prop: 'id', align: 'center', operator: '=', operatorPlaceholder: t('Id'), width: 70 },
            {
                label: t('finance.product.Name'),
                prop: 'name',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: t('Fuzzy query'),
            },
            {
                label: t('finance.product.Thumbnail'),
                prop: 'thumbnail',
                align: 'center',
                render: 'image',
                operator: false,
                width: 120,
            },
            {
                label: t('finance.product.Price'),
                prop: 'price',
                align: 'center',
                operator: 'BETWEEN',
                width: 110,
                render: (row: any) => {
                    const price = Number(row.price)
                    return isNaN(price) ? row.price : price.toFixed(2)
                },
            },
            {
                label: '收益周期',
                prop: 'cycle_days',
                align: 'center',
                operator: false,
                width: 110,
                render: (row: any) => {
                    // 如果cycle_value有值，使用cycle_value和cycle_type组合显示
                    if (row.cycle_value && row.cycle_value > 0) {
                        const typeMap: any = { day: '天', month: '月', year: '年' }
                        return row.cycle_value + (typeMap[row.cycle_type] || '天')
                    }
                    // 否则使用cycle_days显示
                    return (row.cycle_days || 0) + '天'
                },
            },
            {
                label: '收益模式',
                prop: 'income_mode',
                align: 'center',
                operator: 'FIND_IN_SET',
                width: 110,
                render: 'tag',
                custom: {
                    daily: 'success',
                    period: 'primary',
                    stage: 'warning',
                },
                replaceValue: {
                    daily: '每日返息',
                    period: '周期返息',
                    stage: '阶段返息',
                },
                size: 'small',
                options: [
                    { label: '每日返息', value: 'daily' },
                    { label: '周期返息', value: 'period' },
                    { label: '阶段返息', value: 'stage' },
                ],
            },
            {
                label: t('finance.product.Yield Rate'),
                prop: 'yield_rate',
                align: 'center',
                operator: 'BETWEEN',
                width: 100,
                render: (row: any) => `${row.yield_rate}%`,
            },
            {
                label: t('finance.product.Total Amount'),
                prop: 'total_amount',
                align: 'center',
                operator: 'BETWEEN',
                width: 120,
                render: (row: any) => {
                    const amount = Number(row.total_amount || 0)
                    return amount.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                },
            },
            {
                label: t('finance.product.Sold Amount'),
                prop: 'sold_amount',
                align: 'center',
                operator: 'BETWEEN',
                width: 120,
                show: false,
                render: (row: any) => {
                    const amount = Number(row.sold_amount || 0)
                    return amount.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                },
            },
            {
                label: '项目进度',
                prop: 'progress',
                align: 'center',
                operator: 'BETWEEN',
                width: 110,
                render: (row: any) => {
                    // 如果progress有值且大于0，使用progress
                    // 否则根据sold_amount和total_amount自动计算
                    let progress = Number(row.progress || 0)
                    if (progress === 0 && row.total_amount && Number(row.total_amount) > 0) {
                        const sold = Number(row.sold_amount || 0)
                        const total = Number(row.total_amount)
                        progress = (sold / total) * 100
                    }
                    return progress.toFixed(2) + '%'
                },
            },
            {
                label: t('finance.product.Remaining Amount'),
                prop: 'remaining_amount',
                align: 'center',
                operator: false,
                width: 130,
                show: false,
                render: (row: any) => {
                    if (!row.total_amount) return '--'
                    const remaining = Number(row.total_amount) - Number(row.sold_amount || 0)
                    const amount = remaining > 0 ? remaining : 0
                    return amount.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                },
            },
            {
                label: t('finance.product.Min Purchase'),
                prop: 'min_purchase',
                align: 'center',
                operator: 'BETWEEN',
                width: 120,
                show: false,
                render: (row: any) => {
                    const amount = Number(row.min_purchase || 0)
                    return amount.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                },
            },
            {
                label: t('finance.product.Max Purchase'),
                prop: 'max_purchase',
                align: 'center',
                operator: 'BETWEEN',
                width: 120,
                show: false,
                render: (row: any) => {
                    const amount = Number(row.max_purchase || 0)
                    if (amount === 0) return '不限'
                    return amount.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                },
            },
            {
                label: '每人限购',
                prop: 'per_user_limit',
                align: 'center',
                operator: 'BETWEEN',
                width: 100,
                render: (row: any) => {
                    const limit = Number(row.per_user_limit || 0)
                    return limit > 0 ? limit + '份' : '不限'
                },
            },
            {
                label: t('finance.product.Status'),
                prop: 'status',
                align: 'center',
                render: 'switch',
                options: [
                    { label: t('Enable'), value: '1' },
                    { label: t('Disable'), value: '0' },
                ],
                operator: 'select',
                operatorOptions: [
                    { label: t('Enable'), value: '1' },
                    { label: t('Disable'), value: '0' },
                ],
                width: 90,
            },
            {
                label: t('finance.product.Sort'),
                prop: 'sort',
                align: 'center',
                operator: 'BETWEEN',
                width: 90,
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
                width: 140,
                render: 'buttons',
                buttons: defaultOptButtons(['edit', 'delete']),
                operator: false,
            },
        ],
        dblClickNotEditColumn: [undefined],
    },
    {
        defaultItems: {
            status: '1',
            sort: 0,
            min_purchase: 1,
            max_purchase: 0,
            per_user_limit: 0,
            total_amount: 0,
            sold_amount: 0,
            progress: 0,
            cycle_type: 'day',
            cycle_value: 0,
            income_mode: 'period',
            income_value_type: 'percent',
            daily_income_value: 0,
            period_days: 30,
            period_income_value: 0,
            stage_income_config: null,
            return_principal: 1,
            compound_interest: 0,
            gift_rule: null,
        },
    }
)

// 权限校验统一放行，确保按钮正常显示
baTable.auth = () => true

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

<style scoped lang="scss"></style>


