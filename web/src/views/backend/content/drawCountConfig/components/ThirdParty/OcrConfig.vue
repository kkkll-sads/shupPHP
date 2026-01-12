<template>
    <div class="ocr-config">
        <el-card class="config-card" shadow="hover">
            <template #header>
                <div class="card-header">
                    <span class="card-title">网易易盾OCR配置</span>
                    <div class="card-actions">
                        <el-button size="small" @click="fetchYidunOcrConfig" :loading="yidunOcrConfigLoading" :icon="Refresh">刷新</el-button>
                        <el-button size="small" type="primary" @click="saveYidunOcrConfig" :loading="savingYidunOcrConfig">保存</el-button>
                    </div>
                </div>
            </template>
            <el-form :model="yidunOcrConfig" label-width="160px" v-loading="yidunOcrConfigLoading">
                <el-divider content-position="left">businessId</el-divider>
                <el-row :gutter="16">
                    <el-col :span="12">
                        <el-form-item label="身份证OCR">
                            <el-input v-model="yidunOcrConfig.businessId" placeholder="请输入身份证OCR" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="实人认证">
                            <el-input v-model="yidunOcrConfig.realPersonBusinessId" placeholder="请输入实人认证" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="活体检测">
                            <el-input v-model="yidunOcrConfig.livenessBusinessId" placeholder="请输入活体检测" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="H5人脸核身">
                            <el-input v-model="yidunOcrConfig.h5FaceBusinessId" placeholder="请输入H5人脸核身" />
                        </el-form-item>
                    </el-col>
                    <el-col :span="12">
                        <el-form-item label="APP人脸核身">
                            <el-input v-model="yidunOcrConfig.appFaceBusinessId" placeholder="请输入APP人脸核身" />
                        </el-form-item>
                    </el-col>
                </el-row>
                <el-form-item label="secretId">
                    <el-input v-model="yidunOcrConfig.secretId" placeholder="请输入secretId" />
                </el-form-item>
                <el-form-item label="secretKey">
                    <el-input v-model="yidunOcrConfig.secretKey" placeholder="请输入secretKey" />
                </el-form-item>
                <el-form-item label="OCR接口地址">
                    <el-input v-model="yidunOcrConfig.api_url" placeholder="请输入OCR接口地址" />
                </el-form-item>
                <el-form-item label="实人认证接口地址">
                    <el-input v-model="yidunOcrConfig.real_person_api_url" placeholder="http://verify.dun.163.com/v1/rp/check" />
                </el-form-item>
                <el-form-item label="交互式人脸核身接口">
                    <el-input v-model="yidunOcrConfig.live_person_api_url" placeholder="https://verify.dun.163.com/v1/liveperson/audit" />
                </el-form-item>
                <el-form-item label="单活体接口地址">
                    <el-input v-model="yidunOcrConfig.live_person_recheck_api_url" placeholder="https://verify.dun.163.com/v1/liveperson/recheck" />
                </el-form-item>
            </el-form>
        </el-card>
    </div>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import { Refresh } from '@element-plus/icons-vue'
import createAxios from '/@/utils/axios'

const yidunOcrConfigLoading = ref(false)
const savingYidunOcrConfig = ref(false)
const yidunOcrConfig = reactive({
    businessId: '',
    realPersonBusinessId: '',
    livenessBusinessId: '',
    h5FaceBusinessId: '',
    appFaceBusinessId: '',
    secretId: '',
    secretKey: '',
    api_url: '',
    real_person_api_url: '',
    livePersonBusinessId: '',
    live_person_api_url: '',
    livePersonRecheckBusinessId: '',
    live_person_recheck_api_url: '',
})

const fetchYidunOcrConfig = () => {
    yidunOcrConfigLoading.value = true
    createAxios({ url: '/admin/content.DrawCountConfig/yidunOcrConfig', method: 'GET' })
        .then((res) => {
            yidunOcrConfig.businessId = res.data.businessId ?? ''
            yidunOcrConfig.realPersonBusinessId = res.data.realPersonBusinessId ?? ''
            yidunOcrConfig.livenessBusinessId = res.data.livenessBusinessId ?? ''
            yidunOcrConfig.h5FaceBusinessId = res.data.h5FaceBusinessId ?? ''
            yidunOcrConfig.appFaceBusinessId = res.data.appFaceBusinessId ?? ''
            yidunOcrConfig.secretId = res.data.secretId ?? ''
            yidunOcrConfig.secretKey = res.data.secretKey ?? ''
            yidunOcrConfig.api_url = res.data.api_url ?? ''
            yidunOcrConfig.real_person_api_url = res.data.real_person_api_url ?? ''
            yidunOcrConfig.livePersonBusinessId = res.data.livePersonBusinessId ?? ''
            yidunOcrConfig.live_person_api_url = res.data.live_person_api_url ?? ''
            yidunOcrConfig.livePersonRecheckBusinessId = res.data.livePersonRecheckBusinessId ?? ''
            yidunOcrConfig.live_person_recheck_api_url = res.data.live_person_recheck_api_url ?? ''
        })
        .finally(() => {
            yidunOcrConfigLoading.value = false
        })
}

const saveYidunOcrConfig = () => {
    savingYidunOcrConfig.value = true
    createAxios({ url: '/admin/content.DrawCountConfig/yidunOcrConfig', method: 'POST', data: yidunOcrConfig }, { showSuccessMessage: true })
        .then(() => {
            fetchYidunOcrConfig()
        })
        .finally(() => {
            savingYidunOcrConfig.value = false
        })
}

fetchYidunOcrConfig()
</script>

<style scoped lang="scss">
.ocr-config {
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
}
</style>

