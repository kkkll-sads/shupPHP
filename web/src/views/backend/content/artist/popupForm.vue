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
                {{ baTable.form.operate ? (baTable.form.operate === 'Add' ? '添加艺术家' : '编辑艺术家') : '' }}
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
                    <el-form-item prop="name" label="姓名">
                        <el-input
                            v-model="baTable.form.items!.name"
                            type="string"
                            placeholder="请输入艺术家姓名"
                        />
                    </el-form-item>

                    <FormItem
                        label="头像"
                        type="image"
                        v-model="baTable.form.items!.image"
                        prop="image"
                        :placeholder="'请上传头像图片'"
                    />

                    <el-form-item prop="title" label="头衔">
                        <el-input
                            v-model="baTable.form.items!.title"
                            type="string"
                            placeholder="例如：中国美术家协会会员"
                        />
                    </el-form-item>

                    <el-form-item prop="bio" label="简介">
                        <el-input
                            v-model="baTable.form.items!.bio"
                            type="textarea"
                            :rows="4"
                            placeholder="请输入艺术家简介"
                        />
                    </el-form-item>

                    <FormItem
                        label="状态"
                        v-model="baTable.form.items!.status"
                        type="radio"
                        prop="status"
                        :input-attr="{
                            border: true,
                            content: { '1': '启用', '0': '禁用' },
                        }"
                    />

                    <el-form-item prop="sort" label="排序">
                        <el-input-number
                            v-model="baTable.form.items!.sort"
                            :min="0"
                            :max="999"
                            controls-position="right"
                            placeholder="排序值，越大越靠前"
                        />
                    </el-form-item>
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
    name: [{ required: true, message: '请输入艺术家姓名', trigger: 'blur' }],
}
</script>

<style scoped lang="scss"></style>
