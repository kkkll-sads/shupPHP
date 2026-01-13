<template>
    <div class="signin-config">
        <el-card class="config-card" shadow="hover">
            <template #header>
                <div class="card-header">
                    <span class="card-title">签到奖励配置</span>
                    <div class="card-actions">
                        <el-button size="small" @click="fetchSignInConfig" :loading="configLoading" :icon="Refresh">刷新</el-button>
                        <el-button size="small" type="primary" @click="saveSignInConfig" :loading="savingConfig">保存</el-button>
                    </div>
                </div>
            </template>
            <el-form :model="signInConfig" label-width="140px" v-loading="configLoading">
                <el-form-item label="每日签到积分">
                    <el-input-number v-model="signInConfig.daily_reward" :min="0" :step="10" />
                    <span class="form-tip">用户每日签到获得的基础积分</span>
                </el-form-item>
                <el-form-item label="直推签到积分">
                    <el-input-number v-model="signInConfig.referrer_reward" :min="0" :step="10" />
                    <span class="form-tip">邀请人每天因下级签到获得的积分</span>
                </el-form-item>
            </el-form>
        </el-card>
    </div>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import { Refresh } from '@element-plus/icons-vue'
import createAxios from '/@/utils/axios'

const configLoading = ref(false)
const savingConfig = ref(false)
const signInConfig = reactive({
    daily_reward: 0,
    referrer_reward: 0,
})

const fetchSignInConfig = () => {
    configLoading.value = true
    createAxios({
        url: '/admin/content.DrawCountConfig/signInConfig',
        method: 'GET',
    })
        .then((res) => {
            signInConfig.daily_reward = res.data.daily_reward ?? 0
            signInConfig.referrer_reward = res.data.referrer_reward ?? 0
        })
        .finally(() => {
            configLoading.value = false
        })
}

const saveSignInConfig = () => {
    savingConfig.value = true
    createAxios(
        {
            url: '/admin/content.DrawCountConfig/signInConfig',
            method: 'POST',
            data: {
                daily_reward: signInConfig.daily_reward,
                referrer_reward: signInConfig.referrer_reward,
            },
        },
        {
            showSuccessMessage: true,
        }
    )
        .then(() => {
            fetchSignInConfig()
        })
        .finally(() => {
            savingConfig.value = false
        })
}

fetchSignInConfig()
</script>

<style scoped lang="scss">
.signin-config {
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
    
    .form-tip {
        margin-left: 12px;
        color: var(--el-text-color-secondary);
        font-size: 12px;
    }
}
</style>

