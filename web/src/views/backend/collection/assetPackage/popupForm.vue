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
                    <el-form-item prop="name" label="资产包名称">
                        <el-input
                            v-model="baTable.form.items!.name"
                            type="text"
                            placeholder="请输入资产包名称，如：12月27日1000元精选包"
                            maxlength="100"
                            show-word-limit
                        />
                    </el-form-item>

                    <el-form-item prop="session_id" label="关联场次">
                        <el-select
                            v-model="baTable.form.items!.session_id"
                            placeholder="请选择关联场次"
                            filterable
                            style="width: 100%"
                        >
                            <el-option
                                v-for="item in props.sessions"
                                :key="item.id"
                                :label="item.title"
                                :value="item.id"
                            />
                        </el-select>
                    </el-form-item>

                    <el-form-item prop="zone_id" label="关联分区（价格范围）">
                        <el-select
                            v-model="baTable.form.items!.zone_id"
                            placeholder="请选择价格分区"
                            filterable
                            style="width: 100%"
                            @change="onZoneChange"
                        >
                            <el-option
                                v-for="item in props.zones"
                                :key="item.id"
                                :label="`${item.name} (${item.min_price || 350}-${item.max_price}元)`"
                                :value="item.id"
                            />
                        </el-select>
                        <span v-if="baTable.form.items?.min_price" style="margin-left: 10px; color: #67c23a; font-size: 12px;">
                            价格范围：{{ baTable.form.items.min_price }} - {{ baTable.form.items.max_price }} 元
                        </span>
                    </el-form-item>

                    <el-form-item prop="description" label="资产包描述">
                        <el-input
                            v-model="baTable.form.items!.description"
                            type="textarea"
                            :rows="3"
                            placeholder="请输入资产包描述（可选）"
                        />
                    </el-form-item>

                    <el-form-item prop="asset_anchor" label="资产锚定">
                        <el-input
                            v-model="baTable.form.items!.asset_anchor"
                            type="text"
                            placeholder="请输入资产锚定信息（如：古董、艺术品等），将同步到藏品"
                        />
                    </el-form-item>

                    <el-form-item prop="cover_image" label="封面图片">
                        <BaUpload
                            v-model="baTable.form.items!.cover_image"
                            type="image"
                            :limit="1"
                        />
                    </el-form-item>

                    <el-form-item prop="item_count" label="藏品数量">
                        <el-input-number
                            v-model="baTable.form.items!.item_count"
                            :min="0"
                            :max="10000"
                            placeholder="自动生成的藏品数量"
                            style="width: 200px"
                        />
                        <span style="margin-left: 10px; color: #999; font-size: 12px;">
                            创建时自动生成藏品，价格在分区范围内随机（最低350元）
                        </span>
                    </el-form-item>

                    <el-form-item prop="is_default" label="设为默认">
                        <el-switch
                            v-model="baTable.form.items!.is_default"
                            :active-value="1"
                            :inactive-value="0"
                            active-text="是"
                            inactive-text="否"
                        />
                        <span style="margin-left: 10px; color: #999; font-size: 12px;">
                            默认包用于用户寄售时自动归类
                        </span>
                    </el-form-item>

                    <el-form-item prop="status" label="状态">
                        <el-radio-group v-model="baTable.form.items!.status">
                            <el-radio :value="1" border>启用</el-radio>
                            <el-radio :value="0" border>禁用</el-radio>
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
import { inject, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfig } from '/@/stores/config'
import BaUpload from '/@/components/baInput/components/baUpload.vue'

interface ZoneItem {
    id: number
    name: string
    min_price?: number
    max_price?: number
}

const props = defineProps<{
    sessions: { id: number; title: string }[]
    zones: ZoneItem[]
}>()

const baTable = inject<any>('baTable')
const { t } = useI18n()
const config = useConfig()
const formRef = ref()

// 当分区改变时自动填充价格范围
const onZoneChange = (zoneId: number) => {
    if (!baTable.form.items) return
    const zone = props.zones.find(z => z.id === zoneId)
    if (zone) {
        // 500元区最低价350
        const minPrice = zone.min_price && zone.min_price < 350 ? 350 : (zone.min_price || 350)
        baTable.form.items.min_price = minPrice
        baTable.form.items.max_price = zone.max_price || 500
    }
}

const rules = {
    name: [{ required: true, message: '资产包名称不能为空', trigger: 'blur' }],
    session_id: [{ required: true, message: '请选择关联场次', trigger: 'change' }],
    zone_id: [{ required: true, message: '请选择价格分区', trigger: 'change' }],
    status: [{ required: true, message: '状态不能为空', trigger: 'change' }],
}
</script>

<style scoped lang="scss">
</style>
