<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <TableHeader
            :buttons="['refresh', 'add', 'edit', 'delete', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="
                t('Quick search placeholder', {
                    fields: t('content.contentNews.Title') + '/' + t('Id'),
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
    name: 'content/contentNews',
})

const { t } = useI18n()

const baTable = new baTableClass(
    new baTableApi('/admin/content.ContentNews/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: t('Id'), prop: 'id', align: 'center', operator: '=', operatorPlaceholder: t('Id'), width: 70 },
            {
                label: t('content.contentNews.Title'),
                prop: 'title',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: t('Fuzzy query'),
            },
            {
                label: t('content.contentNews.Cover Image'),
                prop: 'cover_image',
                align: 'center',
                render: 'image',
                operator: false,
                width: 120,
            },
            {
                label: t('content.contentNews.Summary'),
                prop: 'summary',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: t('Fuzzy query'),
                show: false,
            },
            {
                label: t('content.contentNews.Is Hot'),
                prop: 'is_hot',
                align: 'center',
                render: 'tag',
                replaceValue: { 1: t('Yes'), 0: t('No') },
                custom: { 1: 'danger', 0: 'info' },
                operator: 'select',
                operatorOptions: [
                    { label: t('Yes'), value: 1 },
                    { label: t('No'), value: 0 },
                ],
                width: 90,
            },
            {
                label: t('content.contentNews.Status'),
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
                label: t('content.contentNews.Publish Time'),
                prop: 'publish_time',
                align: 'center',
                render: 'datetime',
                operator: 'RANGE',
                width: 160,
            },
            {
                label: t('content.contentNews.Sort'),
                prop: 'sort',
                align: 'center',
                operator: 'BETWEEN',
                width: 80,
            },
            {
                label: t('content.contentNews.View Count'),
                prop: 'view_count',
                align: 'center',
                operator: 'BETWEEN',
                width: 110,
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
            is_hot: 1,
            sort: 0,
            publish_time: '',
            view_count: 0,
        },
    }
)

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


