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
                {{ baTable.form.operate === 'Add' ? '新增问题分类' : baTable.form.operate === 'Edit' ? '编辑问题分类' : '' }}
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
                    <el-form-item prop="name" label="分类名称">
                        <el-input
                            v-model="baTable.form.items!.name"
                            type="string"
                            placeholder="请输入分类名称"
                        ></el-input>
                    </el-form-item>

                    <el-form-item prop="code" label="分类编码">
                        <el-input
                            v-model="baTable.form.items!.code"
                            type="string"
                            placeholder="请输入分类编码，如 account / trade / asset / other"
                        ></el-input>
                    </el-form-item>

                    <el-form-item prop="sort" label="排序">
                        <el-input-number
                            v-model="baTable.form.items!.sort"
                            :min="0"
                            :max="999"
                            controls-position="right"
                            placeholder="请输入排序值（越大越靠前）"
                        />
                    </el-form-item>

                    <FormItem
                        label="状态"
                        v-model="baTable.form.items!.status"
                        type="radio"
                        prop="status"
                        :input-attr="{
                            border: true,
                            content: { 1: '启用', 0: '禁用' },
                        }"
                    />
                </el-form>
            </div>
        </el-scrollbar>
        <template #footer>
            <div class="dialog-footer">
                <el-button @click="baTable.toggleForm">取消</el-button>
                <el-button type="primary" @click="baTable.onSubmit(formRef)">确定</el-button>
            </div>
        </template>
    </el-dialog>
</template>

<script setup lang="ts">
import { inject, ref } from 'vue'
import FormItem from '/@/components/formItem/index.vue'
import { useConfig } from '/@/stores/config'

const baTable = inject<any>('baTable')
const config = useConfig()
const formRef = ref()

const rules = {
    name: [{ required: true, message: '请填写分类名称', trigger: 'blur' }],
    code: [{ required: true, message: '请填写分类编码', trigger: 'blur' }],
}
</script>

<style scoped lang="scss"></style>


