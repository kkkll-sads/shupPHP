<template>
    <el-dialog
        class="ba-edit-dialog"
        :close-on-click-modal="false"
        :destroy-on-close="true"
        :model-value="['Add', 'Edit'].includes(baTable.form.operate!)"
        @close="onCancel"
        width="50%"
    >
        <template #header>
            <div class="title">{{ baTable.form.operate === 'Add' ? t('Add') : t('Edit') }}</div>
        </template>
        <el-form :model="form" label-width="120px" ref="formRef">
            <el-form-item
                :label="t('content.luckyDrawPrize.name')"
                prop="name"
                :rules="{ required: true, message: t('Please input field', { field: t('content.luckyDrawPrize.name') }) }"
            >
                <el-input v-model="form.name" :placeholder="t('content.luckyDrawPrize.name')" />
            </el-form-item>

            <el-form-item :label="t('content.luckyDrawPrize.description')" prop="description">
                <el-input v-model="form.description" :placeholder="t('content.luckyDrawPrize.description')" type="textarea" />
            </el-form-item>

            <FormItem
                :label="t('content.luckyDrawPrize.thumbnail')"
                type="image"
                v-model="form.thumbnail"
                prop="thumbnail"
                :input-attr="{ returnFullUrl: true, limit: 1 }"
            />

            <el-form-item
                :label="t('content.luckyDrawPrize.prize_type')"
                prop="prize_type"
                :rules="{ required: true, message: t('Please select field', { field: t('content.luckyDrawPrize.prize_type') }) }"
            >
                <el-select v-model="form.prize_type" :placeholder="t('content.luckyDrawPrize.prize_type')">
                    <el-option label="积分" value="score" />
                    <el-option label="金额" value="money" />
                    <el-option label="优惠券" value="coupon" />
                    <el-option label="实物" value="item" />
                </el-select>
            </el-form-item>

            <el-form-item
                :label="t('content.luckyDrawPrize.prize_value')"
                prop="prize_value"
                :rules="{ required: true, message: t('Please input field', { field: t('content.luckyDrawPrize.prize_value') }) }"
            >
                <el-input v-model.number="form.prize_value" :placeholder="t('content.luckyDrawPrize.prize_value')" type="number" />
            </el-form-item>

            <el-form-item
                :label="t('content.luckyDrawPrize.probability')"
                prop="probability"
                :rules="{ required: true, message: t('Please input field', { field: t('content.luckyDrawPrize.probability') }) }"
            >
                <el-input v-model.number="form.probability" :placeholder="t('content.luckyDrawPrize.probability')" type="number" />
                <div class="form-tip">{{ t('content.luckyDrawPrize.probability_tip') }}</div>
            </el-form-item>

            <el-form-item :label="t('content.luckyDrawPrize.daily_limit')" prop="daily_limit">
                <el-input v-model.number="form.daily_limit" :placeholder="t('content.luckyDrawPrize.daily_limit')" type="number" />
                <div class="form-tip">{{ t('content.luckyDrawPrize.daily_limit_tip') }}</div>
            </el-form-item>

            <el-form-item :label="t('content.luckyDrawPrize.total_limit')" prop="total_limit">
                <el-input v-model.number="form.total_limit" :placeholder="t('content.luckyDrawPrize.total_limit')" type="number" />
                <div class="form-tip">{{ t('content.luckyDrawPrize.total_limit_tip') }}</div>
            </el-form-item>

            <el-form-item :label="t('content.luckyDrawPrize.sort')" prop="sort">
                <el-input v-model.number="form.sort" :placeholder="t('content.luckyDrawPrize.sort')" type="number" />
            </el-form-item>

            <el-form-item :label="t('content.luckyDrawPrize.status')" prop="status">
                <el-radio-group v-model="form.status">
                    <el-radio value="1">{{ t('Enable') }}</el-radio>
                    <el-radio value="0">{{ t('Disable') }}</el-radio>
                </el-radio-group>
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
import luckyDrawPrizeZhCn from '/@/lang/backend/zh-cn/content/luckyDrawPrize'
import luckyDrawPrizeEn from '/@/lang/backend/en/content/luckyDrawPrize'
import FormItem from '/@/components/formItem/index.vue'

const { t, locale } = useI18n()
const baTable: any = inject('baTable')

// 在 setup 阶段立即合并语言文件
if (locale.value === 'zh-cn') {
    mergeMessage(luckyDrawPrizeZhCn, 'content/luckyDrawPrize')
} else {
    mergeMessage(luckyDrawPrizeEn, 'content/luckyDrawPrize')
}

const formRef = ref()

const form = reactive({
    id: '',
    name: '',
    description: '',
    thumbnail: '',
    prize_type: 'score',
    prize_value: 0,
    probability: 0,
    daily_limit: 0,
    total_limit: 0,
    sort: 0,
    status: '1',
})

watch(
    () => baTable.form.items,
    (newVal) => {
        if (newVal && typeof newVal === 'object') {
            Object.assign(form, newVal)
        }
    },
    { deep: true }
)

const onCancel = () => {
    formRef.value?.resetFields()
    baTable.toggleForm('')
}

const onConfirm = () => {
    formRef.value?.validate((valid: boolean) => {
        if (valid) {
            const normalizeNumber = (value: number | string | null | undefined, fallback = 0) => {
                if (value === null || value === undefined || value === '') {
                    return fallback
                }
                const num = Number(value)
                if (Number.isNaN(num) || num < 0) {
                    return fallback
                }
                return Math.floor(num) // 确保是整数
            }
            const normalizedForm = {
                ...form,
                prize_value: normalizeNumber(form.prize_value, 0),
                probability: normalizeNumber(form.probability, 0),
                daily_limit: normalizeNumber(form.daily_limit, 0),
                total_limit: normalizeNumber(form.total_limit, 0),
                sort: normalizeNumber(form.sort, 0),
            }

            Object.assign(baTable.form.items, normalizedForm)
            baTable.onSubmit(formRef.value)
        }
    })
}
</script>

<style scoped lang="scss">
.form-tip {
    font-size: 12px;
    color: #909399;
    margin-top: 5px;
}
</style>
