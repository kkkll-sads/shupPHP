<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <TableHeader
            :buttons="['refresh', 'add', 'edit', 'delete', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="
                t('Quick search placeholder', {
                    fields: t('shop.product.Name') + '/' + t('Id'),
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

defineOptions({
    name: 'shop/product',
})

const { t } = useI18n()

const baTable = new baTableClass(
    new baTableApi('/admin/shop.Product/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: t('Id'), prop: 'id', align: 'center', operator: '=', operatorPlaceholder: t('Id'), width: 70 },
            {
                label: '商品名称',
                prop: 'name',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: t('Fuzzy query'),
            },
            {
                label: '商品缩略图',
                prop: 'thumbnail',
                align: 'center',
                render: 'image',
                operator: false,
                width: 120,
            },
            {
                label: '商品分类',
                prop: 'category',
                align: 'center',
                operator: 'LIKE',
                width: 110,
            },
            {
                label: '商品价格（余额）',
                prop: 'price',
                align: 'center',
                operator: 'BETWEEN',
                width: 140,
                render: (row: any) => {
                    const price = Number(row.price)
                    return isNaN(price) ? row.price : price.toFixed(2) + '元'
                },
            },
            {
                label: '积分价格',
                prop: 'score_price',
                align: 'center',
                operator: 'BETWEEN',
                width: 120,
            },
            {
                label: '库存数量',
                prop: 'stock',
                align: 'center',
                operator: 'BETWEEN',
                width: 110,
            },
            {
                label: '销量',
                prop: 'sales',
                align: 'center',
                operator: 'BETWEEN',
                width: 90,
            },
            {
                label: '购买方式',
                prop: 'purchase_type',
                align: 'center',
                render: 'tag',
                operator: 'select',
                operatorOptions: [
                    { label: '余额购买', value: 'money' },
                    { label: '积分兑换', value: 'score' },
                    { label: '两者都可', value: 'both' },
                ],
                replaceValue: {
                    money: '余额购买',
                    score: '积分兑换',
                    both: '两者都可',
                },
                width: 120,
            },
            {
                label: '商品类型',
                prop: 'is_physical',
                align: 'center',
                render: 'tag',
                operator: 'select',
                operatorOptions: [
                    { label: '实物商品', value: '1' },
                    { label: '虚拟商品', value: '0' },
                ],
                replaceValue: {
                    '1': '实物商品',
                    '0': '虚拟商品',
                },
                width: 110,
            },
            {
                label: '状态',
                prop: 'status',
                align: 'center',
                render: 'switch',
                options: [
                    { label: t('Enable'), value: '1' },
                    { label: t('Disable'), value: '0' },
                ],
                operator: 'select',
                operatorOptions: [
                    { label: t('Enable'), value: '1' },
                    { label: t('Disable'), value: '0' },
                ],
                width: 90,
            },
            {
                label: '排序',
                prop: 'sort',
                align: 'center',
                operator: 'BETWEEN',
                width: 90,
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
                width: 140,
                render: 'buttons',
                buttons: [],
                operator: false,
            },
        ],
    },
    {
        defaultItems: {
            status: '1',
            sort: 0,
            stock: 0,
            sales: 0,
            price: 0,
            score_price: 0,
            purchase_type: 'both',
            is_physical: '1',
        },
    }
)

// 权限校验统一放行，确保按钮正常显示
baTable.auth = () => true

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

