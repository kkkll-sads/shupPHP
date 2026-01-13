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
                {{ baTable.form.operate === 'Add' ? '新增问题' : baTable.form.operate === 'Edit' ? '编辑问题' : '' }}
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
                    <el-form-item prop="category_id" label="问题分类">
                        <el-select
                            v-model="baTable.form.items!.category_id"
                            placeholder="请选择问题分类"
                            style="width: 100%"
                        >
                            <el-option
                                v-for="item in categoryOptions"
                                :key="item.id"
                                :label="item.name + '（ID：' + item.id + '，编码：' + item.code + '）'"
                                :value="item.id"
                            />
                        </el-select>
                    </el-form-item>

                    <el-form-item prop="title" label="问题标题">
                        <el-input
                            v-model="baTable.form.items!.title"
                            type="string"
                            placeholder="请输入问题标题"
                        ></el-input>
                    </el-form-item>

                    <FormItem
                        label="问题内容"
                        type="editor"
                        v-model="baTable.form.items!.content"
                        prop="content"
                        :input-attr="{ height: '240px', fileForceLocal: false }"
                    />

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
import { baTableApi } from '/@/api/common'

const baTable = inject<any>('baTable')
const config = useConfig()
const formRef = ref()

interface HelpCategoryOption {
    id: number
    name: string
    code: string
}

const categoryOptions = ref<HelpCategoryOption[]>([])

// 专门用于获取问题分类的表格 API
const helpCategoryApi = new baTableApi('/admin/content.HelpCategory/')

const loadCategories = async () => {
    const res = await helpCategoryApi.index({
        select: 1,
        limit: 999,
    })
    if (res.code === 1 && res.data?.list) {
        categoryOptions.value = res.data.list as HelpCategoryOption[]
    }
}

// 打开弹窗时加载一次分类列表
loadCategories()

const rules = {
    category_id: [{ required: true, message: '请选择问题分类', trigger: 'change' }],
    title: [{ required: true, message: '请填写问题标题', trigger: 'blur' }],
    content: [{ required: true, message: '请填写问题内容', trigger: 'blur' }],
}
</script>

<style scoped lang="scss"></style>


