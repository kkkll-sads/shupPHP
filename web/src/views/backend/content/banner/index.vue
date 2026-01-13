<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <!-- 表格顶部菜单 -->
        <TableHeader
            :buttons="['refresh', 'add', 'edit', 'delete', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="
                t('Quick search placeholder', {
                    fields: t('content.banner.Title') + '/' + t('Id'),
                })
            "
        />

        <!-- 表格 -->
        <!-- 要使用`el-table`组件原有的属性，直接加在Table标签上即可 -->
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
import { useI18n } from 'vue-i18n'

defineOptions({
    name: 'content/banner',
})

const { t } = useI18n()

const baTable = new baTableClass(
    new baTableApi('/admin/content.Banner/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: t('Id'), prop: 'id', align: 'center', operator: '=', operatorPlaceholder: t('Id'), width: 70 },
            {
                label: t('content.banner.Title'),
                prop: 'title',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: t('Fuzzy query'),
            },
            {
                label: t('content.banner.Image'),
                prop: 'image',
                align: 'center',
                render: 'image',
                operator: false,
                width: 120,
            },
            {
                label: t('content.banner.Url'),
                prop: 'url',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: t('Fuzzy query'),
            },
            {
                label: t('content.banner.Description'),
                prop: 'description',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: t('Fuzzy query'),
                show: false,
            },
            {
                label: t('content.banner.Sort'),
                prop: 'sort',
                align: 'center',
                operator: 'BETWEEN',
                operatorPlaceholder: '最小值-最大值',
                width: 80,
            },
            {
                label: t('content.banner.Status'),
                prop: 'status',
                align: 'center',
                render: 'tag',
                custom: { '0': 'danger', '1': 'success' },
                replaceValue: { '0': t('content.banner.Hide'), '1': t('content.banner.Display') },
                operator: '=',
                operatorOptions: [
                    { label: t('content.banner.Display'), value: '1' },
                    { label: t('content.banner.Hide'), value: '0' },
                ],
                width: 80,
            },
            {
                label: t('content.banner.Start Time'),
                prop: 'start_time',
                align: 'center',
                render: 'datetime',
                sortable: 'custom',
                operator: 'RANGE',
                width: 160,
                show: false,
            },
            {
                label: t('content.banner.End Time'),
                prop: 'end_time',
                align: 'center',
                render: 'datetime',
                sortable: 'custom',
                operator: 'RANGE',
                width: 160,
                show: false,
            },
            { label: t('Create time'), prop: 'create_time', align: 'center', render: 'datetime', sortable: 'custom', operator: 'RANGE', width: 160 },
            {
                label: t('Operate'),
                align: 'center',
                width: '130',
                render: 'buttons',
                buttons: [], // 稍后动态设置
                operator: false,
            },
        ],
        dblClickNotEditColumn: [undefined],
    },
    {
        defaultItems: {
            status: '1',
            sort: 0,
        },
    }
)

// 配置操作按钮，绕过权限检查
const optButtons = defaultOptButtons(['edit', 'delete'])
optButtons.forEach((btn) => {
    btn.display = () => true
})

// 更新操作列的按钮配置
baTable.table.column[baTable.table.column.length - 1].buttons = optButtons

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

<style scoped lang="scss"></style>
