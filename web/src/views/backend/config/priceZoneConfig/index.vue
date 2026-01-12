<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <TableHeader
            :buttons="['refresh', 'add', 'edit', 'delete', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="
                t('Quick search placeholder', {
                    fields: '分区名称/' + t('Id'),
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
    name: 'config/priceZoneConfig',
})

const { t } = useI18n()

const baTable = new baTableClass(
    new baTableApi('/admin/PriceZoneConfig/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: t('Id'), prop: 'id', align: 'center', operator: '=', operatorPlaceholder: t('Id'), width: 70 },
            {
                label: '分区名称',
                prop: 'name',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: t('Fuzzy query'),
            },
            {
                label: '最低价格',
                prop: 'min_price',
                align: 'center',
                operator: false,
                width: 120,
                render: (row: any) => {
                    return '¥' + parseFloat(row.min_price).toFixed(2)
                },
            },
            {
                label: '最高价格',
                prop: 'max_price',
                align: 'center',
                operator: false,
                width: 120,
                render: (row: any) => {
                    return row.max_price ? '¥' + parseFloat(row.max_price).toFixed(2) : '无上限'
                },
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
                operator: 'select',
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
                prop: 'operate',
                align: 'center',
                width: 140,
                render: 'buttons',
                buttons: [],
                operator: false,
            },
        ],
    },
    {
        defaultItems: {
            status: '1',
            sort: 0,
            min_price: 0,
            max_price: null,
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

<style scoped lang="scss"></style>

