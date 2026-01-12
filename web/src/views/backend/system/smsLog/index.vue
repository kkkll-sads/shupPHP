<template>
    <div class="default-main ba-table-box">
        <el-alert title="短信发送记录管理" type="info" :closable="false" class="mb-4">
            <template #default>
                <div class="stats-row">
                    <el-tag>总计: {{ stats.total }}</el-tag>
                    <el-tag type="success">发送成功: {{ stats.success }}</el-tag>
                    <el-tag type="danger">发送失败: {{ stats.failed }}</el-tag>
                    <el-tag type="info">今日发送: {{ stats.today_total }}</el-tag>
                </div>
            </template>
        </el-alert>
        
        <el-card shadow="hover">
            <!-- 搜索栏 -->
            <div class="table-header mb-4">
                <el-row :gutter="20" class="search-row">
                    <el-col :span="6">
                        <el-input v-model="searchParams.quickSearch" placeholder="搜索手机号/内容" clearable @keyup.enter="fetchList" />
                    </el-col>
                    <el-col :span="4">
                        <el-select v-model="searchParams.status" placeholder="发送状态" clearable>
                            <el-option label="待发送" :value="0" />
                            <el-option label="发送成功" :value="1" />
                            <el-option label="发送失败" :value="2" />
                        </el-select>
                    </el-col>
                    <el-col :span="4">
                        <el-select v-model="searchParams.platform" placeholder="短信平台" clearable>
                            <el-option label="短信宝" value="smsbao" />
                            <el-option label="麦讯通" value="weiwebs" />
                        </el-select>
                    </el-col>
                    <el-col :span="10">
                        <el-button type="primary" @click="fetchList"><Icon name="el-icon-Search" /> 搜索</el-button>
                        <el-button @click="resetSearch"><Icon name="el-icon-RefreshLeft" /> 重置</el-button>
                        <el-button type="danger" @click="clearAll" :disabled="tableData.length === 0"><Icon name="el-icon-Delete" /> 清空全部</el-button>
                    </el-col>
                </el-row>
            </div>
            
            <!-- 数据表格 -->
            <el-table v-loading="loading" :data="tableData" stripe border style="width: 100%">
                <el-table-column prop="id" label="ID" width="80" />
                <el-table-column prop="mobile" label="手机号" width="130" />
                <el-table-column prop="content" label="短信内容" min-width="250" show-overflow-tooltip />
                <el-table-column prop="platform_text" label="短信平台" width="100" />
                <el-table-column prop="status" label="发送状态" width="100" align="center">
                    <template #default="{ row }">
                        <el-tag :type="row.status === 1 ? 'success' : row.status === 2 ? 'danger' : 'info'">
                            {{ row.status_text }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="result_code" label="返回码" width="100" />
                <el-table-column prop="result_msg" label="返回信息" width="150" show-overflow-tooltip />
                <el-table-column prop="send_time_text" label="发送时间" width="170" />
                <el-table-column prop="create_time_text" label="创建时间" width="170" />
                <el-table-column label="操作" width="100" fixed="right">
                    <template #default="{ row }">
                        <el-button type="danger" size="small" link @click="handleDelete(row.id)">删除</el-button>
                    </template>
                </el-table-column>
            </el-table>
            
            <!-- 分页 -->
            <div class="table-pagination mt-4">
                <el-pagination
                    v-model:current-page="pagination.page"
                    v-model:page-size="pagination.limit"
                    :page-sizes="[10, 20, 50, 100]"
                    :total="pagination.total"
                    layout="total, sizes, prev, pager, next, jumper"
                    @size-change="fetchList"
                    @current-change="fetchList"
                />
            </div>
        </el-card>
    </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import createAxios from '/@/utils/axios'

const loading = ref(false)
const tableData = ref<any[]>([])
const pagination = reactive({
    page: 1,
    limit: 10,
    total: 0,
})
const searchParams = reactive({
    quickSearch: '',
    status: '',
    platform: '',
})
const stats = reactive({
    total: 0,
    success: 0,
    failed: 0,
    pending: 0,
    today_total: 0,
    today_success: 0,
})

const fetchList = async () => {
    loading.value = true
    try {
        const res = await createAxios({
            url: '/admin/system.SmsLog/index',
            method: 'get',
            params: {
                page: pagination.page,
                limit: pagination.limit,
                quickSearch: searchParams.quickSearch,
                status: searchParams.status,
                platform: searchParams.platform,
            },
        })
        if (res.data.code === 1) {
            tableData.value = res.data.data.list || []
            pagination.total = res.data.data.total || 0
        }
    } catch (e) {
        console.error(e)
    } finally {
        loading.value = false
    }
}

const fetchStats = async () => {
    try {
        const res = await createAxios({
            url: '/admin/system.SmsLog/stats',
            method: 'get',
        })
        if (res.data.code === 1) {
            Object.assign(stats, res.data.data)
        }
    } catch (e) {
        console.error(e)
    }
}

const resetSearch = () => {
    searchParams.quickSearch = ''
    searchParams.status = ''
    searchParams.platform = ''
    pagination.page = 1
    fetchList()
}

const handleDelete = async (id: number) => {
    try {
        await ElMessageBox.confirm('确定删除该条记录吗？', '提示', {
            confirmButtonText: '确定',
            cancelButtonText: '取消',
            type: 'warning',
        })
        const res = await createAxios({
            url: '/admin/system.SmsLog/del',
            method: 'post',
            data: { ids: [id] },
        })
        if (res.data.code === 1) {
            ElMessage.success('删除成功')
            fetchList()
            fetchStats()
        } else {
            ElMessage.error(res.data.msg || '删除失败')
        }
    } catch (e) {
        // 取消操作
    }
}

const clearAll = async () => {
    try {
        await ElMessageBox.confirm('确定清空全部短信记录吗？此操作不可恢复！', '警告', {
            confirmButtonText: '确定清空',
            cancelButtonText: '取消',
            type: 'error',
        })
        const res = await createAxios({
            url: '/admin/system.SmsLog/clear',
            method: 'post',
        })
        if (res.data.code === 1) {
            ElMessage.success(res.data.msg || '清空成功')
            fetchList()
            fetchStats()
        } else {
            ElMessage.error(res.data.msg || '清空失败')
        }
    } catch (e) {
        // 取消操作
    }
}

onMounted(() => {
    fetchList()
    fetchStats()
})
</script>

<style scoped lang="scss">
.stats-row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.search-row {
    display: flex;
    align-items: center;
}

.table-pagination {
    display: flex;
    justify-content: flex-end;
}

.mb-4 {
    margin-bottom: 16px;
}

.mt-4 {
    margin-top: 16px;
}
</style>
