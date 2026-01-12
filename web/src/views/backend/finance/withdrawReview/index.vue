<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <div style="margin-bottom: 13px; display: flex; align-items: center; gap: 10px;">
            <div style="font-size: 14px; color: #606266;">收款信息：</div>
            <el-input 
                v-model="state.paymentSearch" 
                placeholder="搜索收款姓名/账号/银行" 
                style="width: 250px" 
                clearable 
                @keyup.enter="onSearch"
                @clear="onSearch"
            />
            <el-button type="primary" @click="onSearch">搜索</el-button>
        </div>

        <el-row :gutter="20" style="margin-bottom: 20px">
            <el-col :xs="12" :sm="6">
                <el-card shadow="never" body-style="padding: 15px;">
                    <div style="font-size: 14px; color: #909399;">总提现金额</div>
                    <div style="font-size: 20px; font-weight: bold; margin-top: 5px;">¥{{ stats.total_amount }}</div>
                </el-card>
            </el-col>
            <el-col :xs="12" :sm="6">
                <el-card shadow="never" body-style="padding: 15px;">
                    <div style="font-size: 14px; color: #909399;">待审核金额</div>
                    <div style="font-size: 20px; font-weight: bold; margin-top: 5px; color: #e6a23c;">¥{{ stats.pending_amount }}</div>
                </el-card>
            </el-col>
            <el-col :xs="12" :sm="6">
                <el-card shadow="never" body-style="padding: 15px;">
                    <div style="font-size: 14px; color: #909399;">已通过金额</div>
                    <div style="font-size: 20px; font-weight: bold; margin-top: 5px; color: #67c23a;">¥{{ stats.approved_amount }}</div>
                </el-card>
            </el-col>
            <el-col :xs="12" :sm="6">
                <el-card shadow="never" body-style="padding: 15px;">
                    <div style="font-size: 14px; color: #909399;">已拒绝金额</div>
                    <div style="font-size: 20px; font-weight: bold; margin-top: 5px; color: #f56c6c;">¥{{ stats.rejected_amount }}</div>
                </el-card>
            </el-col>
        </el-row>

        <TableHeader
            :buttons="['refresh', 'export', 'add', 'edit', 'delete', 'comSearch', 'quickSearch', 'columnDisplay']"
            quick-search-placeholder="快速搜索：申请方名称/类型"
        />

        <Table />

        <PopupForm />

        <el-dialog
            v-model="auditDialog.visible"
            :title="auditDialog.title"
            width="520px"
            :close-on-click-modal="false"
        >
            <el-form :model="auditDialog.form" label-width="120px">
                <el-form-item label="申请方">
                    <el-input v-model="auditDialog.form.applicant" disabled />
                </el-form-item>
                <el-form-item label="提现金额">
                    <el-input v-model="auditDialog.form.amount" disabled>
                        <template #append>元</template>
                    </el-input>
                </el-form-item>
                <el-form-item label="审核备注">
                    <el-input
                        v-model="auditDialog.form.audit_remark"
                        type="textarea"
                        :rows="4"
                        :placeholder="auditDialog.type === 'approve' ? '可以填写审核备注（选填）' : '请输入拒绝原因'"
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

        <el-dialog
            v-model="detailDialog.visible"
            title="提现审核详情"
            width="720px"
            :close-on-click-modal="false"
        >
            <el-descriptions :column="2" border v-if="detailDialog.data">
                <el-descriptions-item label="申请方">{{ detailDialog.data.applicant_name }}</el-descriptions-item>
                <el-descriptions-item label="申请类型">{{ detailDialog.data.applicant_type_text }}</el-descriptions-item>
                <el-descriptions-item label="申请方ID">{{ detailDialog.data.applicant_id || '-' }}</el-descriptions-item>
                <el-descriptions-item label="手机号">{{ detailDialog.data.applicant_mobile || '-' }}</el-descriptions-item>
                <el-descriptions-item label="收款方式">{{ detailDialog.data.type_text || '-' }}</el-descriptions-item>
                <el-descriptions-item label="提现金额">
                    {{ formatAmount(detailDialog.data.amount) }}
                </el-descriptions-item>
                <el-descriptions-item label="手续费">
                    {{ formatAmount(detailDialog.data.fee) }}
                </el-descriptions-item>
                <el-descriptions-item label="实际到账">
                    {{ formatAmount(detailDialog.data.actual_amount) }}
                </el-descriptions-item>
                <el-descriptions-item label="状态">
                    <el-tag :type="statusTag(detailDialog.data.status)">
                        {{ detailDialog.data.status_text }}
                    </el-tag>
                </el-descriptions-item>
                <el-descriptions-item label="申请时间">{{ formatTime(detailDialog.data.create_time) }}</el-descriptions-item>
                <el-descriptions-item label="审核时间">
                    {{ detailDialog.data.audit_time_text || '-' }}
                </el-descriptions-item>
                <el-descriptions-item label="审核人">
                    {{ detailDialog.data.audit_admin_name || '-' }}
                </el-descriptions-item>
                <el-descriptions-item label="申请说明" :span="2">
                    {{ detailDialog.data.apply_reason || '-' }}
                </el-descriptions-item>
                <el-descriptions-item label="审核备注" :span="2">
                    {{ detailDialog.data.audit_remark || '-' }}
                </el-descriptions-item>
            </el-descriptions>
            
            <!-- 收款信息 -->
            <el-divider v-if="detailDialog.data && detailDialog.data.payment_info" content-position="left">
                <span style="font-size: 16px; font-weight: bold;">收款信息</span>
            </el-divider>
            <el-descriptions :column="2" border v-if="detailDialog.data && detailDialog.data.payment_info">
                <el-descriptions-item label="收款方式">
                    {{ detailDialog.data.payment_info.type_text }}
                </el-descriptions-item>
                <el-descriptions-item label="账户类型">
                    {{ detailDialog.data.payment_info.account_type_text }}
                </el-descriptions-item>
                <el-descriptions-item label="账户姓名">
                    {{ detailDialog.data.payment_info.account_name || '-' }}
                </el-descriptions-item>
                <el-descriptions-item label="账号/卡号" v-if="detailDialog.data.payment_info.account_number">
                    {{ detailDialog.data.payment_info.account_number }}
                </el-descriptions-item>
                <el-descriptions-item label="开户银行" v-if="detailDialog.data.payment_info.bank_name">
                    {{ detailDialog.data.payment_info.bank_name }}
                </el-descriptions-item>
                <el-descriptions-item label="开户支行" v-if="detailDialog.data.payment_info.bank_branch">
                    {{ detailDialog.data.payment_info.bank_branch }}
                </el-descriptions-item>
                <el-descriptions-item label="微信收款码" :span="2" v-if="detailDialog.data.payment_info.type === 'wechat' && detailDialog.data.payment_info.screenshot">
                    <el-image
                        :src="fullUrl(detailDialog.data.payment_info.screenshot)"
                        style="width: 200px; height: auto; max-height: 300px; cursor: pointer;"
                        :preview-src-list="[fullUrl(detailDialog.data.payment_info.screenshot)]"
                        fit="contain"
                        preview-teleported
                    />
                </el-descriptions-item>
            </el-descriptions>
            <template #footer>
                <el-button @click="detailDialog.visible = false">关闭</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup lang="ts">
import { provide, reactive } from 'vue'
import baTableClass from '/@/utils/baTable'
import PopupForm from './popupForm.vue'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import { baTableApi } from '/@/api/common'
import createAxios from '/@/utils/axios'
import { auth, fullUrl } from '/@/utils/common'
import { ElMessage } from 'element-plus'

defineOptions({
    name: 'finance/withdrawReview',
})

const statusReplace = { '0': '待审核', '1': '审核通过', '2': '审核拒绝' }
const statusColors = { '0': 'warning', '1': 'success', '2': 'danger' }

const state = reactive({
    paymentSearch: '',
})

const stats = reactive({
    total_amount: '0.00',
    pending_amount: '0.00',
    approved_amount: '0.00',
    rejected_amount: '0.00',
})

const getStats = async () => {
    try {
        const res = await createAxios({
            url: '/admin/finance.WithdrawReview/stats',
            method: 'get',
        })
        Object.assign(stats, res.data.stats)
    } catch (e) {}
}

getStats()

const onSearch = () => {
    baTable.table.filter!.payment_search = state.paymentSearch
    baTable.onTableHeaderAction('refresh', {})
}

const baTable = new baTableClass(
    new baTableApi('/admin/finance.WithdrawReview/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', operator: '=', width: 70 },
            {
                label: '申请方',
                prop: 'applicant_name',
                align: 'center',
                operator: 'LIKE',
            },
            {
                label: '申请类型',
                prop: 'applicant_type_text',
                align: 'center',
                operator: 'select' as any,
                operatorOptions: [
                    { label: '用户', value: 'user' },
                    { label: '公司', value: 'company' },
                    { label: '合作方', value: 'partner' },
                ] as any,
                replaceValue: { user: '用户', company: '公司', partner: '合作方' },
                width: 100,
            },
            {
                label: '申请方ID',
                prop: 'applicant_id',
                align: 'center',
                operator: '=',
                width: 110,
            },
            {
                label: '手机号',
                prop: 'applicant_mobile',
                align: 'center',
                operator: 'LIKE',
                width: 130,
            },
            {
                label: '收款方式',
                prop: 'type_text',
                align: 'center',
                operator: 'select' as any,
                operatorOptions: [
                    { label: '微信', value: '微信' },
                    { label: '支付宝', value: '支付宝' },
                    { label: '银行卡', value: '银行卡' },
                    { label: 'USDT', value: 'USDT' },
                ] as any,
                replaceValue: { 微信: '微信', 支付宝: '支付宝', 银行卡: '银行卡', USDT: 'USDT' },
                width: 100,
            },
            {
                label: '收款姓名',
                prop: 'account_name',
                align: 'center',
                operator: 'LIKE',
                width: 100,
            },
            {
                label: '收款账号',
                prop: 'account_number',
                align: 'center',
                operator: 'LIKE',
                width: 180,
            },
            {
                label: '开户行',
                prop: 'bank_name',
                align: 'center',
                operator: 'LIKE',
                width: 150,
            },
            {
                label: '提现金额',
                prop: 'amount',
                align: 'center',
                operator: 'RANGE',
                width: 120,
                render: ((row: any) => {
                    const value = row.amount
                    if (value === null || value === undefined || value === '') {
                        return '-'
                    }
                    const num = parseFloat(value)
                    if (isNaN(num)) {
                        return '-'
                    }
                    return '¥' + num.toFixed(2)
                }) as any,
            } as any,
            {
                label: '手续费',
                prop: 'fee',
                align: 'center',
                operator: false,
                width: 100,
                render: ((row: any) => {
                    const value = row.fee
                    if (value === null || value === undefined || value === '') {
                        return '-'
                    }
                    const num = parseFloat(value)
                    if (isNaN(num)) {
                        return '-'
                    }
                    return '¥' + num.toFixed(2)
                }) as any,
            } as any,
            {
                label: '实际到账',
                prop: 'actual_amount',
                align: 'center',
                operator: 'RANGE',
                width: 120,
                render: ((row: any) => {
                    const value = row.actual_amount
                    if (value === null || value === undefined || value === '') {
                        return '-'
                    }
                    const num = parseFloat(value)
                    if (isNaN(num)) {
                        return '-'
                    }
                    return '¥' + num.toFixed(2)
                }) as any,
            } as any,
            {
                label: '状态',
                prop: 'status',
                align: 'center',
                operator: 'select' as any,
                operatorOptions: [
                    { label: '待审核', value: '0' },
                    { label: '审核通过', value: '1' },
                    { label: '审核拒绝', value: '2' },
                ] as any,
                replaceValue: statusReplace,
                render: 'tag',
                custom: statusColors,
                width: 110,
            },
            {
                label: '审核时间',
                prop: 'audit_time',
                align: 'center',
                operator: 'RANGE',
                render: 'datetime',
                width: 160,
            },
            {
                label: '创建时间',
                prop: 'create_time',
                align: 'center',
                operator: 'RANGE',
                render: 'datetime',
                width: 160,
            },
            {
                label: '操作',
                align: 'center',
                width: 220,
                render: 'buttons',
                buttons: ((row: any) => {
                    const buttons: any[] = []
                    buttons.push({
                        render: 'tipButton',
                        name: 'read',
                        title: '查看详情',
                        text: '',
                        type: 'primary',
                        icon: 'fa fa-eye',
                        class: 'table-row-read',
                        display: () => auth('read'),
                        click: () => {
                            showDetail(row)
                        },
                    })
                    if (row.status == 0) {
                        buttons.push({
                            render: 'tipButton',
                            name: 'approve',
                            title: '审核通过',
                            text: '',
                            type: 'success',
                            icon: 'fa fa-check',
                            class: 'table-row-approve',
                            display: () => auth('approve'),
                            click: () => {
                                showAudit(row, 'approve')
                            },
                        })
                        buttons.push({
                            render: 'tipButton',
                            name: 'reject',
                            title: '审核拒绝',
                            text: '',
                            type: 'danger',
                            icon: 'fa fa-times',
                            class: 'table-row-reject',
                            display: () => auth('reject'),
                            click: () => {
                                showAudit(row, 'reject')
                            },
                        })
                    }
                    return buttons
                }) as any,
                operator: false,
            },
        ],
        dblClickNotEditColumn: [undefined],
    }
)

const auditDialog = reactive({
    visible: false,
    loading: false,
    type: 'approve' as 'approve' | 'reject',
    title: '',
    form: {
        id: 0,
        applicant: '',
        amount: '',
        audit_remark: '',
    },
})

const detailDialog = reactive({
    visible: false,
    data: null as any,
})

const showAudit = (row: any, type: 'approve' | 'reject') => {
    auditDialog.type = type
    auditDialog.title = type === 'approve' ? '审核通过' : '审核拒绝'
    const amount = row.amount ? parseFloat(row.amount).toFixed(2) : '0.00'
    auditDialog.form = {
        id: row.id,
        applicant: `${row.applicant_name} (${row.applicant_type_text || ''})`,
        amount: amount,
        audit_remark: '',
    }
    auditDialog.visible = true
}

const submitAudit = async () => {
    if (auditDialog.type === 'reject' && !auditDialog.form.audit_remark.trim()) {
        ElMessage.warning('拒绝原因不能为空')
        return
    }
    auditDialog.loading = true
    try {
        const api =
            auditDialog.type === 'approve'
                ? '/admin/finance.WithdrawReview/approve'
                : '/admin/finance.WithdrawReview/reject'
        const response = await createAxios({
            url: api,
            method: 'post',
            data: {
                id: auditDialog.form.id,
                audit_remark: auditDialog.form.audit_remark,
            },
        })
        // 如果 code == 1，说明请求成功（axios 拦截器不会 reject）
        ElMessage.success(response.msg || '操作成功')
        auditDialog.visible = false
        baTable.getData()
    } catch (error: any) {
        // axios 拦截器在 code !== 1 时会 reject，error 是 response.data，包含 { code, msg, data, time }
        // 如果是业务错误（code !== 1），axios 拦截器默认已显示错误通知（showCodeMessage: true）
        // 如果是网络错误，error 可能没有 msg 属性，使用 error.message
        // 这里只处理网络错误等异常情况，业务错误已由拦截器处理
        if (!error.msg && error.message) {
            ElMessage.error(error.message || '操作失败')
        }
        // 如果 error.msg 存在，说明是业务错误，拦截器已处理，不需要重复显示
    } finally {
        auditDialog.loading = false
    }
}

const showDetail = async (row: any) => {
    try {
        const response = await createAxios({
            url: '/admin/finance.WithdrawReview/read',
            method: 'get',
            params: { id: row.id },
        })
        // 如果 code == 1，说明请求成功（axios 拦截器不会 reject）
        detailDialog.data = response.data.row
        detailDialog.visible = true
    } catch (error: any) {
        // axios 拦截器在 code !== 1 时会 reject，error 是 response.data，包含 { code, msg, data, time }
        // 如果是业务错误（code !== 1），axios 拦截器默认已显示错误通知（showCodeMessage: true）
        // 如果是网络错误，error 可能没有 msg 属性，使用 error.message
        // 这里只处理网络错误等异常情况，业务错误已由拦截器处理
        if (!error.msg && error.message) {
            ElMessage.error(error.message || '获取详情失败')
        }
        // 如果 error.msg 存在，说明是业务错误，拦截器已处理，不需要重复显示
    }
}

const statusTag = (value: number): 'warning' | 'success' | 'danger' | 'info' => {
    const color = statusColors[String(value) as keyof typeof statusColors]
    return (color as 'warning' | 'success' | 'danger' | 'info') || 'info'
}

const formatAmount = (value: any) => {
    if (value === null || value === undefined || value === '') {
        return '-'
    }
    const num = parseFloat(value)
    if (isNaN(num)) {
        return '-'
    }
    return '¥' + num.toFixed(2)
}

const formatTime = (value: number) => {
    if (!value) return '-'
    const date = new Date(value * 1000)
    const y = date.getFullYear()
    const m = (date.getMonth() + 1).toString().padStart(2, '0')
    const d = date.getDate().toString().padStart(2, '0')
    const h = date.getHours().toString().padStart(2, '0')
    const i = date.getMinutes().toString().padStart(2, '0')
    const s = date.getSeconds().toString().padStart(2, '0')
    return `${y}-${m}-${d} ${h}:${i}:${s}`
}

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

<style scoped lang="scss"></style>


