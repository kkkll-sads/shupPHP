<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <TableHeader
            :buttons="['refresh', 'comSearch', 'quickSearch', 'columnDisplay']"
            quick-search-placeholder="快速搜索：ID/用户名/企业名称/法人"
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
                <el-form-item label="记录ID">
                    <el-input v-model="auditDialog.form.id" disabled />
                </el-form-item>
                <el-form-item label="用户ID">
                    <el-input v-model="auditDialog.form.user_id" disabled />
                </el-form-item>
                <el-form-item label="用户名">
                    <el-input v-model="auditDialog.form.username" disabled />
                </el-form-item>
                <el-form-item label="企业名称">
                    <el-input v-model="auditDialog.form.company_name" disabled />
                </el-form-item>
                <el-form-item label="企业法人">
                    <el-input v-model="auditDialog.form.legal_person" disabled />
                </el-form-item>
                <el-form-item label="法人证件号">
                    <el-input v-model="auditDialog.form.legal_id_number" disabled />
                </el-form-item>
                <el-form-item label="主体类型">
                    <el-input v-model="auditDialog.form.subject_type_text" disabled />
                </el-form-item>
                <el-form-item label="营业执照" v-if="auditDialog.form.license_image">
                    <el-image
                        :src="fullUrl(auditDialog.form.license_image)"
                        style="width: 400px; height: auto; max-height: 300px;"
                        :preview-src-list="[fullUrl(auditDialog.form.license_image)]"
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
            title="代理商审核详情"
            width="800px"
            :close-on-click-modal="false"
        >
            <el-descriptions :column="2" border v-if="detailDialog.data">
                <el-descriptions-item label="记录ID">{{ detailDialog.data.id }}</el-descriptions-item>
                <el-descriptions-item label="用户ID">{{ detailDialog.data.user_id }}</el-descriptions-item>
                <el-descriptions-item label="用户名">{{ detailDialog.data.username || '--' }}</el-descriptions-item>
                <el-descriptions-item label="昵称">{{ detailDialog.data.nickname || '--' }}</el-descriptions-item>
                <el-descriptions-item label="手机号">{{ detailDialog.data.mobile || '--' }}</el-descriptions-item>
                <el-descriptions-item label="企业名称">{{ detailDialog.data.company_name || '--' }}</el-descriptions-item>
                <el-descriptions-item label="企业法人">{{ detailDialog.data.legal_person || '--' }}</el-descriptions-item>
                <el-descriptions-item label="法人证件号">{{ detailDialog.data.legal_id_number || '--' }}</el-descriptions-item>
                <el-descriptions-item label="主体类型">
                    <el-tag>
                        {{ detailDialog.data.subject_type_text || '--' }}
                    </el-tag>
                </el-descriptions-item>
                <el-descriptions-item label="审核状态">
                    <el-tag :type="getStatusType(detailDialog.data.status)">
                        {{ detailDialog.data.status_text }}
                    </el-tag>
                </el-descriptions-item>
                <el-descriptions-item label="审核时间">
                    {{ detailDialog.data.audit_time ? timeFormat(detailDialog.data.audit_time) : '--' }}
                </el-descriptions-item>
                <el-descriptions-item label="审核备注" :span="2">
                    {{ detailDialog.data.audit_remark || '--' }}
                </el-descriptions-item>
                <el-descriptions-item label="营业执照" :span="2" v-if="detailDialog.data.license_image">
                    <el-image
                        :src="fullUrl(detailDialog.data.license_image)"
                        style="width: 400px; height: auto;"
                        :preview-src-list="[fullUrl(detailDialog.data.license_image)]"
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
import { auth, fullUrl, timeFormat } from '/@/utils/common'
import createAxios from '/@/utils/axios'
import { ElMessage } from 'element-plus'

defineOptions({
    name: 'user/agentReview',
})

const baTable = new baTableClass(
    new baTableApi('/admin/user.AgentReview/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', operator: '=', operatorPlaceholder: 'ID', width: 80 },
            {
                label: '用户ID',
                prop: 'user_id',
                align: 'center',
                operator: '=',
                operatorPlaceholder: '用户ID',
                width: 100,
            },
            {
                label: '用户名',
                prop: 'username',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '模糊查询',
            },
            {
                label: '企业名称',
                prop: 'company_name',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '模糊查询',
            },
            {
                label: '企业法人',
                prop: 'legal_person',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '模糊查询',
            },
            {
                label: '主体类型',
                prop: 'subject_type',
                align: 'center',
                operator: false,
                render: 'tag',
                custom: { '1': 'info', '2': 'primary' },
                replaceValue: { '1': '个体户', '2': '企业法人' },
                width: 100,
            },
            {
                label: '审核状态',
                prop: 'status',
                align: 'center',
                operator: false,
                render: 'tag',
                custom: { '0': 'warning', '1': 'success', '2': 'danger' },
                replaceValue: { '0': '待审核', '1': '已通过', '2': '已拒绝' },
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
                label: '审核时间',
                prop: 'audit_time',
                align: 'center',
                render: 'datetime',
                sortable: 'custom',
                operator: 'RANGE',
                width: 160,
            },
            {
                label: '操作',
                align: 'center',
                width: 320,
                render: 'buttons',
                buttons: ((row: any) => {
                    const buttons: any[] = []
                    buttons.push({
                        render: 'tipButton',
                        name: 'read',
                        title: '查看详情',
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
                            type: 'success',
                            icon: 'fa fa-check',
                            class: 'table-row-approve',
                            display: () => auth('approve'),
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
                            display: () => auth('reject'),
                            click: () => {
                                showAuditDialog(row, 'reject')
                            },
                        })
                    }
                    // 删除按钮：所有状态都可以删除
                    buttons.push({
                        render: 'confirmButton',
                        name: 'delete',
                        title: '删除',
                        type: 'danger',
                        icon: 'fa fa-trash',
                        class: 'table-row-delete',
                        display: () => auth('del'),
                        popconfirm: {
                            confirmButtonText: '删除',
                            cancelButtonText: '取消',
                            confirmButtonType: 'danger',
                            title: '确定要删除该代理商审核记录吗？',
                        },
                        click: () => {
                            deleteRecord(row)
                        },
                    })
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
        id: '',
        user_id: '',
        username: '',
        company_name: '',
        legal_person: '',
        legal_id_number: '',
        subject_type_text: '',
        license_image: '',
        audit_remark: '',
    },
})

const detailDialog = reactive({
    visible: false,
    data: null as any,
})

const showAuditDialog = (row: any, type: 'approve' | 'reject') => {
    auditDialog.type = type
    auditDialog.title = type === 'approve' ? '审核通过' : '审核拒绝'
    auditDialog.form = {
        id: row.id,
        user_id: row.user_id,
        username: row.username || '',
        company_name: row.company_name || '',
        legal_person: row.legal_person || '',
        legal_id_number: row.legal_id_number || '',
        subject_type_text: row.subject_type_text || (row.subject_type == 2 ? '企业法人' : '个体户'),
        license_image: row.license_image || '',
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
        const api = auditDialog.type === 'approve' ? '/admin/user.AgentReview/approve' : '/admin/user.AgentReview/reject'
        const response = await createAxios({
            url: api,
            method: 'post',
            data: {
                id: auditDialog.form.id,
                audit_remark: auditDialog.form.audit_remark,
            },
        })

        if (response.code == 1 || (response.code == 0 && (!response.msg || response.msg === ''))) {
            ElMessage.success(response.msg || '操作成功')
            auditDialog.visible = false
            baTable.getData()
        } else {
            ElMessage.error(response.msg || '操作失败')
        }
    } catch (error: any) {
        ElMessage.error(error.message || '操作失败')
    } finally {
        auditDialog.loading = false
    }
}

const showDetail = async (row: any) => {
    try {
        const response = await createAxios({
            url: '/admin/user.AgentReview/read',
            method: 'get',
            params: { id: row.id },
        })
        if (response.code == 1) {
            detailDialog.data = response.data.row
            detailDialog.visible = true
        } else {
            ElMessage.error(response.msg || '获取详情失败')
        }
    } catch (error: any) {
        ElMessage.error(error.message || '获取详情失败')
    }
}

const deleteRecord = async (row: any) => {
    try {
        const response = await baTable.api.del([row.id])
        if (response.code == 1 || (response.code == 0 && (!response.msg || response.msg === ''))) {
            ElMessage.success(response.msg || '删除成功')
            baTable.getData()
        } else {
            ElMessage.error(response.msg || '删除失败')
        }
    } catch (error: any) {
        ElMessage.error(error.message || '删除失败')
    }
}

const getStatusType = (status: number) => {
    const map: any = {
        0: 'warning',
        1: 'success',
        2: 'danger',
    }
    return map[status] || 'info'
}

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

<style scoped lang="scss"></style>


