<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <TableHeader
            :buttons="['refresh', 'add', 'edit', 'delete', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="'资产包名称搜索'"
        />

        <Table />

        <PopupForm :sessions="sessions" :zones="zones" />
    </div>
</template>

<script setup lang="ts">
import { provide, ref, onMounted } from 'vue'
import baTableClass from '/@/utils/baTable'
import PopupForm from './popupForm.vue'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import { defaultOptButtons } from '/@/components/table'
import { baTableApi } from '/@/api/common'
import { useI18n } from 'vue-i18n'

defineOptions({
    name: 'collection/assetPackage',
})

const { t } = useI18n()

const sessions = ref<any[]>([])
const zones = ref<any[]>([])

const columns = [
    { type: 'selection', align: 'center', operator: false },
    { label: 'ID', prop: 'id', align: 'center', operator: '=', width: 70 },
    {
        label: '资产包名称',
        prop: 'name',
        align: 'center',
        operator: 'LIKE',
        operatorPlaceholder: '模糊搜索',
        minWidth: 150,
    },
    {
        label: '关联场次',
        prop: 'session_name',
        align: 'center',
        operator: false,
        width: 120,
    },
    {
        label: '关联分区',
        prop: 'zone_name',
        align: 'center',
        operator: false,
        width: 100,
    },
    {
        label: '价格范围',
        prop: 'price_range',
        align: 'center',
        operator: false,
        width: 150,
        render: (row: any) => {
            if (row.min_price || row.max_price) {
                return `${row.min_price || 0} - ${row.max_price || '∞'}`
            }
            return '-'
        },
    },
    {
        label: '商品数',
        prop: 'total_count',
        align: 'center',
        operator: false,
        width: 80,
    },
    {
        label: '已生成',
        prop: 'generated_count',
        align: 'center',
        operator: false,
        width: 80,
    },
    {
        label: '设定数量',
        prop: 'item_count',
        align: 'center',
        operator: false,
        width: 80,
    },
    {
        label: '已售',
        prop: 'sold_count',
        align: 'center',
        operator: false,
        width: 80,
    },
    {
        label: '默认包',
        prop: 'is_default',
        align: 'center',
        render: 'tag',
        replaceValue: { 0: '否', 1: '是' },
        custom: { 0: 'info', 1: 'success' },
        width: 80,
    },
    {
        label: '状态',
        prop: 'status',
        align: 'center',
        render: 'switch',
        replaceValue: { 0: '禁用', 1: '启用' },
        width: 80,
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
        width: 160,
        render: 'buttons',
        buttons: [],
        operator: false,
    },
] as any[]

const baTable = new baTableClass(
    new baTableApi('/admin/collection.AssetPackage/'),
    {
        column: columns as any,
    },
    {
        defaultItems: {
            status: 1,
            is_default: 0,
            zone_id: 0,
            item_count: 0,
            min_price: 350,
        },
    }
)

// 设为默认按钮
const setDefaultBtn = {
    name: 'setDefault',
    render: 'tipButton',
    title: '设为默认',
    text: '',
    type: 'warning',
    icon: 'fa fa-star',
    class: 'table-row-setDefault',
    disabledTip: false,
    display: (row: any) => row.is_default != 1,
    click: (row: any, field: any) => {
        baTable.api.custom({
            url: '/admin/collection.AssetPackage/setDefault',
            data: { id: row.id },
        }).then(() => {
            baTable.onTableAction('refresh', {})
        })
    },
}

// 自定义删除按钮，增加二次确认
const customDeleteBtn = {
    render: 'confirmButton',
    name: 'delete',
    title: '删除资产包',
    text: '',
    type: 'danger',
    icon: 'fa fa-trash',
    class: 'table-row-delete',
    popconfirm: {
        confirmButtonText: '确认删除',
        cancelButtonText: '取消',
        confirmButtonType: 'danger',
        title: '确定要删除选中的资产包吗？\n\n⚠️ 警告：此操作将同时删除该资产包下的所有藏品、用户藏品记录和寄售记录！',
    },
    disabledTip: false,
}

const optButtons = [...defaultOptButtons(['edit']), customDeleteBtn, setDefaultBtn]
optButtons.forEach((btn) => {
    btn.display = btn.display || (() => true)
})
baTable.table.column[baTable.table.column.length - 1].buttons = optButtons

// 放行内部权限，确保添加/编辑/删除按钮显示
baTable.auth = () => true

baTable.mount()

onMounted(async () => {
    await baTable.getData()
    // 获取场次和分区列表
    try {
        const addRes = await baTable.api.custom({ url: baTable.api.actionUrl.get('add') })
        if (addRes.data) {
            sessions.value = addRes.data.sessions || []
            zones.value = addRes.data.zones || []
        }
    } catch (e) {
        console.error('Failed to load sessions/zones', e)
    }
})

provide('baTable', baTable)
</script>

<style scoped lang="scss">
</style>
