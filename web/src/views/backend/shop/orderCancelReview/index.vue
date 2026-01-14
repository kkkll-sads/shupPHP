<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <!-- 统计卡片 -->
        <el-row :gutter="20" style="margin-bottom: 20px">
            <el-col :xs="24" :sm="8">
                <el-card shadow="never" body-style="padding: 15px;">
                    <div style="font-size: 14px; color: #909399;">待审核</div>
                    <div style="font-size: 24px; font-weight: bold; margin-top: 5px; color: #e6a23c;">{{ stats.pending_count }}</div>
                </el-card>
            </el-col>
            <el-col :xs="24" :sm="8">
                <el-card shadow="never" body-style="padding: 15px;">
                    <div style="font-size: 14px; color: #909399;">已通过</div>
                    <div style="font-size: 24px; font-weight: bold; margin-top: 5px; color: #67c23a;">{{ stats.approved_count }}</div>
                </el-card>
            </el-col>
            <el-col :xs=24" :sm="8">
                <el-card shadow="never" body-style="padding: 15px;">
                    <div style="font-size: 14px; color: #909399;">已拒绝</div>
                    <div style="font-size: 24px; font-weight: bold; margin-top: 5px; color: #f56c6c;">{{ stats.rejected_count }}</div>
                </el-card>
            </el-col>
        </el-row>

        <!-- 表格头部 -->
        <TableHeader
            :buttons="['refresh', 'comSearch', 'quickSearch', 'columnDisplay']"
            quick-search-placeholder="快速搜索：订单号/ID"
        />

        <!-- 表格 -->
        <Table />

        <!-- 审核对话框 -->
        <el-dialog
            v-model="auditDialog.visible"
            :title="auditDialog.title"
            width="620px"
            :close-on-click-modal="false"
        >
            <el-form :model="auditDialog.form" label-width="120px">
                <el-form-item label="订单号">
                    <el-input v-model="auditDialog.form.order_no" disabled />
                </el-form-item>
                <el-form-item label="用户">
                    <el-input v-model="auditDialog.form.user" disabled />
                </el-form-item>
                <el-form-item label="取消原因">
                    <el-input
                        v-model="auditDialog.form.cancel_reason"
                        type="textarea"
                        :rows="3"
                        disabled
                    />
                </el-form-item>
                <el-form-item label="订单金额">
                    <el-input v-model="auditDialog.form.amount" disabled />
                </el-form-item>
                <el-form-item label="申请时间">
                    <el-input v-model="auditDialog.form.apply_time" disabled />
                </el-form-item>
                <el-form-item label="审核备注">
                    <el-input
                        v-model="auditDialog.form.audit_remark"
                        type="textarea"
                        :rows="4"
                        :placeholder="auditDialog.type === 'approve' ? '可以填写审核备注（选填）' : '请输入拒绝原因（必填）'"
                    />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="auditDialog.visible = false">取消</el-button>
                <el-button type="primary" @click="submitAudit" :loading="auditDialog.loading">
                    {{ auditDialog.type === 'approve' ? '通过' : '拒绝' }}
                </el-button>
            </template>
        </el-dialog>

        <!-- 详情对话框 -->
        <el-dialog
            v-model="detailDialog.visible"
            title="取消审核详情"
            width="720px"
            :close-on-click-modal="false"
        >
            <el-descriptions :column="2" border v-if="detailDialog.data">
                <el-descriptions-item label="审核ID">{{ detailDialog.data.id }}</el-descriptions-item>
                <el-descriptions-item label="订单号">{{ detailDialog.data.order_no }}</el-descriptions-item>
                <el-descriptions-item label="用户">{{ detailDialog.data.user_nickname }}（{{ detailDialog.data.user_mobile }}）</el-descriptions-item>
                <el-descriptions-item label="订单金额">
                    <span v-if="detailDialog.data.pay_type === 'money'">¥{{ detailDialog.data.total_amount }}</span>
                    <span v-else>{{ detailDialog.data.total_score }} 消费金</span>
                </el-descriptions-item>
                <el-descriptions-item label="支付方式">{{ detailDialog.data.pay_type_text }}</el-descriptions-item>
                <el-descriptions-item label="订单状态">{{ detailDialog.data.order_status }}</el-descriptions-item>
                <el-descriptions-item label="订单创建时间">{{ formatTime(detailDialog.data.order_create_time) }}</el-descriptions-item>
                <el-descriptions-item label="申请时间">{{ formatTime(detailDialog.data.apply_time) }}</el-descriptions-item>
                <el-descriptions-item label="订单时长">{{ detailDialog.data.hours_since_create }} 小时</el-descriptions-item>
                <el-descriptions-item label="审核状态">
                    <el-tag :type="statusTag(detailDialog.data.status)">
                        {{ detailDialog.data.status_text }}
                    </el-tag>
                </el-descriptions-item>
                <el-descriptions-item label="取消原因" :span="2">
                    {{ detailDialog.data.cancel_reason || '-' }}
                </el-descriptions-item>
                <el-descriptions-item label="审核时间" v-if="detailDialog.data.audit_time">
                    {{ formatTime(detailDialog.data.audit_time) }}
                </el-descriptions-item>
                <el-descriptions-item label="审核人" v-if="detailDialog.data.audit_admin_username">
                    {{ detailDialog.data.audit_admin_username }}
                </el-descriptions-item>
                <el-descriptions-item label="审核备注" :span="2" v-if="detailDialog.data.audit_remark">
                    {{ detailDialog.data.audit_remark }}
                </el-descriptions-item>
            </el-descriptions>
            
            <template #footer>
                <el-button @click="detailDialog.visible = false">关闭</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup lang="ts">
import { reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import baTableClass from '/@/utils/baTable'
import baTableApi from '/@/api/common'
import { useI18n } from 'vue-i18n'
import { defaultOptButtons } from '/@/components/table'
import TableHeader from '/@/components/table/header/index.vue'
import Table from '/@/components/table/index.vue'
import { dayjs } from 'element-plus'

const { t } = useI18n()

// 表格配置
const baTable = new baTableClass(
    new baTableApi('/admin/shop.OrderCancelReview/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: '审核ID', prop: 'id', align: 'center', operator: '=', width: 80 },
            {
                label: '订单号',
                prop: 'order_no',
                align: 'center',
                operator: 'LIKE',
                width: 200,
            },
            {
                label: '用户',
                prop: 'user_nickname',
                align: 'center',
                operator: false,
                width: 150,
            },
            {
                label: '手机号',
                prop: 'user_mobile',
                align: 'center',
                operator: false,
                width: 120,
            },
            {
                label: '订单金额',
                prop: 'total_amount',
                align: 'center',
                operator: false,
                width: 120,
                render: (row: any) => {
                    if (row.pay_type === 'money') {
                        return '¥' + Number(row.total_amount).toFixed(2)
                    } else {
                        return row.total_score + ' 消费金'
                    }
                },
            },
            {
                label: '支付方式',
                prop: 'pay_type',
                align: 'center',
                operator: false,
                width: 110,
                render: (row: any) => row.pay_type_text,
            },
            {
                label: '取消原因',
                prop: 'cancel_reason',
                align: 'left',
                operator: false,
                'show-overflow-tooltip': true,
                width: 200,
            },
            {
                label: '订单时长',
                prop: 'hours_since_create',
                align: 'center',
                operator: false,
                width: 100,
                render: (row: any) => row.hours_since_create + ' 小时',
            },
            {
                label: '审核状态',
                prop: 'status',
                align: 'center',
                render: 'tag',
                operator: 'select',
                operatorOptions: [
                    { label: '待审核', value: '0' },
                    { label: '已通过', value: '1' },
                    { label: '已拒绝', value: '2' },
                ],
                replaceValue: {
                    0: '待审核',
                    1: '已通过',
                    2: '已拒绝',
                },
                custom: {
                    0: 'warning',
                    1: 'success',
                    2: 'danger',
                },
                width: 110,
            },
            {
                label: '申请时间',
                prop: 'apply_time',
                align: 'center',
                render: 'datetime',
                sortable: 'custom',
                operator: 'RANGE',
                width: 160,
            },
            {
                label: '审核时间',
                prop: 'audit_time',
                align: 'center',
                render: 'datetime',
                operator: false,
                width: 160,
            },
            {
                label: '审核人',
                prop: 'audit_admin_username',
                align: 'center',
                operator: false,
                width: 120,
            },
            {
                label: t('Operate'),
                prop: 'operate',
                align: 'center',
                width: 200,
                render: 'buttons',
                buttons: [],
                operator: false,
            },
        ],
    }
)

// 自定义操作按钮
const optButtons = defaultOptButtons([])
optButtons.push(
    {
        render: 'tipButton',
        name: 'detail',
        title: '详情',
        text: '',
        type: 'primary',
        icon: 'fa fa-eye',
        class: 'table-row-detail',
        disabledTip: false,
        display: () => true,
        click: (row: any) => showDetail(row),
    },
    {
        render: 'tipButton',
        name: 'approve',
        title: '通过',
        text: '',
        type: 'success',
        icon: 'fa fa-check',
        class: 'table-row-approve',
        disabledTip: false,
        display: (row: any) => row.status === 0,
        click: (row: any) => showAuditDialog(row, 'approve'),
    },
    {
        render: 'tipButton',
        name: 'reject',
        title: '拒绝',
        text: '',
        type: 'danger',
        icon: 'fa fa-times',
        class: 'table-row-reject',
        disabledTip: false,
        display: (row: any) => row.status === 0,
        click: (row: any) => showAuditDialog(row, 'reject'),
    }
)

baTable.table.column[baTable.table.column.length - 1].buttons = optButtons

// 统计数据
const stats = reactive({
    pending_count: 0,
    approved_count: 0,
    rejected_count: 0,
})

// 审核对话框
const auditDialog = reactive({
    visible: false,
    title: '',
    type: '', // 'approve' | 'reject'
    loading: false,
    form: {
        id: 0,
        order_no: '',
        user: '',
        cancel_reason: '',
        amount: '',
        apply_time: '',
        audit_remark: '',
    },
})

// 详情对话框
const detailDialog = reactive({
    visible: false,
    data: null as any,
})

// 显示审核对话框
const showAuditDialog = (row: any, type: 'approve' | 'reject') => {
    auditDialog.type = type
    auditDialog.title = type === 'approve' ? '审核通过' : '审核拒绝'
    auditDialog.form = {
        id: row.id,
        order_no: row.order_no,
        user: `${row.user_nickname}（${row.user_mobile}）`,
        cancel_reason: row.cancel_reason,
        amount: row.pay_type === 'money' ? `¥${row.total_amount}` : `${row.total_score} 消费金`,
        apply_time: formatTime(row.apply_time),
        audit_remark: '',
    }
    auditDialog.visible = true
}

// 提交审核
const submitAudit = async () => {
    if (auditDialog.type === 'reject' && !auditDialog.form.audit_remark) {
        ElMessage.warning('请填写拒绝原因')
        return
    }

    const action = auditDialog.type === 'approve' ? 'approve' : 'reject'
    const actionText = auditDialog.type === 'approve' ? '通过' : '拒绝'

    try {
        await ElMessageBox.confirm(`确定${actionText}该订单取消申请吗？`, '提示', {
            confirmButtonText: '确定',
            cancelButtonText: '取消',
            type: 'warning',
        })
    } catch {
        return
    }

    auditDialog.loading = true
    
    try {
        const res = await baTable.api.postData(`${action}`, {
            id: auditDialog.form.id,
            audit_remark: auditDialog.form.audit_remark,
        })

        ElMessage.success(res.msg || `审核${actionText}成功`)
        auditDialog.visible = false
        baTable.onTableHeaderAction('refresh', {})
        loadStats()
    } catch (error: any) {
        ElMessage.error(error.msg || error.message || `审核${actionText}失败`)
    } finally {
        auditDialog.loading = false
    }
}

// 显示详情
const showDetail = async (row: any) => {
    try {
        const res = await baTable.api.getData(`detail?id=${row.id}`)
        detailDialog.data = res.data.row
        detailDialog.visible = true
    } catch (error: any) {
        ElMessage.error(error.msg || error.message || '获取详情失败')
    }
}

// 格式化时间
const formatTime = (timestamp: number) => {
    if (!timestamp) return '-'
    return dayjs.unix(timestamp).format('YYYY-MM-DD HH:mm:ss')
}

// 状态标签类型
const statusTag = (status: number) => {
    const map: Record<number, string> = {
        0: 'warning',
        1: 'success',
        2: 'danger',
    }
    return map[status] || ''
}

// 加载统计数据
const loadStats = async () => {
    try {
        const res = await baTable.api.getData('index?limit=999999')
        const list = res.data.list || []
        
        stats.pending_count = list.filter((item: any) => item.status === 0).length
        stats.approved_count = list.filter((item: any) => item.status === 1).length
        stats.rejected_count = list.filter((item: any) => item.status === 2).length
    } catch (error) {
        console.error('加载统计数据失败', error)
    }
}

onMounted(() => {
    baTable.mount()
    baTable.getData()
    loadStats()
})
</script>

<style scoped lang="scss"></style>
