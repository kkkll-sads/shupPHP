<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" title="用户藏品管理 - 可按手机号、用户ID、藏品名称搜索用户持有的藏品" type="info" show-icon />

        <!-- 搜索表单 -->
        <el-card class="search-card" shadow="never" style="margin-bottom: 15px;">
            <el-form :model="searchForm" inline>
                <el-form-item label="手机号">
                    <el-input v-model="searchForm.mobile" placeholder="请输入手机号" clearable style="width: 180px;" />
                </el-form-item>
                <el-form-item label="用户ID">
                    <el-input-number v-model="searchForm.user_id" :min="0" placeholder="用户ID" controls-position="right" style="width: 120px;" />
                </el-form-item>
                <el-form-item label="藏品ID">
                    <el-input-number v-model="searchForm.item_id" :min="0" placeholder="藏品ID" controls-position="right" style="width: 120px;" />
                </el-form-item>
                <el-form-item label="寄售状态">
                    <el-select v-model="searchForm.consignment_status" placeholder="全部" clearable style="width: 120px;">
                        <el-option label="未寄售" :value="0" />
                        <el-option label="寄售中" :value="1" />
                        <el-option label="已售出" :value="2" />
                        <el-option label="矿机中" :value="3" />
                    </el-select>
                </el-form-item>
                <el-form-item label="矿机状态">
                    <el-select v-model="searchForm.mining_status" placeholder="全部" clearable style="width: 120px;">
                        <el-option label="未转矿机" :value="0" />
                        <el-option label="矿机运行中" :value="1" />
                    </el-select>
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
                    <el-button type="success" @click="handleUserStats" :disabled="!searchForm.mobile && !searchForm.user_id">
                        <el-icon><User /></el-icon>
                        用户统计
                    </el-button>
                </el-form-item>
            </el-form>
        </el-card>

        <!-- 数据表格 -->
        <el-table :data="tableData" v-loading="loading" stripe border style="width: 100%">
            <el-table-column prop="id" label="记录ID" width="80" align="center" />
            <el-table-column prop="user_id" label="用户ID" width="80" align="center" />
            <el-table-column prop="mobile" label="手机号" width="130" align="center" />
            <el-table-column prop="nickname" label="昵称" width="120" align="center" />
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
            <el-table-column prop="appreciation" label="增值" width="100" align="center">
                <template #default="{ row }">
                    <span :style="{ color: row.appreciation >= 0 ? '#67c23a' : '#f56c6c' }">
                        {{ row.appreciation >= 0 ? '+' : '' }}{{ row.appreciation }}
                    </span>
                </template>
            </el-table-column>
            <el-table-column prop="consignment_status_text" label="寄售状态" width="100" align="center">
                <template #default="{ row }">
                    <el-tag 
                        :type="row.consignment_status === 0 ? 'info' : row.consignment_status === 1 ? 'warning' : 'success'"
                        size="small"
                    >
                        {{ row.consignment_status_text }}
                    </el-tag>
                </template>
            </el-table-column>
            <el-table-column prop="mining_status_text" label="矿机状态" width="100" align="center">
                <template #default="{ row }">
                    <el-tag 
                        :type="row.mining_status === 1 ? 'danger' : 'info'"
                        size="small"
                        effect="plain"
                    >
                        {{ row.mining_status_text }}
                    </el-tag>
                </template>
            </el-table-column>
            <el-table-column prop="session_title" label="所属专场" width="120" align="center" />
            <el-table-column prop="asset_code" label="确权编号" width="160" align="center">
                <template #default="{ row }">
                    <span style="font-size: 12px;">{{ row.asset_code || '-' }}</span>
                </template>
            </el-table-column>
            <el-table-column prop="buy_time_text" label="购买时间" width="160" align="center" />
            <el-table-column label="操作" width="100" align="center" fixed="right">
                <template #default="{ row }">
                    <el-button type="primary" link size="small" @click="handleViewDetail(row)">详情</el-button>
                    <el-popconfirm
                        v-if="row.mining_status === 0 && row.consignment_status !== 2 && row.delivery_status === 0"
                        title="确定要将此藏品转为矿机吗？转为矿机后将强制锁仓分红，且不可逆！"
                        @confirm="handleToMining(row)"
                    >
                        <template #reference>
                            <el-button type="danger" link size="small">转矿机</el-button>
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

        <!-- 用户统计弹窗 -->
        <el-dialog v-model="statsDialog.visible" title="用户藏品统计" width="80%" top="5vh">
            <div v-loading="statsDialog.loading">
                <template v-if="statsDialog.data">
                    <!-- 用户信息 -->
                    <el-descriptions :column="4" border style="margin-bottom: 20px;">
                        <el-descriptions-item label="用户ID">{{ statsDialog.data.user?.id }}</el-descriptions-item>
                        <el-descriptions-item label="手机号">{{ statsDialog.data.user?.mobile }}</el-descriptions-item>
                        <el-descriptions-item label="昵称">{{ statsDialog.data.user?.nickname }}</el-descriptions-item>
                        <el-descriptions-item label="注册时间">{{ statsDialog.data.user?.create_time }}</el-descriptions-item>
                    </el-descriptions>

                    <!-- 统计数据 -->
                    <el-row :gutter="20" style="margin-bottom: 20px;">
                        <el-col :span="4">
                            <el-statistic title="藏品总数" :value="statsDialog.data.stats?.total_count || 0" />
                        </el-col>
                        <el-col :span="4">
                            <el-statistic title="总价值" :value="statsDialog.data.stats?.total_value || 0" :precision="2" prefix="¥" />
                        </el-col>
                        <el-col :span="4">
                            <el-statistic title="平均价格" :value="statsDialog.data.stats?.avg_price || 0" :precision="2" prefix="¥" />
                        </el-col>
                        <el-col :span="4">
                            <el-statistic title="持有中" :value="statsDialog.data.stats?.holding || 0" />
                        </el-col>
                        <el-col :span="4">
                            <el-statistic title="寄售中" :value="statsDialog.data.stats?.consigning || 0" />
                        </el-col>
                        <el-col :span="4">
                            <el-statistic title="已售出" :value="statsDialog.data.stats?.sold || 0" />
                        </el-col>
                        <el-col :span="4">
                            <el-statistic title="矿机中" :value="statsDialog.data.stats?.mining || 0" />
                        </el-col>
                    </el-row>

                    <!-- 藏品列表 -->
                    <el-table :data="statsDialog.data.collections || []" stripe border max-height="400">
                        <el-table-column prop="id" label="记录ID" width="80" align="center" />
                        <el-table-column prop="item_id" label="藏品ID" width="80" align="center" />
                        <el-table-column prop="title" label="藏品名称" min-width="150" align="center" />
                        <el-table-column prop="buy_price" label="购买价格" width="100" align="center">
                            <template #default="{ row }">¥{{ row.buy_price }}</template>
                        </el-table-column>
                        <el-table-column prop="current_price" label="当前价格" width="100" align="center">
                            <template #default="{ row }">¥{{ row.current_price }}</template>
                        </el-table-column>
                        <el-table-column prop="appreciation" label="增值" width="100" align="center">
                            <template #default="{ row }">
                                <span :style="{ color: row.appreciation >= 0 ? '#67c23a' : '#f56c6c' }">
                                    {{ row.appreciation >= 0 ? '+' : '' }}{{ row.appreciation }}
                                </span>
                            </template>
                        </el-table-column>
                        <el-table-column prop="consignment_status_text" label="寄售状态" width="100" align="center">
                            <template #default="{ row }">
                                <el-tag 
                                    :type="row.consignment_status === 0 ? 'info' : row.consignment_status === 1 ? 'warning' : 'success'"
                                    size="small"
                                >
                                    {{ row.consignment_status_text }}
                                </el-tag>
                            </template>
                        </el-table-column>
                        <el-table-column prop="mining_status_text" label="矿机状态" width="100" align="center">
                            <template #default="{ row }">
                                <el-tag 
                                    :type="row.mining_status === 1 ? 'danger' : 'info'"
                                    size="small"
                                    effect="plain"
                                >
                                    {{ row.mining_status_text }}
                                </el-tag>
                            </template>
                        </el-table-column>
                        <el-table-column prop="session_title" label="所属专场" width="120" align="center" />
                        <el-table-column prop="buy_time_text" label="购买时间" width="160" align="center" />
                    </el-table>
                </template>
            </div>
        </el-dialog>

        <!-- 详情弹窗 -->
        <el-dialog v-model="detailDialog.visible" title="藏品详情" width="70%">
            <div v-loading="detailDialog.loading">
                <template v-if="detailDialog.data">
                    <el-descriptions :column="2" border>
                        <el-descriptions-item label="记录ID">{{ detailDialog.data.row?.id }}</el-descriptions-item>
                        <el-descriptions-item label="用户ID">{{ detailDialog.data.row?.user_id }}</el-descriptions-item>
                        <el-descriptions-item label="手机号">{{ detailDialog.data.row?.mobile }}</el-descriptions-item>
                        <el-descriptions-item label="昵称">{{ detailDialog.data.row?.nickname }}</el-descriptions-item>
                        <el-descriptions-item label="藏品ID">{{ detailDialog.data.row?.item_id }}</el-descriptions-item>
                        <el-descriptions-item label="藏品名称">{{ detailDialog.data.row?.title }}</el-descriptions-item>
                        <el-descriptions-item label="购买价格">¥{{ detailDialog.data.row?.price }}</el-descriptions-item>
                        <el-descriptions-item label="当前价格">¥{{ detailDialog.data.row?.current_price }}</el-descriptions-item>
                        <el-descriptions-item label="发行价格">¥{{ detailDialog.data.row?.issue_price }}</el-descriptions-item>
                        <el-descriptions-item label="所属专场">{{ detailDialog.data.row?.session_title }}</el-descriptions-item>
                        <el-descriptions-item label="资产包">{{ detailDialog.data.row?.package_name || '-' }}</el-descriptions-item>
                        <el-descriptions-item label="订单号">{{ detailDialog.data.row?.order_no || '-' }}</el-descriptions-item>
                        <el-descriptions-item label="确权编号" :span="2">{{ detailDialog.data.row?.asset_code || '-' }}</el-descriptions-item>
                        <el-descriptions-item label="存证指纹" :span="2">
                            <span style="font-size: 12px; word-break: break-all;">{{ detailDialog.data.row?.fingerprint || '-' }}</span>
                        </el-descriptions-item>
                        <el-descriptions-item label="购买时间">{{ detailDialog.data.row?.buy_time_text }}</el-descriptions-item>
                        <el-descriptions-item label="购买时间">{{ detailDialog.data.row?.buy_time_text }}</el-descriptions-item>
                        <el-descriptions-item label="创建时间">{{ detailDialog.data.row?.create_time_text }}</el-descriptions-item>
                        <el-descriptions-item label="矿机状态">
                            <el-tag :type="detailDialog.data.row?.mining_status === 1 ? 'danger' : 'info'">{{ detailDialog.data.row?.mining_status_text }}</el-tag>
                        </el-descriptions-item>
                        <el-descriptions-item label="矿机启动时间">{{ detailDialog.data.row?.mining_start_time_text || '-' }}</el-descriptions-item>
                        <el-descriptions-item label="上次分红时间">{{ detailDialog.data.row?.last_dividend_time_text || '-' }}</el-descriptions-item>
                        <el-descriptions-item label="确权状态">{{ detailDialog.data.row?.rights_status || '-' }}</el-descriptions-item>
                        <el-descriptions-item label="合约地址" :span="2">{{ detailDialog.data.row?.contract_no || '-' }}</el-descriptions-item>
                        <el-descriptions-item label="权益哈希" :span="2">
                            <span style="font-size: 12px; word-break: break-all;">{{ detailDialog.data.row?.rights_hash || '-' }}</span>
                        </el-descriptions-item>
                        <el-descriptions-item label="区块高度">{{ detailDialog.data.row?.block_height || '-' }}</el-descriptions-item>
                    </el-descriptions>

                    <!-- 寄售记录 -->
                    <div style="margin-top: 20px;" v-if="detailDialog.data.consignments?.length">
                        <h4>寄售记录</h4>
                        <el-table :data="detailDialog.data.consignments" stripe border>
                            <el-table-column prop="id" label="寄售ID" width="80" align="center" />
                            <el-table-column prop="price" label="寄售价格" width="100" align="center">
                                <template #default="{ row }">¥{{ row.price }}</template>
                            </el-table-column>
                            <el-table-column prop="status_text" label="状态" width="100" align="center">
                                <template #default="{ row }">
                                    <el-tag 
                                        :type="row.status === 2 ? 'success' : row.status === 1 ? 'warning' : 'info'"
                                        size="small"
                                    >
                                        {{ row.status_text }}
                                    </el-tag>
                                </template>
                            </el-table-column>
                            <el-table-column prop="create_time_text" label="创建时间" width="160" align="center" />
                            <el-table-column prop="update_time_text" label="更新时间" width="160" align="center" />
                        </el-table>
                    </div>
                </template>
            </div>
        </el-dialog>
    </div>
</template>

<script setup lang="ts">
import { ref, reactive } from 'vue'
import { ElMessage } from 'element-plus'
import { Search, Refresh, User } from '@element-plus/icons-vue'
import { baTableApi } from '/@/api/common'

defineOptions({
    name: 'collection/userCollection',
})

const api = new baTableApi('/admin/collection.UserCollection/')

const loading = ref(false)
const tableData = ref<any[]>([])
const pagination = reactive({
    page: 1,
    limit: 20,
    total: 0,
})

const searchForm = reactive({
    mobile: '',
    user_id: undefined as number | undefined,
    item_id: undefined as number | undefined,

    consignment_status: undefined as number | undefined,
    mining_status: undefined as number | undefined,
    quick_search: '',
})

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
        if (searchForm.consignment_status !== undefined) params.consignment_status = searchForm.consignment_status
        if (searchForm.mining_status !== undefined) params.mining_status = searchForm.mining_status
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

    searchForm.consignment_status = undefined
    searchForm.mining_status = undefined
    searchForm.quick_search = ''
    pagination.page = 1
    handleSearch()
}

// 用户统计弹窗
const statsDialog = reactive({
    visible: false,
    loading: false,
    data: null as any,
})

const handleUserStats = async () => {
    if (!searchForm.mobile && !searchForm.user_id) {
        ElMessage.warning('请先输入手机号或用户ID')
        return
    }
    
    statsDialog.visible = true
    statsDialog.loading = true
    statsDialog.data = null
    
    try {
        const params: any = {}
        if (searchForm.mobile) params.mobile = searchForm.mobile
        if (searchForm.user_id) params.user_id = searchForm.user_id
        
        const res = await api.postData('userStats', params)
        if (res.code === 1) {
            statsDialog.data = res.data
        } else {
            ElMessage.error(res.msg || '获取统计失败')
        }
    } catch (error: any) {
        ElMessage.error(error.message || '获取统计失败')
    } finally {
        statsDialog.loading = false
    }
}

// 详情弹窗
const detailDialog = reactive({
    visible: false,
    loading: false,
    data: null as any,
})

const handleViewDetail = async (row: any) => {
    detailDialog.visible = true
    detailDialog.loading = true
    detailDialog.data = null
    
    try {
        const res = await api.postData('detail', { id: row.id })
        if (res.code === 1) {
            detailDialog.data = res.data
        } else {
            ElMessage.error(res.msg || '获取详情失败')
        }
    } catch (error: any) {
        ElMessage.error(error.message || '获取详情失败')
    } finally {
        detailDialog.loading = false
    }

}

// 转为矿机
const handleToMining = async (row: any) => {
    try {
        // 调用后端API（注意：这里如果是管理后台直接调用API，路径可能不同，或者需要通过admin控制器中转）
        // 这里假设已经在 admin/collection.UserCollection 中添加了 toMining 方法，或者我们直接调用 api 模块的接口
        // 由于是后台管理，通常建议在 Admin 控制器中封装一个方法。
        // 但既然我们刚才是在 API 模块加的，我们可以尝试直接请求 API 模块（如果权限允许），或者更规范的做法是在 Admin 模块也加上。
        
        // 考虑到鉴权，我们应该在 Admin 的 UserCollection 控制器中也添加一个 toMining 方法，调用 API 或者直接执行逻辑。
        // 临时方案：如果拥有后台权限，通常也拥有前台操作权限（或者我们添加一个后台专用的接口）。
        // 让我们先假设后台也有这个接口，或者我们刚才应该加在 Admin 里。
        // 这里的 baTableApi 是封装了 admin 路径的，所以我们去 Admin UserCollection 加一个 toMining 方法吧。
        
        // 暂时先用 postData 调用 admin 的 toMining
        const res = await api.postData('toMining', { user_collection_id: row.id })
        if (res.code === 1) {
            ElMessage.success(res.msg || '操作成功')
            handleSearch() // 刷新列表
        } else {
            ElMessage.error(res.msg || '操作失败')
        }
    } catch (error: any) {
        ElMessage.error(error.message || '操作失败')
    }
}

// 页面加载时执行一次查询
handleSearch()
</script>

<style scoped lang="scss">
.search-card {
    :deep(.el-card__body) {
        padding: 15px 15px 0 15px;
    }
}
</style>
