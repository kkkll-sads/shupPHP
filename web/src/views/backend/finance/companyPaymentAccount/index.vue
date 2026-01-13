<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <TableHeader
            :buttons="['refresh', 'add', 'edit', 'delete', 'comSearch', 'quickSearch', 'columnDisplay']"
            quick-search-placeholder="快速搜索：账户名/账号/银行名称"
        />

        <Table />

        <!-- 表单 -->
        <PopupForm />
    </div>
</template>

<script setup lang="ts">
import { provide, h } from 'vue'
import baTableClass from '/@/utils/baTable'
import PopupForm from './popupForm.vue'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import { baTableApi } from '/@/api/common'
import { defaultOptButtons } from '/@/components/table'

defineOptions({
    name: 'finance/companyPaymentAccount',
})

const baTable = new baTableClass(
    new baTableApi('/admin/finance.CompanyPaymentAccount/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', operator: '=', operatorPlaceholder: 'ID', width: 70 },
            {
                label: '图标',
                prop: 'icon',
                align: 'center',
                operator: false,
                width: 60,
                render: 'image'
            },
            {
                label: '账户类型',
                prop: 'type_text',
                align: 'center',
                operator: 'select' as any,
                operatorOptions: [
                    { label: '银行卡', value: 'bank_card' },
                    { label: '支付宝', value: 'alipay' },
                    { label: '微信', value: 'wechat' },
                    { label: 'USDT', value: 'usdt' },
                ] as any,
                width: 120,
            },
            {
                label: '账户名',
                prop: 'account_name',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '模糊查询',
            },
            {
                label: '账号/卡号',
                prop: 'account_number',
                align: 'center',
            },
            {
                label: '银行名称',
                prop: 'bank_name',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '模糊查询',
                show: false,
            },
            {
                label: '开户行/网络',
                prop: 'bank_branch',
                align: 'center',
                show: false,
            },
            {
                label: '状态',
                prop: 'status',
                align: 'center',
                operator: 'select' as any,
                operatorOptions: [
                    { label: '充值可用', value: '1' },
                    { label: '提现可用', value: '2' },
                    { label: '充值提现可用', value: '3' },
                    { label: '关闭', value: '0' },
                ] as any,
                render: 'tag',
                custom: { '0': 'danger', '1': 'success', '2': 'warning', '3': 'primary' },
                replaceValue: { '0': '关闭', '1': '充值可用', '2': '提现可用', '3': '充值提现可用' },
                width: 80,
            },
            {
                label: '排序',
                prop: 'sort',
                align: 'center',
                width: 80,
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
                align: 'center',
                width: '100',
                render: 'buttons',
                buttons: defaultOptButtons(['edit', 'delete']),
                operator: false,
            },
        ],
        dblClickNotEditColumn: [undefined],
    }
)

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

<style scoped lang="scss"></style>

