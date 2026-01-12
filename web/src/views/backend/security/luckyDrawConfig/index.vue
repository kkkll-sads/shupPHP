<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <!-- 表格顶部菜单 -->
        <TableHeader :buttons="['refresh', 'comSearch', 'quickSearch', 'columnDisplay']" />

        <!-- 表格 -->
        <Table :column="baTable.column" :data="baTable.data" />

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
import luckyDrawConfigZhCn from '/@/lang/backend/zh-cn/security/luckyDrawConfig'
import luckyDrawConfigEn from '/@/lang/backend/en/security/luckyDrawConfig'

defineOptions({
    name: 'security/luckyDrawConfig',
})

const { t, locale } = useI18n()

// 在 setup 阶段立即合并语言文件（在 baTable 创建前）
if (locale.value === 'zh-cn') {
    mergeMessage(luckyDrawConfigZhCn, 'security/luckyDrawConfig')
} else {
    mergeMessage(luckyDrawConfigEn, 'security/luckyDrawConfig')
}

const baTable = new baTableClass(
    new baTableApi('/admin/security.LuckyDrawConfig/'),
    {
        column: [
            { label: t('Id'), prop: 'id', align: 'center', operator: '=', operatorPlaceholder: t('Id'), width: 60 },
            {
                label: t('security.luckyDrawConfig.config_key'),
                prop: 'config_key',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: t('Fuzzy query'),
            },
            {
                label: t('security.luckyDrawConfig.config_value'),
                prop: 'config_value',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: t('Fuzzy query'),
            },
            {
                label: t('security.luckyDrawConfig.remark'),
                prop: 'remark',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: t('Fuzzy query'),
                show: false,
            },
            { label: t('Create time'), prop: 'create_time', align: 'center', render: 'datetime', sortable: 'custom', operator: 'RANGE', width: 160 },
            {
                label: t('Operate'),
                align: 'center',
                width: 100,
                render: 'buttons',
                buttons: [
                    { render: 'editButton', text: t('Edit'), icon: 'fa fa-edit' },
                ],
                operator: false,
            },
        ],
    }
)

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

