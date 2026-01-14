<template>
    <div class="mining-config">
        <el-card class="config-card" shadow="hover">
            <template #header>
                <div class="card-header">
                    <span class="card-title">兜底熔断（转分红）配置</span>
                    <div class="card-actions">
                        <el-button size="small" @click="fetchCollectionMiningConfig" :loading="miningConfigLoading" :icon="Refresh">刷新</el-button>
                        <el-button size="small" type="primary" @click="saveCollectionMiningConfig" :loading="savingMiningConfig">保存</el-button>
                    </div>
                </div>
            </template>
            <el-form :model="collectionMiningConfig" label-width="180px" v-loading="miningConfigLoading">
                <el-form-item label="配置说明">
                    <el-alert type="info" :closable="false" show-icon>
                        <template #default>
                            <div>当商品满足任一触发条件时，系统会强制回收并转为"矿机"：</div>
                            <div class="alert-tip">1. 连续失败：连续指定次数寄售都没卖出去（流拍）</div>
                            <div class="alert-tip">2. 长期滞销：持有超过指定天数还没卖掉（或没操作上架）</div>
                            <div class="alert-tip">3. 价格触顶：现价超过了发行价的指定倍数</div>
                            <div class="alert-tip">转为矿机后，每天会自动发放分红：</div>
                            <div class="alert-tip">&nbsp;&nbsp;&nbsp;&nbsp;• 可设置按藏品当前价格的比例分红（如价格374元，按1%比例分红3.74元）</div>
                            <div class="alert-tip">&nbsp;&nbsp;&nbsp;&nbsp;• 也可设置固定金额分红（如每日10元）</div>
                            <div class="alert-tip">&nbsp;&nbsp;&nbsp;&nbsp;• 分红按配置比例分配到余额和消费金</div>
                        </template>
                    </el-alert>
                </el-form-item>
                <el-divider />
                <el-row :gutter="20">
                    <el-col :xs="24" :sm="12">
                        <el-form-item label="连续失败次数">
                            <el-input-number v-model="collectionMiningConfig.continuous_fail_count" :min="1" :max="100" :step="1" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">次（连续寄售失败达到此次数时，触发强制锁仓转为矿机）</span>
                        </el-form-item>
                    </el-col>
                    <el-col :xs="24" :sm="12">
                        <el-form-item label="长期滞销天数">
                            <el-input-number v-model="collectionMiningConfig.long_term_days" :min="1" :max="365" :step="1" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">天（持有超过此天数还没卖掉或没操作上架时，触发强制锁仓转为矿机）</span>
                        </el-form-item>
                    </el-col>
                </el-row>
                <el-row :gutter="20">
                    <el-col :xs="24" :sm="12">
                        <el-form-item label="价格触顶倍数">
                            <el-input-number v-model="collectionMiningConfig.price_top_multiple" :min="1" :max="100" :step="0.1" :precision="1" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">倍（现价超过发行价的此倍数时，触发强制锁仓转为矿机）</span>
                        </el-form-item>
                    </el-col>
                    <el-col :xs="24" :sm="12">
                        <el-form-item label="每日分红金额">
                            <el-input-number v-model="collectionMiningConfig.daily_dividend_amount" :min="0" :step="0.01" :precision="2" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">元（矿机每日分红总金额，当价格比例为0时使用此固定金额）</span>
                        </el-form-item>
                    </el-col>
                </el-row>
                <el-row :gutter="20">
                    <el-col :xs="24" :sm="12">
                        <el-form-item label="分红价格比例">
                            <el-input-number v-model="collectionMiningConfig.dividend_price_rate" :min="0" :max="1" :step="0.001" :precision="3" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">例如：0.01 表示 1%，设置为0则使用固定金额分红</span>
                        </el-form-item>
                    </el-col>
                </el-row>
                <el-divider />
                <el-row :gutter="20">
                    <el-col :xs="24" :sm="12">
                        <el-form-item label="分红余额分配比例">
                            <el-input-number v-model="collectionMiningConfig.dividend_balance_rate" :min="0" :max="1" :step="0.01" :precision="2" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">例如：0.5 表示 50%</span>
                        </el-form-item>
                    </el-col>
                    <el-col :xs="24" :sm="12">
                        <el-form-item label="分红消费金分配比例">
                            <el-input-number v-model="collectionMiningConfig.dividend_score_rate" :min="0" :max="1" :step="0.01" :precision="2" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">例如：0.5 表示 50%</span>
                        </el-form-item>
                    </el-col>
                </el-row>
                <el-form-item label="比例总和">
                    <el-tag :type="totalMiningDividendRate > 1.01 || totalMiningDividendRate < 0.99 ? 'danger' : 'success'" size="large">
                        {{ (totalMiningDividendRate * 100).toFixed(2) }}%
                    </el-tag>
                    <span class="form-tip" v-if="totalMiningDividendRate > 1.01 || totalMiningDividendRate < 0.99" style="color: #f56c6c;">
                        警告：两个比例之和必须等于1（100%）
                    </span>
                    <span class="form-tip" v-else style="color: #67c23a;">正确：比例总和为100%</span>
                </el-form-item>
            </el-form>
        </el-card>
    </div>
</template>

<script setup lang="ts">
import { reactive, ref, computed } from 'vue'
import { ElMessage } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import createAxios from '/@/utils/axios'

const miningConfigLoading = ref(false)
const savingMiningConfig = ref(false)
const collectionMiningConfig = reactive({
    continuous_fail_count: 5,
    long_term_days: 7,
    price_top_multiple: 7.0,
    dividend_balance_rate: 0.5,
    dividend_score_rate: 0.5,
    daily_dividend_amount: 0,
    dividend_price_rate: 0,
})

const totalMiningDividendRate = computed(() => {
    const balance = collectionMiningConfig.dividend_balance_rate || 0
    const score = collectionMiningConfig.dividend_score_rate || 0
    return balance + score
})

const fetchCollectionMiningConfig = () => {
    miningConfigLoading.value = true
    createAxios({ url: '/admin/content.DrawCountConfig/collectionMiningConfig', method: 'GET' })
        .then((res) => {
            collectionMiningConfig.continuous_fail_count = res.data.continuous_fail_count ?? 5
            collectionMiningConfig.long_term_days = res.data.long_term_days ?? 7
            collectionMiningConfig.price_top_multiple = res.data.price_top_multiple ?? 7.0
            collectionMiningConfig.dividend_balance_rate = res.data.dividend_balance_rate ?? 0.5
            collectionMiningConfig.dividend_score_rate = res.data.dividend_score_rate ?? 0.5
            collectionMiningConfig.daily_dividend_amount = res.data.daily_dividend_amount ?? 0
            collectionMiningConfig.dividend_price_rate = res.data.dividend_price_rate ?? 0
        })
        .finally(() => {
            miningConfigLoading.value = false
        })
}

const saveCollectionMiningConfig = () => {
    const total = totalMiningDividendRate.value
    if (total > 1.01 || total < 0.99) {
        ElMessage.warning('分红余额分配比例和分红消费金分配比例之和必须等于1（100%）')
        return
    }
    if (collectionMiningConfig.continuous_fail_count < 1 || collectionMiningConfig.continuous_fail_count > 100) {
        ElMessage.warning('连续失败次数必须在1-100之间')
        return
    }
    if (collectionMiningConfig.long_term_days < 1 || collectionMiningConfig.long_term_days > 365) {
        ElMessage.warning('长期滞销天数必须在1-365之间')
        return
    }
    if (collectionMiningConfig.price_top_multiple < 1 || collectionMiningConfig.price_top_multiple > 100) {
        ElMessage.warning('价格触顶倍数必须在1-100之间')
        return
    }
    if (collectionMiningConfig.dividend_price_rate < 0 || collectionMiningConfig.dividend_price_rate > 1) {
        ElMessage.warning('分红价格比例必须在0-1之间（0表示不按价格比例分红，0.01表示1%）')
        return
    }
    savingMiningConfig.value = true
    createAxios({ url: '/admin/content.DrawCountConfig/collectionMiningConfig', method: 'POST', data: collectionMiningConfig }, { showSuccessMessage: true })
        .then(() => {
            fetchCollectionMiningConfig()
        })
        .finally(() => {
            savingMiningConfig.value = false
        })
}

fetchCollectionMiningConfig()
</script>

<style scoped lang="scss">
.mining-config {
    .config-card {
        border-radius: 8px;
        transition: all 0.3s;
        
        &:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
    }
    
    .card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        
        .card-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--el-text-color-primary);
        }
        
        .card-actions {
            display: flex;
            gap: 10px;
        }
    }
    
    .alert-tip {
        margin-top: 5px;
        color: #909399;
        font-size: 12px;
    }
    
    .form-tip {
        margin-left: 12px;
        color: var(--el-text-color-secondary);
        font-size: 12px;
    }
}
</style>

