<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <TableHeader
            :buttons="['refresh', 'comSearch', 'quickSearch', 'columnDisplay']"
            quick-search-placeholder="快速搜索：ID/用户名/账户名/账号"
        />

        <Table />

        <!-- 审核对话框 -->
        <el-dialog
            v-model="auditDialog.visible"
            :title="auditDialog.title"
            width="700px"
            :close-on-click-modal="false"
        >
            <el-form :model="auditDialog.form" label-width="120px">
                <el-form-item label="账户ID">
                    <el-input v-model="auditDialog.form.id" disabled />
                </el-form-item>
                <el-form-item label="用户名">
                    <el-input v-model="auditDialog.form.username" disabled />
                </el-form-item>
                <el-form-item label="账户类型">
                    <el-input v-model="auditDialog.form.type_text" disabled />
                </el-form-item>
                <el-form-item label="账户性质">
                    <el-input v-model="auditDialog.form.account_type_text" disabled />
                </el-form-item>
                <el-form-item label="银行名称" v-if="auditDialog.form.type === 'bank_card'">
                    <el-input v-model="auditDialog.form.bank_name" disabled />
                </el-form-item>
                <el-form-item label="账户名">
                    <el-input v-model="auditDialog.form.account_name" disabled />
                </el-form-item>
                <el-form-item label="账号/卡号">
                    <el-input v-model="auditDialog.form.account_number_display" disabled />
                </el-form-item>
                <el-form-item label="开户行" v-if="auditDialog.form.type === 'bank_card' && auditDialog.form.bank_branch">
                    <el-input v-model="auditDialog.form.bank_branch" disabled />
                </el-form-item>
                <el-form-item label="打款截图" v-if="auditDialog.form.account_type === 'company' && auditDialog.form.screenshot && auditDialog.form.type !== 'wechat'">
                    <el-image
                        :src="fullUrl(auditDialog.form.screenshot)"
                        style="width: 400px; height: auto; max-height: 300px;"
                        :preview-src-list="[fullUrl(auditDialog.form.screenshot)]"
                        fit="contain"
                    />
                </el-form-item>
                <el-form-item label="微信收款码" v-if="auditDialog.form.type === 'wechat' && auditDialog.form.screenshot">
                    <el-image
                        :src="fullUrl(auditDialog.form.screenshot)"
                        style="width: 400px; height: auto; max-height: 300px;"
                        :preview-src-list="[fullUrl(auditDialog.form.screenshot)]"
                        fit="contain"
                    />
                </el-form-item>
                <el-form-item label="审核备注" v-if="auditDialog.type === 'reject'">
                    <el-input
                        v-model="auditDialog.form.audit_reason"
                        type="textarea"
                        :rows="4"
                        placeholder="请输入拒绝原因"
                    />
                </el-form-item>
                <el-form-item label="审核备注" v-else>
                    <el-input
                        v-model="auditDialog.form.audit_reason"
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

        <!-- 查看绑卡列表对话框 -->
        <el-dialog
            v-model="detailDialog.visible"
            title="绑卡列表"
            width="1000px"
            :close-on-click-modal="false"
        >
            <div v-if="detailDialog.userInfo" style="margin-bottom: 20px;">
                <el-descriptions :column="3" border>
                    <el-descriptions-item label="用户ID">{{ detailDialog.userInfo.user_id }}</el-descriptions-item>
                    <el-descriptions-item label="用户名">{{ detailDialog.userInfo.username || '--' }}</el-descriptions-item>
                    <el-descriptions-item label="昵称">{{ detailDialog.userInfo.nickname || '--' }}</el-descriptions-item>
                    <el-descriptions-item label="手机号" :span="3">{{ detailDialog.userInfo.mobile || '--' }}</el-descriptions-item>
                </el-descriptions>
            </div>
            <el-table :data="detailDialog.list" border style="width: 100%" v-loading="detailDialog.loading">
                <el-table-column prop="id" label="账户ID" align="center" width="80" />
                <el-table-column prop="type_text" label="账户类型" align="center" width="100">
                    <template #default="{ row }">
                        <el-tag :type="getTypeTagType(row.type)">
                            {{ row.type_text }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="account_type_text" label="账户性质" align="center" width="100">
                    <template #default="{ row }">
                        <el-tag :type="row.account_type === 'personal' ? 'info' : 'warning'">
                            {{ row.account_type_text }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="bank_name" label="银行名称" align="center" width="150" v-if="hasBankCard">
                    <template #default="{ row }">
                        {{ row.type === 'bank_card' ? (row.bank_name || '--') : '--' }}
                    </template>
                </el-table-column>
                <el-table-column prop="account_name" label="账户名" align="center" width="150" />
                <el-table-column prop="account_number_display" label="账号/卡号" align="center" width="200" />
                <el-table-column prop="bank_branch" label="开户行" align="center" width="150" v-if="hasBankCard">
                    <template #default="{ row }">
                        {{ row.type === 'bank_card' ? (row.bank_branch || '--') : '--' }}
                    </template>
                </el-table-column>
                <el-table-column prop="audit_status_text" label="审核状态" align="center" width="100">
                    <template #default="{ row }">
                        <el-tag :type="getAuditStatusType(row.audit_status)">
                            {{ row.audit_status_text }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="is_default" label="是否默认" align="center" width="100">
                    <template #default="{ row }">
                        <el-tag :type="row.is_default ? 'success' : 'info'">
                            {{ row.is_default ? '是' : '否' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="create_time" label="创建时间" align="center" width="160">
                    <template #default="{ row }">
                        {{ row.create_time ? timeFormat(row.create_time) : '--' }}
                    </template>
                </el-table-column>
                <el-table-column label="操作" align="center" width="150" fixed="right">
                    <template #default="{ row }">
                        <el-button
                            v-if="row.account_type === 'company' && row.screenshot && row.type !== 'wechat'"
                            type="primary"
                            size="small"
                            @click="showScreenshot(row.screenshot, '打款截图')"
                        >
                            查看截图
                        </el-button>
                        <el-button
                            v-if="row.type === 'wechat' && row.screenshot"
                            type="success"
                            size="small"
                            @click="showScreenshot(row.screenshot, '微信收款码')"
                        >
                            查看收款码
                        </el-button>
                    </template>
                </el-table-column>
            </el-table>
            <template #footer>
                <el-button @click="detailDialog.visible = false">关闭</el-button>
            </template>
        </el-dialog>

        <!-- 打款截图对话框 -->
        <el-dialog
            v-model="screenshotDialog.visible"
            :title="screenshotDialog.title"
            width="600px"
            :close-on-click-modal="false"
        >
            <el-image
                :src="fullUrl(screenshotDialog.url)"
                style="width: 100%; height: auto; max-height: 500px;"
                :preview-src-list="[fullUrl(screenshotDialog.url)]"
                fit="contain"
            />
            <template #footer>
                <el-button @click="screenshotDialog.visible = false">关闭</el-button>
            </template>
        </el-dialog>

        <!-- 编辑对话框 -->
        <el-dialog
            v-model="editDialog.visible"
            title="编辑收款账户"
            width="600px"
            :close-on-click-modal="false"
        >
            <el-form :model="editDialog.form" label-width="100px">
                <el-form-item label="用户">
                    <el-input :value="`${editDialog.form.username || ''} (${editDialog.form.mobile || ''})`" disabled />
                </el-form-item>
                <el-form-item label="账户类型" required>
                    <el-select v-model="editDialog.form.type" style="width: 100%">
                        <el-option label="银行卡" value="bank_card" />
                        <el-option label="支付宝" value="alipay" />
                        <el-option label="微信" value="wechat" />
                        <el-option label="USDT" value="usdt" />
                    </el-select>
                </el-form-item>
                <el-form-item label="银行名称" v-if="editDialog.form.type === 'bank_card'">
                    <el-input v-model="editDialog.form.bank_name" placeholder="请输入银行名称" />
                </el-form-item>
                <el-form-item label="账户名" required>
                    <el-input v-model="editDialog.form.account_name" placeholder="请输入账户名（姓名）" />
                </el-form-item>
                <el-form-item label="账号/卡号" required>
                    <el-input v-model="editDialog.form.account_number" placeholder="请输入账号或卡号" />
                </el-form-item>
                <el-form-item label="开户支行" v-if="editDialog.form.type === 'bank_card'">
                    <el-input v-model="editDialog.form.bank_branch" placeholder="请输入开户支行" />
                </el-form-item>
                <el-form-item label="审核状态">
                    <el-select v-model="editDialog.form.audit_status" style="width: 100%">
                        <el-option label="待审核" :value="0" />
                        <el-option label="已通过" :value="1" />
                        <el-option label="已拒绝" :value="2" />
                    </el-select>
                </el-form-item>
                <el-form-item label="是否默认">
                    <el-switch v-model="editDialog.form.is_default" :active-value="1" :inactive-value="0" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="editDialog.visible = false">取消</el-button>
                <el-button type="danger" @click="handleDelete" :loading="editDialog.loading">删除账户</el-button>
                <el-button type="primary" @click="submitEdit" :loading="editDialog.loading">保存</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup lang="ts">
import { provide, reactive, computed } from 'vue'
import baTableClass from '/@/utils/baTable'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import { baTableApi } from '/@/api/common'
import { fullUrl, timeFormat } from '/@/utils/common'
import { ElMessage, ElMessageBox } from 'element-plus'
import createAxios from '/@/utils/axios'

defineOptions({
    name: 'user/paymentAccount',
})

const baTable = new baTableClass(
    new baTableApi('/admin/user.PaymentAccount/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', operator: '=', operatorPlaceholder: 'ID', width: 70 },
            {
                label: '用户名',
                prop: 'username',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '模糊查询',
            },
            {
                label: '账户类型',
                prop: 'type',
                align: 'center',
                operator: 'select',
                operatorOptions: [
                    { label: '银行卡', value: 'bank_card' },
                    { label: '支付宝', value: 'alipay' },
                    { label: '微信', value: 'wechat' },
                    { label: 'USDT', value: 'usdt' },
                ],
                render: 'tag',
                custom: { 'bank_card': 'primary', 'alipay': 'success', 'wechat': 'success', 'usdt': 'warning' },
                replaceValue: { 'bank_card': '银行卡', 'alipay': '支付宝', 'wechat': '微信', 'usdt': 'USDT' },
                width: 100,
            },
            {
                label: '账户性质',
                prop: 'account_type',
                align: 'center',
                operator: 'select',
                operatorOptions: [
                    { label: '个人', value: 'personal' },
                    { label: '公司', value: 'company' },
                ],
                render: 'tag',
                custom: { 'personal': 'info', 'company': 'warning' },
                replaceValue: { 'personal': '个人', 'company': '公司' },
                width: 100,
            },
            {
                label: '银行名称',
                prop: 'bank_name',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '模糊查询',
                show: false,
            },
            {
                label: '账户名',
                prop: 'account_name',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '模糊查询',
            },
            {
                label: '账号/卡号',
                prop: 'account_number_display',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '模糊查询',
            },
            {
                label: '审核状态',
                prop: 'audit_status',
                align: 'center',
                operator: 'select',
                operatorOptions: [
                    { label: '待审核', value: '0' },
                    { label: '已通过', value: '1' },
                    { label: '已拒绝', value: '2' },
                ],
                render: 'tag',
                custom: { '0': 'warning', '1': 'success', '2': 'danger' },
                replaceValue: { '0': '待审核', '1': '已通过', '2': '已拒绝' },
                width: 100,
            },
            {
                label: '是否默认',
                prop: 'is_default',
                align: 'center',
                render: 'tag',
                custom: { '0': 'info', '1': 'success' },
                replaceValue: { '0': '否', '1': '是' },
                width: 100,
            },
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
                width: 300,
                render: 'buttons',
                buttons: (row: any) => {
                    const buttons: any[] = []
                    buttons.push({
                        render: 'tipButton',
                        name: 'read',
                        title: '查看详情',
                        type: 'primary',
                        icon: 'fa fa-eye',
                        class: 'table-row-read',
                        click: () => {
                            showDetail(row)
                        },
                    })
                    // 编辑按钮
                    buttons.push({
                        render: 'tipButton',
                        name: 'edit',
                        title: '编辑',
                        type: 'warning',
                        icon: 'fa fa-edit',
                        class: 'table-row-edit',
                        click: () => {
                            showEditDialog(row)
                        },
                    })
                    // 银行卡类型不需要审核，只对非银行卡类型且待审核的账户显示审核按钮
                    if (row.audit_status == 0 && row.type != 'bank_card') {
                        // 待审核状态，显示审核按钮
                        buttons.push({
                            render: 'tipButton',
                            name: 'approve',
                            title: '审核通过',
                            type: 'success',
                            icon: 'fa fa-check',
                            class: 'table-row-approve',
                            click: () => {
                                showAuditDialog(row, 'approve')
                            },
                        })
                        buttons.push({
                            render: 'tipButton',
                            name: 'reject',
                            title: '审核拒绝',
                            type: 'danger',
                            icon: 'fa fa-times',
                            class: 'table-row-reject',
                            click: () => {
                                showAuditDialog(row, 'reject')
                            },
                        })
                    }
                    return buttons
                },
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
        id: '',
        username: '',
        type: '',
        type_text: '',
        account_type: '',
        account_type_text: '',
        bank_name: '',
        account_name: '',
        account_number_display: '',
        bank_branch: '',
        screenshot: '',
        audit_reason: '',
    },
})

// 详情对话框
const detailDialog = reactive({
    visible: false,
    loading: false,
    list: [] as any[],
    userInfo: null as any,
})

// 打款截图对话框
const screenshotDialog = reactive({
    visible: false,
    url: '',
    title: '打款截图',
})

// 编辑对话框
const editDialog = reactive({
    visible: false,
    loading: false,
    form: {
        id: 0,
        user_id: 0,
        username: '',
        mobile: '',
        type: 'bank_card',
        account_name: '',
        account_number: '',
        bank_name: '',
        bank_branch: '',
        audit_status: 1,
        is_default: 0,
    },
})

// 检查是否有银行卡
const hasBankCard = computed(() => {
    return detailDialog.list.some((item: any) => item.type === 'bank_card')
})

// 显示审核对话框
const showAuditDialog = (row: any, type: 'approve' | 'reject') => {
    auditDialog.type = type
    auditDialog.title = type === 'approve' ? '审核通过' : '审核拒绝'
    auditDialog.form = {
        id: row.id,
        username: row.username || '',
        type: row.type || '',
        type_text: row.type_text || '',
        account_type: row.account_type || '',
        account_type_text: row.account_type_text || '',
        bank_name: row.bank_name || '',
        account_name: row.account_name || '',
        account_number_display: row.account_number_display || '',
        bank_branch: row.bank_branch || '',
        screenshot: row.screenshot || '',
        audit_reason: '',
    }
    auditDialog.visible = true
}

// 提交审核
const submitAudit = async () => {
    if (auditDialog.type === 'reject' && !auditDialog.form.audit_reason.trim()) {
        ElMessage.warning('拒绝原因不能为空')
        return
    }

    auditDialog.loading = true
    try {
        const api = auditDialog.type === 'approve' ? '/admin/user.PaymentAccount/approve' : '/admin/user.PaymentAccount/reject'
        const response = await createAxios({
            url: api,
            method: 'post',
            data: {
                id: auditDialog.form.id,
                audit_reason: auditDialog.form.audit_reason,
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

// 显示详情（绑卡列表）
const showDetail = async (row: any) => {
    detailDialog.loading = true
    detailDialog.visible = true
    detailDialog.list = []
    detailDialog.userInfo = null
    
    try {
        const response = await createAxios({
            url: '/admin/user.PaymentAccount/getUserAccounts',
            method: 'get',
            params: { user_id: row.user_id },
        })
        if (response.code == 1) {
            detailDialog.list = response.data.list || []
            // 保存用户信息（从第一条记录中获取）
            if (detailDialog.list.length > 0) {
                detailDialog.userInfo = {
                    user_id: row.user_id,
                    username: row.username || detailDialog.list[0].username,
                    nickname: row.nickname || detailDialog.list[0].nickname,
                    mobile: row.mobile || detailDialog.list[0].mobile,
                }
            }
        } else {
            ElMessage.error(response.msg)
            detailDialog.visible = false
        }
    } catch (error: any) {
        ElMessage.error(error.message || '获取绑卡列表失败')
        detailDialog.visible = false
    } finally {
        detailDialog.loading = false
    }
}

// 显示打款截图或收款码
const showScreenshot = (url: string, title: string = '打款截图') => {
    screenshotDialog.url = url
    screenshotDialog.title = title
    screenshotDialog.visible = true
}

// 获取账户类型标签颜色
const getTypeTagType = (type: string) => {
    const map: any = {
        'bank_card': 'primary',
        'alipay': 'success',
        'wechat': 'success',
        'usdt': 'warning',
    }
    return map[type] || 'info'
}

// 获取审核状态类型
const getAuditStatusType = (status: number) => {
    const map: any = {
        0: 'warning',
        1: 'success',
        2: 'danger',
    }
    return map[status] || 'info'
}

// 显示编辑对话框
const showEditDialog = (row: any) => {
    editDialog.form = {
        id: row.id,
        user_id: row.user_id,
        username: row.username || '',
        mobile: row.mobile || '',
        type: row.type || 'bank_card',
        account_name: row.account_name || '',
        account_number: row.account_number_display || '',
        bank_name: row.bank_name || '',
        bank_branch: row.bank_branch || '',
        audit_status: row.audit_status ?? 1,
        is_default: row.is_default ?? 0,
    }
    editDialog.visible = true
}

// 提交编辑
const submitEdit = async () => {
    if (!editDialog.form.account_name.trim()) {
        ElMessage.warning('账户名不能为空')
        return
    }
    if (!editDialog.form.account_number.trim()) {
        ElMessage.warning('账号/卡号不能为空')
        return
    }

    editDialog.loading = true
    try {
        const response = await createAxios({
            url: '/admin/user.PaymentAccount/edit',
            method: 'post',
            data: {
                id: editDialog.form.id,
                type: editDialog.form.type,
                account_name: editDialog.form.account_name,
                account_number: editDialog.form.account_number,
                bank_name: editDialog.form.bank_name,
                bank_branch: editDialog.form.bank_branch,
                audit_status: editDialog.form.audit_status,
                is_default: editDialog.form.is_default,
            },
        })

        if (response.code == 1) {
            ElMessage.success('修改成功')
            editDialog.visible = false
            baTable.getData()
        } else {
            ElMessage.error(response.msg || '修改失败')
        }
    } catch (error: any) {
        ElMessage.error(error.message || '操作失败')
    } finally {
        editDialog.loading = false
    }
}

// 删除账户
const handleDelete = async () => {
    ElMessageBox.confirm('确定要删除该收款账户吗？删除后无法恢复！', '警告', {
        confirmButtonText: '确定删除',
        cancelButtonText: '取消',
        type: 'warning',
    }).then(async () => {
        editDialog.loading = true
        try {
            const response = await createAxios({
                url: '/admin/user.PaymentAccount/del',
                method: 'post',
                data: { id: editDialog.form.id },
            })

            if (response.code == 1) {
                ElMessage.success('删除成功')
                editDialog.visible = false
                baTable.getData()
            } else {
                ElMessage.error(response.msg || '删除失败')
            }
        } catch (error: any) {
            ElMessage.error(error.message || '操作失败')
        } finally {
            editDialog.loading = false
        }
    }).catch(() => {})
}

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

<style scoped lang="scss"></style>

