<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <TableHeader
            :buttons="['refresh', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="t('Quick search placeholder', { fields: 'ID' })"
        />

        <Table />

        <PopupForm />
    </div>
</template>

<script setup lang="ts">
import { provide } from 'vue'
import { useI18n } from 'vue-i18n'
import baTableClass from '/@/utils/baTable'
import { baTableApi } from '/@/api/common'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import PopupForm from './popupForm.vue'
import { defaultOptButtons } from '/@/components/table'
import { ElMessageBox, ElMessage } from 'element-plus'

defineOptions({
    name: 'collection/tradeReservation',
})

const { t } = useI18n()

const baTable = new baTableClass(
    new baTableApi('/admin/collection.TradeReservation/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', operator: '=', operatorPlaceholder: 'ID', width: 70 },
            { label: '用户ID', prop: 'user_id', align: 'center', operator: '=', width: 100 },
            { label: '用户名', prop: 'user.username', align: 'center', operator: 'LIKE', render: 'tags' },
            { label: '手机号', prop: 'user.mobile', align: 'center', operator: 'LIKE' },
            { label: '专场', prop: 'session.title', align: 'center', operator: 'LIKE' },
            { label: '价格分区', prop: 'zone.name', align: 'center', operator: 'LIKE' },
            { label: '冻结金额', prop: 'freeze_amount', align: 'center', operator: 'BETWEEN' },
            { label: '消耗算力', prop: 'power_used', align: 'center', operator: 'BETWEEN' },
            { label: '权重', prop: 'weight', align: 'center', operator: 'BETWEEN' },
            { label: '状态', prop: 'status', align: 'center', render: 'tag', custom: { 0: 'info', 1: 'success', 2: 'danger', 3: 'warning' }, replaceValue: { 0: '待处理', 1: '已中签', 2: '未中签', 3: '已取消' } },
            { label: '创建时间', prop: 'create_time', align: 'center', render: 'datetime', sortable: 'custom', operator: 'RANGE', width: 160 },
            { label: '操作', prop: 'operate', align: 'center', width: 100, render: 'buttons', buttons: ['delete'] },
        ],
        dblClickNotEditColumn: ['all'],
    },
    {
        defaultItems: {
            status: 0,
        },
    }
)


const cancelButton = {
    render: 'tipButton',
    name: 'cancel',
    text: '取消预约',
    type: 'danger',
    icon: 'fa fa-ban',
    title: '取消预约并退款',
    display: (row: any) => row.status === 0,
    class: 'table-row-cancel',
    click: (row: any) => {
        ElMessageBox.confirm('确定要取消该预约吗？冻结资金将自动退回。', '提示', {
            confirmButtonText: '确定',
            cancelButtonText: '取消',
            type: 'warning',
        })
            .then(() => {
                baTable.api
                    .postData('cancel', { ids: [row.id] })
                    .then((res) => {
                        baTable.onTableHeaderAction('refresh', {})
                    })
            })
            .catch(() => {})
    },
}

const optButtons = defaultOptButtons(['delete'])
optButtons.push(cancelButton)

baTable.table.column[baTable.table.column.length - 1].buttons = optButtons

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

<style scoped lang="scss"></style>
