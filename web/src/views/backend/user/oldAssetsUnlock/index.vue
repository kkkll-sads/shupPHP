<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <TableHeader
            :buttons="['refresh', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="t('Quick search placeholder', { fields: t('user.oldAssetsUnlock.quick Search Fields') })"
        />

        <Table />
    </div>
</template>

<script setup lang="ts">
import { provide } from 'vue'
import { useI18n } from 'vue-i18n'
import baTableClass from '/@/utils/baTable'
import { baTableApi } from '/@/api/common'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'

defineOptions({
    name: 'user/oldAssetsUnlock',
})

const { t } = useI18n()

const baTable = new baTableClass(
    new baTableApi('/admin/user.OldAssetsUnlock/'),
    {
        pk: 'id',
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', width: 70, operator: 'RANGE', sortable: 'custom' },
            { label: '用户ID', prop: 'user_id', align: 'center', operator: '=' },
            { label: '用户名', prop: 'user.username', align: 'center', operator: 'LIKE', operatorPlaceholder: '模糊查询' },
            { label: '呢称', prop: 'user.nickname', align: 'center', operator: 'LIKE', operatorPlaceholder: '模糊查询' },
            { label: '解锁次数', prop: 'unlock_count', align: 'center', operator: 'RANGE', sortable: 'custom' },
            { label: '消耗待激活金', prop: 'consumed_gold', align: 'center', operator: 'RANGE' },
            { label: '奖励权益包', prop: 'reward_equity_package', align: 'center', operator: 'RANGE' },
            { label: '奖励寄售券', prop: 'reward_consignment_coupon', align: 'center', operator: 'RANGE' },
            { label: '解锁时间', prop: 'unlock_time', align: 'center', render: 'datetime', operator: 'RANGE', sortable: 'custom', width: 160 },
            { label: '记录时间', prop: 'create_time', align: 'center', render: 'datetime', operator: 'RANGE', sortable: 'custom', width: 160 },
        ],
        dblClickNotEditColumn: ['all'],
    },
    {
        defaultItems: {},
    }
)

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

<style scoped lang="scss"></style>
