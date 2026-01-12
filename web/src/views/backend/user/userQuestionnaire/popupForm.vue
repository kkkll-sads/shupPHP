<template>
    <el-dialog
        class="ba-operate-dialog"
        :close-on-click-modal="false"
        :model-value="['Add', 'Edit'].includes(baTable.form.operate!)"
        @close="baTable.toggleForm"
    >
        <template #header>
            <div class="title" v-drag="['.ba-operate-dialog', '.el-dialog__header']" v-zoom="'.ba-operate-dialog'">
                问卷详情
            </div>
        </template>
        <el-scrollbar v-loading="baTable.form.loading" class="ba-table-form-scrollbar">
            <div class="ba-operate-form" :style="config.layout.shrink ? '' : 'width: calc(100% - ' + baTable.form.labelWidth! / 2 + 'px)'">
                <el-form
                    v-if="!baTable.form.loading"
                    ref="formRef"
                    :model="baTable.form.items"
                    :label-position="config.layout.shrink ? 'top' : 'right'"
                    :label-width="baTable.form.labelWidth + 'px'"
                >
                    <el-form-item label="用户">
                        <el-input :model-value="baTable.form.items!.user?.username || baTable.form.items!.user_id" readonly />
                    </el-form-item>
                    
                    <el-form-item label="标题">
                        <el-input v-model="baTable.form.items!.title" readonly />
                    </el-form-item>
                    
                    <el-form-item label="内容">
                        <el-input v-model="baTable.form.items!.content" type="textarea" :rows="6" readonly />
                    </el-form-item>

                    <el-form-item label="凭证图片" v-if="baTable.form.items!.images">
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <el-image 
                                v-for="(img, idx) in baTable.form.items!.images.split(',')" 
                                :key="idx" 
                                :src="fullUrl(img)" 
                                :preview-src-list="baTable.form.items!.images.split(',').map((url:string) => fullUrl(url))"
                                style="width: 100px; height: 100px; border-radius: 4px;"
                                fit="cover"
                            />
                        </div>
                    </el-form-item>
                    
                    <el-form-item label="当前状态">
                        <el-tag :type="statusType">{{ statusText }}</el-tag>
                    </el-form-item>
                    
                    <el-form-item v-if="baTable.form.items!.reward_power > 0" label="已奖励算力">
                        <el-input :model-value="baTable.form.items!.reward_power" readonly />
                    </el-form-item>
                    
                    <el-form-item label="管理员备注">
                        <el-input v-model="adminRemark" type="textarea" :rows="3" placeholder="请输入审核备注（可选）" />
                    </el-form-item>
                </el-form>
            </div>
        </el-scrollbar>
        <template #footer>
            <div :style="'width: calc(100% - ' + baTable.form.labelWidth! / 1.8 + 'px)'">
                <el-button @click="baTable.toggleForm('')">关闭</el-button>
                <el-button 
                    v-if="baTable.form.items!.status === 0" 
                    type="danger" 
                    :loading="rejectLoading"
                    @click="handleReject"
                >
                    拒绝
                </el-button>
                <el-button 
                    v-if="baTable.form.items!.status === 0" 
                    type="success" 
                    :loading="adoptLoading"
                    @click="handleAdopt"
                >
                    采纳并奖励
                </el-button>
            </div>
        </template>
    </el-dialog>
</template>

<script setup lang="ts">
import { ref, inject, computed } from 'vue'
import type baTableClass from '/@/utils/baTable'
import { useConfig } from '/@/stores/config'
import createAxios from '/@/utils/axios'
import { ElMessage } from 'element-plus'
import { fullUrl } from '/@/utils/common'

const config = useConfig()
const baTable = inject('baTable') as baTableClass

const adminRemark = ref('')
const adoptLoading = ref(false)
const rejectLoading = ref(false)

const statusText = computed(() => {
    const status = baTable.form.items!.status
    return status === 0 ? '待审核' : status === 1 ? '已采纳' : '已拒绝'
})

const statusType = computed(() => {
    const status = baTable.form.items!.status
    return status === 0 ? 'warning' : status === 1 ? 'success' : 'danger'
})

const handleAdopt = async () => {
    adoptLoading.value = true
    try {
        const res = await createAxios({
            url: '/admin/user.UserQuestionnaire/adopt',
            method: 'post',
            data: {
                ids: baTable.form.items!.id,
                admin_remark: adminRemark.value
            }
        })
        if (res.code === 1) {
            ElMessage.success(res.msg || '采纳成功')
            baTable.toggleForm('')
            baTable.getData()
        } else {
            ElMessage.error(res.msg || '操作失败')
        }
    } catch (e: any) {
        ElMessage.error(e.msg || '请求失败')
    } finally {
        adoptLoading.value = false
    }
}

const handleReject = async () => {
    rejectLoading.value = true
    try {
        const res = await createAxios({
            url: '/admin/user.UserQuestionnaire/reject',
            method: 'post',
            data: {
                ids: baTable.form.items!.id,
                admin_remark: adminRemark.value
            }
        })
        if (res.code === 1) {
            ElMessage.success(res.msg || '已拒绝')
            baTable.toggleForm('')
            baTable.getData()
        } else {
            ElMessage.error(res.msg || '操作失败')
        }
    } catch (e: any) {
        ElMessage.error(e.msg || '请求失败')
    } finally {
        rejectLoading.value = false
    }
}
</script>

<style scoped lang="scss"></style>
