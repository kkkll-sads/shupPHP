<template>
    <div class="power-weight-config">
        <el-card class="config-card" shadow="hover">
            <template #header>
                <div class="card-header">
                    <span class="card-title">算力权重比例配置</span>
                    <div class="card-actions">
                        <el-button size="small" @click="fetchConfig" :loading="configLoading" :icon="Refresh">刷新</el-button>
                        <el-button size="small" type="primary" @click="saveConfig" :loading="savingConfig">保存</el-button>
                    </div>
                </div>
            </template>
            <el-form :model="weightConfig" label-width="180px" v-loading="configLoading">
                <el-form-item label="配置说明">
                    <el-alert type="info" :closable="false" show-icon>
                        <template #default>
                            <div>设置算力与权重的兑换比例</div>
                            <div class="alert-tip">分别设置基准算力和基准权重，系统会自动计算比例</div>
                            <div class="alert-tip">例如：设置 1算力 = 20权重，或 20算力 = 30权重，系统会自动计算比例</div>
                            <div class="alert-tip">此配置用于竞价购买时的权重计算，必须配置后才能使用竞价购买功能</div>
                        </template>
                    </el-alert>
                </el-form-item>
                <el-form-item label="基准算力" required>
                    <el-input-number 
                        v-model="weightConfig.power_base" 
                        :min="0.01" 
                        :max="10000" 
                        :step="0.1"
                        :precision="2"
                        style="width: 200px;"
                    />
                    <span class="form-tip">设置基准算力值</span>
                </el-form-item>
                <el-form-item label="基准权重" required>
                    <el-input-number 
                        v-model="weightConfig.weight_base" 
                        :min="1" 
                        :max="1000000" 
                        :step="1"
                        :precision="0"
                        style="width: 200px;"
                    />
                    <span class="form-tip">设置基准权重值</span>
                </el-form-item>
                <el-form-item label="计算出的比例" v-if="weightConfig.power_base > 0 && weightConfig.weight_base > 0">
                    <div class="rate-display">
                        <span class="rate-text">{{ weightConfig.power_base }} 算力 = {{ weightConfig.weight_base }} 权重</span>
                        <span class="rate-formula">（1算力 = {{ calculatedRate.toFixed(2) }}权重）</span>
                    </div>
                </el-form-item>
                <el-form-item label="换算示例" v-if="calculatedRate > 0">
                    <div class="example-box">
                        <div class="example-item">
                            <span class="example-label">1 算力</span>
                            <span class="example-arrow">→</span>
                            <span class="example-value">{{ calculateWeight(1) }} 权重</span>
                        </div>
                        <div class="example-item">
                            <span class="example-label">5 算力</span>
                            <span class="example-arrow">→</span>
                            <span class="example-value">{{ calculateWeight(5) }} 权重</span>
                        </div>
                        <div class="example-item">
                            <span class="example-label">10 算力</span>
                            <span class="example-arrow">→</span>
                            <span class="example-value">{{ calculateWeight(10) }} 权重</span>
                        </div>
                        <div class="example-item" style="margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--el-border-color-light);">
                            <span class="example-label">100 权重</span>
                            <span class="example-arrow">→</span>
                            <span class="example-value">{{ calculatePower(100) }} 算力</span>
                        </div>
                        <div class="example-item">
                            <span class="example-label">200 权重</span>
                            <span class="example-arrow">→</span>
                            <span class="example-value">{{ calculatePower(200) }} 算力</span>
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
const weightConfig = reactive({
    power_base: 0,
    weight_base: 0,
})

const calculatedRate = computed(() => {
    if (weightConfig.power_base > 0 && weightConfig.weight_base > 0) {
        return weightConfig.weight_base / weightConfig.power_base
    }
    return 0
})

const fetchConfig = () => {
    configLoading.value = true
    createAxios({
        url: '/admin/content.DrawCountConfig/powerWeightRateConfig',
        method: 'GET',
    })
        .then((res) => {
            weightConfig.power_base = res.data.power_base ?? 0
            weightConfig.weight_base = res.data.weight_base ?? 0
        })
        .finally(() => {
            configLoading.value = false
        })
}

const saveConfig = () => {
    if (weightConfig.power_base <= 0) {
        ElMessage.error('基准算力必须大于0')
        return
    }
    if (weightConfig.weight_base <= 0) {
        ElMessage.error('基准权重必须大于0')
        return
    }
    
    savingConfig.value = true
    createAxios(
        {
            url: '/admin/content.DrawCountConfig/powerWeightRateConfig',
            method: 'POST',
            data: {
                power_base: weightConfig.power_base,
                weight_base: weightConfig.weight_base,
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

const calculateWeight = (power: number): string => {
    if (calculatedRate.value <= 0) {
        return '0'
    }
    const weight = power * calculatedRate.value
    return Math.floor(weight).toString()
}

const calculatePower = (weight: number): string => {
    if (calculatedRate.value <= 0) {
        return '0.00'
    }
    const power = weight / calculatedRate.value
    return power.toFixed(2)
}

// 初始化时获取配置
fetchConfig()
</script>

<style scoped lang="scss">
.power-weight-config {
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

