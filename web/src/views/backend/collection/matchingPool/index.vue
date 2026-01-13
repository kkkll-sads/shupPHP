<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <TableHeader
            :buttons="['refresh', 'comSearch', 'quickSearch', 'columnDisplay', 'delete']"
            :quick-search-placeholder="
                t('Quick search placeholder', {
                    fields: t('Id'),
                })
            "
        >
            <template #refreshAppend>
                <el-button v-blur class="table-header-operate" type="danger" plain @click="onClearOrphans">
                    <span class="table-header-operate-text">一键清除未关联用户订单</span>
                </el-button>
            </template>
        </TableHeader>

        <Table />

        <!-- 详情对话框 -->
        <el-dialog v-model="detailDialogVisible" title="撮合池详情" width="800px" destroy-on-close>
            <el-descriptions v-if="detailData" :column="2" border>
                <el-descriptions-item label="记录ID">{{ detailData.id }}</el-descriptions-item>
                <el-descriptions-item label="状态">
                    <el-tag :type="getStatusType(detailData.status)">{{ detailData.status_text }}</el-tag>
                </el-descriptions-item>
                <el-descriptions-item label="藏品ID">{{ detailData.item_id }}</el-descriptions-item>
                <el-descriptions-item label="藏品标题">{{ detailData.item_title || '-' }}</el-descriptions-item>
                <el-descriptions-item label="时段ID">{{ detailData.session_id }}</el-descriptions-item>
                <el-descriptions-item label="时段名称">{{ detailData.session_title || '-' }}</el-descriptions-item>
                <el-descriptions-item label="用户ID">{{ detailData.user_id }}</el-descriptions-item>
                <el-descriptions-item label="用户昵称">{{ detailData.user_nickname || '-' }}</el-descriptions-item>
                <el-descriptions-item label="消耗算力">{{ detailData.power_used }}</el-descriptions-item>
                <el-descriptions-item label="获得权重">{{ detailData.weight }}</el-descriptions-item>
                <el-descriptions-item label="撮合时间" v-if="detailData.match_time">
                    {{ formatDateTime(detailData.match_time) }}
                </el-descriptions-item>
                <el-descriptions-item label="撮合订单号" v-if="detailData.match_order_no">
                    {{ detailData.match_order_no }}
                </el-descriptions-item>
                <el-descriptions-item label="创建时间">
                    {{ formatDateTime(detailData.create_time) }}
                </el-descriptions-item>
                <el-descriptions-item label="更新时间">
                    {{ formatDateTime(detailData.update_time) }}
                </el-descriptions-item>
            </el-descriptions>
        </el-dialog>
    </div>
</template>

<script setup lang="ts">
import { provide, ref } from 'vue'
import baTableClass from '/@/utils/baTable'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import { baTableApi } from '/@/api/common'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'

defineOptions({
    name: 'collection/matchingPool',
})

const { t } = useI18n()

const detailDialogVisible = ref(false)
const detailData = ref<any>(null)

const baTable = new baTableClass(
    new baTableApi('/admin/collection.MatchingPool/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: t('Id'), prop: 'id', align: 'center', operator: '=', operatorPlaceholder: t('Id'), width: 70 },
            {
                label: '藏品ID',
                prop: 'item_id',
                align: 'center',
                operator: '=',
                operatorPlaceholder: '藏品ID',
            },
            {
                label: '藏品标题',
                prop: 'item_title',
                align: 'center',
                operator: 'LIKE' as any,
                operatorPlaceholder: t('Fuzzy query'),
                render: ((row: any) => {
                    return row.item_title || '-'
                }) as any,
            },
            {
                label: '时段',
                prop: 'session_title',
                align: 'center',
                render: ((row: any) => {
                    return row.session_title || '-'
                }) as any,
            },
            {
                label: '用户ID',
                prop: 'user_id',
                align: 'center',
                operator: '=',
            },
            {
                label: '用户昵称',
                prop: 'user_nickname',
                align: 'center',
                render: ((row: any) => {
                    return row.user_nickname || '-'
                }) as any,
            },
            {
                label: '消耗算力',
                prop: 'power_used',
                align: 'center',
                render: ((row: any) => {
                    return Number(row.power_used).toFixed(2)
                }) as any,
            },
            {
                label: '获得权重',
                prop: 'weight',
                align: 'center',
            },
            ({
                label: '状态',
                prop: 'status',
                align: 'center',
                render: 'tag' as any,
                operator: 'select' as any,
                operatorOptions: ([
                    { label: '待撮合', value: 'pending' },
                    { label: '已撮合', value: 'matched' },
                    { label: '已取消', value: 'cancelled' },
                ]) as any,
                replaceValue: {
                    pending: '待撮合',
                    matched: '已撮合',
                    cancelled: '已取消',
                },
                custom: {
                    pending: 'warning',
                    matched: 'success',
                    cancelled: 'info',
                },
            } as any),
            {
                label: '撮合时间',
                prop: 'match_time',
                align: 'center',
                render: 'datetime',
                operator: 'RANGE',
                width: 160,
            },
            {
                label: '撮合订单号',
                prop: 'match_order_no',
                align: 'center',
                render: ((row: any) => {
                    return row.match_order_no || '-'
                }) as any,
            },
            {
                label: t('Create time'),
                prop: 'create_time',
                align: 'center',
                render: 'datetime' as any,
                sortable: 'custom' as any,
                operator: 'RANGE' as any,
                width: 160,
            },
            {
                label: t('Operate'),
                prop: 'operate',
                align: 'center',
                width: 150,
                render: 'buttons',
                buttons: ((row: any) => {
                    return [
                        {
                            name: '查看详情',
                            type: 'primary',
                            icon: 'fa fa-eye',
                            click: () => {
                                viewDetail(row.id)
                            },
                        },
                        {
                            name: 'delete',
                            render: 'confirmButton',
                            type: 'danger',
                            icon: 'fa fa-trash',
                            title: '确认删除该撮合记录？',
                        },
                    ]
                }) as any,
                operator: false,
            },
        ],
    },
    {
        defaultItems: {},
    }
)

const getStatusType = (status: string): 'success' | 'warning' | 'danger' | 'info' => {
    const typeMap: Record<string, 'success' | 'warning' | 'danger' | 'info'> = {
        pending: 'warning',
        matched: 'success',
        cancelled: 'info',
    }
    return typeMap[status] || 'info'
}

const formatDateTime = (timestamp: number): string => {
    if (!timestamp) return '-'
    const date = new Date(timestamp * 1000)
    return date.toLocaleString('zh-CN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    })
}

const viewDetail = async (id: number) => {
    try {
        const res = await (baTable.api as any).detail({ id })
        if (res.code === 1) {
            detailData.value = res.data
            detailDialogVisible.value = true
        } else {
            ElMessage.error(res.msg || '获取详情失败')
        }
    } catch (error) {
        ElMessage.error('获取详情失败')
    }
}

const onClearOrphans = async () => {
    try {
        const res = await baTable.api.postData('clearOrphans', {})
        if (res.code === 1) {
            const cleared = res.data?.cleared ?? 0
            ElMessage.success(`已清除 ${cleared} 条未关联用户订单`)
            baTable.onTableHeaderAction('refresh', {})
        } else {
            ElMessage.error(res.msg || '清除失败')
        }
    } catch (e) {
        ElMessage.error('清除失败')
    }
}

provide('baTable', baTable)

// 初始化加载数据
baTable.mount()
baTable.getData()
</script>

<style scoped lang="scss">
</style>


