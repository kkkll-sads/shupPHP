<template>
    <div class="register-config">
        <el-row :gutter="20">
            <el-col :xs="24" :sm="12">
                <el-card class="config-card" shadow="hover">
                    <template #header>
                        <div class="card-header">
                            <span class="card-title">邀请码注册配置</span>
                            <div class="card-actions">
                                <el-button size="small" @click="fetchInviteCodeRegisterConfig" :loading="inviteCodeRegisterConfigLoading" :icon="Refresh">刷新</el-button>
                                <el-button size="small" type="primary" @click="saveInviteCodeRegisterConfig" :loading="savingInviteCodeRegisterConfig">保存</el-button>
                            </div>
                        </div>
                    </template>
                    <el-form :model="inviteCodeRegisterConfig" label-width="140px" v-loading="inviteCodeRegisterConfigLoading">
                        <el-form-item label="配置说明">
                            <el-alert type="info" :closable="false" show-icon>
                                <template #default>
                                    <div>启用邀请码注册后，用户注册时必须填写有效的邀请码</div>
                                    <div class="alert-tip">关闭后，用户注册时不需要填写邀请码（可选）</div>
                                    <div class="alert-tip">邀请码管理：用户管理 → 用户管理（查看用户的邀请码）</div>
                                </template>
                            </el-alert>
                        </el-form-item>
                        <el-form-item label="启用邀请码注册">
                            <el-switch v-model="inviteCodeRegisterConfig.enabled" />
                            <span class="form-tip">{{ inviteCodeRegisterConfig.enabled ? '已启用：注册时必须填写有效的邀请码' : '已关闭：注册时不需要填写邀请码' }}</span>
                        </el-form-item>
                    </el-form>
                </el-card>
            </el-col>
            <el-col :xs="24" :sm="12">
                <el-card class="config-card" shadow="hover">
                    <template #header>
                        <div class="card-header">
                            <span class="card-title">手机号白名单配置</span>
                            <div class="card-actions">
                                <el-button size="small" @click="fetchMobileWhitelistConfig" :loading="mobileWhitelistConfigLoading" :icon="Refresh">刷新</el-button>
                                <el-button size="small" type="primary" @click="saveMobileWhitelistConfig" :loading="savingMobileWhitelistConfig">保存</el-button>
                            </div>
                        </div>
                    </template>
                    <el-form :model="mobileWhitelistConfig" label-width="140px" v-loading="mobileWhitelistConfigLoading">
                        <el-form-item label="配置说明">
                            <el-alert type="info" :closable="false" show-icon>
                                <template #default>
                                    <div>启用手机号白名单验证后，只有白名单中的手机号才能注册</div>
                                    <div class="alert-tip">关闭后，所有手机号都可以注册（不受白名单限制）</div>
                                    <div class="alert-tip">白名单管理：用户管理 → 手机号白名单</div>
                                </template>
                            </el-alert>
                        </el-form-item>
                        <el-form-item label="启用白名单验证">
                            <el-switch v-model="mobileWhitelistConfig.enabled" />
                            <span class="form-tip">{{ mobileWhitelistConfig.enabled ? '已启用：只有白名单中的手机号才能注册' : '已关闭：所有手机号都可以注册' }}</span>
                        </el-form-item>
                    </el-form>
                </el-card>
            </el-col>
        </el-row>
    </div>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import createAxios from '/@/utils/axios'

const inviteCodeRegisterConfigLoading = ref(false)
const savingInviteCodeRegisterConfig = ref(false)
const inviteCodeRegisterConfig = reactive({
    enabled: false,
})

const mobileWhitelistConfigLoading = ref(false)
const savingMobileWhitelistConfig = ref(false)
const mobileWhitelistConfig = reactive({
    enabled: false,
})

const fetchInviteCodeRegisterConfig = () => {
    inviteCodeRegisterConfigLoading.value = true
    createAxios({
        url: '/admin/content.DrawCountConfig/inviteCodeRegisterConfig',
        method: 'GET',
    })
        .then((res) => {
            inviteCodeRegisterConfig.enabled = res.data.enabled === 1 || res.data.enabled === true
        })
        .finally(() => {
            inviteCodeRegisterConfigLoading.value = false
        })
}

const saveInviteCodeRegisterConfig = () => {
    savingInviteCodeRegisterConfig.value = true
    createAxios(
        {
            url: '/admin/content.DrawCountConfig/inviteCodeRegisterConfig',
            method: 'POST',
            data: {
                enabled: inviteCodeRegisterConfig.enabled ? 1 : 0,
            },
        },
        {
            showSuccessMessage: true,
        }
    )
        .then(() => {
            fetchInviteCodeRegisterConfig()
        })
        .finally(() => {
            savingInviteCodeRegisterConfig.value = false
        })
}

const fetchMobileWhitelistConfig = () => {
    mobileWhitelistConfigLoading.value = true
    createAxios({
        url: '/admin/content.DrawCountConfig/mobileWhitelistConfig',
        method: 'GET',
    })
        .then((res) => {
            mobileWhitelistConfig.enabled = res.data.enabled === 1 || res.data.enabled === true
        })
        .finally(() => {
            mobileWhitelistConfigLoading.value = false
        })
}

const saveMobileWhitelistConfig = () => {
    savingMobileWhitelistConfig.value = true
    createAxios(
        {
            url: '/admin/content.DrawCountConfig/mobileWhitelistConfig',
            method: 'POST',
            data: {
                enabled: mobileWhitelistConfig.enabled ? 1 : 0,
            },
        },
        {
            showSuccessMessage: true,
        }
    )
        .then(() => {
            fetchMobileWhitelistConfig()
        })
        .finally(() => {
            savingMobileWhitelistConfig.value = false
        })
}

// 初始化时获取配置
fetchInviteCodeRegisterConfig()
fetchMobileWhitelistConfig()
</script>

<style scoped lang="scss">
.register-config {
    .config-card {
        margin-bottom: 20px;
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

