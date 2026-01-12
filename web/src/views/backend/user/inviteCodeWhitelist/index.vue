<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <TableHeader
            :buttons="['refresh', 'add', 'edit', 'delete', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="'邀请码 / 备注'"
        >
            <!-- 单个邀请码快速添加 -->
            <div class="single-code-input">
                <el-input
                    v-model="singleCode"
                    placeholder="输入邀请码"
                    style="width: 140px;"
                    maxlength="20"
                    @keyup.enter="addSingleCode"
                />
                <el-button v-blur type="success" @click="addSingleCode" :loading="addingCode">
                    <Icon name="fa fa-plus" />
                    <span class="table-header-operate-text">添加</span>
                </el-button>
            </div>
            <el-upload
                ref="uploadRef"
                :auto-upload="false"
                :show-file-list="false"
                accept=".txt,.xlsx,.xls,.csv"
                :on-change="handleFileChange"
                class="mlr-12"
            >
                <template #trigger>
                    <el-button v-blur class="table-header-operate" type="primary">
                        <Icon name="fa fa-upload" />
                        <span class="table-header-operate-text">批量导入</span>
                    </el-button>
                </template>
            </el-upload>
            <el-button v-blur class="table-header-operate mlr-12" type="success" @click="batchEnable">
                <Icon name="fa fa-check" />
                <span class="table-header-operate-text">批量启用</span>
            </el-button>
            <el-button v-blur class="table-header-operate" type="warning" @click="batchDisable">
                <Icon name="fa fa-ban" />
                <span class="table-header-operate-text">批量禁用</span>
            </el-button>
        </TableHeader>

        <Table />

        <PopupForm />
    </div>
</template>

<script setup lang="ts">
import { provide, ref } from 'vue'
import baTableClass from '/@/utils/baTable'
import PopupForm from './popupForm.vue'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import { defaultOptButtons } from '/@/components/table'
import { baTableApi } from '/@/api/common'
import createAxios from '/@/utils/axios'
import { ElMessage, ElMessageBox } from 'element-plus'
import Icon from '/@/components/icon/index.vue'
import { useAdminInfo } from '/@/stores/adminInfo'

defineOptions({
    name: 'user/inviteCodeWhitelist',
})

const adminInfo = useAdminInfo()
const uploadRef = ref()
const singleCode = ref('')
const addingCode = ref(false)

// 添加单个邀请码
const addSingleCode = () => {
    const code = singleCode.value.trim()
    if (!code) {
        ElMessage.warning('请输入邀请码')
        return
    }
    
    if (!/^[a-zA-Z0-9]+$/.test(code)) {
        ElMessage.warning('邀请码格式不正确，仅允许字母和数字')
        return
    }
    
    addingCode.value = true
    createAxios({
        url: '/admin/user.InviteCodeWhitelist/add',
        method: 'post',
        data: {
            code: code,
            status: 1,
            remark: '手动添加',
        },
    })
        .then((res) => {
            if (res.code === 1) {
                ElMessage.success('添加成功')
                singleCode.value = ''
                baTable.getData()
            } else {
                ElMessage.error(res.msg || '添加失败')
            }
        })
        .catch((error) => {
            ElMessage.error(error?.response?.data?.msg || '添加失败')
        })
        .finally(() => {
            addingCode.value = false
        })
}

const handleFileChange = (file: any) => {
    if (!file || !file.raw) {
        return
    }
    
    const allowedExts = ['txt', 'xlsx', 'xls', 'csv']
    const fileExt = file.name.split('.').pop()?.toLowerCase()
    
    if (!fileExt || !allowedExts.includes(fileExt)) {
        ElMessage.error('不支持的文件格式，仅支持：' + allowedExts.join(', '))
        return
    }
    
    if (file.size > 10 * 1024 * 1024) {
        ElMessage.error('文件大小不能超过 10MB')
        return
    }
    
    // 手动上传文件
    const formData = new FormData()
    formData.append('file', file.raw)
    
    ElMessage.info('正在导入，请稍候...')
    
    createAxios({
        url: '/admin/user.InviteCodeWhitelist/import',
        method: 'post',
        data: formData,
    })
        .then((res) => {
            onImportSuccess(res)
        })
        .catch((error) => {
            onImportError(error)
        })
}

const onImportSuccess = (response: any) => {
    if (response.code === 1) {
        ElMessage.success(response.msg || '导入成功')
        if (response.data) {
            const { success_count, skip_count, error_count, errors } = response.data
            let msg = `成功导入 ${success_count} 条`
            if (skip_count > 0) {
                msg += `，跳过 ${skip_count} 条重复记录`
            }
            if (error_count > 0) {
                msg += `，错误 ${error_count} 条`
                if (errors && errors.length > 0) {
                    // 显示前3个错误详情
                    const errorDetails = errors.slice(0, 3).join('；')
                    ElMessage.warning('部分错误：' + errorDetails + (errors.length > 3 ? '...' : ''))
                }
            }
            ElMessage.info(msg)
        }
        baTable.getData()
    } else {
        ElMessage.error(response.msg || '导入失败')
    }
}

const onImportError = (error: any) => {
    let errorMsg = '导入失败'
    
    if (error?.response?.data?.msg) {
        errorMsg += '：' + error.response.data.msg
    } else if (error?.response?.data?.message) {
        errorMsg += '：' + error.response.data.message
    } else if (error?.message) {
        errorMsg += '：' + error.message
    } else if (error?.response?.statusText) {
        errorMsg += '：' + error.response.statusText
    } else if (typeof error === 'string') {
        errorMsg += '：' + error
    } else {
        errorMsg += '：未知错误'
    }
    
    ElMessage.error(errorMsg)
}

const batchEnable = () => {
    const ids = baTable.getSelectionIds()
    if (ids.length === 0) {
        ElMessage.warning('请选择要启用的记录')
        return
    }
    
    ElMessageBox.confirm(`确定要启用选中的 ${ids.length} 条记录吗？`, '提示', {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        type: 'warning',
    }).then(() => {
        createAxios({
            url: '/admin/user.InviteCodeWhitelist/batchStatus',
            method: 'post',
            data: {
                ids: ids,
                status: 1,
            },
        }).then((res) => {
            if (res.code === 1) {
                ElMessage.success('操作成功')
                baTable.getData()
            } else {
                ElMessage.error(res.msg || '操作失败')
            }
        })
    })
}

const batchDisable = () => {
    const ids = baTable.getSelectionIds()
    if (ids.length === 0) {
        ElMessage.warning('请选择要禁用的记录')
        return
    }
    
    ElMessageBox.confirm(`确定要禁用选中的 ${ids.length} 条记录吗？`, '提示', {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        type: 'warning',
    }).then(() => {
        createAxios({
            url: '/admin/user.InviteCodeWhitelist/batchStatus',
            method: 'post',
            data: {
                ids: ids,
                status: 0,
            },
        }).then((res) => {
            if (res.code === 1) {
                ElMessage.success('操作成功')
                baTable.getData()
            } else {
                ElMessage.error(res.msg || '操作失败')
            }
        })
    })
}

const baTable = new baTableClass(
    new baTableApi('/admin/user.InviteCodeWhitelist/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', operator: '=', width: 80 },
            {
                label: '邀请码',
                prop: 'code',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '邀请码',
                width: 130,
            },
            {
                label: '状态',
                prop: 'status',
                align: 'center',
                operator: 'select' as any,
                operatorOptions: [
                    { label: '启用', value: '1' },
                    { label: '禁用', value: '0' },
                ],
                render: 'tag',
                custom: { '1': 'success', '0': 'danger' },
                replaceValue: { '1': '启用', '0': '禁用' },
                width: 100,
            } as any,
            {
                label: '备注',
                prop: 'remark',
                align: 'left',
                operator: 'LIKE',
                operatorPlaceholder: '备注',
                showOverflowTooltip: true,
            },
            {
                label: '创建时间',
                prop: 'create_time',
                align: 'center',
                render: 'datetime',
                sortable: 'custom',
                operator: 'RANGE',
                width: 170,
            },
            {
                label: '操作',
                align: 'center',
                width: '100',
                render: 'buttons',
                buttons: defaultOptButtons(['edit', 'delete']),
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
.single-code-input {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-right: 12px;
}
</style>
