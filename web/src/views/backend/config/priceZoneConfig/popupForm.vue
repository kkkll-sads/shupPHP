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
                    <el-form-item prop="name" label="分区名称">
                        <el-input
                            v-model="baTable.form.items!.name"
                            type="string"
                            placeholder="请输入分区名称（如：1K区、2K区、3K区、4K区）"
                        ></el-input>
                    </el-form-item>

                    <el-form-item prop="min_price" label="最低价格">
                        <el-input-number
                            v-model="baTable.form.items!.min_price"
                            :min="0"
                            :precision="2"
                            :step="100"
                            controls-position="right"
                            placeholder="请输入最低价格"
                            style="width: 100%"
                        />
                        <span style="margin-left: 10px; color: #999;">单位：元</span>
                    </el-form-item>

                    <el-form-item prop="max_price" label="最高价格">
                        <el-input-number
                            v-model="baTable.form.items!.max_price"
                            :min="0"
                            :precision="2"
                            :step="100"
                            controls-position="right"
                            placeholder="请输入最高价格（留空表示无上限）"
                            style="width: 100%"
                        />
                        <span style="margin-left: 10px; color: #999;">单位：元，留空表示无上限</span>
                    </el-form-item>

                    <FormItem
                        label="状态"
                        v-model="baTable.form.items!.status"
                        type="radio"
                        prop="status"
                        :input-attr="{
                            border: true,
                            content: { '1': t('Enable'), '0': t('Disable') },
                        }"
                    />

                    <el-form-item prop="sort" label="排序">
                        <el-input-number
                            v-model="baTable.form.items!.sort"
                            :min="0"
                            :max="9999"
                            controls-position="right"
                            placeholder="请输入排序值"
                        />
                        <span style="margin-left: 10px; color: #999;">数值越大越靠前</span>
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
import { useConfig } from '/@/stores/config'
import FormItem from '/@/components/formItem/index.vue'

const baTable = inject<any>('baTable')
const { t } = useI18n()
const config = useConfig()
const formRef = ref()

const rules = {
    name: [{ required: true, message: '分区名称不能为空', trigger: 'blur' }],
    min_price: [{ required: true, message: '最低价格不能为空', trigger: 'blur' }],
    status: [{ required: true, message: '状态不能为空', trigger: 'change' }],
}
</script>

<style scoped lang="scss"></style>

