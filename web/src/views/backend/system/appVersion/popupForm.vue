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
                {{ baTable.form.operate ? t(String(baTable.form.operate)) : '' }}
            </div>
        </template>
        <el-scrollbar v-loading="baTable.form.loading" class="ba-table-form-scrollbar">
            <div
                class="ba-operate-form"
                :class="'ba-' + baTable.form.operate + '-form'"
                :style="config?.layout?.shrink ? '' : 'width: calc(100% - ' + (baTable.form.labelWidth || 160) / 2 + 'px)'"
            >
                <el-form
                    ref="formRef"
                    @keyup.enter="baTable.onSubmit(formRef)"
                    :model="baTable.form.items"
                    :label-position="config?.layout?.shrink ? 'top' : 'right'"
                    :label-width="(baTable.form.labelWidth || 160) + 'px'"
                    :rules="rules"
                    v-if="!baTable.form.loading"
                >
                    <FormItem
                        :label="'平台'"
                        v-model="baTable.form.items!.platform"
                        type="radio"
                        prop="platform"
                        :input-attr="{
                            border: true,
                            content: { android: '安卓', ios: '苹果' },
                        }"
                    />

                    <el-form-item prop="app_name" :label="'软件名称'">
                        <el-input
                            v-model="baTable.form.items!.app_name"
                            type="string"
                            :placeholder="'请输入软件名称'"
                        ></el-input>
                    </el-form-item>

                    <el-form-item prop="version_code" :label="'版本号'">
                        <el-input
                            v-model="baTable.form.items!.version_code"
                            type="string"
                            :placeholder="'请输入版本号，如：1.0.0'"
                        ></el-input>
                    </el-form-item>

                    <el-form-item prop="download_url" :label="'下载链接'">
                        <el-input
                            v-model="baTable.form.items!.download_url"
                            type="string"
                            :placeholder="'请输入软件下载链接'"
                        ></el-input>
                    </el-form-item>
                </el-form>
            </div>
        </el-scrollbar>
        <template #footer>
            <div class="dialog-footer">
                <el-button @click="baTable.toggleForm">{{ t('Cancel') }}</el-button>
                <el-button type="primary" @click="baTable.onSubmit(formRef)">{{ t('Confirm') }}</el-button>
            </div>
        </template>
    </el-dialog>
</template>

<script setup lang="ts">
import { inject, ref } from 'vue'
import FormItem from '/@/components/formItem/index.vue'
import { useI18n } from 'vue-i18n'
import { useConfig } from '/@/stores/config'
import { buildValidatorData } from '/@/utils/validate'
import type { FormItemRule } from 'element-plus'

const baTable = inject<any>('baTable')
const { t } = useI18n()
const config = useConfig()
const formRef = ref()

const rules: Partial<Record<string, FormItemRule[]>> = {
    platform: [buildValidatorData({ name: 'required', title: '平台' })],
    app_name: [buildValidatorData({ name: 'required', title: '软件名称' })],
    version_code: [buildValidatorData({ name: 'required', title: '版本号' })],
    download_url: [
        buildValidatorData({ name: 'required', title: '下载链接' }),
        buildValidatorData({ name: 'url', title: '下载链接' }),
    ],
}
</script>

<style scoped lang="scss"></style>

