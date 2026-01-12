<template>
    <el-dialog
        class="ba-edit-dialog"
        :close-on-click-modal="false"
        :destroy-on-close="true"
        :model-value="baTable && baTable.form && ['Add', 'Edit'].includes(baTable.form.operate!)"
        @close="onCancel"
        width="460px"
    >
        <template #header>
            <div class="title">{{ baTable?.form?.operate === 'Add' ? t('Add') : t('Edit') }}</div>
        </template>
        <el-form :model="form" label-width="120px" ref="formRef">
            <el-form-item label="直推人数" prop="direct_people" :rules="rules.direct_people">
                <el-input v-model.number="form.direct_people" placeholder="请输入直推人数" type="number" />
            </el-form-item>
            <el-form-item label="抽奖次数" prop="draw_count" :rules="rules.draw_count">
                <el-input v-model.number="form.draw_count" placeholder="请输入抽奖次数" type="number" />
            </el-form-item>
            <el-form-item label="备注" prop="remark">
                <el-input v-model="form.remark" type="textarea" placeholder="请输入备注" :rows="3" maxlength="255" show-word-limit />
            </el-form-item>
        </el-form>
        <template #footer>
            <span class="dialog-footer">
                <el-button @click="onCancel">{{ t('Cancel') }}</el-button>
                <el-button type="primary" @click="onSubmit">{{ t('Confirm') }}</el-button>
            </span>
        </template>
    </el-dialog>
</template>

<script setup lang="ts">
import { inject, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const baTable: any = inject('baTable')

const formRef = ref()
const form = reactive({
    id: '',
    direct_people: 1,
    draw_count: 1,
    remark: '',
})

const positiveIntegerValidator = (fieldLabel: string) => {
    return (_rule: any, value: number, callback: (err?: Error) => void) => {
        if (value === undefined || value === null || value === '') {
            callback(new Error(`请输入${fieldLabel}`))
            return
        }
        const num = Number(value)
        if (!Number.isInteger(num) || num <= 0) {
            callback(new Error(`${fieldLabel}必须为大于0的整数`))
            return
        }
        callback()
    }
}

const rules = {
    direct_people: [
        { required: true, message: '直推人数不能为空', trigger: 'blur' },
        { validator: positiveIntegerValidator('直推人数'), trigger: 'blur' },
    ],
    draw_count: [
        { required: true, message: '抽奖次数不能为空', trigger: 'blur' },
        { validator: positiveIntegerValidator('抽奖次数'), trigger: 'blur' },
    ],
}

watch(
    () => baTable?.form?.items,
    (newVal) => {
        if (newVal && typeof newVal === 'object') {
            Object.assign(form, {
                id: newVal.id ?? '',
                direct_people: newVal.direct_people ?? 1,
                draw_count: newVal.draw_count ?? 1,
                remark: newVal.remark ?? '',
            })
        }
    },
    { deep: true }
)

const normalizeInteger = (value: number) => {
    const num = Number(value)
    if (!Number.isFinite(num) || num <= 0) {
        return 1
    }
    return Math.floor(num)
}

const onCancel = () => {
    formRef.value?.resetFields()
    baTable?.toggleForm('')
}

const onSubmit = () => {
    if (!baTable) return
    formRef.value?.validate((valid: boolean) => {
        if (!valid) return
        const payload = {
            ...form,
            direct_people: normalizeInteger(form.direct_people),
            draw_count: normalizeInteger(form.draw_count),
        }
        if (baTable.form.items) {
        Object.assign(baTable.form.items, payload)
        }
        baTable.onSubmit(formRef.value)
    })
}
</script>

<style scoped></style>

