<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <!-- 表格顶部菜单 -->
        <TableHeader
            :buttons="['refresh', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="
                t('Quick search placeholder', {
                    fields: t('content.inviteCode.code') + '/' + t('content.inviteCode.creator'),
                })
            "
        />

        <!-- 表格 -->
        <!-- 要使用`el-table`组件原有的属性，直接加在Table标签上即可 -->
        <Table :default-expand-all="false">
            <!-- 展开行显示下级用户 -->
            <template #expand="slotProps: any">
                <div class="expand-details">
                    <div style="padding-bottom: 10px; font-weight: bold">下级用户列表（共 {{ slotProps.row.child_count }} 人）:</div>
                    <el-table :data="slotProps.row.child_users || []" stripe style="width: 100%; margin: 10px 0" size="small">
                        <el-table-column prop="id" label="用户ID" width="100" align="center" />
                        <el-table-column prop="nickname" label="昵称" align="center" />
                        <el-table-column prop="mobile" label="手机号" align="center" />
                        <el-table-column prop="username" label="用户名" align="center" />
                    </el-table>
                </div>
            </template>
        </Table>

        <!-- 邀请码详情弹窗 -->
        <PopupDetail />
    </div>
</template>

<script setup lang="ts">
import { provide } from 'vue'
import baTableClass from '/@/utils/baTable'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import PopupDetail from './popupDetail.vue'
import { baTableApi } from '/@/api/common'
import { useI18n } from 'vue-i18n'

defineOptions({
    name: 'content/inviteCode',
})

const { t } = useI18n()
const baTable = new baTableClass(
    new baTableApi('/admin/content.InviteCode/'),
    {
        column: [
            { type: 'expand', operator: false, width: 50 },
            { label: t('Id'), prop: 'id', align: 'center', operator: '=', operatorPlaceholder: t('Id'), width: 60 },
            {
                label: t('content.inviteCode.code'),
                prop: 'code',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: t('Fuzzy query'),
                render: 'tag',
            },
            {
                label: t('content.inviteCode.creator'),
                prop: 'user.nickname',
                align: 'center',
                operator: false,
                formatter: (row: any) => {
                    if (row?.user) {
                        return `${row.user.mobile}`
                    }
                    return '-'
                },
            },
            {
                label: t('content.inviteCode.upuser'),
                prop: 'upuser.nickname',
                align: 'center',
                operator: false,
                show: false,
                formatter: (row: any) => {
                    if (row?.upuser) {
                        return `${row.upuser.nickname}(${row.upuser.mobile})`
                    }
                    return '-'
                },
            },
            {
                label: t('content.inviteCode.child_count'),
                prop: 'child_count',
                align: 'center',
                operator: '=',
                operatorPlaceholder: t('content.inviteCode.child_count'),
            },
            {
                label: t('content.inviteCode.status'),
                prop: 'status',
                align: 'center',
                width: 80,
                render: 'tag',
                custom: { '0': 'danger', '1': 'success' },
                replaceValue: { '0': t('Disable'), '1': t('Enable') },
            },
            {
                label: t('content.inviteCode.use_count'),
                prop: 'use_count',
                align: 'center',
                operator: '=',
                operatorPlaceholder: t('content.inviteCode.use_count'),
            },
            {
                label: t('content.inviteCode.remark'),
                prop: 'remark',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: t('Fuzzy query'),
                show: false,
            },
            { label: t('Create time'), prop: 'createtime', align: 'center', render: 'datetime', sortable: 'custom', operator: 'RANGE', width: 160 },
            {
                label: t('Operate'),
                align: 'center',
                width: 100,
                render: 'buttons',
                buttons: [
                    {
                        render: 'basicButton',
                        name: 'view',
                        text: '查看详情',
                        type: 'primary',
                        icon: 'fa fa-eye',
                        click: (row: any) => {
                            baTable.form.items = row
                            baTable.form.operate = 'view'
                        },
                    },
                ],
                operator: false,
            },
        ],
        dblClickNotEditColumn: [undefined],
    },
    {
        defaultItems: {
            status: '1',
            use_count: 0,
            max_use: 0,
        },
    }
)

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

<style scoped lang="scss">
.expand-details {
    padding: 20px;
    background-color: #f5f7fa;
}

.expand-details :deep(.el-table) {
    background-color: #fff;
    border: 1px solid #dcdfe6;
    border-radius: 4px;
}

.expand-details :deep(.el-table__row) {
    background-color: #fff;
}

.expand-details :deep(.el-table__body-wrapper) {
    overflow-x: auto;
}
</style>
