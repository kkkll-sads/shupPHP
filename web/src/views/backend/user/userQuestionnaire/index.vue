<script setup lang="ts">
import { provide } from 'vue'
import { baTableApi } from '/@/api/common'
import TableHeader from '/@/components/table/header/index.vue'
import Table from '/@/components/table/index.vue'
import baTableClass from '/@/utils/baTable'
import { defaultOptButtons } from '/@/components/table'
import PopupForm from './popupForm.vue'

defineOptions({
    name: 'user/userQuestionnaire',
})

const baTable = new baTableClass(
    new baTableApi('/admin/user.UserQuestionnaire/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', operator: '=', width: 70 },
            { label: '用户ID', prop: 'user_id', align: 'center', operator: '=', width: 80 },
            { 
                label: '用户名', 
                prop: 'user.username', 
                align: 'center', 
                operator: 'LIKE',
            },
            { 
                label: '手机号', 
                prop: 'user.mobile', 
                align: 'center', 
                operator: 'LIKE',
            },
            { label: '图片', prop: 'images', align: 'center', render: 'images', width: 100 },
            { label: '标题', prop: 'title', align: 'center', operator: 'LIKE' },
            { 
                label: '状态', 
                prop: 'status', 
                align: 'center', 
                render: 'tag',
                custom: { '0': 'warning', '1': 'success', '2': 'danger' },
                replaceValue: { '0': '待审核', '1': '已采纳', '2': '已拒绝' },
                operator: 'select' as any,
                operatorOptions: [
                    { label: '待审核', value: '0' },
                    { label: '已采纳', value: '1' },
                    { label: '已拒绝', value: '2' },
                ] as any,
                width: 100,
            } as any,
            { label: '奖励算力', prop: 'reward_power', align: 'center', width: 100 },
            { label: '创建时间', prop: 'create_time', align: 'center', render: 'datetime', sortable: 'custom', operator: 'RANGE', width: 160 },
            {
                label: '操作',
                align: 'center',
                width: 160,
                render: 'buttons',
                buttons: defaultOptButtons(['edit']),
                operator: false,
            }
        ],
        dblClickNotEditColumn: ['all'],
    },
    {
        defaultItems: {
            status: 0,
            reward_power: 0,
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

        <TableHeader
            :buttons="['refresh', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="'搜索：用户名/手机号/标题'"
        />

        <Table />
        
        <PopupForm />
    </div>
</template>

<style scoped lang="scss"></style>
