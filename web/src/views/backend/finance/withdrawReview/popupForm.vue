<template>
    <el-dialog
        class="ba-operate-dialog"
        :close-on-click-modal="false"
        :destroy-on-close="true"
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
                    ref="formRef"
                    @keyup.enter="baTable.onSubmit(formRef)"
                    :model="baTable.form.items"
                    :label-position="config.layout.shrink ? 'top' : 'right'"
                    :label-width="baTable.form.labelWidth + 'px'"
                    :rules="rules"
                    v-if="!baTable.form.loading"
                >
                    <el-form-item prop="applicant_type" label="申请方类型">
                        <el-select v-model="baTable.form.items!.applicant_type" placeholder="请选择申请方类型">
                            <el-option label="用户" value="user" />
                            <el-option label="公司" value="company" />
                            <el-option label="合作方" value="partner" />
                        </el-select>
                    </el-form-item>
                    <el-form-item prop="applicant_name" label="申请方名称">
                        <el-input v-model="baTable.form.items!.applicant_name" placeholder="请输入申请方名称"></el-input>
                    </el-form-item>
                    <el-form-item prop="applicant_id" label="申请方ID">
                        <el-input-number
                            v-model="baTable.form.items!.applicant_id"
                            :min="0"
                            :step="1"
                            :precision="0"
                            controls-position="right"
                            placeholder="请输入申请方ID"
                        ></el-input-number>
                    </el-form-item>
                    <el-form-item prop="amount" label="提现金额（元）">
                        <el-input-number
                            v-model="baTable.form.items!.amount"
                            :min="0.01"
                            :step="0.01"
                            :precision="2"
                            controls-position="right"
                            placeholder="请输入提现金额"
                        ></el-input-number>
                    </el-form-item>
                    <el-form-item prop="status" label="当前状态">
                        <el-radio-group v-model="baTable.form.items!.status">
                            <el-radio :label="0">待审核</el-radio>
                            <el-radio :label="1">审核通过</el-radio>
                            <el-radio :label="2">审核拒绝</el-radio>
                        </el-radio-group>
                    </el-form-item>
                    <el-form-item prop="apply_reason" label="申请说明">
                        <el-input
                            v-model="baTable.form.items!.apply_reason"
                            type="textarea"
                            :rows="4"
                            placeholder="请填写申请备注"
                            maxlength="500"
                            show-word-limit
                        ></el-input>
                    </el-form-item>
                    <el-form-item prop="audit_remark" label="审核备注" v-if="baTable.form.operate === 'Edit'">
                        <el-input
                            v-model="baTable.form.items!.audit_remark"
                            type="textarea"
                            :rows="3"
                            placeholder="可填写审核备注"
                            maxlength="500"
                            show-word-limit
                        ></el-input>
                    </el-form-item>
                </el-form>
            </div>
        </el-scrollbar>
        <template #footer>
            <div :style="'width: calc(100% - ' + baTable.form.labelWidth! / 1.8 + 'px)'">
                <el-button @click="baTable.toggleForm('')">{{ t('Cancel') }}</el-button>
                <el-button v-blur :loading="baTable.form.submitLoading" @click="baTable.onSubmit(formRef)" type="primary">
                    {{ baTable.form.operateIds && baTable.form.operateIds.length > 1 ? t('Save and edit next item') : t('Save') }}
                </el-button>
            </div>
        </template>
    </el-dialog>
</template>

<script setup lang="ts">
import { inject, reactive, useTemplateRef } from 'vue'
import { useI18n } from 'vue-i18n'
import type baTableClass from '/@/utils/baTable'
import type { FormItemRule } from 'element-plus'
import { buildValidatorData } from '/@/utils/validate'
import { useConfig } from '/@/stores/config'

const config = useConfig()
const formRef = useTemplateRef('formRef')
const baTable = inject('baTable') as baTableClass

const { t } = useI18n()

const rules: Partial<Record<string, FormItemRule[]>> = reactive({
    applicant_type: [buildValidatorData({ name: 'required', title: '申请方类型' })],
    applicant_name: [buildValidatorData({ name: 'required', title: '申请方名称' })],
    amount: [buildValidatorData({ name: 'required', title: '提现金额' })],
    status: [buildValidatorData({ name: 'required', title: '当前状态' })],
})
</script>

<style scoped lang="scss"></style>


