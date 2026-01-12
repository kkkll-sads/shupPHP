<template>
    <!-- 对话框表单 -->
    <el-dialog
        class="ba-operate-dialog"
        :close-on-click-modal="false"
        :model-value="['Add', 'Edit'].includes(baTable.form.operate!)"
        @close="baTable.toggleForm"
    >
        <template #header>
            <div class="title" v-drag="['.ba-operate-dialog', '.el-dialog__header']" v-zoom="'.ba-operate-dialog'">
                {{ baTable.form.operate ? t(baTable.form.operate) : '' }}
            </div>
        </template>
        <el-scrollbar v-loading="baTable.form.loading" class="ba-table-form-scrollbar">
            <div
                class="ba-operate-form"
                :class="'ba-' + baTable.form.operate + '-form'"
                :style="config.layout.shrink ? '' : 'width: calc(100% - ' + baTable.form.labelWidth! / 2 + 'px)'"
            >
                <el-form
                    v-if="!baTable.form.loading"
                    ref="formRef"
                    @keyup.enter="baTable.onSubmit(formRef)"
                    :model="baTable.form.items"
                    :label-position="config.layout.shrink ? 'top' : 'right'"
                    :label-width="baTable.form.labelWidth + 'px'"
                    :rules="rules"
                >
                    <FormItem
                        type="remoteSelect"
                        :label="t('user.consignmentCoupon.user_id')"
                        v-model="baTable.form.items!.user_id"
                        prop="user_id"
                        :input-attr="{
                            pk: 'user.id',
                            field: 'user.username',
                            remoteUrl: '/admin/user.User/index',
                            placeholder: '请选择用户'
                        }"
                    />
                    
                    <FormItem
                        type="remoteSelect"
                        :label="t('user.consignmentCoupon.session_id')"
                        v-model="baTable.form.items!.session_id"
                        prop="session_id"
                        :input-attr="{
                            pk: 'collection_session.id',
                            field: 'collection_session.title',
                            remoteUrl: '/admin/CollectionSession/index',
                            placeholder: '请选择场次'
                        }"
                    />

                    <FormItem
                        type="remoteSelect"
                        :label="t('user.consignmentCoupon.zone_id')"
                        v-model="baTable.form.items!.zone_id"
                        prop="zone_id"
                        :input-attr="{
                            pk: 'price_zone_config.id',
                            field: 'price_zone_config.name',
                            remoteUrl: '/admin/PriceZoneConfig/index',
                            placeholder: '请选择价格分区'
                        }"
                    />
                    
                    <FormItem
                        :label="t('user.consignmentCoupon.status')"
                        v-model="baTable.form.items!.status"
                        type="radio"
                        :input-attr="{
                            border: true,
                            content: { 0: '已使用', 1: '可用' }
                        }"
                    />
                    
                    <el-form-item :label="t('user.consignmentCoupon.expire_time')" prop="expire_time">
                        <el-date-picker
                            class="w100"
                            v-model="baTable.form.items!.expire_time"
                            type="datetime"
                            placeholder="请选择过期时间"
                            value-format="X"
                        />
                    </el-form-item>

                </el-form>
            </div>
        </el-scrollbar>
        <template #footer>
            <div :style="'width: calc(100% - ' + baTable.form.labelWidth! / 1.8 + 'px)'">
                <el-button @click="baTable.toggleForm('')">{{ t('Cancel') }}</el-button>
                <el-button v-blur :loading="baTable.form.submitLoading" @click="onSubmit" type="primary">
                    {{ t('Save') }}
                </el-button>
            </div>
        </template>
    </el-dialog>
</template>

<script setup lang="ts">
import { reactive, inject, useTemplateRef, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type baTableClass from '/@/utils/baTable'
import FormItem from '/@/components/formItem/index.vue'
import type { FormItemRule } from 'element-plus'
import { useConfig } from '/@/stores/config'

const config = useConfig()
const { t } = useI18n()
const baTable = inject('baTable') as baTableClass
const formRef = useTemplateRef('formRef')

const rules: Partial<Record<string, FormItemRule[]>> = reactive({
    user_id: [
        { required: true, message: '请选择用户', trigger: 'change' }
    ],
    session_id: [
        { required: true, message: '请选择场次', trigger: 'change' }
    ],
    zone_id: [
        { required: true, message: '请选择价格分区', trigger: 'change' }
    ],
    status: [
        { required: true, message: '请选择状态', trigger: 'change' }
    ]
})
const onSubmit = () => {
    // Ensure expire_time is a valid integer timestamp or 0
    if (baTable.form.items!.expire_time === null || baTable.form.items!.expire_time === undefined || baTable.form.items!.expire_time === '') {
        baTable.form.items!.expire_time = 0
    } else {
        // Convert to integer if it's a string timestamp
        baTable.form.items!.expire_time = parseInt(baTable.form.items!.expire_time as any)
    }
    baTable.onSubmit(formRef.value)
}

// Watch for form open to ensure expire_time is properly formatted for display
watch(
    () => baTable.form.operate,
    (newVal) => {
        if (newVal === 'Edit' && baTable.form.items!.expire_time) {
            // Ensure expire_time is a number for the date picker
            const expireTime = baTable.form.items!.expire_time
            if (typeof expireTime === 'string') {
                baTable.form.items!.expire_time = parseInt(expireTime)
            }
            // Convert 0 to empty for better UX
            if (baTable.form.items!.expire_time === 0) {
                baTable.form.items!.expire_time = null as any
            }
        }
    }
)

</script>

<style scoped lang="scss"></style>
