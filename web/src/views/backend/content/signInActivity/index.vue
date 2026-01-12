<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <!-- 表格顶部菜单 -->
        <TableHeader
            :buttons="['refresh', 'add', 'edit', 'delete', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="'快速搜索：活动名称/ID'"
        />

        <!-- 表格 -->
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
    name: 'content/signInActivity',
})

const baTable = new baTableClass(
    new baTableApi('/admin/content.SignInActivity/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', operator: '=', operatorPlaceholder: 'ID', width: 70 },
            {
                label: '活动名称',
                prop: 'name',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '活动名称',
                minWidth: 200,
            },
            {
                label: '开始时间',
                prop: 'start_time',
                align: 'center',
                operator: 'RANGE' as any,
                operatorPlaceholder: '开始时间',
                width: 160,
                render: 'datetime' as any,
            },
            {
                label: '结束时间',
                prop: 'end_time',
                align: 'center',
                operator: 'RANGE' as any,
                operatorPlaceholder: '结束时间',
                width: 160,
                render: 'datetime' as any,
            },
            {
                label: '资金来源',
                prop: 'fund_source',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '资金来源',
                minWidth: 150,
            },
            {
                label: '注册奖励',
                prop: 'register_reward',
                align: 'center',
                operator: '=',
                operatorPlaceholder: '注册奖励',
                width: 100,
                render: ((row: any) => {
                    return row.register_reward ? `¥${parseFloat(row.register_reward).toFixed(2)}` : '-'
                }) as any,
            },
            {
                label: '签到奖励范围',
                prop: 'sign_reward_range',
                align: 'center',
                operator: false,
                width: 150,
                render: ((row: any) => {
                    const min = parseFloat(row.sign_reward_min || 0)
                    const max = parseFloat(row.sign_reward_max || 0)
                    if (min > 0 || max > 0) {
                        return `¥${min.toFixed(2)} - ¥${max.toFixed(2)}`
                    }
                    return '-'
                }) as any,
            },
            {
                label: '邀请奖励范围',
                prop: 'invite_reward_range',
                align: 'center',
                operator: false,
                width: 150,
                render: ((row: any) => {
                    const min = parseFloat(row.invite_reward_min || 0)
                    const max = parseFloat(row.invite_reward_max || 0)
                    if (min > 0 || max > 0) {
                        return `¥${min.toFixed(2)} - ¥${max.toFixed(2)}`
                    }
                    return '-'
                }) as any,
            },
            {
                label: '提现门槛',
                prop: 'withdraw_min_amount',
                align: 'center',
                operator: '=',
                operatorPlaceholder: '提现门槛',
                width: 100,
                render: ((row: any) => {
                    return row.withdraw_min_amount ? `¥${parseFloat(row.withdraw_min_amount).toFixed(2)}` : '-'
                }) as any,
            },
            {
                label: '每日限提',
                prop: 'withdraw_daily_limit',
                align: 'center',
                operator: '=',
                operatorPlaceholder: '每日限提',
                width: 100,
                render: ((row: any) => {
                    const limit = parseInt(row.withdraw_daily_limit || 0)
                    return limit > 0 ? `${limit}次/天` : '不限制'
                }) as any,
            },
            {
                label: '审核时间',
                prop: 'withdraw_audit_hours',
                align: 'center',
                operator: '=',
                operatorPlaceholder: '审核时间',
                width: 100,
                render: ((row: any) => {
                    const hours = parseInt(row.withdraw_audit_hours || 0)
                    return hours > 0 ? `${hours}小时` : '-'
                }) as any,
            },
            {
                label: '状态',
                prop: 'status',
                align: 'center',
                operator: 'select' as any,
                operatorPlaceholder: '状态',
                render: 'switch',
                width: 100,
            } as any,
            {
                label: '创建时间',
                prop: 'create_time',
                align: 'center',
                operator: 'RANGE' as any,
                operatorPlaceholder: '创建时间',
                width: 160,
                render: 'datetime' as any,
                sortable: 'custom',
            },
            {
                label: '更新时间',
                prop: 'update_time',
                align: 'center',
                operator: 'RANGE' as any,
                operatorPlaceholder: '更新时间',
                width: 160,
                render: 'datetime' as any,
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
            title: '签到活动',
            labelWidth: '120px',
        },
    } as any
)

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

