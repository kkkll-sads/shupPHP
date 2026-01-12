<script setup lang="ts">
import { ref, onMounted } from 'vue'
import createAxios from '/@/utils/axios'
import { ElMessage } from 'element-plus'

defineOptions({
    name: 'content/activityRewardConfig',
})

interface ConfigItem {
    id: number
    name: string
    title: string
    tip: string
    value: string
    type: string
}

const loading = ref(false)
const saveLoading = ref(false)
const configs = ref<ConfigItem[]>([])
const formData = ref<Record<string, string>>({})

const fetchConfigs = async () => {
    loading.value = true
    try {
        const res = await createAxios({
            url: '/admin/content.ActivityRewardConfig/index',
            method: 'get',
        })
        if (res.code === 1) {
            configs.value = res.data.list || []
            // 初始化表单数据
            configs.value.forEach((item: ConfigItem) => {
                formData.value[item.name] = item.value
            })
        }
    } catch (e) {
        console.error(e)
    } finally {
        loading.value = false
    }
}

const handleSave = async () => {
    saveLoading.value = true
    try {
        const res = await createAxios({
            url: '/admin/content.ActivityRewardConfig/save',
            method: 'post',
            data: { data: formData.value }
        })
        if (res.code === 1) {
            ElMessage.success(res.msg || '保存成功')
        } else {
            ElMessage.error(res.msg || '保存失败')
        }
    } catch (e: any) {
        ElMessage.error(e.msg || '请求失败')
    } finally {
        saveLoading.value = false
    }
}

onMounted(() => {
    fetchConfigs()
})
</script>

<template>
    <div class="default-main">
        <el-card v-loading="loading">
            <template #header>
                <div class="card-header">
                    <span>活动奖励配置</span>
                    <el-button type="primary" :loading="saveLoading" @click="handleSave">保存配置</el-button>
                </div>
            </template>
            
            <el-form label-width="200px">
                <el-form-item 
                    v-for="item in configs" 
                    :key="item.name" 
                    :label="item.title"
                >
                    <el-input-number 
                        v-model.number="formData[item.name]" 
                        :min="0" 
                        :precision="2"
                        style="width: 200px"
                    />
                    <span v-if="item.tip" class="config-tip">{{ item.tip }}</span>
                </el-form-item>
            </el-form>
        </el-card>
    </div>
</template>

<style scoped lang="scss">
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.config-tip {
    margin-left: 12px;
    color: #909399;
    font-size: 12px;
}
</style>
