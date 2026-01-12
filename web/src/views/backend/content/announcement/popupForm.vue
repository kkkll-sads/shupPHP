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
                {{ baTable.form.operate === 'Add' ? '添加' : baTable.form.operate === 'Edit' ? '编辑' : '' }}
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
                    <el-form-item prop="title" label="标题">
                        <el-input
                            v-model="baTable.form.items!.title"
                            type="string"
                            placeholder="请输入标题"
                        ></el-input>
                    </el-form-item>

                    <el-form-item prop="content" label="内容">
                        <FormItem
                            type="editor"
                            v-model="baTable.form.items!.content"
                            :input-attr="{
                                height: '300px',
                                placeholder: '请输入内容'
                            }"
                        />
                    </el-form-item>

                    <FormItem
                        label="类型"
                        v-model="baTable.form.items!.type"
                        type="radio"
                        prop="type"
                        :input-attr="{
                            border: true,
                            content: {
                                'normal': '普通',
                                'important': '重要'
                            },
                        }"
                    />

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

                    <FormItem
                        label="是否弹窗"
                        v-model="baTable.form.items!.is_popup"
                        type="radio"
                        prop="is_popup"
                        :input-attr="{
                            border: true,
                            content: { '1': '是', '0': '否' },
                        }"
                    />

                    <el-form-item
                        v-if="baTable.form.items!.is_popup === '1' || baTable.form.items!.is_popup === 1"
                        prop="popup_delay"
                        label="弹窗延迟"
                    >
                        <el-input-number
                            v-model="baTable.form.items!.popup_delay"
                            :min="1000"
                            :max="10000"
                            :step="500"
                            controls-position="right"
                            placeholder="请输入弹窗延迟"
                        />
                        <div class="form-item-tip">弹窗延迟时间，单位：毫秒（1秒=1000毫秒）</div>
                    </el-form-item>

                    <el-form-item prop="sort" label="排序">
                        <el-input-number
                            v-model="baTable.form.items!.sort"
                            :min="0"
                            :max="999"
                            controls-position="right"
                            placeholder="请输入排序"
                        />
                    </el-form-item>

                    <el-form-item label="开始时间">
                        <el-date-picker
                            class="w100"
                            value-format="YYYY-MM-DD HH:mm:ss"
                            v-model="baTable.form.items!.start_time"
                            type="datetime"
                            placeholder="请选择开始时间"
                        />
                    </el-form-item>

                    <el-form-item label="结束时间">
                        <el-date-picker
                            class="w100"
                            value-format="YYYY-MM-DD HH:mm:ss"
                            v-model="baTable.form.items!.end_time"
                            type="datetime"
                            placeholder="请选择结束时间"
                        />
                    </el-form-item>
                </el-form>
            </div>
        </el-scrollbar>

        <template #footer>
            <div class="ba-operate-footer">
                <el-button @click="baTable.toggleForm">取消</el-button>
                <el-button
                    type="primary"
                    @click="baTable.onSubmit(formRef)"
                    :loading="baTable.form.loading"
                    :disabled="baTable.form.loading"
                >
                    确定
                </el-button>
            </div>
        </template>
    </el-dialog>
</template>

<script setup lang="ts">
import { inject, ref, reactive } from 'vue'
import FormItem from '/@/components/formItem/index.vue'
import { useConfig } from '/@/stores/config'

const config = useConfig()

const baTable = inject<any>('baTable')!

const formRef = ref()

const rules = reactive({
    title: [
        { required: true, message: '请输入标题', trigger: 'blur' },
        { max: 200, message: '标题最大长度为200个字符', trigger: 'blur' },
    ],
    content: [
        { required: true, message: '请输入内容', trigger: 'blur' },
    ],
    type: [
        { required: true, message: '请选择类型', trigger: 'change' },
    ],
    status: [
        { required: true, message: '请选择状态', trigger: 'change' },
    ],
    is_popup: [
        { required: true, message: '请选择是否弹窗', trigger: 'change' },
    ],
    popup_delay: [
        { type: 'number', min: 1000, max: 10000, message: '弹窗延迟范围为1000-10000毫秒', trigger: 'change' },
    ],
    sort: [
        { type: 'number', min: 0, max: 999, message: '排序范围为0-999', trigger: 'change' },
    ],
})
</script>

<style scoped lang="scss">
.form-item-tip {
    font-size: 12px;
    color: #909399;
    margin-top: 4px;
    line-height: 1.4;
}
</style>
