<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <TableHeader
            :buttons="['refresh', 'add', 'edit', 'delete', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="'支持按分类名称 / 编码 / ID 快速搜索'"
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

defineOptions({
    name: 'content/helpCategory',
})

const baTable = new baTableClass(
    new baTableApi('/admin/content.HelpCategory/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', operator: '=', operatorPlaceholder: 'ID', width: 70 },
            {
                label: '分类名称',
                prop: 'name',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '模糊搜索',
            },
            {
                label: '分类编码',
                prop: 'code',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '模糊搜索',
            },
            {
                label: '排序',
                prop: 'sort',
                align: 'center',
                width: 80,
            },
            {
                label: '状态',
                prop: 'status',
                align: 'center',
                render: 'tag',
                replaceValue: { 1: '启用', 0: '禁用' },
                custom: { 1: 'success', 0: 'info' },
                width: 90,
            },
            {
                label: '创建时间',
                prop: 'create_time',
                align: 'center',
                render: 'datetime',
                sortable: 'custom',
                operator: 'RANGE',
                width: 160,
            },
            {
                label: '操作',
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
            status: 1,
            sort: 0,
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


