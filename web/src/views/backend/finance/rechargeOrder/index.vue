<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <!-- 统计卡片 -->
        <el-row :gutter="16" style="margin-bottom: 15px;">
            <el-col :span="4">
                <el-card shadow="hover" class="stat-card">
                    <el-statistic title="订单总数" :value="stats.total || 0" />
                </el-card>
            </el-col>
            <el-col :span="4">
                <el-card shadow="hover" class="stat-card">
                    <el-statistic title="待审核" :value="stats.status?.[0]?.count || 0" value-style="color: #e6a23c" />
                </el-card>
            </el-col>
            <el-col :span="4">
                <el-card shadow="hover" class="stat-card">
                    <el-statistic title="已通过金额" :value="stats.total_approved_amount || 0" :precision="2" prefix="¥" value-style="color: #67c23a" />
                </el-card>
            </el-col>
            <el-col :span="4">
                <el-card shadow="hover" class="stat-card">
                    <el-statistic title="今日新增" :value="stats.today_new || 0" />
                </el-card>
            </el-col>
            <el-col :span="4">
                <el-card shadow="hover" class="stat-card">
                    <el-statistic title="今日审核" :value="stats.today_approved || 0" value-style="color: #67c23a" />
                </el-card>
            </el-col>
            <el-col :span="4">
                <el-card shadow="hover" class="stat-card">
                    <el-statistic title="今日到账" :value="stats.today_approved_amount || 0" :precision="2" prefix="¥" value-style="color: #67c23a" />
                </el-card>
            </el-col>
        </el-row>

        <!-- 银行通道统计表格 -->
        <el-card shadow="never" style="margin-bottom: 15px;" v-if="stats.banks?.length > 0">
            <template #header>
                <span style="font-weight: bold;">支付通道统计 (TOP 20)</span>
            </template>
            <el-table :data="stats.banks" stripe size="small" max-height="300">
                <el-table-column prop="bank_name" label="通道名称" min-width="100" show-overflow-tooltip>
                    <template #default="{ row }">
                        <el-link type="primary" @click="filterByBank(row.bank_name)" :underline="false">
                            {{ row.bank_name }}
                        </el-link>
                    </template>
                </el-table-column>
                <el-table-column prop="today_count" label="今日订单" align="center" width="75">
                    <template #default="{ row }">
                        <span style="color: #409eff; font-weight: bold;">{{ row.today_count || 0 }}</span>
                    </template>
                </el-table-column>
                <el-table-column prop="today_amount" label="今日金额" align="center" width="90">
                    <template #default="{ row }">
                        <span style="color: #409eff;">¥{{ row.today_amount || 0 }}</span>
                    </template>
                </el-table-column>
                <el-table-column prop="today_approved_count" label="今日到账" align="center" width="75">
                    <template #default="{ row }">
                        <span style="color: #67c23a; font-weight: bold;">{{ row.today_approved_count || 0 }}</span>
                    </template>
                </el-table-column>
                <el-table-column prop="today_approved_amount" label="今日到账额" align="center" width="90">
                    <template #default="{ row }">
                        <span style="color: #67c23a; font-weight: bold;">¥{{ row.today_approved_amount || 0 }}</span>
                    </template>
                </el-table-column>
                <el-table-column prop="pending_count" label="待审核" align="center" width="70">
                    <template #default="{ row }">
                        <el-tag type="warning" size="small">{{ row.pending_count || 0 }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="approved_count" label="累计通过" align="center" width="75">
                    <template #default="{ row }">
                        <el-tag type="success" size="small">{{ row.approved_count || 0 }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="total_amount" label="累计总额" align="center" width="100">
                    <template #default="{ row }">
                        <span>¥{{ row.total_amount || 0 }}</span>
                    </template>
                </el-table-column>
                <el-table-column prop="approved_amount" label="累计到账" align="center" width="90">
                    <template #default="{ row }">
                        <span style="color: #67c23a;">¥{{ row.approved_amount || 0 }}</span>
                    </template>
                </el-table-column>
                <el-table-column prop="approval_rate" label="通过率" align="center" width="65">
                    <template #default="{ row }">
                        <span :style="{ color: row.approval_rate > 50 ? '#67c23a' : row.approval_rate > 20 ? '#e6a23c' : '#f56c6c' }">
                            {{ row.approval_rate || 0 }}%
                        </span>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>

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
                <el-form-item label="用户备注" v-if="auditDialog.form.user_remark">
                    <el-input v-model="auditDialog.form.user_remark" type="textarea" :rows="3" disabled />
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
                <el-descriptions-item label="用户备注" v-if="detailDialog.data.user_remark">
                    {{ detailDialog.data.user_remark }}
                </el-descriptions-item>
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
                <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                    <div>
                        <el-button-group>
                            <el-button :disabled="isFirst" @click="switchDetail('prev')" icon="ArrowLeft">上一条</el-button>
                            <el-button :disabled="isLast" @click="switchDetail('next')">
                                下一条<el-icon class="el-icon--right"><ArrowRight /></el-icon>
                            </el-button>
                        </el-button-group>
                    </div>
                    <div>
                        <!-- 待审核状态显示操作按钮（线上支付自动到账的除外） -->
                        <template v-if="detailDialog.data && detailDialog.data.status == 0 && !(detailDialog.data.payment_type === 'online' && detailDialog.data.audit_remark === '线上支付自动到账')">
                            <el-button 
                                type="success" 
                                @click="handleDetailApprove"
                                v-if="auth('approve')"
                            >
                                <el-icon><Check /></el-icon>
                                审核通过
                            </el-button>
                            <el-button 
                                type="warning" 
                                @click="handleDetailReject"
                                v-if="auth('reject') && detailDialog.data.payment_type !== 'online'"
                            >
                                <el-icon><Close /></el-icon>
                                审核拒绝
                            </el-button>
                        </template>
                        <el-button @click="detailDialog.visible = false">关闭</el-button>
                    </div>
                </div>
            </template>
        </el-dialog>
    </div>
</template>

<script setup lang="ts">
import { bind } from 'lodash-es'
import { provide, reactive, onMounted, computed } from 'vue'
import { Check, Close, ArrowLeft, ArrowRight } from '@element-plus/icons-vue'
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

// 统计数据
const stats = reactive({
    total: 0,
    total_approved_amount: 0,
    today_new: 0,
    today_approved: 0,
    today_approved_amount: 0,
    status: {} as any,
    banks: [] as any[],
    bank_options: [] as string[],
})

// 获取统计数据
const fetchStats = async () => {
    try {
        const res = await createAxios({
            url: '/admin/finance.RechargeOrder/stats',
            method: 'get',
        })
        if (res.code === 1 && res.data?.stats) {
            const s = res.data.stats
            stats.total = s.total || 0
            stats.total_approved_amount = s.total_approved_amount || 0
            stats.today_new = s.today_new || 0
            stats.today_approved = s.today_approved || 0
            stats.today_approved_amount = s.today_approved_amount || 0
            stats.status = s.status || {}
            stats.banks = s.banks || []
            stats.bank_options = s.bank_options || []
        }
    } catch (error) {
        console.error('获取统计数据失败:', error)
    }
}

// 页面加载时获取统计
onMounted(() => {
    fetchStats()
})

// 点击银行名称筛选
const filterByBank = (bankName: string) => {
    // 获取当前搜索条件，如果不存在则初始化为空数组
    let currentSearch = baTable.table.filter!.search || []
    
    // 移除已有的 payment_channel 条件（如果有）
    currentSearch = currentSearch.filter(item => item.field !== 'payment_channel')
    
    // 添加新的条件
    currentSearch.push({ field: 'payment_channel', val: bankName, operator: 'LIKE' })
    
    // 更新搜索条件
    baTable.table.filter!.search = currentSearch
    baTable.getData()
}

const baTable = new baTableClass(
    new baTableApi('/admin/finance.RechargeOrder/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', operator: '=', operatorPlaceholder: 'ID', width: 70, display: false },
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
                display: false,
            },
            {
                label: '付款截图',
                prop: 'payment_screenshot',
                align: 'center',
                operator: false,
                width: 100,
                render: 'image',
            } as any,
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
                label: '用户备注',
                prop: 'user_remark',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '备注内容',
                width: 150,
                showOverflowTooltip: true,
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
        user_remark: '',
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
        user_remark: row.user_remark || '',
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

// 从详情对话框审核通过
const handleDetailApprove = () => {
    if (detailDialog.data) {
        detailDialog.visible = false
        showAuditDialog(detailDialog.data, 'approve')
    }
}

// 从详情对话框审核拒绝
const handleDetailReject = () => {
    if (detailDialog.data) {
        detailDialog.visible = false
        showAuditDialog(detailDialog.data, 'reject')
    }
}

// 详情页切换上一条/下一条
const currentIndex = computed(() => {
    if (!detailDialog.data || !detailDialog.visible) return -1
    return baTable.table.data.findIndex((item) => item.id === detailDialog.data.id)
})

const isFirst = computed(() => {
    return currentIndex.value <= 0
})

const isLast = computed(() => {
    return currentIndex.value === -1 || currentIndex.value >= baTable.table.data.length - 1
})

const switchDetail = (direction: 'prev' | 'next') => {
    if (currentIndex.value === -1) return
    
    const newIndex = direction === 'prev' ? currentIndex.value - 1 : currentIndex.value + 1
    if (newIndex >= 0 && newIndex < baTable.table.data.length) {
        showDetail(baTable.table.data[newIndex])
    }
}

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

<style scoped lang="scss"></style>

