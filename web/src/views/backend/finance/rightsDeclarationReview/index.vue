<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <TableHeader
            :buttons="['refresh', 'comSearch', 'quickSearch', 'columnDisplay']"
            quick-search-placeholder="快速搜索：手机号/金额"
        />

        <Table />

        <!-- 审核对话框 -->
        <el-dialog
            v-model="auditDialog.visible"
            :title="auditDialog.title"
            width="520px"
            :close-on-click-modal="false"
        >
            <el-form :model="auditDialog.form" label-width="120px">
                <el-form-item label="用户">
                    <el-input v-model="auditDialog.form.user_info" disabled />
                </el-form-item>
                <el-form-item label="申请金额">
                    <el-input v-model="auditDialog.form.amount" disabled>
                        <template #append>元</template>
                    </el-input>
                </el-form-item>
                <el-form-item label="凭证类型">
                    <el-input v-model="auditDialog.form.voucher_type_text" disabled />
                </el-form-item>
                <template v-if="auditDialog.type === 'approve'">
                    <el-form-item label="发放奖励">
                        <el-checkbox-group v-model="auditDialog.form.rewards">
                        <el-checkbox label="balance">待激活确权金 ({{ auditDialog.form.amount }}元)</el-checkbox>
                            <el-checkbox label="green_power">绿色能量 ({{ Math.floor(auditDialog.form.amount * 10) }})</el-checkbox>
                            <el-checkbox label="consignment_coupon">寄售卷 ({{ Math.floor(auditDialog.form.amount / 10) }})</el-checkbox>
                        </el-checkbox-group>
                    </el-form-item>
                    <el-form-item label="审核备注">
                        <el-input
                            v-model="auditDialog.form.audit_remark"
                            type="textarea"
                            :rows="3"
                            placeholder="可以填写审核备注（选填）"
                        />
                    </el-form-item>
                </template>
                <template v-else>
                    <el-form-item label="审核备注" required>
                        <el-input
                            v-model="auditDialog.form.audit_remark"
                            type="textarea"
                            :rows="4"
                            placeholder="请输入拒绝原因"
                        />
                    </el-form-item>
                </template>
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
            title="确权申报详情"
            width="800px"
            :close-on-click-modal="false"
        >
            <el-descriptions :column="2" border v-if="detailDialog.data">
                <el-descriptions-item label="用户昵称">{{ detailDialog.data.user_nickname }}</el-descriptions-item>
                <el-descriptions-item label="手机号">{{ detailDialog.data.user_mobile }}</el-descriptions-item>
                <el-descriptions-item label="手机号">{{ detailDialog.data.user_mobile || '-' }}</el-descriptions-item>
                <el-descriptions-item label="申请金额">
                    <span class="amount-text">{{ formatAmount(detailDialog.data.amount) }}</span>
                </el-descriptions-item>
                <el-descriptions-item label="凭证类型">{{ detailDialog.data.voucher_type_text }}</el-descriptions-item>
                <el-descriptions-item label="状态">
                    <el-tag :type="statusTag(detailDialog.data.status)">
                        {{ detailDialog.data.status_text }}
                    </el-tag>
                </el-descriptions-item>
                <el-descriptions-item label="申请时间">{{ formatTime(detailDialog.data.create_time) }}</el-descriptions-item>
                <el-descriptions-item label="审核时间">
                    {{ detailDialog.data.review_time ? formatTime(detailDialog.data.review_time) : '-' }}
                </el-descriptions-item>
                <el-descriptions-item label="审核人">
                    {{ detailDialog.data.review_admin_name || '-' }}
                </el-descriptions-item>
                <el-descriptions-item label="用户备注" :span="2">
                    {{ detailDialog.data.remark || '-' }}
                </el-descriptions-item>
                <el-descriptions-item label="审核备注" :span="2">
                    {{ detailDialog.data.review_remark || '-' }}
                </el-descriptions-item>
            </el-descriptions>

            <!-- 凭证图片 -->
            <el-divider v-if="detailDialog.data && detailDialog.data.images_array && detailDialog.data.images_array.length > 0" content-position="left">
                <span style="font-size: 16px; font-weight: bold;">凭证图片</span>
            </el-divider>
            <div v-if="detailDialog.data && detailDialog.data.images_array && detailDialog.data.images_array.length > 0" class="images-container">
                <el-image
                    v-for="(image, index) in detailDialog.data.images_array"
                    :key="index"
                    :src="image"
                    :preview-src-list="detailDialog.data.images_array"
                    :preview-teleported="true"
                    class="proof-image"
                    fit="cover"
                />
            </div>
        </el-dialog>
    </div>
</template>

<script setup lang="ts">
import { provide, ref, computed } from 'vue'
import baTableClass from '/@/utils/baTable'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import { baTableApi } from '/@/api/common'
import createAxios from '/@/utils/axios'
import { ElMessage, ElMessageBox } from 'element-plus'
import Icon from '/@/components/icon/index.vue'

defineOptions({
    name: 'finance/rightsDeclarationReview',
})

// 审核对话框
const auditDialog = ref({
    visible: false,
    title: '',
    type: '', // 'approve' 或 'reject'
    loading: false,
    form: {
        id: 0,
        user_info: '',
        amount: '',
        voucher_type_text: '',
        rewards: ['balance'], // 默认只选可用金额
        audit_remark: '',
    },
})

// 详情对话框
const detailDialog = ref({
    visible: false,
    data: null as any,
})

// 凭证类型映射
const voucherTypeMap = {
    screenshot: '截图',
    transfer_record: '转账记录',
    other: '其他凭证',
}

// 状态映射
const statusMap = {
    pending: '待审核',
    approved: '已通过',
    rejected: '已拒绝',
    cancelled: '已撤销',
}

// 格式化金额
const formatAmount = (amount: number | string) => {
    const num = Number(amount)
    return Number.isNaN(num) ? '0.00' : num.toFixed(2) + ' 元'
}

// 格式化时间
const formatTime = (timestamp: number | string) => {
    if (!timestamp) return '-'
    const num = Number(timestamp)
    if (Number.isNaN(num) || num <= 0) return '-'
    const date = new Date(num * 1000)
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')} ${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}:${String(date.getSeconds()).padStart(2, '0')}`
}

// 状态标签类型
const statusTag = (status: string): 'success' | 'info' | 'warning' | 'danger' => {
    const tagMap: Record<string, 'success' | 'info' | 'warning' | 'danger'> = {
        pending: 'warning',
        approved: 'success',
        rejected: 'danger',
        cancelled: 'info',
    }
    return tagMap[status] || 'info'
}

// 打开审核对话框
const openAuditDialog = (row: any, type: 'approve' | 'reject') => {
    auditDialog.value = {
        visible: true,
        title: type === 'approve' ? '审核通过' : '审核拒绝',
        type,
        loading: false,
        form: {
            id: row.id,
            user_info: `${row.user_nickname} (${row.user_mobile})`,
            amount: row.amount,
            voucher_type_text: voucherTypeMap[row.voucher_type as keyof typeof voucherTypeMap] || '未知',
            rewards: type === 'approve' ? ['balance'] : [], // 通过时默认只选可用金额
            audit_remark: '',
        },
    }
}

// 提交审核
const submitAudit = async () => {
    if (auditDialog.value.type === 'reject' && !auditDialog.value.form.audit_remark.trim()) {
        ElMessage.error('请填写拒绝原因')
        return
    }

    if (auditDialog.value.type === 'approve' && (!auditDialog.value.form.rewards || auditDialog.value.form.rewards.length === 0)) {
        ElMessage.error('请至少选择一种奖励')
        return
    }

    auditDialog.value.loading = true
    try {
        const action = auditDialog.value.type === 'approve' ? 'approve' : 'reject'
        const data: any = {
            id: auditDialog.value.form.id,
            audit_remark: auditDialog.value.form.audit_remark,
        }

        // 通过审核时，添加选中的奖励
        if (action === 'approve') {
            data.rewards = auditDialog.value.form.rewards
        }

        const res = await createAxios({
            url: `/admin/finance.RightsDeclarationReview/${action}`,
            method: 'post',
            data,
        })

        if (res.code === 1) {
            ElMessage.success(res.msg || '操作成功')
            auditDialog.value.visible = false
            baTable.getData()
        } else {
            ElMessage.error(res.msg || '操作失败')
        }
    } catch (error: any) {
        console.error('审核失败:', error)
        ElMessage.error(error?.msg || error?.response?.data?.msg || '操作失败，请稍后重试')
    } finally {
        auditDialog.value.loading = false
    }
}

// 查看详情
const viewDetail = async (row: any) => {
    try {
        const res = await createAxios({
            url: '/admin/finance.RightsDeclarationReview/detail',
            method: 'get',
            params: { id: row.id },
        })

        if (res.code === 1) {
            const row = res.data.row
            // 扁平化用户信息字段
            row.user_nickname = row.user?.nickname || ''
            row.user_mobile = row.user?.mobile || ''
            detailDialog.value.data = row
            detailDialog.value.visible = true
        } else {
            ElMessage.error(res.msg || '获取详情失败')
        }
    } catch (error: any) {
        console.error('获取详情失败:', error)
        ElMessage.error(error?.msg || error?.response?.data?.msg || '获取详情失败')
    }
}

const baTable = new baTableClass(
    new baTableApi('/admin/finance.RightsDeclarationReview/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', operator: '=', width: 80 },
            {
                label: '用户昵称',
                prop: 'user_nickname',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '用户昵称',
                render: 'tag',
            },
            {
                label: '手机号',
                prop: 'user_mobile',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '手机号',
                render: 'tag',
            },
            {
                label: '申请金额',
                prop: 'amount',
                align: 'center',
                operator: '=',
                width: 120,
                formatter: (row: any) => formatAmount(row.amount),
            },
            {
                label: '凭证类型',
                prop: 'voucher_type',
                align: 'center',
                operator: '=',
                render: 'tag',
                custom: {
                    screenshot: 'primary',
                    transfer_record: 'success',
                    other: 'warning',
                },
                replaceValue: voucherTypeMap,
            },
            {
                label: '状态',
                prop: 'status',
                align: 'center',
                operator: '=',
                render: 'tag',
                custom: {
                    pending: 'warning',
                    approved: 'success',
                    rejected: 'danger',
                    cancelled: 'info',
                },
                replaceValue: statusMap,
            },
            {
                label: '申请时间',
                prop: 'create_time',
                align: 'center',
                render: 'datetime',
                operator: 'RANGE',
                width: 170,
            },
            {
                label: '审核时间',
                prop: 'review_time',
                align: 'center',
                render: 'datetime',
                width: 170,
                formatter: (row: any) => row.review_time ? formatTime(row.review_time) : '-',
            },
            {
                label: '操作',
                align: 'center',
                width: 200,
                render: 'buttons',
                buttons: ((row: any) => {
                    const buttons: any[] = []
                    buttons.push({
                        name: 'detail',
                        text: '详情',
                        type: 'primary',
                        icon: 'fa fa-info-circle',
                        render: 'basicButton',
                        click: () => viewDetail(row),
                    })
                    if (row.status === 'pending') {
                        buttons.push({
                            name: 'approve',
                            text: '通过',
                            type: 'success',
                            icon: 'fa fa-check',
                            render: 'basicButton',
                            click: () => openAuditDialog(row, 'approve'),
                        })
                        buttons.push({
                            name: 'reject',
                            text: '拒绝',
                            type: 'danger',
                            icon: 'fa fa-times',
                            render: 'basicButton',
                            click: () => openAuditDialog(row, 'reject'),
                        })
                    }
                    return buttons
                }) as any,
                operator: false,
            },
        ],
    }
)

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

<style scoped lang="scss">
.amount-text {
    font-weight: bold;
    color: #e6a23c;
    font-size: 16px;
}

.images-container {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    margin-top: 16px;
}

.proof-image {
    width: 120px;
    height: 120px;
    border-radius: 8px;
    cursor: pointer;
    transition: transform 0.2s;

    &:hover {
        transform: scale(1.05);
    }
}
</style>
