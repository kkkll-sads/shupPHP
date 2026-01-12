<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <!-- 场次选择 -->
        <el-card shadow="never" style="margin-bottom: 15px;">
            <template #header>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-weight: bold;">场次选择</span>
                </div>
            </template>
            <el-select v-model="currentSessionId" placeholder="请选择场次" style="width: 300px;" @change="handleSessionChange">
                <el-option
                    v-for="session in sessions"
                    :key="session.id"
                    :label="session.title"
                    :value="session.id"
                />
            </el-select>
        </el-card>

        <!-- 预约统计表格 -->
        <el-card shadow="never" style="margin-bottom: 15px;">
            <template #header>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-weight: bold;">预约统计（待处理，按资产包+分区）</span>
                    <el-button type="primary" size="small" @click="fetchData">
                        <el-icon><Refresh /></el-icon>
                        刷新
                    </el-button>
                </div>
            </template>
            <el-table :data="reservationStats" stripe border v-loading="loading" max-height="600">
                <el-table-column type="index" label="序号" align="center" width="60" />
                <el-table-column prop="session_title" label="场次" align="center" min-width="150" show-overflow-tooltip />
                <el-table-column prop="package_name" label="资产包" align="center" min-width="150" show-overflow-tooltip />
                <el-table-column prop="zone_name" label="价格分区" align="center" width="120" />
                <el-table-column prop="user_count" label="人数" align="center" width="100">
                    <template #default="{ row }">
                        <el-tag type="primary">{{ row.user_count || 0 }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="item_count" label="藏品数量" align="center" width="100">
                    <template #default="{ row }">
                        <el-tag type="success">{{ row.item_count || 0 }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="reservation_count" label="待处理数量" align="center" width="100" />
                <el-table-column prop="total_freeze_amount" label="冻结金额" align="center" width="120">
                    <template #default="{ row }">
                        <span style="color: #409eff;">¥{{ (row.total_freeze_amount || 0).toFixed(2) }}</span>
                    </template>
                </el-table-column>
            </el-table>
            <el-empty v-if="!loading && reservationStats.length === 0" description="暂无数据" />
        </el-card>

        <!-- 寄售统计表格 -->
        <el-card shadow="never" style="margin-bottom: 15px;">
            <template #header>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-weight: bold;">寄售统计（在售，按资产包+分区）</span>
                    <el-button type="primary" size="small" @click="fetchData">
                        <el-icon><Refresh /></el-icon>
                        刷新
                    </el-button>
                </div>
            </template>
            <el-table :data="consignmentStats" stripe border v-loading="loading" max-height="600">
                <el-table-column type="index" label="序号" align="center" width="60" />
                <el-table-column prop="session_title" label="场次" align="center" min-width="150" show-overflow-tooltip />
                <el-table-column prop="package_name" label="资产包" align="center" min-width="150" show-overflow-tooltip />
                <el-table-column prop="zone_name" label="价格分区" align="center" width="120" />
                <el-table-column prop="user_count" label="人数" align="center" width="100">
                    <template #default="{ row }">
                        <el-tag type="primary">{{ row.user_count || 0 }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="item_count" label="藏品数量" align="center" width="100">
                    <template #default="{ row }">
                        <el-tag type="success">{{ row.item_count || 0 }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="consignment_count" label="在售数量" align="center" width="100" />
                <el-table-column prop="total_price" label="在售总价" align="center" width="120">
                    <template #default="{ row }">
                        <span style="color: #67c23a;">¥{{ (row.total_price || 0).toFixed(2) }}</span>
                    </template>
                </el-table-column>
            </el-table>
            <el-empty v-if="!loading && consignmentStats.length === 0" description="暂无数据" />
        </el-card>
    </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { Refresh } from '@element-plus/icons-vue'
import createAxios from '/@/utils/axios'

defineOptions({
    name: 'collection/tradeHall',
})

const loading = ref(false)
const currentSessionId = ref<number>(0)
const sessions = ref<any[]>([])
const reservationStats = ref<any[]>([])
const consignmentStats = ref<any[]>([])

// 获取数据
const fetchData = async () => {
    loading.value = true
    try {
        const res = await createAxios({
            url: '/admin/collection.TradeHall/index',
            method: 'get',
            params: {
                session_id: currentSessionId.value > 0 ? currentSessionId.value : undefined,
            },
        })
        if (res.code === 1 && res.data) {
            sessions.value = res.data.sessions || []
            reservationStats.value = res.data.reservation_stats || []
            consignmentStats.value = res.data.consignment_stats || []
            
            // 调试：检查数据是否包含 item_count
            if (reservationStats.value.length > 0) {
                console.log('预约统计数据示例:', reservationStats.value[0])
                console.log('是否包含 item_count:', 'item_count' in reservationStats.value[0])
                console.log('item_count 值:', reservationStats.value[0].item_count)
            }
            if (consignmentStats.value.length > 0) {
                console.log('寄售统计数据示例:', consignmentStats.value[0])
                console.log('是否包含 item_count:', 'item_count' in consignmentStats.value[0])
                console.log('item_count 值:', consignmentStats.value[0].item_count)
            }
            
            // 如果没有选择场次，使用第一个场次
            if (currentSessionId.value <= 0 && sessions.value.length > 0) {
                currentSessionId.value = sessions.value[0].id
                // 重新获取数据（使用新的session_id）
                const res2 = await createAxios({
                    url: '/admin/collection.TradeHall/index',
                    method: 'get',
                    params: {
                        session_id: currentSessionId.value,
                    },
                })
                if (res2.code === 1 && res2.data) {
                    reservationStats.value = res2.data.reservation_stats || []
                    consignmentStats.value = res2.data.consignment_stats || []
                    
                    // 调试：检查数据是否包含 item_count
                    if (reservationStats.value.length > 0) {
                        console.log('预约统计数据示例（重新获取）:', reservationStats.value[0])
                        console.log('是否包含 item_count:', 'item_count' in reservationStats.value[0])
                    }
                    if (consignmentStats.value.length > 0) {
                        console.log('寄售统计数据示例（重新获取）:', consignmentStats.value[0])
                        console.log('是否包含 item_count:', 'item_count' in consignmentStats.value[0])
                    }
                }
            }
        }
    } catch (error) {
        console.error('获取数据失败:', error)
    } finally {
        loading.value = false
    }
}

// 场次改变事件
const handleSessionChange = () => {
    fetchData()
}

// 页面加载时获取数据
onMounted(() => {
    fetchData()
})

// 提供空对象以满足某些组件的要求
const baTable = reactive({
    table: {
        remark: '',
    },
})
</script>

<style scoped lang="scss">
:deep(.el-card__header) {
    padding: 15px 20px;
}

:deep(.el-card__body) {
    padding: 20px;
}

:deep(.el-table) {
    .el-table__header {
        th {
            background-color: #f5f7fa;
            color: #606266;
            font-weight: 600;
        }
    }
}
</style>
