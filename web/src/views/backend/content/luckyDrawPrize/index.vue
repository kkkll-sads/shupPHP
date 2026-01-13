<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <!-- 表格顶部菜单 -->
        <TableHeader
            :buttons="['refresh', 'add', 'comSearch', 'quickSearch', 'columnDisplay', 'delete']"
            :quick-search-placeholder="t('Quick search placeholder', { fields: t('content.luckyDrawPrize.name') })"
        />

        <!-- 表格 -->
        <Table />

        <!-- 编辑弹窗 -->
        <PopupForm />
    </div>
</template>

<script setup lang="ts">
import { provide } from 'vue'
import baTableClass from '/@/utils/baTable'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import PopupForm from './popupForm.vue'
import { baTableApi } from '/@/api/common'
import { useI18n } from 'vue-i18n'
import { mergeMessage } from '/@/lang/index'
import luckyDrawPrizeZhCn from '/@/lang/backend/zh-cn/content/luckyDrawPrize'
import luckyDrawPrizeEn from '/@/lang/backend/en/content/luckyDrawPrize'
import { defaultOptButtons } from '/@/components/table'

defineOptions({
    name: 'content/luckyDrawPrize',
})

const { t, locale } = useI18n()

// 在 setup 阶段立即合并语言文件（在 baTable 创建前）
if (locale.value === 'zh-cn') {
    mergeMessage(luckyDrawPrizeZhCn, 'content/luckyDrawPrize')
} else {
    mergeMessage(luckyDrawPrizeEn, 'content/luckyDrawPrize')
}

const baTable = new baTableClass(
    new baTableApi('/admin/content.LuckyDrawPrize/'),
    {
        column: [
            { type: 'selection', align: 'center', width: 50, operator: false },
            { label: t('Id'), prop: 'id', align: 'center', operator: '=', operatorPlaceholder: t('Id'), width: 60 },
            {
                label: t('content.luckyDrawPrize.name'),
                prop: 'name',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: t('Fuzzy query'),
            },
            {
                label: t('content.luckyDrawPrize.thumbnail'),
                prop: 'thumbnail',
                align: 'center',
                render: 'image',
                width: 80,
                operator: false,
            },
            {
                label: t('content.luckyDrawPrize.prize_type'),
                prop: 'prize_type',
                align: 'center',
                operator: '=',
                width: 100,
                render: 'tag',
                custom: {
                    score: 'success',
                    money: 'warning',
                    coupon: 'info',
                    item: 'danger',
                },
                replaceValue: {
                    score: t('content.luckyDrawPrize.prize_type_score'),
                    money: t('content.luckyDrawPrize.prize_type_money'),
                    coupon: t('content.luckyDrawPrize.prize_type_coupon'),
                    item: t('content.luckyDrawPrize.prize_type_item'),
                },
            },
            {
                label: t('content.luckyDrawPrize.prize_value'),
                prop: 'prize_value',
                align: 'center',
                operator: '=',
                operatorPlaceholder: t('content.luckyDrawPrize.prize_value'),
                width: 100,
            },
            {
                label: t('content.luckyDrawPrize.probability'),
                prop: 'probability',
                align: 'center',
                operator: '=',
                operatorPlaceholder: t('content.luckyDrawPrize.probability'),
                width: 100,
            },
            {
                label: t('content.luckyDrawPrize.daily_limit'),
                prop: 'daily_limit',
                align: 'center',
                operator: '=',
                operatorPlaceholder: t('content.luckyDrawPrize.daily_limit'),
                width: 100,
                show: false,
            },
            {
                label: t('content.luckyDrawPrize.daily_count'),
                prop: 'daily_count',
                align: 'center',
                operator: '=',
                width: 100,
                show: false,
            },
            {
                label: t('content.luckyDrawPrize.total_limit'),
                prop: 'total_limit',
                align: 'center',
                operator: '=',
                operatorPlaceholder: t('content.luckyDrawPrize.total_limit'),
                width: 100,
                show: false,
            },
            {
                label: t('content.luckyDrawPrize.total_count'),
                prop: 'total_count',
                align: 'center',
                operator: '=',
                width: 100,
                show: false,
            },
            {
                label: t('content.luckyDrawPrize.sort'),
                prop: 'sort',
                align: 'center',
                operator: '=',
                operatorPlaceholder: t('content.luckyDrawPrize.sort'),
                width: 80,
                sortable: 'custom',
            },
            {
                label: t('content.luckyDrawPrize.status'),
                prop: 'status',
                align: 'center',
                width: 80,
                render: 'tag',
                custom: { '0': 'danger', '1': 'success' },
                replaceValue: { '0': t('Disable'), '1': t('Enable') },
            },
            { label: t('Create time'), prop: 'create_time', align: 'center', render: 'datetime', sortable: 'custom', operator: 'RANGE', width: 160 },
            {
                label: t('Operate'),
                align: 'center',
                width: 100,
                render: 'buttons',
                buttons: [],
                operator: false,
            },
        ],
    },
    {
        defaultItems: {
            status: '1',
            prize_type: 'score',
            prize_value: 0,
            probability: 0,
            daily_limit: 0,
            total_limit: 0,
            sort: 0,
        },
    }
)

const optButtons = defaultOptButtons(['edit', 'delete'])
optButtons.forEach((btn) => {
    btn.text = btn.name === 'edit' ? t('Edit') : t('Delete')
    btn.display = () => true
})
baTable.table.column[baTable.table.column.length - 1].buttons = optButtons

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>
