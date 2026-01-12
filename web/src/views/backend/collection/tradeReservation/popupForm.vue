<template>
    <el-dialog
        class="ba-operate-dialog"
        :close-on-click-modal="false"
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
            >
                <el-form
                    ref="formRef"
                    :model="baTable.form.items"
                    label-position="right"
                    label-width="120px"
                    v-if="!baTable.form.loading"
                >
                    <el-form-item label="用户ID">
                        <el-input v-model="baTable.form.items!.user_id" disabled />
                    </el-form-item>
                    <el-form-item label="冻结金额">
                        <el-input v-model="baTable.form.items!.freeze_amount" disabled />
                    </el-form-item>
                    <el-form-item label="消耗算力">
                        <el-input v-model="baTable.form.items!.power_used" disabled />
                    </el-form-item>
                     <el-form-item label="状态" prop="status">
                        <el-radio-group v-model="baTable.form.items!.status">
                            <el-radio :label="0">待处理</el-radio>
                            <el-radio :label="1">已中签</el-radio>
                            <el-radio :label="2">未中签</el-radio>
                            <el-radio :label="3">已取消</el-radio>
                        </el-radio-group>
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

const baTable = inject<any>('baTable')
const { t } = useI18n()
const formRef = ref()
</script>

<style scoped lang="scss"></style>
