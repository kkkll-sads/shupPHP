<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <TableHeader
            :buttons="['refresh', 'add', 'edit', 'delete', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="
                t('Quick search placeholder', {
                    fields: t('collection.session.Title') + '/' + t('Id'),
                })
            "
        />

        <Table />

        <PopupForm />
    </div>
</template>

<script setup lang="ts">
import { provide, computed } from 'vue'
import baTableClass from '/@/utils/baTable'
import PopupForm from './popupForm.vue'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import { defaultOptButtons } from '/@/components/table'
import { baTableApi } from '/@/api/common'
import { useI18n } from 'vue-i18n'

defineOptions({
    name: 'collection/session',
})

const { t } = useI18n()

// 从数据库数据生成资产池配置
const getPoolConfig = (row: any) => {
    return {
        code: row.code || 'D-Asset',
        name: row.title || '资产池',
        subName: row.sub_name || '资产池',
        roi: row.roi || '+3.0%',
        quota: row.quota || '不限',
        themeClass: getThemeClass(row.start_time, row.end_time),
    }
}

const getThemeClass = (start: string, end: string) => {
    const startHour = Number((start || '00:00').split(':')[0])
    const endHour = Number((end || '00:00').split(':')[0])
    if (startHour >= 5 && endHour <= 12) return 'pool-card--blue'
    if (startHour >= 12 && endHour <= 18) return 'pool-card--orange'
    if (startHour >= 18 || endHour <= 5) return 'pool-card--green'
    return 'pool-card--purple'
}

const columns = [
            { type: 'selection', align: 'center', operator: false },
            { label: t('Id'), prop: 'id', align: 'center', operator: '=', operatorPlaceholder: t('Id'), width: 70 },
            {
                label: '专场标题',
                prop: 'title',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: t('Fuzzy query'),
            },
            {
                label: '专场图片',
                prop: 'image',
                align: 'center',
                render: 'image',
                operator: false,
                width: 120,
            },
            {
                label: '开始时间',
                prop: 'start_time',
                align: 'center',
                operator: false,
                width: 100,
            },
            {
                label: '结束时间',
                prop: 'end_time',
                align: 'center',
                operator: false,
                width: 100,
            },
            {
                label: '时间区间',
                prop: 'time_range',
                align: 'center',
                operator: false,
                width: 150,
                render: ((row: any) => {
                    return `${row.start_time} - ${row.end_time}`
                }) as any,
            },
            {
                label: '资产池',
                prop: 'pool',
                align: 'left',
                operator: false,
                render: ((row: any) => {
                    const pool = getPoolConfig(row)
                    return `${pool.name}｜${pool.roi}｜${pool.code}`
                }) as any,
                minWidth: 220,
            },
            {
                label: '状态',
                prop: 'status',
                align: 'center',
                render: 'switch',
                options: [
                    { label: t('Enable'), value: '1' },
                    { label: t('Disable'), value: '0' },
                ],
                operator: 'select' as any,
                operatorOptions: [
                    { label: t('Enable'), value: '1' },
                    { label: t('Disable'), value: '0' },
                ],
                width: 90,
            },
            {
                label: '排序',
                prop: 'sort',
                align: 'center',
                operator: 'BETWEEN' as any,
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
                prop: 'operate',
                align: 'center',
                width: 140,
                render: 'buttons',
                buttons: [],
                operator: false,
            },
        ] as any[]

const baTable = new baTableClass(
    new baTableApi('/admin/collection.Session/'),
    {
        column: columns as any,
    },
    {
        defaultItems: {
            status: '1',
            sort: 0,
            start_time: '00:00',
            end_time: '23:59',
            code: 'D-Asset',
            roi: '+3.0%',
            quota: '不限',
            sub_name: '资产池',
        },
    }
)

const optButtons = defaultOptButtons(['edit', 'delete'])
optButtons.forEach((btn) => {
    btn.display = () => true
})
baTable.table.column[baTable.table.column.length - 1].buttons = optButtons

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

<style scoped lang="scss">
.pool-card--blue {
    background: linear-gradient(120deg, #2563eb, #06b6d4);
    box-shadow: 0 10px 20px rgba(37, 99, 235, 0.15);
}
.pool-card--orange {
    background: linear-gradient(120deg, #f97316, #ef4444);
    box-shadow: 0 10px 20px rgba(249, 115, 22, 0.15);
}
.pool-card--green {
    background: linear-gradient(120deg, #059669, #14b8a6);
    box-shadow: 0 10px 20px rgba(5, 150, 105, 0.15);
}
.pool-card--purple {
    background: linear-gradient(120deg, #7c3aed, #ec4899);
    box-shadow: 0 10px 20px rgba(124, 58, 237, 0.15);
}
</style>

