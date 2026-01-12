<template>
    <div class="default-main ba-table-box">
        <el-card shadow="never" class="search-card">
            <el-form :inline="true" :model="form" class="demo-form-inline" @submit.prevent>
                <el-form-item label="确权编号/藏品ID">
                    <el-input v-model="form.keyword" placeholder="请输入确权编号(Asset Code)或ID" clearable @keyup.enter="onSearch" style="width: 300px"></el-input>
                </el-form-item>
                <el-form-item>
                    <el-button type="primary" @click="onSearch" :loading="loading">查询溯源</el-button>
                </el-form-item>
            </el-form>
        </el-card>

        <div v-if="result" class="result-container">
            <el-row :gutter="20">
                <!-- 藏品信息 -->
                <el-col :span="8">
                    <el-card shadow="hover" class="box-card">
                        <template #header>
                            <div class="card-header">
                                <span>🎨 藏品信息</span>
                                <el-tag v-if="result.item.status == 1" type="success">上架中</el-tag>
                                <el-tag v-else type="info">未上架</el-tag>
                            </div>
                        </template>
                        <div class="item-info">
                            <el-image 
                                style="width: 100px; height: 100px; border-radius: 6px; margin-bottom: 10px;"
                                :src="fullUrl(result.item.image)" 
                                :preview-src-list="[fullUrl(result.item.image)]"
                                fit="cover"
                            ></el-image>
                            <el-descriptions :column="1" border size="small">
                                <el-descriptions-item label="藏品ID">{{ result.item.id }}</el-descriptions-item>
                                <el-descriptions-item label="名称">{{ result.item.title }}</el-descriptions-item>
                                <el-descriptions-item label="确权编号">{{ result.item.asset_code }}</el-descriptions-item>
                                <el-descriptions-item label="价格区间">{{ result.item.price_zone }}</el-descriptions-item>
                            </el-descriptions>
                        </div>
                    </el-card>
                </el-col>

                <!-- 增值分析 -->
                <el-col :span="8">
                    <el-card shadow="hover" class="box-card">
                        <template #header>
                            <div class="card-header">
                                <span>📈 价值分析</span>
                            </div>
                        </template>
                        <div class="analysis-info">
                            <div class="price-stat">
                                <div class="label">当前市场价</div>
                                <div class="value price">¥{{ result.appreciation.current_price }}</div>
                            </div>
                            <el-divider direction="vertical"></el-divider>
                            <div class="price-stat">
                                <div class="label">发行价</div>
                                <div class="value">¥{{ result.appreciation.issue_price }}</div>
                            </div>
                            
                            <el-divider></el-divider>
                            
                            <div class="growth-stat">
                                <div class="item">
                                    <span class="label">累计增值：</span>
                                    <span class="val green">+{{ result.appreciation.value_add }}</span>
                                </div>
                                <div class="item">
                                    <span class="label">增值幅度：</span>
                                    <span class="val green">+{{ result.appreciation.rate }}%</span>
                                </div>
                            </div>
                        </div>
                    </el-card>
                </el-col>

                <!-- 当前持有 -->
                <el-col :span="8">
                    <el-card shadow="hover" class="box-card">
                        <template #header>
                            <div class="card-header">
                                <span>👤 当前持有</span>
                            </div>
                        </template>
                        <div class="holder-info" v-if="result.holder">
                            <div class="user-row">
                                <el-avatar :size="50" :src="fullUrl(result.holder.avatar)"></el-avatar>
                                <div class="user-detail">
                                    <div class="name">{{ result.holder.holder_nickname || '未设置昵称' }}</div>
                                    <div class="sub">用户名: {{ result.holder.holder_username }}</div>
                                    <div class="sub">ID: {{ result.holder.user_id }}</div>
                                </div>
                            </div>
                            <el-divider></el-divider>
                            <el-descriptions :column="1" size="small">
                                <el-descriptions-item label="买入价格">¥{{ result.holder.price }}</el-descriptions-item>
                                <el-descriptions-item label="买入时间">{{ formatDate(result.holder.create_time) }}</el-descriptions-item>
                                <el-descriptions-item label="寄售状态">
                                    <el-tag v-if="result.holder.consignment_status == 1" type="warning">寄售中</el-tag>
                                    <el-tag v-else-if="result.holder.consignment_status == 2" type="danger">已售出</el-tag>
                                    <el-tag v-else type="info">持有中</el-tag>
                                </el-descriptions-item>
                            </el-descriptions>
                        </div>
                        <div v-else class="empty-holder">
                            暂无持有者（可能在官方库存中）
                        </div>
                    </el-card>
                </el-col>
            </el-row>

            <!-- 溯源时间轴 -->
            <el-card shadow="never" class="timeline-card" style="margin-top: 20px;">
                <template #header>
                    <div class="card-header">
                        <span>⏳ 全生命周期溯源</span>
                    </div>
                </template>
                <el-timeline>
                    <el-timeline-item
                        v-for="(activity, index) in result.timeline"
                        :key="index"
                        :timestamp="formatDate(activity.time)"
                        placement="top"
                        :type="getTimelineType(activity.type)"
                        :color="getTimelineColor(activity.type)"
                        size="large"
                    >
                        <el-card class="timeline-content">
                            <h4>{{ activity.title }}</h4>
                            <p style="white-space: pre-line;">{{ activity.desc }}</p>
                            <el-tag size="small" effect="plain" v-if="activity.price > 0">价格: ¥{{ activity.price }}</el-tag>
                        </el-card>
                    </el-timeline-item>
                </el-timeline>
            </el-card>
        </div>
        
        <div v-else-if="!loading && searched" class="empty-state">
            <el-empty description="未找到相关藏品信息" />
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, reactive } from 'vue'
import { baTableApi } from '/@/api/common'
import { fullUrl } from '/@/utils/common'
import { timeFormat } from '/@/utils/common'

const loading = ref(false)
const searched = ref(false)
const form = reactive({
    keyword: ''
})
const result = ref<any>(null)

// 使用通用API请求自定义接口
const onSearch = () => {
    if (!form.keyword) return
    
    loading.value = true
    searched.value = true
    
    // 这里假设后端路由是 /admin/collection/valueLog/trace
    // 如果是新建的控制器，需要在 BuildAdmin 的路由或者 API 映射中确认路径
    // 我们可以直接使用 baTableApi 的 request 方法
    new baTableApi('/admin/collection.ValueLog/').postData('trace', { 
        asset_code: form.keyword.includes('LEGACY') || form.keyword.includes('-') ? form.keyword : '',
        item_id: !isNaN(Number(form.keyword)) ? Number(form.keyword) : ''
    })
    .then((res: any) => {
        result.value = res.data
    })
    .catch(() => {
        result.value = null
    })
    .finally(() => {
        loading.value = false
    })
}

const formatDate = (ts: number) => {
    return timeFormat(ts)
}

const getTimelineType = (type: string) => {
    const map: any = {
        'created': 'primary',
        'trade': 'success',
        'consignment': 'warning',
        'failed': 'danger'
    }
    return map[type] || 'info'
}

const getTimelineColor = (type: string) => {
    const map: any = {
        'created': '#409EFF',
        'trade': '#67C23A',
        'consignment': '#E6A23C',
        'failed': '#F56C6C'
    }
    return map[type] || '#909399'
}
</script>

<style scoped lang="scss">
.search-card {
    margin-bottom: 20px;
}
.result-container {
    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-weight: bold;
    }
    .item-info {
        text-align: center;
    }
    .analysis-info {
        text-align: center;
        padding: 10px 0;
        
        .price-stat {
            display: inline-block;
            width: 45%;
            
            .label {
                color: #909399;
                font-size: 12px;
            }
            .value {
                font-size: 20px;
                font-weight: bold;
                margin-top: 5px;
                
                &.price {
                    color: #F56C6C;
                    font-size: 24px;
                }
            }
        }
        
        .growth-stat {
            margin-top: 20px;
            text-align: left;
            padding: 0 20px;
            
            .item {
                display: flex;
                justify-content: space-between;
                margin-bottom: 10px;
                font-size: 14px;
                
                .green {
                    color: #67C23A;
                    font-weight: bold;
                }
            }
        }
    }
    
    .holder-info {
        .user-row {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            
            .user-detail {
                margin-left: 15px;
                text-align: left;
                
                .name {
                    font-weight: bold;
                    font-size: 16px;
                }
                .sub {
                    color: #909399;
                    font-size: 12px;
                }
            }
        }
    }
    
    .empty-holder {
        color: #909399;
        text-align: center;
        padding: 40px 0;
    }
    
    .timeline-content {
        h4 {
            margin: 0;
            margin-bottom: 10px;
        }
        p {
            color: #606266;
            margin: 0;
            margin-bottom: 10px;
            line-height: 1.5;
        }
    }
}
.empty-state {
    padding: 40px 0;
}
</style>
