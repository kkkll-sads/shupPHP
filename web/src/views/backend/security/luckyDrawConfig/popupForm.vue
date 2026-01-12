<template>
    <el-dialog
        class="ba-edit-dialog"
        :close-on-click-modal="false"
        :destroy-on-close="true"
        :model-value="baTable.form.operate !== null"
        @close="baTable.toggleForm"
        width="50%"
    >
        <template #header>
            <div class="title">{{ t('Edit') }}</div>
        </template>
        <el-form :model="form" label-width="150px" ref="formRef">
            <el-form-item :label="t('security.luckyDrawConfig.config_key')" prop="config_key">
                <el-input v-model="form.config_key" :placeholder="t('security.luckyDrawConfig.config_key')" disabled />
            </el-form-item>

            <el-form-item :label="t('security.luckyDrawConfig.config_value')" prop="config_value" :rules="{ required: true, message: t('security.luckyDrawConfig.config_value') + ' ' + t('is_required') }">
                <el-input
                    v-if="form.config_key === 'prize_send_auto'"
                    v-model="form.config_value"
                    :placeholder="t('security.luckyDrawConfig.config_value')"
                    type="number"
                    min="0"
                    max="1"
                />
                <el-input v-else v-model="form.config_value" :placeholder="t('security.luckyDrawConfig.config_value')" type="number" />
            </el-form-item>

            <el-form-item :label="t('security.luckyDrawConfig.remark')" prop="remark">
                <el-input v-model="form.remark" :placeholder="t('security.luckyDrawConfig.remark')" type="textarea" />
            </el-form-item>

            <div class="config-info">
                <el-descriptions :column="1" border>
                    <el-descriptions-item v-if="form.config_key === 'daily_draw_limit'" :label="t('Tip')">
                        {{ t('security.luckyDrawConfig.daily_draw_limit') }} - 用户每天可以抽奖的次数
                    </el-descriptions-item>
                    <el-descriptions-item v-if="form.config_key === 'draw_score_cost'" :label="t('Tip')">
                        {{ t('security.luckyDrawConfig.draw_score_cost') }} - 每次抽奖需要消耗的积分数量
                    </el-descriptions-item>
                    <el-descriptions-item v-if="form.config_key === 'daily_limit_reset_hour'" :label="t('Tip')">
                        {{ t('security.luckyDrawConfig.daily_limit_reset_hour') }} - 每天何时重置每日次数（0-23）
                    </el-descriptions-item>
                    <el-descriptions-item v-if="form.config_key === 'prize_send_auto'" :label="t('Tip')">
                        {{ t('security.luckyDrawConfig.prize_send_auto') }} - 是否自动发放奖品（1=是，0=否）
                    </el-descriptions-item>
                </el-descriptions>
            </div>
        </el-form>
        <template #footer>
            <span class="dialog-footer">
                <el-button @click="baTable.toggleForm">{{ t('Cancel') }}</el-button>
                <el-button type="primary" @click="onConfirm">{{ t('Confirm') }}</el-button>
            </span>
        </template>
    </el-dialog>
</template>

<script setup lang="ts">
import { reactive, ref, inject, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { mergeMessage } from '/@/lang/index'
import luckyDrawConfigZhCn from '/@/lang/backend/zh-cn/security/luckyDrawConfig'
import luckyDrawConfigEn from '/@/lang/backend/en/security/luckyDrawConfig'

const { t, locale } = useI18n()
const baTable: any = inject('baTable')

// 在 setup 阶段立即合并语言文件
if (locale.value === 'zh-cn') {
    mergeMessage(luckyDrawConfigZhCn, 'security/luckyDrawConfig')
} else {
    mergeMessage(luckyDrawConfigEn, 'security/luckyDrawConfig')
}

const formRef = ref()

const form = reactive({
    id: '',
    config_key: '',
    config_value: '',
    remark: '',
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

const onConfirm = () => {
    formRef.value?.validate((valid: boolean) => {
        if (valid) {
            baTable.onEdit(null, { loading: true })
        }
    })
}
</script>

<style scoped lang="scss">
.config-info {
    margin-top: 20px;
    padding: 15px;
    background-color: #f5f7fa;
    border-radius: 4px;
}
</style>

