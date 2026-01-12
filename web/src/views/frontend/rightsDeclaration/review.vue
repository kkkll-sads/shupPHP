<template>
    <div class="review-status-page">
        <div class="container">
            <h1 class="page-title">确权审核状态</h1>

            <!-- 统计信息 -->
            <div class="stats-section">
                <el-row :gutter="20">
                    <el-col :span="8">
                        <el-card class="stat-card">
                            <div class="stat-content">
                                <div class="stat-number">{{ reviewStats.pending_count }}</div>
                                <div class="stat-label">待审核</div>
                            </div>
                        </el-card>
                    </el-col>
                    <el-col :span="8">
                        <el-card class="stat-card">
                            <div class="stat-content">
                                <div class="stat-number">{{ reviewStats.approved_count }}</div>
                                <div class="stat-label">已通过</div>
                            </div>
                        </el-card>
                    </el-col>
                    <el-col :span="8">
                        <el-card class="stat-card">
                            <div class="stat-content">
                                <div class="stat-number">{{ reviewStats.total - reviewStats.pending_count - reviewStats.approved_count }}</div>
                                <div class="stat-label">其他状态</div>
                            </div>
                        </el-card>
                    </el-col>
                </el-row>
            </div>

            <!-- 筛选和列表 -->
            <div class="content-section">
                <el-card>
                    <template #header>
                        <div class="card-header">
                            <span>申报记录</span>
                            <el-button type="primary" @click="goToDeclaration" size="small">
                                提交新申报
                            </el-button>
                        </div>
                    </template>

                    <!-- 筛选 -->
                    <div class="filter-section">
                        <el-select v-model="filterStatus" placeholder="筛选状态" @change="loadReviewData" clearable style="width: 150px; margin-right: 10px;">
                            <el-option label="待审核" value="pending" />
                            <el-option label="已通过" value="approved" />
                            <el-option label="已拒绝" value="rejected" />
                            <el-option label="已撤销" value="cancelled" />
                        </el-select>
                    </div>

                    <!-- 列表 -->
                    <div v-loading="loading" class="list-section">
                        <div v-if="reviewList.length === 0" class="empty-state">
                            <el-empty description="暂无申报记录">
                                <el-button type="primary" @click="goToDeclaration">
                                    提交申报
                                </el-button>
                            </el-empty>
                        </div>

                        <div v-else class="declaration-list">
                            <div
                                v-for="item in reviewList"
                                :key="item.id"
                                class="declaration-item"
                            >
                                <el-card class="item-card" shadow="hover">
                                    <div class="item-header">
                                        <div class="item-info">
                                            <div class="item-title">
                                                申报金额：
                                                <span class="amount-text">{{ formatAmount(item.amount) }}</span>
                                            </div>
                                            <div class="item-meta">
                                                <span class="voucher-type">{{ item.voucher_type_text }}</span>
                                                <el-tag :type="getStatusTag(item.status)" size="small">
                                                    {{ item.status_text }}
                                                </el-tag>
                                            </div>
                                        </div>
                                        <div class="item-time">
                                            {{ item.create_time_text }}
                                        </div>
                                    </div>

                                    <div class="item-content">
                                        <div v-if="item.images_array && item.images_array.length > 0" class="images-preview">
                                            <el-image
                                                v-for="(image, index) in item.images_array.slice(0, 3)"
                                                :key="index"
                                                :src="image"
                                                :preview-src-list="item.images_array"
                                                :preview-teleported="true"
                                                class="preview-image"
                                                fit="cover"
                                            />
                                            <div v-if="item.images_array.length > 3" class="more-images">
                                                +{{ item.images_array.length - 3 }}
                                            </div>
                                        </div>

                                        <div v-if="item.remark" class="remark-section">
                                            <div class="remark-label">备注：</div>
                                            <div class="remark-content">{{ item.remark }}</div>
                                        </div>

                                        <div v-if="item.review_remark" class="review-remark-section">
                                            <div class="remark-label">审核备注：</div>
                                            <div class="remark-content">{{ item.review_remark }}</div>
                                        </div>

                                        <div v-if="item.review_time_text" class="review-time">
                                            审核时间：{{ item.review_time_text }}
                                        </div>
                                    </div>

                                    <div class="item-actions">
                                        <el-button
                                            v-if="item.status === 'pending'"
                                            type="danger"
                                            size="small"
                                            @click="cancelDeclaration(item.id)"
                                        >
                                            撤销申报
                                        </el-button>
                                    </div>
                                </el-card>
                            </div>
                        </div>

                        <!-- 分页 -->
                        <div v-if="reviewList.length > 0" class="pagination-section">
                            <el-pagination
                                v-model:current-page="currentPage"
                                v-model:page-size="pageSize"
                                :page-sizes="[10, 20, 50]"
                                :total="totalCount"
                                layout="total, sizes, prev, pager, next, jumper"
                                @size-change="handleSizeChange"
                                @current-change="handleCurrentChange"
                            />
                        </div>
                    </div>
                </el-card>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted, reactive } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import createAxios from '/@/utils/axios'

// 响应式数据
const loading = ref(false)
const currentPage = ref(1)
const pageSize = ref(20)
const totalCount = ref(0)
const filterStatus = ref('')
const reviewList = ref([])
const reviewStats = reactive({
    pending_count: 0,
    approved_count: 0,
    total: 0
})

// 格式化金额
const formatAmount = (amount: number | string) => {
    const num = Number(amount)
    return Number.isNaN(num) ? '0.00' : num.toFixed(2) + ' 元'
}

// 获取状态标签类型
const getStatusTag = (status: string): 'success' | 'info' | 'warning' | 'danger' => {
    const tagMap: Record<string, 'success' | 'info' | 'warning' | 'danger'> = {
        pending: 'warning',
        approved: 'success',
        rejected: 'danger',
        cancelled: 'info',
    }
    return tagMap[status] || 'info'
}

// 跳转到申报页面
const goToDeclaration = () => {
    window.location.href = '/rightsDeclaration'
}

// 加载审核数据
const loadReviewData = async () => {
    loading.value = true
    try {
        const params: any = {
            page: currentPage.value,
            limit: pageSize.value
        }

        if (filterStatus.value) {
            params.status = filterStatus.value
        }

        const res = await createAxios({
            url: '/api/rightsDeclaration/reviewStatus',
            method: 'get',
            params
        })

        if (res.code === 1) {
            reviewList.value = res.data.list
            totalCount.value = res.data.total
            reviewStats.pending_count = res.data.pending_count
            reviewStats.approved_count = res.data.approved_count
            reviewStats.total = res.data.total
        } else {
            ElMessage.error(res.msg || '获取数据失败')
        }
    } catch (error: any) {
        console.error('获取审核数据失败:', error)
        ElMessage.error(error?.msg || error?.response?.data?.msg || '获取数据失败')
    } finally {
        loading.value = false
    }
}

// 撤销申报
const cancelDeclaration = async (id: number) => {
    try {
        await ElMessageBox.confirm(
            '确定要撤销这个申报吗？撤销后无法恢复。',
            '撤销确认',
            {
                confirmButtonText: '确定撤销',
                cancelButtonText: '取消',
                type: 'warning',
            }
        )

        const res = await createAxios({
            url: '/api/rightsDeclaration/cancel',
            method: 'post',
            data: { id }
        })

        if (res.code === 1) {
            ElMessage.success('申报已撤销')
            loadReviewData() // 重新加载数据
        } else {
            ElMessage.error(res.msg || '撤销失败')
        }
    } catch (error: any) {
        if (error !== 'cancel') {
            console.error('撤销申报失败:', error)
            ElMessage.error(error?.msg || error?.response?.data?.msg || '撤销失败')
        }
    }
}

// 分页大小改变
const handleSizeChange = (size: number) => {
    pageSize.value = size
    currentPage.value = 1
    loadReviewData()
}

// 当前页改变
const handleCurrentChange = (page: number) => {
    currentPage.value = page
    loadReviewData()
}

// 页面加载时获取数据
onMounted(() => {
    loadReviewData()
})
</script>

<style scoped lang="scss">
.review-status-page {
    min-height: 100vh;
    background-color: #f5f5f5;
    padding: 20px 0;
}

.container {
    max-width: 1000px;
    margin: 0 auto;
    padding: 0 20px;
}

.page-title {
    text-align: center;
    color: #333;
    margin-bottom: 30px;
    font-size: 28px;
    font-weight: 500;
}

.stats-section {
    margin-bottom: 30px;

    .stat-card {
        text-align: center;

        .stat-content {
            .stat-number {
                font-size: 36px;
                font-weight: bold;
                color: #409eff;
                margin-bottom: 8px;
            }

            .stat-label {
                color: #666;
                font-size: 14px;
            }
        }
    }
}

.content-section {
    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;

        span {
            font-size: 18px;
            font-weight: 500;
        }
    }
}

.filter-section {
    margin-bottom: 20px;
}

.list-section {
    .empty-state {
        padding: 60px 0;
    }

    .declaration-list {
        .declaration-item {
            margin-bottom: 20px;

            .item-card {
                .item-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: flex-start;
                    margin-bottom: 15px;

                    .item-info {
                        .item-title {
                            font-size: 16px;
                            font-weight: 500;
                            margin-bottom: 8px;

                            .amount-text {
                                color: #e6a23c;
                                font-weight: bold;
                                font-size: 18px;
                            }
                        }

                        .item-meta {
                            display: flex;
                            align-items: center;
                            gap: 10px;

                            .voucher-type {
                                color: #666;
                                font-size: 14px;
                            }
                        }
                    }

                    .item-time {
                        color: #999;
                        font-size: 12px;
                    }
                }

                .item-content {
                    .images-preview {
                        display: flex;
                        gap: 10px;
                        margin-bottom: 15px;

                        .preview-image {
                            width: 80px;
                            height: 80px;
                            border-radius: 4px;
                            cursor: pointer;
                        }

                        .more-images {
                            width: 80px;
                            height: 80px;
                            border-radius: 4px;
                            background-color: #f5f5f5;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            color: #999;
                            font-size: 12px;
                            border: 1px solid #ddd;
                        }
                    }

                    .remark-section,
                    .review-remark-section {
                        margin-bottom: 10px;

                        .remark-label {
                            font-weight: 500;
                            color: #333;
                            margin-bottom: 5px;
                        }

                        .remark-content {
                            color: #666;
                            background-color: #f8f9fa;
                            padding: 8px 12px;
                            border-radius: 4px;
                            font-size: 14px;
                            line-height: 1.5;
                        }
                    }

                    .review-time {
                        color: #999;
                        font-size: 12px;
                        margin-top: 10px;
                    }
                }

                .item-actions {
                    margin-top: 15px;
                    text-align: right;
                }
            }
        }
    }

    .pagination-section {
        margin-top: 30px;
        text-align: center;
    }
}
</style>
