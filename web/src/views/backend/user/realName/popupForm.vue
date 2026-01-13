<template>
    <!-- 对话框表单 -->
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
                    <!-- 实名认证信息表单字段 -->
                    <el-form-item prop="user_id" label="用户ID">
                        <el-input
                            v-model="baTable.form.items!.user_id"
                            type="number"
                            placeholder="请输入用户ID"
                        ></el-input>
                    </el-form-item>
                    <el-form-item prop="real_name" label="真实姓名">
                        <el-input
                            v-model="baTable.form.items!.real_name"
                            type="string"
                            placeholder="请输入真实姓名"
                        ></el-input>
                    </el-form-item>
                    <el-form-item prop="id_card" label="身份证号">
                        <el-input
                            v-model="baTable.form.items!.id_card"
                            type="string"
                            placeholder="请输入身份证号"
                        ></el-input>
                    </el-form-item>
                    <el-form-item prop="id_card_front" label="身份证正面">
                        <FormItem type="image" v-model="baTable.form.items!.id_card_front" />
                    </el-form-item>
                    <el-form-item prop="id_card_back" label="身份证反面">
                        <FormItem type="image" v-model="baTable.form.items!.id_card_back" />
                    </el-form-item>
                    <el-form-item prop="real_name_status" label="实名状态">
                        <el-select v-model="baTable.form.items!.real_name_status" placeholder="请选择实名状态">
                            <el-option label="待审核" value="1" />
                            <el-option label="已通过" value="2" />
                            <el-option label="已拒绝" value="3" />
                        </el-select>
                    </el-form-item>
                    <el-form-item prop="audit_remark" label="审核备注">
                        <el-input
                            v-model="baTable.form.items!.audit_remark"
                            type="textarea"
                            :rows="4"
                            placeholder="请输入审核备注"
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
import { reactive, inject, useTemplateRef } from 'vue'
import { useI18n } from 'vue-i18n'
import type baTableClass from '/@/utils/baTable'
import type { FormItemRule } from 'element-plus'
import FormItem from '/@/components/formItem/index.vue'
import { buildValidatorData } from '/@/utils/validate'
import { useConfig } from '/@/stores/config'

const config = useConfig()
const formRef = useTemplateRef('formRef')
const baTable = inject('baTable') as baTableClass

const { t } = useI18n()

const rules: Partial<Record<string, FormItemRule[]>> = reactive({
    user_id: [buildValidatorData({ name: 'required', title: '用户ID' })],
    real_name: [buildValidatorData({ name: 'required', title: '真实姓名' })],
    id_card: [buildValidatorData({ name: 'required', title: '身份证号' })],
})
</script>

<style scoped lang="scss"></style>

