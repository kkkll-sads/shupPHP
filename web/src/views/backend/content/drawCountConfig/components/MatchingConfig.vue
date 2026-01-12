<template>
    <div class="matching-config">
        <el-card class="config-card" shadow="hover">
            <template #header>
                <div class="card-header">
                    <span class="card-title">撮合配置</span>
                    <div class="card-actions">
                        <el-button size="small" @click="fetchConfig" :loading="configLoading" :icon="Refresh">刷新</el-button>
                        <el-button size="small" type="primary" @click="saveConfig" :loading="savingConfig">保存</el-button>
                    </div>
                </div>
            </template>

            <el-form :model="config" label-width="220px" v-loading="configLoading">
                <el-form-item label="中签利润占比（相对于价格）" required>
                    <el-input-number v-model="config.matching_profit_rate" :min="0" :max="1" :step="0.01" :precision="2" style="width: 200px;" />
                    <span class="form-tip">例如 0.5 表示利润为价格的 50%</span>
                </el-form-item>

                <el-form-item label="利润分配：可调度收益比例" required>
                    <el-input-number v-model="config.matching_profit_balance" :min="0" :max="1" :step="0.01" :precision="2" style="width: 200px;" />
                </el-form-item>

                <el-form-item label="利润分配：消费金比例" required>
                    <el-input-number v-model="config.matching_profit_score" :min="0" :max="1" :step="0.01" :precision="2" style="width: 200px;" />
                </el-form-item>

                <el-form-item label="权重相同时平局处理方式" required>
                    <el-radio-group v-model="config.matching_tie_breaker">
                        <el-radio label="time">按时间（早到先得）</el-radio>
                        <el-radio label="random">随机</el-radio>
                    </el-radio-group>
                    <div class="form-tip">当候选权重全部相同时，选择按时间优先或随机</div>
                </el-form-item>
            </el-form>
        </el-card>
    </div>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import createAxios from '/@/utils/axios'

const configLoading = ref(false)
const savingConfig = ref(false)
const config = reactive({
    matching_profit_rate: 0.5,
    matching_profit_balance: 0.5,
    matching_profit_score: 0.5,
    matching_tie_breaker: 'time',
})

const fetchConfig = () => {
    configLoading.value = true
    createAxios({
        url: '/admin/content.DrawCountConfig/matchingSettlementConfig',
        method: 'GET',
    })
        .then((res) => {
            const data = res.data || {}
            config.matching_profit_rate = data.matching_profit_rate ?? 0.5
            config.matching_profit_balance = data.matching_profit_balance ?? 0.5
            config.matching_profit_score = data.matching_profit_score ?? 0.5
            config.matching_tie_breaker = data.matching_tie_breaker ?? 'time'
        })
        .finally(() => {
            configLoading.value = false
        })
}

const saveConfig = () => {
    if (config.matching_profit_rate < 0 || config.matching_profit_rate > 1) {
        ElMessage.error('中签利润占比必须在 0-1 之间')
        return
    }
    if (Math.abs((config.matching_profit_balance + config.matching_profit_score) - 1.0) > 0.0001) {
        ElMessage.error('利润分配比例之和必须等于 1')
        return
    }

    savingConfig.value = true
    createAxios(
        {
            url: '/admin/content.DrawCountConfig/matchingSettlementConfig',
            method: 'POST',
            data: {
                matching_profit_rate: config.matching_profit_rate,
                matching_profit_balance: config.matching_profit_balance,
                matching_profit_score: config.matching_profit_score,
                matching_tie_breaker: config.matching_tie_breaker,
            },
        },
        {
            showSuccessMessage: true,
        }
    )
        .then(() => {
            fetchConfig()
        })
        .finally(() => {
            savingConfig.value = false
        })
}

// init
fetchConfig()
</script>

<style scoped lang="scss">
.matching-config {
    .config-card {
        border-radius: 8px;
    }
    .card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        .card-title {
            font-size: 16px;
            font-weight: 600;
        }
    }
    .form-tip {
        margin-top: 6px;
        color: var(--el-text-color-secondary);
        font-size: 12px;
    }
}
</style>










