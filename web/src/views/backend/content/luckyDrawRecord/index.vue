<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <!-- 表格顶部菜单 -->
        <TableHeader
            :buttons="['refresh', 'comSearch', 'quickSearch', 'columnDisplay', 'delete']"
            :quick-search-placeholder="t('Quick search placeholder', { fields: t('content.luckyDrawRecord.prize_name') + '/' + t('content.luckyDrawRecord.user_id') })"
        >
            <el-popconfirm
                @confirm="onClearAll"
                :confirm-button-text="t('Confirm')"
                :cancel-button-text="t('Cancel')"
                confirmButtonType="danger"
                :title="'确定要清除全部抽奖记录吗？此操作不可恢复！'"
            >
                <template #reference>
                    <div class="mlr-12">
                        <el-tooltip content="清除全部记录" placement="top">
                            <el-button v-blur class="table-header-operate" type="danger">
                                <Icon name="fa fa-trash-o" />
                                <span class="table-header-operate-text">清除全部</span>
                            </el-button>
                        </el-tooltip>
                    </div>
                </template>
            </el-popconfirm>
        </TableHeader>

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
import luckyDrawRecordZhCn from '/@/lang/backend/zh-cn/content/luckyDrawRecord'
import luckyDrawRecordEn from '/@/lang/backend/en/content/luckyDrawRecord'
import { ElMessage } from 'element-plus'
import createAxios from '/@/utils/axios'
import Icon from '/@/components/icon/index.vue'

defineOptions({
    name: 'content/luckyDrawRecord',
})

const { t, locale } = useI18n()

// 在 setup 阶段立即合并语言文件（在 baTable 创建前）
if (locale.value === 'zh-cn') {
    mergeMessage(luckyDrawRecordZhCn, 'content/luckyDrawRecord')
} else {
    mergeMessage(luckyDrawRecordEn, 'content/luckyDrawRecord')
}

const onClearAll = async () => {
    try {
        const res = await createAxios({
            url: '/admin/content.LuckyDrawRecord/clearAll',
            method: 'post',
        })
        console.log('清除记录响应:', res)
        if (res.code === 1) {
            ElMessage.success(res.msg || '清除成功')
            baTable.getData()
        } else {
            ElMessage.error(res.msg || '清除失败')
        }
    } catch (error: any) {
        console.error('清除记录错误:', error)
        ElMessage.error(error?.msg || error?.response?.data?.msg || '清除失败，请稍后重试')
    }
}

const baTable = new baTableClass(
    new baTableApi('/admin/content.LuckyDrawRecord/'),
    {
        column: [
            { type: 'selection', align: 'center', width: 50, operator: false },
            { label: t('Id'), prop: 'id', align: 'center', operator: '=', operatorPlaceholder: t('Id'), width: 60 },
            {
                label: t('content.luckyDrawRecord.user_id'),
                prop: 'user_id',
                align: 'center',
                operator: '=',
                operatorPlaceholder: t('content.luckyDrawRecord.user_id'),
                width: 100,
            },
            {
                label: t('content.luckyDrawRecord.prize_name'),
                prop: 'prize_name',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: t('Fuzzy query'),
            },
            {
                label: t('content.luckyDrawRecord.prize_type'),
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
                    score: t('content.luckyDrawRecord.prize_type_score'),
                    money: t('content.luckyDrawRecord.prize_type_money'),
                    coupon: t('content.luckyDrawRecord.prize_type_coupon'),
                    item: t('content.luckyDrawRecord.prize_type_item'),
                },
            },
            {
                label: t('content.luckyDrawRecord.prize_value'),
                prop: 'prize_value',
                align: 'center',
                operator: '=',
                operatorPlaceholder: t('content.luckyDrawRecord.prize_value'),
                width: 100,
            },
            {
                label: t('content.luckyDrawRecord.status'),
                prop: 'status',
                align: 'center',
                width: 100,
                render: 'tag',
                custom: {
                    '1': 'warning',
                    '2': 'success',
                    '0': 'danger',
                },
                replaceValue: {
                    '0': t('content.luckyDrawRecord.status_revoke'),
                    '1': t('content.luckyDrawRecord.status_pending'),
                    '2': t('content.luckyDrawRecord.status_send'),
                },
            },
            {
                label: t('content.luckyDrawRecord.draw_time'),
                prop: 'draw_time',
                align: 'center',
                render: 'datetime',
                sortable: 'custom',
                operator: 'RANGE',
                width: 160,
            },
            {
                label: t('content.luckyDrawRecord.send_time'),
                prop: 'send_time',
                align: 'center',
                render: 'datetime',
                width: 160,
                show: false,
            },
            {
                label: t('Operate'),
                align: 'center',
                width: 150,
                render: 'buttons',
                buttons: [
                    {
                        render: 'basicButton',
                        name: 'edit',
                        text: t('Edit'),
                        type: 'primary',
                        icon: 'fa fa-edit',
                        click: (row: any) => {
                            baTable.form.items = row
                            baTable.form.operate = 'edit'
                        },
                    },
                    {
                        render: 'confirmButton',
                        name: 'delete',
                        text: t('Delete'),
                        type: 'danger',
                        icon: 'fa fa-trash',
                        popconfirm: {
                            confirmButtonText: t('Delete'),
                            cancelButtonText: t('Cancel'),
                            confirmButtonType: 'danger',
                            title: t('Are you sure to delete the selected record?'),
                        },
                    },
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

