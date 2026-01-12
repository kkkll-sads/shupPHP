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
                    :model="baTable.form.items"
                    :label-position="config?.layout?.shrink ? 'top' : 'right'"
                    :label-width="(baTable.form.labelWidth || 160) + 'px'"
                    :rules="rules"
                    v-if="!baTable.form.loading"
                >
                    <el-form-item prop="title" :label="t('content.contentHotVideo.Title')">
                        <el-input
                            v-model="baTable.form.items!.title"
                            type="string"
                            :placeholder="t('Please input field', { field: t('content.contentHotVideo.Title') })"
                        ></el-input>
                    </el-form-item>

                    <el-form-item prop="summary" :label="t('content.contentHotVideo.Summary')">
                        <el-input
                            v-model="baTable.form.items!.summary"
                            type="textarea"
                            :rows="3"
                            :placeholder="t('Please input field', { field: t('content.contentHotVideo.Summary') })"
                        ></el-input>
                    </el-form-item>

                    <FormItem
                        :label="t('content.contentHotVideo.Video Url')"
                        type="file"
                        v-model="baTable.form.items!.video_url"
                        prop="video_url"
                        :input-attr="{ returnFullUrl: true, accept: 'video/*', limit: 1 }"
                    />

                    <FormItem
                        :label="t('content.contentHotVideo.Cover Image')"
                        type="image"
                        v-model="baTable.form.items!.cover_image"
                        prop="cover_image"
                        :input-attr="{ returnFullUrl: true, limit: 1 }"
                    />

                    <FormItem
                        :label="t('content.contentHotVideo.Status')"
                        v-model="baTable.form.items!.status"
                        type="radio"
                        prop="status"
                        :input-attr="{
                            border: true,
                            content: { '1': t('Enable'), '0': t('Disable') },
                        }"
                    />

                    <FormItem
                        :label="t('content.contentHotVideo.Publish Time')"
                        v-model="baTable.form.items!.publish_time"
                        type="datetime"
                        prop="publish_time"
                        :input-attr="{ 'value-format': 'YYYY-MM-DD HH:mm:ss', clearable: true }"
                    />

                    <el-form-item prop="sort" :label="t('content.contentHotVideo.Sort')">
                        <el-input-number
                            v-model="baTable.form.items!.sort"
                            :min="0"
                            :max="999"
                            controls-position="right"
                            :placeholder="t('Please input field', { field: t('content.contentHotVideo.Sort') })"
                        />
                    </el-form-item>

                    <el-form-item prop="view_count" :label="t('content.contentHotVideo.View Count')">
                        <el-input-number
                            v-model="baTable.form.items!.view_count"
                            :min="0"
                            controls-position="right"
                            :placeholder="t('Please input field', { field: t('content.contentHotVideo.View Count') })"
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
import { useI18n } from 'vue-i18n'
import FormItem from '/@/components/formItem/index.vue'
import { useConfig } from '/@/stores/config'

const baTable = inject<any>('baTable')
const { t } = useI18n()
const config = useConfig()
const formRef = ref()

const rules = {
    title: [{ required: true, message: t('content.contentHotVideo.Title is required'), trigger: 'blur' }],
    status: [{ required: true, message: t('content.contentHotVideo.Status value is incorrect'), trigger: 'change' }],
    publish_time: [{ type: 'date' as const, message: t('Please select field', { field: t('content.contentHotVideo.Publish Time') }), trigger: 'change' }],
}
</script>

<style scoped lang="scss"></style>

