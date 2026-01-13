<template>
    <el-dialog
        class="ba-edit-dialog"
        :close-on-click-modal="false"
        :destroy-on-close="true"
        :model-value="Boolean(baTable.form.operate)"
        @close="baTable.toggleForm"
        width="50%"
    >
        <template #header>
            <div class="title">{{ t('Edit') }}</div>
        </template>
        <el-form :model="form" label-width="120px" ref="formRef">
            <el-form-item :label="t('content.luckyDrawRecord.user_id')" prop="user_id">
                <el-input v-model="form.user_id" :placeholder="t('content.luckyDrawRecord.user_id')" disabled />
            </el-form-item>

            <el-form-item :label="t('content.luckyDrawRecord.prize_name')" prop="prize_name">
                <el-input v-model="form.prize_name" :placeholder="t('content.luckyDrawRecord.prize_name')" disabled />
            </el-form-item>

            <el-form-item :label="t('content.luckyDrawRecord.prize_type')" prop="prize_type">
                <el-input v-model="form.prize_type" :placeholder="t('content.luckyDrawRecord.prize_type')" disabled />
            </el-form-item>

            <el-form-item :label="t('content.luckyDrawRecord.prize_value')" prop="prize_value">
                <el-input v-model="form.prize_value" :placeholder="t('content.luckyDrawRecord.prize_value')" disabled />
            </el-form-item>

            <el-form-item :label="t('content.luckyDrawRecord.status')" prop="status">
                <el-select v-model="form.status" :placeholder="t('content.luckyDrawRecord.status')">
                    <el-option :label="t('content.luckyDrawRecord.status_pending')" value="1" />
                    <el-option :label="t('content.luckyDrawRecord.status_send')" value="2" />
                    <el-option :label="t('content.luckyDrawRecord.status_revoke')" value="0" />
                </el-select>
            </el-form-item>

            <el-form-item :label="t('content.luckyDrawRecord.draw_time')" prop="draw_time">
                <el-input v-model="form.draw_time" :placeholder="t('content.luckyDrawRecord.draw_time')" disabled type="datetime-local" />
            </el-form-item>
        </el-form>
        <template #footer>
            <span class="dialog-footer">
                <el-button @click="onCancel">{{ t('Cancel') }}</el-button>
                <el-button type="primary" @click="onConfirm">{{ t('Confirm') }}</el-button>
            </span>
        </template>
    </el-dialog>
</template>

<script setup lang="ts">
import { reactive, ref, inject, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { mergeMessage } from '/@/lang/index'
import luckyDrawRecordZhCn from '/@/lang/backend/zh-cn/content/luckyDrawRecord'
import luckyDrawRecordEn from '/@/lang/backend/en/content/luckyDrawRecord'

const { t, locale } = useI18n()
const baTable: any = inject('baTable')

// 在 setup 阶段立即合并语言文件
if (locale.value === 'zh-cn') {
    mergeMessage(luckyDrawRecordZhCn, 'content/luckyDrawRecord')
} else {
    mergeMessage(luckyDrawRecordEn, 'content/luckyDrawRecord')
}

const formRef = ref()

const form = reactive({
    id: '',
    user_id: '',
    prize_id: '',
    prize_name: '',
    prize_type: '',
    prize_value: '',
    status: '1',
    draw_time: '',
    send_time: '',
})

const originalStatus = ref<string>('')

watch(
    () => baTable.form.items,
    (newVal) => {
        if (newVal && typeof newVal === 'object') {
            Object.assign(form, newVal)
            originalStatus.value = newVal.status || ''
        }
    },
    { deep: true }
)

const onCancel = () => {
    baTable.toggleForm('')
}

const onConfirm = () => {
    formRef.value?.validate((valid: boolean) => {
        if (valid) {
            Object.assign(baTable.form.items, form)
            baTable.onSubmit(formRef.value)
        }
    })
}
</script>
