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
                {{ baTable.form.operate === 'Add' ? '添加' : '编辑' }}
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
                    <el-form-item prop="code" label="邀请码">
                        <el-input
                            v-model="baTable.form.items!.code"
                            type="text"
                            maxlength="20"
                            placeholder="请输入邀请码"
                            @input="handleCodeInput"
                        ></el-input>
                        <div class="form-tip">仅允许字母和数字</div>
                    </el-form-item>
                    <el-form-item prop="status" label="状态">
                        <el-radio-group v-model="baTable.form.items!.status">
                            <el-radio :label="1" border>启用</el-radio>
                            <el-radio :label="0" border>禁用</el-radio>
                        </el-radio-group>
                        <div class="form-tip">禁用状态的邀请码将无法注册</div>
                    </el-form-item>
                    <el-form-item prop="remark" label="备注">
                        <el-input
                            v-model="baTable.form.items!.remark"
                            type="textarea"
                            :rows="3"
                            placeholder="请输入备注信息（可选）"
                            maxlength="255"
                            show-word-limit
                        ></el-input>
                    </el-form-item>
                </el-form>
            </div>
        </el-scrollbar>
        <template #footer>
            <div :style="'width: calc(100% - ' + baTable.form.labelWidth! / 1.8 + 'px)'">
                <el-button @click="baTable.toggleForm('')">取消</el-button>
                <el-button v-blur :loading="baTable.form.submitLoading" @click="baTable.onSubmit(formRef)" type="primary">
                    {{ baTable.form.operateIds && baTable.form.operateIds.length > 1 ? '保存并编辑下一条' : '保存' }}
                </el-button>
            </div>
        </template>
    </el-dialog>
</template>

<script setup lang="ts">
import { reactive, inject, useTemplateRef } from 'vue'
import type baTableClass from '/@/utils/baTable'
import type { FormItemRule } from 'element-plus'
import { buildValidatorData } from '/@/utils/validate'
import { useConfig } from '/@/stores/config'

const config = useConfig()
const formRef = useTemplateRef('formRef')
const baTable = inject('baTable') as baTableClass

const rules: Partial<Record<string, FormItemRule[]>> = reactive({
    code: [
        buildValidatorData({ name: 'required', title: '邀请码' }),
        {
            validator: (rule: any, val: string, callback: Function) => {
                if (!val) {
                    return callback(new Error('请输入邀请码'))
                }
                if (!/^[a-zA-Z0-9]+$/.test(val)) {
                    return callback(new Error('邀请码格式不正确，仅允许字母和数字'))
                }
                return callback()
            },
            trigger: 'blur',
        },
    ],
    status: [buildValidatorData({ name: 'required', title: '状态' })],
})

const handleCodeInput = (value: string) => {
    // 只保留字母和数字
    const val = value.replace(/[^a-zA-Z0-9]/g, '')
    if (baTable.form.items) {
        baTable.form.items.code = val
    }
}
</script>

<style scoped lang="scss">
.form-tip {
    font-size: 12px;
    color: var(--el-text-color-secondary);
    margin-top: 4px;
}
</style>
