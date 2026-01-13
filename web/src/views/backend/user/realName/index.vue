<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <TableHeader
            :buttons="['refresh', 'add', 'edit', 'delete', 'comSearch', 'quickSearch', 'columnDisplay']"
            quick-search-placeholder="快速搜索：ID/用户名/真实姓名/身份证号"
        />

        <Table />

        <!-- 表单 -->
        <PopupForm />

        <!-- 审核对话框 -->
        <el-dialog
            v-model="auditDialog.visible"
            :title="auditDialog.title"
            width="600px"
            :close-on-click-modal="false"
        >
            <el-form :model="auditDialog.form" label-width="100px">
                <el-form-item label="用户ID">
                    <el-input v-model="auditDialog.form.user_id" disabled />
                </el-form-item>
                <el-form-item label="用户名">
                    <el-input v-model="auditDialog.form.username" disabled />
                </el-form-item>
                <el-form-item label="真实姓名">
                    <el-input v-model="auditDialog.form.real_name" disabled />
                </el-form-item>
                <el-form-item label="身份证号">
                    <el-input v-model="auditDialog.form.id_card" disabled />
                </el-form-item>
                <el-form-item label="身份证正面" v-if="auditDialog.form.id_card_front">
                    <el-image
                        :src="fullUrl(auditDialog.form.id_card_front)"
                        style="width: 300px; height: auto;"
                        :preview-src-list="[fullUrl(auditDialog.form.id_card_front)]"
                        fit="contain"
                    />
                </el-form-item>
                <el-form-item label="身份证反面" v-if="auditDialog.form.id_card_back">
                    <el-image
                        :src="fullUrl(auditDialog.form.id_card_back)"
                        style="width: 300px; height: auto;"
                        :preview-src-list="[fullUrl(auditDialog.form.id_card_back)]"
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
            title="实名认证详情"
            width="800px"
            :close-on-click-modal="false"
        >
            <el-descriptions :column="2" border v-if="detailDialog.data">
                <el-descriptions-item label="用户ID">{{ detailDialog.data.id }}</el-descriptions-item>
                <el-descriptions-item label="用户名">{{ detailDialog.data.username }}</el-descriptions-item>
                <el-descriptions-item label="昵称">{{ detailDialog.data.nickname }}</el-descriptions-item>
                <el-descriptions-item label="手机号">{{ detailDialog.data.mobile }}</el-descriptions-item>
                <el-descriptions-item label="真实姓名">{{ detailDialog.data.real_name || '--' }}</el-descriptions-item>
                <el-descriptions-item label="身份证号">{{ detailDialog.data.id_card || '--' }}</el-descriptions-item>
                <el-descriptions-item label="实名状态">
                    <el-tag :type="getStatusType(detailDialog.data.real_name_status)">
                        {{ getStatusText(detailDialog.data.real_name_status) }}
                    </el-tag>
                </el-descriptions-item>
                <el-descriptions-item label="审核时间">
                    {{ detailDialog.data.audit_time ? timeFormat(detailDialog.data.audit_time) : '--' }}
                </el-descriptions-item>
                <el-descriptions-item label="审核备注" :span="2">
                    {{ detailDialog.data.audit_remark || '--' }}
                </el-descriptions-item>
                <el-descriptions-item label="身份证正面" :span="2" v-if="detailDialog.data.id_card_front">
                    <el-image
                        :src="fullUrl(detailDialog.data.id_card_front)"
                        style="width: 400px; height: auto;"
                        :preview-src-list="[fullUrl(detailDialog.data.id_card_front)]"
                        fit="contain"
                    />
                </el-descriptions-item>
                <el-descriptions-item label="身份证反面" :span="2" v-if="detailDialog.data.id_card_back">
                    <el-image
                        :src="fullUrl(detailDialog.data.id_card_back)"
                        style="width: 400px; height: auto;"
                        :preview-src-list="[fullUrl(detailDialog.data.id_card_back)]"
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
import PopupForm from './popupForm.vue'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import { baTableApi } from '/@/api/common'
import createAxios from '/@/utils/axios'
import { auth, fullUrl, timeFormat } from '/@/utils/common'
import { ElMessage, ElMessageBox } from 'element-plus'

defineOptions({
    name: 'user/realName',
})

const baTable = new baTableClass(
    new baTableApi('/admin/user.RealName/'),
    {
        column: [
            { label: 'ID', prop: 'id', align: 'center', operator: '=', operatorPlaceholder: 'ID', width: 70 },
            {
                label: '用户名',
                prop: 'username',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '模糊查询',
            },
            {
                label: '昵称',
                prop: 'nickname',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '模糊查询',
            },
            {
                label: '真实姓名',
                prop: 'real_name',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '模糊查询',
            },
            {
                label: '身份证号',
                prop: 'id_card',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '模糊查询',
            },
            {
                label: '实名状态',
                prop: 'real_name_status',
                align: 'center',
                operator: 'select',
                operatorOptions: [
                    { label: '待审核', value: '1' },
                    { label: '已通过', value: '2' },
                    { label: '已拒绝', value: '3' },
                ],
                render: 'tag',
                custom: { '1': 'warning', '2': 'success', '3': 'danger' },
                replaceValue: { '1': '待审核', '2': '已通过', '3': '已拒绝' },
                width: 100,
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
                label: '审核备注',
                prop: 'audit_remark',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '模糊查询',
                show: false,
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
                width: 280,
                render: 'buttons',
                buttons: (row: any) => {
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
                    // 通过按钮：待审核或已拒绝状态可以重新审核通过
                    if (row.real_name_status == 1 || row.real_name_status == 3) {
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
                    // 拒绝按钮：待审核或已通过状态可以拒绝
                    if (row.real_name_status == 1 || row.real_name_status == 2) {
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
                    // 删除按钮：所有状态都可以删除
                    buttons.push({
                        render: 'confirmButton',
                        name: 'delete',
                        title: '删除',
                        text: '',
                        type: 'danger',
                        icon: 'fa fa-trash',
                        class: 'table-row-delete',
                        display: () => auth('del'),
                        popconfirm: {
                            confirmButtonText: '删除',
                            cancelButtonText: '取消',
                            confirmButtonType: 'danger',
                            title: '确定要删除该实名认证记录吗？删除后将清除用户的实名信息。',
                        },
                        click: () => {
                            deleteRecord(row)
                        },
                    })
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
        user_id: '',
        username: '',
        real_name: '',
        id_card: '',
        id_card_front: '',
        id_card_back: '',
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
        user_id: row.id,
        username: row.username,
        real_name: row.real_name || '',
        id_card: row.id_card || '',
        id_card_front: row.id_card_front || '',
        id_card_back: row.id_card_back || '',
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
        const api = auditDialog.type === 'approve' ? '/admin/user.RealName/approve' : '/admin/user.RealName/reject'
        const response = await createAxios({
            url: api,
            method: 'post',
            data: {
                id: auditDialog.form.user_id,
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
            url: '/admin/user.RealName/read',
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

// 删除记录
const deleteRecord = async (row: any) => {
    try {
        const response = await baTable.api.del([row.id])
        if (response.code == 1) {
            ElMessage.success(response.msg || '删除成功')
            baTable.getData()
        } else {
            ElMessage.error(response.msg || '删除失败')
        }
    } catch (error: any) {
        ElMessage.error(error.message || '删除失败')
    }
}

// 获取状态文本
const getStatusText = (status: number) => {
    const map: any = {
        0: '未实名',
        1: '待审核',
        2: '已通过',
        3: '已拒绝',
    }
    return map[status] || '未知'
}

// 获取状态类型
const getStatusType = (status: number) => {
    const map: any = {
        0: 'info',
        1: 'warning',
        2: 'success',
        3: 'danger',
    }
    return map[status] || 'info'
}

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

<style scoped lang="scss"></style>

