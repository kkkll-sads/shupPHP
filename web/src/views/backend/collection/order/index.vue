<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <TableHeader
            :buttons="['refresh', 'edit', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="
                t('Quick search placeholder', {
                    fields: '订单号/' + t('Id'),
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
import { ElMessageBox, ElMessage } from 'element-plus'

defineOptions({
    name: 'collection/order',
})

const { t } = useI18n()

const baTable = new baTableClass(
    new baTableApi('/admin/collection.Order/'),
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
                label: '用户ID',
                prop: 'user_id',
                align: 'center',
                operator: '=',
            },
            {
                label: '订单金额',
                prop: 'total_amount',
                align: 'center',
                operator: 'BETWEEN',
                render: (row: any) => {
                    const amount = Number(row.total_amount)
                    return isNaN(amount) ? row.total_amount : amount.toFixed(2) + '元'
                },
            },
            {
                label: '支付方式',
                prop: 'pay_type',
                align: 'center',
                render: 'tag',
                operator: 'select',
                operatorOptions: [
                    { label: '余额支付', value: 'money' },
                    { label: '积分兑换', value: 'score' },
                ],
                replaceValue: {
                    money: '余额支付',
                    score: '积分兑换',
                },
            },
            {
                label: '订单状态',
                prop: 'status',
                align: 'center',
                render: 'tag',
                operator: 'select',
                operatorOptions: [
                    { label: '待支付', value: 'pending' },
                    { label: '已支付', value: 'paid' },
                    { label: '已完成', value: 'completed' },
                    { label: '已取消', value: 'cancelled' },
                    { label: '已退款', value: 'refunded' },
                ],
                replaceValue: {
                    pending: '待支付',
                    paid: '已支付',
                    completed: '已完成',
                    cancelled: '已取消',
                    refunded: '已退款',
                },
                custom: {
                    pending: 'info',
                    paid: 'warning',
                    completed: 'success',
                    cancelled: 'info',
                    refunded: 'danger',
                },
            },
            {
                label: '支付时间',
                prop: 'pay_time',
                align: 'center',
                render: 'datetime',
                sortable: 'custom',
                operator: 'RANGE',
                width: 160,
            },
            {
                label: '完成时间',
                prop: 'complete_time',
                align: 'center',
                render: 'datetime',
                operator: 'RANGE',
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
                prop: 'operate',
                align: 'center',
                width: 200,
                render: 'buttons',
                buttons: [],
                operator: false,
            },
        ],
    },
    {
        defaultItems: {},
    }
)

// 自定义操作按钮
const completeButton = {
    render: 'tipButton',
    name: 'complete',
    text: '完成',
    type: 'success',
    icon: 'fa fa-check-circle',
    title: '完成订单',
    display: (row: any) => {
        // 已支付的订单可以完成
        return row.status === 'paid'
    },
    class: 'table-row-complete',
    click: (row: any) => {
        ElMessageBox.confirm('确认该订单已完成？', '提示', {
            confirmButtonText: '确定',
            cancelButtonText: '取消',
            type: 'info',
        })
            .then(() => {
                baTable.api
                    .postData('complete', { id: row.id })
                    .then(() => {
                        ElMessage.success('订单已完成')
                        baTable.onTableHeaderAction('refresh', {})
                    })
                    .catch((err: any) => {
                        ElMessage.error(err.msg || '操作失败')
                    })
            })
            .catch(() => {})
    },
}

const cancelButton = {
    render: 'tipButton',
    name: 'cancel',
    text: '取消订单',
    type: 'danger',
    icon: 'fa fa-ban',
    title: '取消订单',
    display: (row: any) => ['pending', 'paid'].includes(row.status),
    class: 'table-row-cancel',
    click: (row: any) => {
        ElMessageBox.confirm('确定要取消该订单吗？如已支付将自动退款并恢复库存。', '提示', {
            confirmButtonText: '确定',
            cancelButtonText: '取消',
            type: 'warning',
        })
            .then(() => {
                baTable.api
                    .postData('cancel', { id: row.id })
                    .then(() => {
                        ElMessage.success('取消订单成功')
                        baTable.onTableHeaderAction('refresh', {})
                    })
                    .catch((err: any) => {
                        ElMessage.error(err.msg || '取消订单失败')
                    })
            })
            .catch(() => {})
    },
}

const optButtons = defaultOptButtons(['edit'])
optButtons.push(completeButton)
optButtons.push(cancelButton)

optButtons.forEach((btn) => {
    if (btn.name === 'edit') {
        btn.display = () => true
    }
})
baTable.table.column[baTable.table.column.length - 1].buttons = optButtons

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

<style scoped lang="scss"></style>

