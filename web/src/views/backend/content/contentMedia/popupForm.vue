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
                    @keyup.enter="baTable.onSubmit(formRef)"
                    :model="baTable.form.items"
                    :label-position="config?.layout?.shrink ? 'top' : 'right'"
                    :label-width="(baTable.form.labelWidth || 160) + 'px'"
                    :rules="rules"
                    v-if="!baTable.form.loading"
                >
                    <FormItem
                        :label="t('content.contentMedia.Category')"
                        v-model="baTable.form.items!.category"
                        type="radio"
                        prop="category"
                        :input-attr="{ border: true }"
                        :data="{
                            content: {
                                promo_video: t('content.contentMedia.Category promo_video'),
                                resource: t('content.contentMedia.Category resource'),
                                hot_video: t('content.contentMedia.Category hot_video'),
                            },
                        }"
                    />

                    <el-form-item prop="title" :label="t('content.contentMedia.Title')">
                        <el-input
                            v-model="baTable.form.items!.title"
                            type="string"
                            :placeholder="t('Please input field', { field: t('content.contentMedia.Title') })"
                        ></el-input>
                    </el-form-item>

                    <el-form-item prop="description" :label="t('content.contentMedia.Description')">
                        <el-input
                            v-model="baTable.form.items!.description"
                            type="textarea"
                            :rows="3"
                            :placeholder="t('Please input field', { field: t('content.contentMedia.Description') })"
                        ></el-input>
                    </el-form-item>

                    <FormItem
                        :label="t('content.contentMedia.Cover Image')"
                        type="image"
                        v-model="baTable.form.items!.cover_image"
                        prop="cover_image"
                        :input-attr="{ returnFullUrl: true, limit: 1 }"
                    />

                    <FormItem
                        :label="t('content.contentMedia.Media Type')"
                        v-model="baTable.form.items!.media_type"
                        type="select"
                        prop="media_type"
                        :data="{
                            content: {
                                image: t('content.contentMedia.Media Type image'),
                                video: t('content.contentMedia.Media Type video'),
                                document: t('content.contentMedia.Media Type document'),
                                other: t('content.contentMedia.Media Type other'),
                            },
                        }"
                        :input-attr="{ clearable: false }"
                    />

                    <FormItem
                        :label="t('content.contentMedia.Media Url')"
                        type="file"
                        v-model="baTable.form.items!.media_url"
                        prop="media_url"
                        :input-attr="mediaUploadAttr"
                    />

                    <FormItem
                        :label="t('content.contentMedia.Status')"
                        v-model="baTable.form.items!.status"
                        type="radio"
                        prop="status"
                        :input-attr="{
                            border: true,
                            content: { '1': t('Enable'), '0': t('Disable') },
                        }"
                    />

                    <el-form-item prop="sort" :label="t('content.contentMedia.Sort')">
                        <el-input-number
                            v-model="baTable.form.items!.sort"
                            :min="0"
                            :max="999"
                            controls-position="right"
                            :placeholder="t('Please input field', { field: t('content.contentMedia.Sort') })"
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
import { computed, inject, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import FormItem from '/@/components/formItem/index.vue'
import { useConfig } from '/@/stores/config'

const baTable = inject<any>('baTable')
const { t } = useI18n()
const config = useConfig()
const formRef = ref()

const mediaUploadAttr = computed(() => {
    const type = baTable.form.items?.media_type || 'image'
    let accept = '*/*'
    if (type === 'image') {
        accept = 'image/*'
    } else if (type === 'video') {
        accept = 'video/*'
    } else if (type === 'document') {
        accept = '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt'
    }
    return {
        accept,
        limit: 1,
        returnFullUrl: true,
        dataType: 'string',
        hideSelectFile: false,
    }
})

watch(
    () => baTable.form.items?.category,
    (val) => {
        if (!val || !baTable.form.items) {
            return
        }
        if (['promo_video', 'hot_video'].includes(val) && baTable.form.items.media_type !== 'video') {
            baTable.form.items.media_type = 'video'
        }
    }
)

const rules = {
    category: [{ required: true, message: t('content.contentMedia.Category is invalid'), trigger: 'change' }],
    title: [
        { required: true, message: t('Please input field', { field: t('content.contentMedia.Title') }), trigger: 'blur' },
    ],
    media_type: [{ required: true, message: t('content.contentMedia.Media type is invalid'), trigger: 'change' }],
    media_url: [{ required: true, message: t('content.contentMedia.Media resource is required'), trigger: 'change' }],
    status: [{ required: true, message: t('content.contentMedia.Status value is incorrect'), trigger: 'change' }],
}
</script>

<style scoped lang="scss"></style>


