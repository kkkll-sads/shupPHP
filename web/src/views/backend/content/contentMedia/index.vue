<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <TableHeader
            :buttons="['refresh', 'add', 'edit', 'delete', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="
                t('Quick search placeholder', {
                    fields: t('content.contentMedia.Title') + '/' + t('Id'),
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
    name: 'content/contentMedia',
})

const { t } = useI18n()

const mediaCategoryOptions = {
    promo_video: t('content.contentMedia.Category promo_video'),
    resource: t('content.contentMedia.Category resource'),
    hot_video: t('content.contentMedia.Category hot_video'),
}

const mediaTypeOptions = {
    image: t('content.contentMedia.Media Type image'),
    video: t('content.contentMedia.Media Type video'),
    document: t('content.contentMedia.Media Type document'),
    other: t('content.contentMedia.Media Type other'),
}

const baTable = new baTableClass(
    new baTableApi('/admin/content.ContentMedia/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: t('Id'), prop: 'id', align: 'center', operator: '=', operatorPlaceholder: t('Id'), width: 70 },
            {
                label: t('content.contentMedia.Category'),
                prop: 'category',
                align: 'center',
                render: 'tag',
                replaceValue: mediaCategoryOptions,
                custom: { promo_video: 'warning', resource: 'info', hot_video: 'success' },
                operator: 'select',
                operatorOptions: Object.keys(mediaCategoryOptions).map((key) => ({
                    label: mediaCategoryOptions[key as keyof typeof mediaCategoryOptions],
                    value: key,
                })),
                width: 120,
            },
            {
                label: t('content.contentMedia.Title'),
                prop: 'title',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: t('Fuzzy query'),
            },
            {
                label: t('content.contentMedia.Media Type'),
                prop: 'media_type',
                align: 'center',
                render: 'tag',
                replaceValue: mediaTypeOptions,
                operator: 'select',
                operatorOptions: Object.keys(mediaTypeOptions).map((key) => ({
                    label: mediaTypeOptions[key as keyof typeof mediaTypeOptions],
                    value: key,
                })),
                width: 110,
            },
            {
                label: t('content.contentMedia.Cover Image'),
                prop: 'cover_image',
                align: 'center',
                render: 'image',
                operator: false,
                width: 120,
            },
            {
                label: t('content.contentMedia.Media Url'),
                prop: 'media_url',
                align: 'center',
                render: (row: any) => {
                    if (!row.media_url) {
                        return '-'
                    }
                    return `<a href="${row.media_url}" target="_blank">${t('content.contentMedia.View link')}</a>`
                },
                operator: false,
            },
            {
                label: t('content.contentMedia.Sort'),
                prop: 'sort',
                align: 'center',
                operator: 'BETWEEN',
                width: 80,
            },
            {
                label: t('content.contentMedia.Status'),
                prop: 'status',
                align: 'center',
                render: 'switch',
                options: [
                    { label: t('Disable'), value: '0' },
                    { label: t('Enable'), value: '1' },
                ],
                operator: 'select',
                operatorOptions: [
                    { label: t('Enable'), value: '1' },
                    { label: t('Disable'), value: '0' },
                ],
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
            category: 'resource',
            media_type: 'image',
            status: '1',
            sort: 0,
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


