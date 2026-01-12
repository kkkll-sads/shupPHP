<template>
    <div class="rights-declaration-page">
        <div class="container">
            <h1 class="page-title">确权申报</h1>

            <!-- 申报表单 -->
            <div class="declaration-form">
                <el-card class="form-card">
                    <template #header>
                        <div class="card-header">
                            <span>提交确权申报</span>
                        </div>
                    </template>

                    <el-form
                        ref="formRef"
                        :model="formData"
                        :rules="formRules"
                        label-width="120px"
                        size="large"
                    >
                        <el-form-item label="凭证类型" prop="voucher_type">
                            <el-select v-model="formData.voucher_type" placeholder="请选择凭证类型">
                                <el-option label="截图" value="screenshot" />
                                <el-option label="转账记录" value="transfer_record" />
                                <el-option label="其他凭证" value="other" />
                            </el-select>
                        </el-form-item>

                        <el-form-item label="申请金额" prop="amount">
                            <el-input-number
                                v-model="formData.amount"
                                :min="0.01"
                                :max="100000"
                                :precision="2"
                                controls-position="right"
                                placeholder="请输入申请金额"
                                style="width: 200px"
                            />
                            <span class="unit-text">元</span>
                        </el-form-item>

                        <el-form-item label="凭证图片" prop="images">
                            <div class="upload-section">
                                <el-upload
                                    ref="uploadRef"
                                    v-model:file-list="fileList"
                                    action="/api/common/upload"
                                    :headers="uploadHeaders"
                                    :on-success="handleUploadSuccess"
                                    :on-remove="handleUploadRemove"
                                    :before-upload="beforeUpload"
                                    multiple
                                    :limit="10"
                                    list-type="picture-card"
                                    accept="image/*"
                                >
                                    <el-icon><Plus /></el-icon>
                                    <div class="upload-text">上传图片</div>
                                </el-upload>
                                <div class="upload-tips">
                                    <p>最多上传10张图片，支持jpg、png、gif格式</p>
                                </div>
                            </div>
                        </el-form-item>

                        <el-form-item label="备注" prop="remark">
                            <el-input
                                v-model="formData.remark"
                                type="textarea"
                                :rows="4"
                                placeholder="请输入备注信息（可选）"
                                maxlength="500"
                                show-word-limit
                            />
                        </el-form-item>

                        <el-form-item>
                            <el-button
                                type="primary"
                                @click="submitDeclaration"
                                :loading="submitLoading"
                                size="large"
                            >
                                提交申报
                            </el-button>
                            <el-button @click="resetForm" size="large">
                                重置
                            </el-button>
                        </el-form-item>
                    </el-form>
                </el-card>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted, reactive } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import createAxios from '/@/utils/axios'

// 响应式数据
const submitLoading = ref(false)
const formRef = ref()
const uploadRef = ref()

// 文件列表
const fileList = ref([])

// 表单数据
const formData = reactive({
    voucher_type: '',
    amount: 0,
    images: [] as string[],
    remark: ''
})

// 表单验证规则
const formRules = {
    voucher_type: [
        { required: true, message: '请选择凭证类型', trigger: 'change' }
    ],
    amount: [
        { required: true, message: '请输入申请金额', trigger: 'blur' },
        { type: 'number', min: 0.01, message: '金额不能小于0.01元', trigger: 'blur' },
        { type: 'number', max: 100000, message: '金额不能超过10万元', trigger: 'blur' }
    ],
    images: [
        { required: true, message: '请上传至少一张凭证图片', trigger: 'change' }
    ]
}

// 上传请求头
const uploadHeaders = {
    'ba-token': localStorage.getItem('ba-token') || '',
    'ba-user-token': localStorage.getItem('ba-user-token') || ''
}

// 检查用户是否有待审核的申报
const checkPendingDeclaration = async () => {
    try {
        const res = await createAxios({
            url: '/api/rightsDeclaration/reviewStatus',
            method: 'get',
            params: { status: 'pending', page: 1, limit: 1 }
        })

        if (res.code === 1 && res.data.pending_count > 0) {
            // 如果有待审核的申报，直接跳转到审核状态页面
            goToReviewStatus()
        }
    } catch (error) {
        console.error('检查审核状态失败:', error)
    }
}

// 跳转到审核状态页面
const goToReviewStatus = () => {
    // 这里需要根据实际路由配置跳转
    window.location.href = '/rightsDeclaration/review'
}

// 上传成功处理
const handleUploadSuccess = (response: any, file: any, fileList: any[]) => {
    if (response.code === 1) {
        formData.images.push(response.data.url)
    } else {
        ElMessage.error(response.msg || '上传失败')
    }
}

// 上传移除处理
const handleUploadRemove = (file: any, fileList: any[]) => {
    // 从images数组中移除对应的URL
    const index = formData.images.findIndex(url => url.includes(file.name))
    if (index > -1) {
        formData.images.splice(index, 1)
    }
}

// 上传前验证
const beforeUpload = (file: File) => {
    const isImage = file.type.startsWith('image/')
    const isLt10M = file.size / 1024 / 1024 < 10

    if (!isImage) {
        ElMessage.error('只能上传图片文件!')
        return false
    }

    if (!isLt10M) {
        ElMessage.error('上传图片大小不能超过 10MB!')
        return false
    }

    return true
}

// 提交申报
const submitDeclaration = async () => {
    if (!formRef.value) return

    await formRef.value.validate(async (valid: boolean) => {
        if (!valid) return

        submitLoading.value = true

        try {
            const res = await createAxios({
                url: '/api/rightsDeclaration/submit',
                method: 'post',
                data: {
                    voucher_type: formData.voucher_type,
                    amount: formData.amount,
                    images: JSON.stringify(formData.images),
                    remark: formData.remark
                }
            })

            if (res.code === 1) {
                ElMessage.success('确权申报提交成功，请等待管理员审核')
                resetForm()
                // 重新检查审核状态
                await checkPendingDeclaration()
            } else {
                ElMessage.error(res.msg || '提交失败')
            }
        } catch (error: any) {
            console.error('提交申报失败:', error)
            ElMessage.error(error?.msg || error?.response?.data?.msg || '提交失败，请稍后重试')
        } finally {
            submitLoading.value = false
        }
    })
}

// 重置表单
const resetForm = () => {
    formRef.value?.resetFields()
    formData.images = []
    fileList.value = []
}

// 页面加载时检查审核状态
onMounted(() => {
    checkPendingDeclaration()
})
</script>

<style scoped lang="scss">
.rights-declaration-page {
    min-height: 100vh;
    background-color: #f5f5f5;
    padding: 20px 0;
}

.container {
    max-width: 800px;
    margin: 0 auto;
    padding: 0 20px;
}

.page-title {
    text-align: center;
    color: #333;
    margin-bottom: 30px;
    font-size: 28px;
    font-weight: 500;
}


.declaration-form {
    .form-card {
        box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
    }

    .card-header {
        font-size: 18px;
        font-weight: 500;
    }
}

.upload-section {
    .upload-text {
        margin-top: 8px;
        color: #666;
        font-size: 12px;
    }

    .upload-tips {
        margin-top: 10px;

        p {
            margin: 0;
            color: #999;
            font-size: 12px;
            line-height: 1.5;
        }
    }
}

.unit-text {
    margin-left: 10px;
    color: #666;
}
</style>
