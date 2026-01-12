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
                    <el-form-item prop="name" label="商品名称">
                        <el-input
                            v-model="baTable.form.items!.name"
                            type="string"
                            placeholder="请输入商品名称"
                        ></el-input>
                    </el-form-item>

                    <FormItem
                        label="商品缩略图"
                        type="image"
                        v-model="baTable.form.items!.thumbnail"
                        prop="thumbnail"
                        :input-attr="{ returnFullUrl: true, limit: 1 }"
                    />

                    <FormItem
                        label="商品图片"
                        type="image"
                        v-model="baTable.form.items!.images"
                        prop="images"
                        :input-attr="{ returnFullUrl: true, limit: 9 }"
                    />

                    <el-form-item prop="description" label="商品描述">
                        <el-input
                            v-model="baTable.form.items!.description"
                            type="textarea"
                            :rows="4"
                            placeholder="请输入商品详细描述"
                        ></el-input>
                    </el-form-item>

                    <el-form-item prop="category" label="商品分类">
                        <el-input
                            v-model="baTable.form.items!.category"
                            type="string"
                            placeholder="请输入商品分类"
                        ></el-input>
                    </el-form-item>

                    <el-form-item prop="price" label="商品价格（余额）">
                        <el-input-number
                            v-model="baTable.form.items!.price"
                            :min="0"
                            :step="0.01"
                            :precision="2"
                            controls-position="right"
                            placeholder="请输入商品价格"
                        />
                        <span style="margin-left: 10px; color: #999;">元</span>
                    </el-form-item>

                    <el-form-item prop="score_price" label="积分价格">
                        <el-input-number
                            v-model="baTable.form.items!.score_price"
                            :min="0"
                            controls-position="right"
                            placeholder="请输入积分价格"
                        />
                        <span style="margin-left: 10px; color: #999;">积分</span>
                    </el-form-item>

                    <el-form-item prop="stock" label="库存数量">
                        <el-input-number
                            v-model="baTable.form.items!.stock"
                            :min="0"
                            controls-position="right"
                            placeholder="请输入库存数量"
                        />
                    </el-form-item>

                    <FormItem
                        label="购买方式"
                        v-model="baTable.form.items!.purchase_type"
                        type="radio"
                        prop="purchase_type"
                        :input-attr="{
                            border: true,
                            content: { 'money': '余额购买', 'score': '积分兑换', 'both': '两者都可' },
                        }"
                    />

                    <FormItem
                        label="商品类型"
                        v-model="baTable.form.items!.is_physical"
                        type="radio"
                        prop="is_physical"
                        :input-attr="{
                            border: true,
                            content: { '1': '实物商品', '0': '虚拟商品' },
                        }"
                    />

                    <FormItem
                        v-if="baTable.form.items!.is_physical === '0'"
                        label="是否卡密商品"
                        v-model="baTable.form.items!.is_card_product"
                        type="radio"
                        prop="is_card_product"
                        :input-attr="{
                            border: true,
                            content: { '0': '否', '1': '是' },
                        }"
                    >
                        <template #append>
                            <el-alert 
                                type="info" 
                                :closable="false"
                                style="margin-top: 5px;">
                                <template #title>
                                    <span style="font-size: 12px;">卡密商品：管理员填写备注后订单自动标记为已发货</span>
                                </template>
                            </el-alert>
                        </template>
                    </FormItem>

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
import { inject, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfig } from '/@/stores/config'
import FormItem from '/@/components/formItem/index.vue'

const baTable = inject<any>('baTable')
const { t } = useI18n()
const config = useConfig()
const formRef = ref()

// 监听商品类型变化，自动重置卡密商品选项
watch(() => baTable.form.items?.is_physical, (newVal) => {
    if (newVal === '1' && baTable.form.items) {
        // 如果改为实物商品，自动将卡密商品设为否
        baTable.form.items.is_card_product = '0'
    }
    if (newVal === '0' && baTable.form.items && !baTable.form.items.is_card_product) {
        // 如果是新建虚拟商品，默认设置为非卡密
        baTable.form.items.is_card_product = '0'
    }
})

const rules = {
    name: [{ required: true, message: '商品名称不能为空', trigger: 'blur' }],
    price: [{ required: true, message: '商品价格不能为空', trigger: 'change' }],
    score_price: [{ required: true, message: '积分价格不能为空', trigger: 'change' }],
    stock: [{ required: true, message: '库存数量不能为空', trigger: 'change' }],
    purchase_type: [{ required: true, message: '购买方式不能为空', trigger: 'change' }],
    is_physical: [{ required: true, message: '商品类型不能为空', trigger: 'change' }],
    status: [{ required: true, message: '状态不能为空', trigger: 'change' }],
}
</script>

<style scoped lang="scss"></style>

