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
                    <el-form-item prop="title" :label="t('content.banner.Title')">
                        <el-input
                            v-model="baTable.form.items!.title"
                            type="string"
                            :placeholder="t('Please input field', { field: t('content.banner.Title') })"
                        ></el-input>
                    </el-form-item>

                    <FormItem
                        :label="t('content.banner.Image')"
                        type="image"
                        v-model="baTable.form.items!.image"
                        prop="image"
                        :placeholder="t('content.banner.Please upload image')"
                    />

                    <el-form-item prop="url" :label="t('content.banner.Url')">
                        <el-input
                            v-model="baTable.form.items!.url"
                            type="string"
                            :placeholder="t('Please input field', { field: t('content.banner.Url') })"
                        ></el-input>
                    </el-form-item>

                    <el-form-item prop="description" :label="t('content.banner.Description')">
                        <el-input
                            v-model="baTable.form.items!.description"
                            type="textarea"
                            :rows="3"
                            :placeholder="t('Please input field', { field: t('content.banner.Description') })"
                        ></el-input>
                    </el-form-item>

                    <el-form-item prop="sort" :label="t('content.banner.Sort')">
                        <el-input-number
                            v-model="baTable.form.items!.sort"
                            :min="0"
                            :max="999"
                            controls-position="right"
                            :placeholder="t('Please input field', { field: t('content.banner.Sort') })"
                        />
                    </el-form-item>


                    <FormItem
                        :label="t('content.banner.Status')"
                        v-model="baTable.form.items!.status"
                        type="radio"
                        prop="status"
                        :input-attr="{
                            border: true,
                            content: { '1': t('content.banner.Display'), '0': t('content.banner.Hide') },
                        }"
                    />

                    <el-form-item :label="t('content.banner.Start Time')">
                        <el-date-picker
                            class="w100"
                            value-format="YYYY-MM-DD HH:mm:ss"
                            v-model="baTable.form.items!.start_time"
                            type="datetime"
                            :placeholder="t('Please select field', { field: t('content.banner.Start Time') })"
                        />
                    </el-form-item>

                    <el-form-item :label="t('content.banner.End Time')">
                        <el-date-picker
                            class="w100"
                            value-format="YYYY-MM-DD HH:mm:ss"
                            v-model="baTable.form.items!.end_time"
                            type="datetime"
                            :placeholder="t('Please select field', { field: t('content.banner.End Time') })"
                        />
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

const baTable = inject<any>('baTable')
const { t } = useI18n()
const config = useConfig()
const formRef = ref()

const rules = {
    title: [{ required: true, message: t('content.banner.Title is required'), trigger: 'blur' }],
    image: [{ required: true, message: t('content.banner.Image is required'), trigger: 'change' }],
    url: [
        { type: 'url', message: t('content.banner.Url format is incorrect'), trigger: 'blur' },
    ],
    sort: [
        { type: 'number', message: t('content.banner.Sort must be a number'), trigger: 'blur' },
    ],
    status: [
        { required: true, message: t('content.banner.Status value is incorrect'), trigger: 'change' },
    ],
}
</script>

<style scoped lang="scss"></style>
