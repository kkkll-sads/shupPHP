<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" title="矿机管理 - 管理已转为矿机状态的藏品，支持查看分红统计和批量操作" type="info" show-icon />

        <!-- 矿机统计卡片 -->
        <el-row :gutter="20" style="margin-bottom: 15px;">
            <el-col :span="6">
                <el-card shadow="hover">
                    <el-statistic title="矿机总数" :value="statistics.total_count" />
                </el-card>
            </el-col>
            <el-col :span="6">
                <el-card shadow="hover">
                    <el-statistic title="矿机总价值" :value="statistics.total_value" :precision="2" prefix="¥" />
                </el-card>
            </el-col>
            <el-col :span="6">
                <el-card shadow="hover">
                    <el-statistic title="今日新增" :value="statistics.today_count" />
                </el-card>
            </el-col>
            <el-col :span="6">
                <el-card shadow="hover">
                    <el-statistic title="涉及用户数" :value="statistics.user_count" />
                </el-card>
            </el-col>
        </el-row>

        <!-- 分红统计卡片 -->
        <el-row :gutter="20" style="margin-bottom: 15px;">
            <el-col :span="6">
                <el-card shadow="hover" class="dividend-card">
                    <el-statistic title="今日分红(余额)" :value="statistics.today_dividend_balance" :precision="2" prefix="¥" value-style="color: #67c23a" />
                </el-card>
            </el-col>
            <el-col :span="6">
                <el-card shadow="hover" class="dividend-card">
                    <el-statistic title="今日分红(消费金)" :value="statistics.today_dividend_score" value-style="color: #e6a23c" />
                </el-card>
            </el-col>
            <el-col :span="6">
                <el-card shadow="hover" class="dividend-card">
                    <el-statistic title="累计分红(余额)" :value="statistics.total_dividend_balance" :precision="2" prefix="¥" value-style="color: #409eff" />
                </el-card>
            </el-col>
            <el-col :span="6">
                <el-card shadow="hover" class="dividend-card">
                    <el-statistic title="累计分红(消费金)" :value="statistics.total_dividend_score" value-style="color: #909399" />
                </el-card>
            </el-col>
        </el-row>

        <!-- 近7日分红趋势 -->
        <el-card shadow="never" style="margin-bottom: 15px;">
            <template #header>
                <span style="font-weight: bold;">近7日分红趋势</span>
            </template>
            <el-table :data="statistics.dividend_trend" stripe size="small">
                <el-table-column prop="date" label="日期" align="center" width="100" />
                <el-table-column prop="count" label="分红笔数" align="center" width="100" />
                <el-table-column prop="balance" label="余额分红(元)" align="center">
                    <template #default="{ row }">
                        <span style="color: #67c23a; font-weight: bold;">¥{{ row.balance }}</span>
                    </template>
                </el-table-column>
                <el-table-column prop="score" label="消费金分红" align="center">
                    <template #default="{ row }">
                        <span style="color: #e6a23c;">{{ row.score }}</span>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>

        <!-- 搜索表单 -->
        <el-card class="search-card" shadow="never" style="margin-bottom: 15px;">
            <el-form :model="searchForm" inline>
                <el-form-item label="手机号">
                    <el-input v-model="searchForm.mobile" placeholder="请输入手机号" clearable style="width: 150px;" />
                </el-form-item>
                <el-form-item label="用户ID">
                    <el-input-number v-model="searchForm.user_id" :min="0" placeholder="用户ID" controls-position="right" style="width: 100px;" />
                </el-form-item>
                <el-form-item label="藏品ID">
                    <el-input-number v-model="searchForm.item_id" :min="0" placeholder="藏品ID" controls-position="right" style="width: 100px;" />
                </el-form-item>
                <el-form-item label="关键词">
                    <el-input v-model="searchForm.quick_search" placeholder="手机号/昵称/藏品名/确权编号" clearable style="width: 200px;" />
                </el-form-item>
                <el-form-item>
                    <el-button type="primary" @click="handleSearch" :loading="loading">
                        <el-icon><Search /></el-icon>
                        搜索
                    </el-button>
                    <el-button @click="handleReset">
                        <el-icon><Refresh /></el-icon>
                        重置
                    </el-button>
                    <el-popconfirm
                        v-if="selectedIds.length > 0"
                        title="确定要批量取消选中的矿机状态吗？"
                        @confirm="handleBatchCancel"
                    >
                        <template #reference>
                            <el-button type="danger">
                                <el-icon><Delete /></el-icon>
                                批量取消 ({{ selectedIds.length }})
                            </el-button>
                        </template>
                    </el-popconfirm>
                </el-form-item>
            </el-form>
        </el-card>

        <!-- 数据表格 -->
        <el-table 
            :data="tableData" 
            v-loading="loading" 
            stripe 
            border 
            style="width: 100%"
            @selection-change="handleSelectionChange"
        >
            <el-table-column type="selection" width="55" align="center" />
            <el-table-column prop="id" label="记录ID" width="80" align="center" />
            <el-table-column prop="user_id" label="用户ID" width="80" align="center" />
            <el-table-column prop="mobile" label="手机号" width="130" align="center" />
            <el-table-column prop="nickname" label="昵称" width="100" align="center" />
            <el-table-column prop="item_id" label="藏品ID" width="80" align="center" />
            <el-table-column prop="item_title" label="藏品名称" min-width="150" align="center" />
            <el-table-column prop="buy_price" label="购买价格" width="100" align="center">
                <template #default="{ row }">
                    <span style="color: #f56c6c; font-weight: bold;">¥{{ row.buy_price }}</span>
                </template>
            </el-table-column>
            <el-table-column prop="current_price" label="当前价格" width="100" align="center">
                <template #default="{ row }">
                    ¥{{ row.current_price }}
                </template>
            </el-table-column>
            <el-table-column prop="session_title" label="所属专场" width="120" align="center" />
            <el-table-column prop="mining_start_time_text" label="矿机启动时间" width="160" align="center" />
            <el-table-column prop="mining_days" label="运行天数" width="90" align="center">
                <template #default="{ row }">
                    <el-tag type="success" size="small">{{ row.mining_days }} 天</el-tag>
                </template>
            </el-table-column>
            <el-table-column prop="last_dividend_time_text" label="上次分红时间" width="160" align="center">
                <template #default="{ row }">
                    {{ row.last_dividend_time_text || '未分红' }}
                </template>
            </el-table-column>
            <el-table-column prop="asset_code" label="确权编号" width="160" align="center">
                <template #default="{ row }">
                    <span style="font-size: 12px;">{{ row.asset_code || '-' }}</span>
                </template>
            </el-table-column>
            <el-table-column label="操作" width="100" align="center" fixed="right">
                <template #default="{ row }">
                    <el-popconfirm
                        title="确定要取消此藏品的矿机状态吗？"
                        @confirm="handleCancelMining(row)"
                    >
                        <template #reference>
                            <el-button type="danger" link size="small">取消矿机</el-button>
                        </template>
                    </el-popconfirm>
                </template>
            </el-table-column>
        </el-table>

        <!-- 分页 -->
        <div class="pagination-container" style="margin-top: 15px; text-align: right;">
            <el-pagination
                v-model:current-page="pagination.page"
                v-model:page-size="pagination.limit"
                :page-sizes="[10, 20, 50, 100]"
                :total="pagination.total"
                layout="total, sizes, prev, pager, next, jumper"
                @size-change="handleSearch"
                @current-change="handleSearch"
            />
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Search, Refresh, Delete } from '@element-plus/icons-vue'
import { baTableApi } from '/@/api/common'

defineOptions({
    name: 'collection/miningMachine',
})

const api = new baTableApi('/admin/collection.MiningMachine/')

const loading = ref(false)
const tableData = ref<any[]>([])
const selectedIds = ref<number[]>([])
const pagination = reactive({
    page: 1,
    limit: 20,
    total: 0,
})

const statistics = reactive({
    total_count: 0,
    total_value: 0,
    today_count: 0,
    user_count: 0,
    today_dividend_balance: 0,
    today_dividend_score: 0,
    total_dividend_balance: 0,
    total_dividend_score: 0,
    dividend_trend: [] as Array<{date: string, balance: number, score: number, count: number}>,
})

const searchForm = reactive({
    mobile: '',
    user_id: undefined as number | undefined,
    item_id: undefined as number | undefined,
    quick_search: '',
})

// 获取统计数据
const fetchStatistics = async () => {
    try {
        const res = await api.postData('statistics', {})
        if (res.code === 1) {
            statistics.total_count = res.data?.total_count || 0
            statistics.total_value = res.data?.total_value || 0
            statistics.today_count = res.data?.today_count || 0
            statistics.user_count = res.data?.user_count || 0
            // 分红统计
            statistics.today_dividend_balance = res.data?.today_dividend_balance || 0
            statistics.today_dividend_score = res.data?.today_dividend_score || 0
            statistics.total_dividend_balance = res.data?.total_dividend_balance || 0
            statistics.total_dividend_score = res.data?.total_dividend_score || 0
            statistics.dividend_trend = res.data?.dividend_trend || []
        }
    } catch (error) {
        console.error('获取统计数据失败:', error)
    }
}

// 搜索
const handleSearch = async () => {
    loading.value = true
    try {
        const params: any = {
            page: pagination.page,
            limit: pagination.limit,
        }
        if (searchForm.mobile) params.mobile = searchForm.mobile
        if (searchForm.user_id) params.user_id = searchForm.user_id
        if (searchForm.item_id) params.item_id = searchForm.item_id
        if (searchForm.quick_search) params.quick_search = searchForm.quick_search

        const res = await api.index(params)
        if (res.code === 1) {
            tableData.value = res.data?.list || []
            pagination.total = res.data?.total || 0
        } else {
            ElMessage.error(res.msg || '查询失败')
        }
    } catch (error: any) {
        ElMessage.error(error.message || '查询失败')
    } finally {
        loading.value = false
    }
}

// 重置
const handleReset = () => {
    searchForm.mobile = ''
    searchForm.user_id = undefined
    searchForm.item_id = undefined
    searchForm.quick_search = ''
    pagination.page = 1
    handleSearch()
}

// 多选
const handleSelectionChange = (selection: any[]) => {
    selectedIds.value = selection.map(item => item.id)
}

// 取消矿机状态
const handleCancelMining = async (row: any) => {
    try {
        const res = await api.postData('cancelMining', { user_collection_id: row.id })
        if (res.code === 1) {
            ElMessage.success(res.msg || '操作成功')
            handleSearch()
            fetchStatistics()
        } else {
            ElMessage.error(res.msg || '操作失败')
        }
    } catch (error: any) {
        ElMessage.error(error.message || '操作失败')
    }
}

// 批量取消矿机状态
const handleBatchCancel = async () => {
    if (selectedIds.value.length === 0) {
        ElMessage.warning('请先选择要操作的记录')
        return
    }
    try {
        const res = await api.postData('batchCancelMining', { ids: selectedIds.value })
        if (res.code === 1) {
            ElMessage.success(res.msg || '操作成功')
            selectedIds.value = []
            handleSearch()
            fetchStatistics()
        } else {
            ElMessage.error(res.msg || '操作失败')
        }
    } catch (error: any) {
        ElMessage.error(error.message || '操作失败')
    }
}

// 页面加载时执行
onMounted(() => {
    fetchStatistics()
    handleSearch()
})
</script>

<style scoped lang="scss">
.search-card {
    :deep(.el-card__body) {
        padding: 15px 15px 0 15px;
    }
}
</style>
