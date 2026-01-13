<template>
    <div class="sms-config">
        <el-card class="config-card" shadow="hover" style="margin-bottom: 20px;">
            <template #header>
                <div class="card-header">
                    <span class="card-title">短信平台选择</span>
                    <div class="card-actions">
                        <el-button size="small" @click="fetchSmsPlatformConfig" :loading="smsPlatformConfigLoading" :icon="Refresh">刷新</el-button>
                        <el-button size="small" type="primary" @click="saveSmsPlatformConfig" :loading="savingSmsPlatformConfig">保存</el-button>
                    </div>
                </div>
            </template>
            <el-form :model="smsPlatformConfig" label-width="160px" v-loading="smsPlatformConfigLoading">
                <el-form-item label="选择短信平台">
                    <el-radio-group v-model="smsPlatformConfig.platform">
                        <el-radio label="smsbao">短信宝</el-radio>
                        <el-radio label="weiwebs">麦讯通</el-radio>
                    </el-radio-group>
                    <span class="form-tip">当前选择的短信平台：{{ smsPlatformConfig.platform === 'smsbao' ? '短信宝' : '麦讯通' }}</span>
                </el-form-item>
            </el-form>
        </el-card>

        <el-row :gutter="20">
            <el-col :xs="24" :lg="12">
                <el-card class="config-card" shadow="hover">
                    <template #header>
                        <div class="card-header">
                            <span class="card-title">短信宝配置</span>
                            <div class="card-actions">
                                <el-button size="small" @click="fetchSmsBaoConfig" :loading="smsBaoConfigLoading" :icon="Refresh">刷新</el-button>
                                <el-button size="small" type="primary" @click="saveSmsBaoConfig" :loading="savingSmsBaoConfig">保存</el-button>
                            </div>
                        </div>
                    </template>
                    <el-form :model="smsBaoConfig" label-width="160px" v-loading="smsBaoConfigLoading">
                        <el-form-item label="名称">
                            <el-input v-model="smsBaoConfig.name" placeholder="短信宝名称" />
                        </el-form-item>
                        <el-form-item label="username">
                            <el-input v-model="smsBaoConfig.username" placeholder="请输入username" />
                        </el-form-item>
                        <el-form-item label="API Key">
                            <el-input v-model="smsBaoConfig.api_key" placeholder="请输入API Key" />
                        </el-form-item>
                        <el-form-item label="发送接口地址">
                            <el-input v-model="smsBaoConfig.api_url" placeholder="请输入发送短信接口地址" />
                        </el-form-item>
                        <el-form-item label="查询接口地址">
                            <el-input v-model="smsBaoConfig.query_url" placeholder="请输入查询余额接口地址" />
                        </el-form-item>
                    </el-form>
                </el-card>
            </el-col>
            <el-col :xs="24" :lg="12">
                <el-card class="config-card" shadow="hover">
                    <template #header>
                        <div class="card-header">
                            <span class="card-title">麦讯通短信配置</span>
                            <div class="card-actions">
                                <el-button size="small" @click="fetchWeiWebsSmsConfig" :loading="weiWebsSmsConfigLoading" :icon="Refresh">刷新</el-button>
                                <el-button size="small" type="primary" @click="saveWeiWebsSmsConfig" :loading="savingWeiWebsSmsConfig">保存</el-button>
                            </div>
                        </div>
                    </template>
                    <el-form :model="weiWebsSmsConfig" label-width="160px" v-loading="weiWebsSmsConfigLoading">
                        <el-form-item label="名称">
                            <el-input v-model="weiWebsSmsConfig.name" placeholder="微网短信名称" />
                        </el-form-item>
                        <el-form-item label="account">
                            <el-input v-model="weiWebsSmsConfig.account" placeholder="请输入用户账号" />
                        </el-form-item>
                        <el-form-item label="password">
                            <el-input v-model="weiWebsSmsConfig.password" type="password" placeholder="请输入用户密码" show-password />
                        </el-form-item>
                        <el-form-item label="接口地址">
                            <el-input v-model="weiWebsSmsConfig.api_url" placeholder="请输入接口地址" />
                        </el-form-item>
                    </el-form>
                </el-card>
            </el-col>
        </el-row>
    </div>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import { Refresh } from '@element-plus/icons-vue'
import createAxios from '/@/utils/axios'

const smsPlatformConfigLoading = ref(false)
const savingSmsPlatformConfig = ref(false)
const smsPlatformConfig = reactive({
    platform: 'smsbao',
})

const smsBaoConfigLoading = ref(false)
const savingSmsBaoConfig = ref(false)
const smsBaoConfig = reactive({
    name: '',
    username: '',
    api_key: '',
    api_url: '',
    query_url: '',
})

const weiWebsSmsConfigLoading = ref(false)
const savingWeiWebsSmsConfig = ref(false)
const weiWebsSmsConfig = reactive({
    name: '',
    account: '',
    password: '',
    api_url: '',
})

const fetchSmsPlatformConfig = () => {
    smsPlatformConfigLoading.value = true
    createAxios({ url: '/admin/content.DrawCountConfig/smsPlatformConfig', method: 'GET' })
        .then((res) => {
            if (res.data.code === 1 && res.data.data) {
                smsPlatformConfig.platform = res.data.data.platform ?? 'smsbao'
            }
        })
        .finally(() => {
            smsPlatformConfigLoading.value = false
        })
}

const saveSmsPlatformConfig = () => {
    savingSmsPlatformConfig.value = true
    createAxios({ url: '/admin/content.DrawCountConfig/smsPlatformConfig', method: 'POST', data: smsPlatformConfig }, { showSuccessMessage: true })
        .then(() => {
            fetchSmsPlatformConfig()
        })
        .finally(() => {
            savingSmsPlatformConfig.value = false
        })
}

const fetchSmsBaoConfig = () => {
    smsBaoConfigLoading.value = true
    createAxios({ url: '/admin/content.DrawCountConfig/smsBaoConfig', method: 'GET' })
        .then((res) => {
            if (res.data.code === 1 && res.data.data) {
                smsBaoConfig.name = res.data.data.name ?? ''
                smsBaoConfig.username = res.data.data.username ?? ''
                smsBaoConfig.api_key = res.data.data.api_key ?? ''
                smsBaoConfig.api_url = res.data.data.api_url ?? ''
                smsBaoConfig.query_url = res.data.data.query_url ?? ''
            }
        })
        .finally(() => {
            smsBaoConfigLoading.value = false
        })
}

const saveSmsBaoConfig = () => {
    savingSmsBaoConfig.value = true
    createAxios({ url: '/admin/content.DrawCountConfig/smsBaoConfig', method: 'POST', data: smsBaoConfig }, { showSuccessMessage: true })
        .then(() => {
            fetchSmsBaoConfig()
        })
        .finally(() => {
            savingSmsBaoConfig.value = false
        })
}

const fetchWeiWebsSmsConfig = () => {
    weiWebsSmsConfigLoading.value = true
    createAxios({ url: '/admin/content.DrawCountConfig/weiWebsSmsConfig', method: 'GET' })
        .then((res) => {
            if (res.data.code === 1 && res.data.data) {
                weiWebsSmsConfig.name = res.data.data.name ?? ''
                weiWebsSmsConfig.account = res.data.data.account ?? ''
                weiWebsSmsConfig.password = res.data.data.password ?? ''
                weiWebsSmsConfig.api_url = res.data.data.api_url ?? ''
            }
        })
        .finally(() => {
            weiWebsSmsConfigLoading.value = false
        })
}

const saveWeiWebsSmsConfig = () => {
    savingWeiWebsSmsConfig.value = true
    createAxios({ url: '/admin/content.DrawCountConfig/weiWebsSmsConfig', method: 'POST', data: weiWebsSmsConfig }, { showSuccessMessage: true })
        .then(() => {
            fetchWeiWebsSmsConfig()
        })
        .finally(() => {
            savingWeiWebsSmsConfig.value = false
        })
}

fetchSmsPlatformConfig()
fetchSmsBaoConfig()
fetchWeiWebsSmsConfig()
</script>

<style scoped lang="scss">
.sms-config {
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

