<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <!-- 表格顶部菜单 -->
        <TableHeader
            :buttons="['refresh', 'add', 'edit', 'delete', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="'快速搜索：标题/ID'"
        />

        <!-- 表格 -->
        <!-- 要使用`el-table`组件原有的属性，直接加在Table标签上即可 -->
        <Table />

        <!-- 表单 -->
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
    name: 'content/announcement',
})

const baTable = new baTableClass(
    new baTableApi('/admin/content.Announcement/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', operator: '=', operatorPlaceholder: 'ID', width: 70 },
            {
                label: '标题',
                prop: 'title',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '标题',
            },
            {
                label: '类型',
                prop: 'type',
                align: 'center',
                operator: 'select',
                operatorPlaceholder: '类型',
                render: 'tag',
                replaceValue: { 'normal': '平台公告', 'important': '平台动态' },
                custom: { 'normal': 'info', 'important': 'danger' },
                operatorOptions: [
                    { label: '平台公告', value: 'normal' },
                    { label: '平台动态', value: 'important' },
                ],
                width: 100,
            },
            {
                label: '状态',
                prop: 'status',
                align: 'center',
                operator: 'select',
                operatorPlaceholder: '状态',
                render: 'switch',
                options: [
                    { label: '禁用', value: '0' },
                    { label: '启用', value: '1' },
                ],
            },
            {
                label: '是否弹窗',
                prop: 'is_popup',
                align: 'center',
                operator: 'select',
                operatorPlaceholder: '是否弹窗',
                render: 'tag',
                options: [
                    { label: '否', value: '0' },
                    { label: '是', value: '1' },
                ],
                tagType: (row: any) => {
                    return row.is_popup === '1' ? 'success' : 'info'
                },
            },
            {
                label: '弹窗延迟',
                prop: 'popup_delay',
                align: 'center',
                operator: 'between',
                operatorPlaceholder: '弹窗延迟',
                width: 120,
                render: (row: any) => {
                    return row.popup_delay ? `${row.popup_delay}ms` : '-'
                },
            },
            {
                label: '排序',
                prop: 'sort',
                align: 'center',
                operator: 'between',
                operatorPlaceholder: '排序',
                width: 80,
                sortable: 'custom',
            },
            {
                label: '开始时间',
                prop: 'start_time',
                align: 'center',
                operator: 'datetime',
                operatorPlaceholder: '开始时间',
                width: 160,
                render: 'datetime',
            },
            {
                label: '结束时间',
                prop: 'end_time',
                align: 'center',
                operator: 'datetime',
                operatorPlaceholder: '结束时间',
                width: 160,
                render: 'datetime',
            },
            {
                label: '浏览次数',
                prop: 'view_count',
                align: 'center',
                operator: '=',
                operatorPlaceholder: '浏览次数',
                width: 100,
            },
            {
                label: '创建时间',
                prop: 'createtime',
                align: 'center',
                operator: 'datetime',
                operatorPlaceholder: '创建时间',
                width: 160,
                render: 'datetime',
                sortable: 'custom',
            },
            {
                label: '更新时间',
                prop: 'updatetime',
                align: 'center',
                operator: 'datetime',
                operatorPlaceholder: '更新时间',
                width: 160,
                render: 'datetime',
                sortable: 'custom',
            },
            {
                label: '操作',
                prop: 'operate',
                align: 'center',
                width: 120,
                render: 'buttons',
                buttons: defaultOptButtons(['edit', 'delete']),
                operator: false,
            },
        ],
        form: {
            title: '标题',
            labelWidth: '100px',
        },
    }
)

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>
