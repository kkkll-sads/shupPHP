<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <TableHeader
            :buttons="['refresh', 'comSearch', 'quickSearch', 'columnDisplay']"
            quick-search-placeholder="快速搜索：订单号/ID/手机号"
        />

        <Table />

        <!-- 审核对话框 -->
        <el-dialog
            v-model="auditDialog.visible"
            :title="auditDialog.title"
            width="600px"
            :close-on-click-modal="false"
        >
            <el-form :model="auditDialog.form" label-width="120px">
                <el-form-item label="订单号">
                    <el-input v-model="auditDialog.form.order_no" disabled />
                </el-form-item>
                <el-form-item label="用户ID">
                    <el-input v-model="auditDialog.form.user_id" disabled />
                </el-form-item>
                <el-form-item label="充值金额">
                    <el-input v-model="auditDialog.form.amount" disabled>
                        <template #append>元</template>
                    </el-input>
                </el-form-item>
                <el-form-item label="支付方式">
                    <el-input v-model="auditDialog.form.payment_type_text" disabled />
                </el-form-item>
                <el-form-item label="付款截图" v-if="auditDialog.form.payment_screenshot">
                    <el-image
                        :src="fullUrl(auditDialog.form.payment_screenshot)"
                        style="width: 300px; height: auto;"
                        :preview-src-list="[fullUrl(auditDialog.form.payment_screenshot)]"
                        fit="contain"
                    />
                </el-form-item>
                <el-form-item label="审核备注" v-if="auditDialog.type === 'reject'">
                    <el-input
                        v-model="auditDialog.form.audit_remark"
                        type="textarea"
                        :rows="4"
                        placeholder="请输入拒绝原因"
                    />
                </el-form-item>
                <el-form-item label="审核备注" v-else>
                    <el-input
                        v-model="auditDialog.form.audit_remark"
                        type="textarea"
                        :rows="4"
                        placeholder="请输入审核备注（可选）"
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

        <!-- 查看详情对话框 -->
        <el-dialog
            v-model="detailDialog.visible"
            title="充值订单详情"
            width="800px"
            :close-on-click-modal="false"
        >
            <el-descriptions :column="2" border v-if="detailDialog.data">
                <el-descriptions-item label="订单号">{{ detailDialog.data.order_no }}</el-descriptions-item>
                <el-descriptions-item label="订单状态">
                    <el-tag :type="detailDialog.data.status_color">
                        {{ detailDialog.data.status_text }}
                    </el-tag>
                </el-descriptions-item>
                <el-descriptions-item label="用户ID">{{ detailDialog.data.user_id }}</el-descriptions-item>
                <el-descriptions-item label="充值金额">¥{{ detailDialog.data.amount }}</el-descriptions-item>
                <el-descriptions-item label="支付方式">{{ detailDialog.data.payment_type_text }}</el-descriptions-item>
                <el-descriptions-item label="支付通道">{{ detailDialog.data.payment_channel || '-' }}</el-descriptions-item>
                <el-descriptions-item label="用户手机号">{{ detailDialog.data.user?.mobile || '-' }}</el-descriptions-item>
                <el-descriptions-item label="创建时间">{{ detailDialog.data.create_time_text }}</el-descriptions-item>
                <el-descriptions-item label="审核时间" v-if="detailDialog.data.audit_time_text">
                    {{ detailDialog.data.audit_time_text }}
                </el-descriptions-item>
                <el-descriptions-item label="审核备注" v-if="detailDialog.data.audit_remark">
                    {{ detailDialog.data.audit_remark }}
                </el-descriptions-item>
                <el-descriptions-item label="付款截图" :span="2" v-if="detailDialog.data.payment_screenshot">
                    <el-image
                        :src="fullUrl(detailDialog.data.payment_screenshot)"
                        style="width: 400px; height: auto;"
                        :preview-src-list="[fullUrl(detailDialog.data.payment_screenshot)]"
                        fit="contain"
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
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import { baTableApi } from '/@/api/common'
import createAxios from '/@/utils/axios'
import { auth, fullUrl } from '/@/utils/common'
import { ElMessage } from 'element-plus'

defineOptions({
    name: 'finance/rechargeOrder',
})

const baTable = new baTableClass(
    new baTableApi('/admin/finance.RechargeOrder/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', operator: '=', operatorPlaceholder: 'ID', width: 70 },
            {
                label: '订单号',
                prop: 'order_no',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '模糊查询',
            },
            {
                label: '用户ID',
                prop: 'user_id',
                align: 'center',
                operator: '=',
                operatorPlaceholder: '用户ID',
                width: 100,
            },
            {
                label: '充值金额',
                prop: 'amount',
                align: 'center',
                operator: 'RANGE',
                width: 120,
                render: ((row: any) => {
                    return '¥' + parseFloat(row.amount).toFixed(2)
                }) as any,
            } as any,
            {
                label: '支付方式',
                prop: 'payment_type_text',
                align: 'center',
                operator: 'select' as any,
                operatorOptions: [
                    { label: '银行卡', value: 'bank_card' },
                    { label: '支付宝', value: 'alipay' },
                    { label: '微信', value: 'wechat' },
                    { label: 'USDT', value: 'usdt' },
                    { label: '线上支付', value: 'online' },
                ] as any,
                width: 100,
            } as any,
            {
                label: '用户手机号',
                prop: 'user.mobile',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '手机号',
                width: 130,
            },
            {
                label: '支付通道',
                prop: 'payment_channel',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '通道名称',
                width: 150,
            },
            {
                label: '订单状态',
                prop: 'status_text',
                align: 'center',
                operator: 'select' as any,
                operatorOptions: [
                    { label: '待审核', value: '0' },
                    { label: '已通过', value: '1' },
                    { label: '已拒绝', value: '2' },
                ] as any,
                render: 'tag',
                custom: {
                    '待审核': 'warning',
                    '已通过': 'success',
                    '已拒绝': 'danger',
                    '待支付': 'primary',
                    '线上支付已到账': 'success'
                },
                width: 100,
            } as any,
            {
                label: '创建时间',
                prop: 'create_time',
                align: 'center',
                render: 'datetime',
                sortable: 'custom',
                operator: 'RANGE',
                width: 160,
            },
            {
                label: '操作',
                align: 'center',
                width: '200',
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
                    // 通过按钮：待审核状态可以审核通过，但线上支付自动到账的不显示
                    if (row.status == 0 && !(row.payment_type === 'online' && row.audit_remark === '线上支付自动到账')) {
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
                                showAuditDialog(row, 'approve')
                            },
                        })
                    }
                    // 拒绝按钮：待审核状态可以拒绝，但线上支付订单不显示
                    if (row.status == 0 && row.payment_type !== 'online') {
                        buttons.push({
                            render: 'tipButton',
                            name: 'reject',
                            title: '审核拒绝',
                            text: '',
                            type: 'warning',
                            icon: 'fa fa-times',
                            class: 'table-row-reject',
                            display: () => auth('reject'),
                            click: () => {
                                showAuditDialog(row, 'reject')
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

// 审核对话框
const auditDialog = reactive({
    visible: false,
    loading: false,
    type: 'approve' as 'approve' | 'reject',
    title: '',
    form: {
        id: 0,
        order_no: '',
        user_id: '',
        amount: '',
        payment_type_text: '',
        payment_screenshot: '',
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
        user_id: row.user_id,
        amount: row.amount,
        payment_type_text: row.payment_type_text || '',
        payment_screenshot: row.payment_screenshot || '',
        audit_remark: '',
    }
    auditDialog.visible = true
}

// 提交审核
const submitAudit = async () => {
    if (auditDialog.type === 'reject' && !auditDialog.form.audit_remark.trim()) {
        ElMessage.warning('拒绝原因不能为空')
        return
    }

    auditDialog.loading = true
    try {
        const api = auditDialog.type === 'approve' ? '/admin/finance.RechargeOrder/approve' : '/admin/finance.RechargeOrder/reject'
        const response = await createAxios({
            url: api,
            method: 'post',
            data: {
                id: auditDialog.form.id,
                audit_remark: auditDialog.form.audit_remark,
            },
        })

        if (response.code == 1) {
            ElMessage.success(response.msg)
            auditDialog.visible = false
            baTable.getData()
        } else {
            ElMessage.error(response.msg)
        }
    } catch (error: any) {
        ElMessage.error(error.message || '操作失败')
    } finally {
        auditDialog.loading = false
    }
}

// 显示详情
const showDetail = async (row: any) => {
    try {
        const response = await createAxios({
            url: '/admin/finance.RechargeOrder/read',
            method: 'get',
            params: { id: row.id },
        })
        if (response.code == 1) {
            detailDialog.data = response.data.row
            detailDialog.visible = true
        } else {
            ElMessage.error(response.msg)
        }
    } catch (error: any) {
        ElMessage.error(error.message || '获取详情失败')
    }
}

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

<style scoped lang="scss"></style>

