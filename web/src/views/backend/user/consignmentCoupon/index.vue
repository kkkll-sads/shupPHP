<script setup lang="ts">
import { provide } from 'vue'
import { baTableApi } from '/@/api/common'
import TableHeader from '/@/components/table/header/index.vue'
import Table from '/@/components/table/index.vue'
import baTableClass from '/@/utils/baTable'
import { defaultOptButtons } from '/@/components/table'
import PopupForm from './popupForm.vue'

defineOptions({
    name: 'user/consignmentCoupon',
})

const baTable = new baTableClass(
    new baTableApi('/admin/user.ConsignmentCoupon/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', operator: '=', operatorPlaceholder: 'ID', width: 70 },
            { label: '用户ID', prop: 'user_id', align: 'center', operator: '=', width: 80 },
            { 
                label: '用户名', 
                prop: 'user.username', 
                align: 'center', 
                operator: 'LIKE', 
                operatorPlaceholder: '模糊查询' 
            },
            { 
                label: '用户昵称', 
                prop: 'user.nickname', 
                align: 'center', 
                operator: 'LIKE', 
                operatorPlaceholder: '模糊查询' 
            },
            { 
                label: '场次', 
                prop: 'session.title', 
                align: 'center', 
                operator: 'LIKE',
                operatorPlaceholder: '场次名称',
                width: 150,
            },
            { 
                label: '价格分区', 
                prop: 'zone.name', 
                align: 'center', 
                operator: 'LIKE',
                operatorPlaceholder: '分区名称',
                width: 120,
            },
            {
                label: '状态',
                prop: 'status',
                align: 'center',
                operator: 'select' as any,
                operatorOptions: [
                    { label: '可用', value: '1' },
                    { label: '已使用', value: '0' },
                ] as any,
                render: 'tag',
                custom: { '0': 'info', '1': 'success' },
                replaceValue: { '0': '已使用', '1': '可用' },
                width: 100,
            } as any,
            {
                label: '状态详情',
                prop: 'status_text',
                align: 'center',
                operator: false,
                render: 'tag',
                custom: { '已使用': 'info', '可用': 'success', '已过期': 'danger' },
                width: 100,
            },
            { 
                label: '过期时间', 
                prop: 'expire_time', 
                align: 'center', 
                sortable: 'custom', 
                operator: 'RANGE', 
                width: 160,
                render: 'datetime'
            },
            { 
                label: '创建时间', 
                prop: 'create_time', 
                align: 'center', 
                render: 'datetime', 
                sortable: 'custom', 
                operator: 'RANGE', 
                width: 160 
            },
            {
                label: '操作',
                align: 'center',
                width: 100,
                render: 'buttons',
                buttons: defaultOptButtons(['edit', 'delete']),
                operator: false,
            }
        ],
        dblClickNotEditColumn: ['all'],
    },
    {
        defaultItems: {
            status: 1,
            expire_time: 0,
        }
    }
)

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <!-- 表格顶部菜单 -->
        <TableHeader
            :buttons="['refresh', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="'快速搜索：用户名/昵称/手机号'"
        />

        <!-- 表格 -->
        <Table />
        
        <!-- 表单 -->
        <PopupForm />
    </div>
</template>

<style scoped lang="scss"></style>
