<template>
    <div class="signin-activity-config">
        <el-card class="config-card" shadow="hover" style="margin-bottom: 20px;">
            <template #header>
                <div class="card-header">
                    <span class="card-title">注册奖励配置</span>
                    <div class="card-actions">
                        <el-button size="small" @click="fetchConfig" :loading="configLoading" :icon="Refresh">刷新</el-button>
                        <el-button size="small" type="primary" @click="saveConfig" :loading="savingConfig">保存</el-button>
                    </div>
                </div>
            </template>
            <el-form :model="config" label-width="180px" v-loading="configLoading">
                <el-form-item label="配置说明">
                    <el-alert type="info" :closable="false" show-icon>
                        <template #default>
                            <div>用户注册/激活时获得的奖励金额（全局配置）</div>
                            <div class="alert-tip">此配置对所有用户生效，不受活动影响</div>
                        </template>
                    </el-alert>
                </el-form-item>
                <el-divider />
                <el-row :gutter="20">
                    <el-col :xs="24" :sm="12">
                        <el-form-item label="注册奖励（元）">
                            <el-input-number v-model="config.register_reward" :min="0" :max="9999.99" :precision="2" :step="0.01" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">用户注册/激活时获得的奖励金额</span>
                        </el-form-item>
                    </el-col>
                    <el-col :xs="24" :sm="12">
                        <el-form-item label="注册送绿色算力">
                            <el-input-number v-model="config.register_green_power" :min="0" :max="9999.99" :precision="2" :step="0.01" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">用户注册/激活时获得的绿色算力</span>
                        </el-form-item>
                    </el-col>
                </el-row>
            </el-form>
        </el-card>

        <el-card class="config-card" shadow="hover">
            <template #header>
                <div class="card-header">
                    <span class="card-title">提现规则配置</span>
                    <div class="card-actions">
                        <el-button size="small" @click="fetchConfig" :loading="configLoading" :icon="Refresh">刷新</el-button>
                        <el-button size="small" type="primary" @click="saveConfig" :loading="savingConfig">保存</el-button>
                    </div>
                </div>
            </template>
            <el-form :model="config" label-width="180px" v-loading="configLoading">
                <el-form-item label="配置说明">
                    <el-alert type="info" :closable="false" show-icon>
                        <template #default>
                            <div>全局提现规则配置，对所有用户生效</div>
                            <div class="alert-tip">这些配置不受活动影响，是系统级别的全局设置</div>
                        </template>
                    </el-alert>
                </el-form-item>
                <el-divider />
                <el-row :gutter="20">
                    <el-col :xs="24" :sm="12">
                        <el-form-item label="提现最低金额（元）">
                            <el-input-number v-model="config.withdraw_min_amount" :min="0" :max="99999.99" :precision="2" :step="1" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">账户余额满此金额才可申请提现</span>
                        </el-form-item>
                    </el-col>
                    <el-col :xs="24" :sm="12">
                        <el-form-item label="每日提现次数限制">
                            <el-input-number v-model="config.withdraw_daily_limit" :min="0" :max="100" :step="1" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">每人每天可提现的次数，0表示不限制</span>
                        </el-form-item>
                    </el-col>
                </el-row>
                <el-row :gutter="20">
                    <el-col :xs="24" :sm="12">
                        <el-form-item label="提现审核时间（小时）">
                            <el-input-number v-model="config.withdraw_audit_hours" :min="0" :max="168" :step="1" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">提现申请提交后，承诺在此时间内审核到账</span>
                        </el-form-item>
                    </el-col>
                </el-row>
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
    register_reward: 0,
    register_green_power: 0,
    withdraw_min_amount: 10,
    withdraw_daily_limit: 1,
    withdraw_audit_hours: 24,
})

const fetchConfig = () => {
    configLoading.value = true
    createAxios({ url: '/admin/content.DrawCountConfig/signInActivityConfig', method: 'GET' })
        .then((res) => {
            config.register_reward = res.data.register_reward ?? 0
            config.register_green_power = res.data.register_green_power ?? 0
            config.withdraw_min_amount = res.data.withdraw_min_amount ?? 10
            config.withdraw_daily_limit = res.data.withdraw_daily_limit ?? 1
            config.withdraw_audit_hours = res.data.withdraw_audit_hours ?? 24
        })
        .finally(() => {
            configLoading.value = false
        })
}

const saveConfig = () => {
    savingConfig.value = true
    createAxios({ url: '/admin/content.DrawCountConfig/signInActivityConfig', method: 'POST', data: config }, { showSuccessMessage: true })
        .then(() => {
            fetchConfig()
        })
        .finally(() => {
            savingConfig.value = false
        })
}

fetchConfig()
</script>

<style scoped lang="scss">
.signin-activity-config {
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

