<template>
    <div class="score-exchange-config">
        <el-card class="config-card" shadow="hover">
            <template #header>
                <div class="card-header">
                    <span class="card-title">消费金兑换绿色算力配置</span>
                    <div class="card-actions">
                        <el-button size="small" @click="fetchConfig" :loading="configLoading" :icon="Refresh">刷新</el-button>
                        <el-button size="small" type="primary" @click="saveConfig" :loading="savingConfig">保存</el-button>
                    </div>
                </div>
            </template>
            <el-form :model="exchangeConfig" label-width="180px" v-loading="configLoading">
                <el-form-item label="配置说明">
                    <el-alert type="info" :closable="false" show-icon>
                        <template #default>
                            <div>设置消费金兑换绿色算力的比例</div>
                            <div class="alert-tip">分别设置基准消费金和基准绿色算力，系统会自动计算比例</div>
                            <div class="alert-tip">例如：设置 2消费金 = 1绿色算力，系统会自动计算比例</div>
                            <div class="alert-tip">用户可以通过API接口 /api/Account/exchangeScoreToGreenPower 进行兑换</div>
                        </template>
                    </el-alert>
                </el-form-item>
                <el-form-item label="基准消费金" required>
                    <el-input-number 
                        v-model="exchangeConfig.score_base" 
                        :min="0.01" 
                        :max="10000" 
                        :step="0.1"
                        :precision="2"
                        style="width: 200px;"
                    />
                    <span class="form-tip">设置基准消费金值</span>
                </el-form-item>
                <el-form-item label="基准绿色算力" required>
                    <el-input-number 
                        v-model="exchangeConfig.green_power_base" 
                        :min="0.01" 
                        :max="10000" 
                        :step="0.1"
                        :precision="2"
                        style="width: 200px;"
                    />
                    <span class="form-tip">设置基准绿色算力值</span>
                </el-form-item>
                <el-form-item label="计算出的比例" v-if="exchangeConfig.score_base > 0 && exchangeConfig.green_power_base > 0">
                    <div class="rate-display">
                        <span class="rate-text">{{ calculatedRate.toFixed(2) }} 消费金 = 1 绿色算力</span>
                        <span class="rate-formula">（{{ exchangeConfig.score_base }} ÷ {{ exchangeConfig.green_power_base }} = {{ calculatedRate.toFixed(2) }}）</span>
                    </div>
                </el-form-item>
                <el-form-item label="兑换示例" v-if="calculatedRate > 0">
                    <div class="example-box">
                        <div class="example-item">
                            <span class="example-label">100 消费金</span>
                            <span class="example-arrow">→</span>
                            <span class="example-value">{{ calculateGreenPower(100) }} 绿色算力</span>
                        </div>
                        <div class="example-item">
                            <span class="example-label">200 消费金</span>
                            <span class="example-arrow">→</span>
                            <span class="example-value">{{ calculateGreenPower(200) }} 绿色算力</span>
                        </div>
                        <div class="example-item">
                            <span class="example-label">500 消费金</span>
                            <span class="example-arrow">→</span>
                            <span class="example-value">{{ calculateGreenPower(500) }} 绿色算力</span>
                        </div>
                    </div>
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

const configLoading = ref(false)
const savingConfig = ref(false)
const exchangeConfig = reactive({
    score_base: 0,
    green_power_base: 0,
})

const calculatedRate = computed(() => {
    if (exchangeConfig.score_base > 0 && exchangeConfig.green_power_base > 0) {
        return exchangeConfig.score_base / exchangeConfig.green_power_base
    }
    return 0
})

const fetchConfig = () => {
    configLoading.value = true
    createAxios({
        url: '/admin/content.DrawCountConfig/scoreExchangeGreenPowerConfig',
        method: 'GET',
    })
        .then((res) => {
            exchangeConfig.score_base = res.data.score_base ?? 0
            exchangeConfig.green_power_base = res.data.green_power_base ?? 0
        })
        .finally(() => {
            configLoading.value = false
        })
}

const saveConfig = () => {
    if (exchangeConfig.score_base <= 0) {
        ElMessage.error('基准消费金必须大于0')
        return
    }
    if (exchangeConfig.green_power_base <= 0) {
        ElMessage.error('基准绿色算力必须大于0')
        return
    }
    
    savingConfig.value = true
    createAxios(
        {
            url: '/admin/content.DrawCountConfig/scoreExchangeGreenPowerConfig',
            method: 'POST',
            data: {
                score_base: exchangeConfig.score_base,
                green_power_base: exchangeConfig.green_power_base,
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

const calculateGreenPower = (score: number): string => {
    if (calculatedRate.value <= 0) {
        return '0.00'
    }
    const greenPower = score / calculatedRate.value
    return greenPower.toFixed(2)
}

// 初始化时获取配置
fetchConfig()
</script>

<style scoped lang="scss">
.score-exchange-config {
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
        
        &.form-tip-warning {
            color: var(--el-color-warning);
        }
    }
    
    .rate-display {
        padding: 10px 15px;
        background-color: var(--el-bg-color-page);
        border-radius: 6px;
        border: 1px solid var(--el-border-color-light);
        
        .rate-text {
            font-size: 16px;
            font-weight: 600;
            color: var(--el-color-primary);
        }
        
        .rate-formula {
            margin-left: 10px;
            font-size: 12px;
            color: var(--el-text-color-secondary);
        }
    }
    
    .example-box {
        padding: 15px;
        background-color: var(--el-bg-color-page);
        border-radius: 6px;
        border: 1px solid var(--el-border-color-light);
        
        .example-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 0;
            
            .example-label {
                color: var(--el-text-color-primary);
                font-weight: 500;
                min-width: 100px;
            }
            
            .example-arrow {
                color: var(--el-color-primary);
                font-size: 16px;
                font-weight: bold;
            }
            
            .example-value {
                color: var(--el-color-success);
                font-weight: 600;
                min-width: 120px;
            }
        }
    }
}
</style>

