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
                    <el-form-item prop="session_id" label="专场">
                        <el-select
                            v-model="baTable.form.items!.session_id"
                            placeholder="请选择专场"
                            style="width: 100%"
                        >
                            <el-option
                                v-for="item in sessionOptions"
                                :key="item.id"
                                :label="item.title + '（ID：' + item.id + '）'"
                                :value="item.id"
                            />
                        </el-select>
                    </el-form-item>

                    <el-form-item prop="package_id" label="资产包">
                        <el-select
                            v-model="baTable.form.items!.package_id"
                            placeholder="请选择资产包（撮合时按此归类）"
                            filterable
                            style="width: 100%"
                            @change="onPackageChange"
                        >
                            <el-option
                                v-for="pkg in filteredPackageOptions"
                                :key="pkg.id"
                                :label="pkg.name + '（ID：' + pkg.id + '）'"
                                :value="pkg.id"
                            />
                        </el-select>
                        <span style="margin-left: 10px; color: #999;">同资产包的藏品在同一撮合池</span>
                    </el-form-item>

                    <el-form-item prop="package_name" label="资产包名称" v-if="false">
                        <el-input v-model="baTable.form.items!.package_name" disabled />
                    </el-form-item>

                    <el-form-item prop="zone_id" label="价格分区">
                        <el-select
                            v-model="baTable.form.items!.zone_id"
                            placeholder="请选择价格分区"
                            filterable
                            style="width: 100%"
                        >
                            <el-option
                                v-for="zone in zoneOptions"
                                :key="zone.id"
                                :label="zone.name + '（ID：' + zone.id + '）'"
                                :value="zone.id"
                            />
                        </el-select>
                    </el-form-item>

                    <el-form-item prop="title" label="藏品标题">
                        <el-input
                            v-model="baTable.form.items!.title"
                            type="string"
                            placeholder="请输入藏品标题"
                        ></el-input>
                    </el-form-item>

                    <FormItem
                        label="藏品图片"
                        type="image"
                        v-model="baTable.form.items!.image"
                        prop="image"
                        :input-attr="{ returnFullUrl: true, limit: 1 }"
                    />

                    <FormItem
                        label="藏品详情图片"
                        type="image"
                        v-model="baTable.form.items!.images"
                        prop="images"
                        :input-attr="{ returnFullUrl: true, limit: 9 }"
                    />

                    <el-form-item prop="price" label="价格">
                        <el-input-number
                            v-model="baTable.form.items!.price"
                            :min="0"
                            :step="0.01"
                            :precision="2"
                            controls-position="right"
                            placeholder="请输入价格"
                        />
                        <span style="margin-left: 10px; color: #999;">元</span>
                    </el-form-item>

                    <el-form-item prop="asset_anchor" label="资产锚定">
                        <el-input
                            v-model="baTable.form.items!.asset_anchor"
                            type="string"
                            placeholder="请输入资产锚定信息（如：古董、艺术品、茶等）"
                        ></el-input>
                    </el-form-item>

                    <el-form-item prop="fingerprint" label="存证指纹">
                        <div style="display: flex; gap: 8px; width: 100%; align-items: flex-start;">
                            <el-input
                                v-model="baTable.form.items!.fingerprint"
                                type="textarea"
                                :rows="2"
                                placeholder="请输入链上/存证指纹哈希（示例：0x****）"
                                show-word-limit
                                maxlength="255"
                            ></el-input>
                            <el-button type="primary" plain @click="handleGenerateFingerprint">生成</el-button>
                        </div>
                        <span style="margin-left: 10px; color: #999;">保存链上哈希或第三方存证编号，未填写自动生成</span>
                    </el-form-item>

                    <el-form-item prop="stock" label="库存数量">
                        <el-input-number
                            v-model="baTable.form.items!.stock"
                            :min="0"
                            :max="99999"
                            :step="1"
                            controls-position="right"
                            placeholder="请输入库存数量"
                        />
                        <span style="margin-left: 10px; color: #999;">批量添加时会自动设置为1</span>
                    </el-form-item>

                    <el-form-item prop="status" label="状态">
                        <el-radio-group v-model="baTable.form.items!.status">
                            <el-radio :label="1">{{ t('Enable') }}</el-radio>
                            <el-radio :label="0">{{ t('Disable') }}</el-radio>
                        </el-radio-group>
                    </el-form-item>

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

                    <el-form-item prop="quantity" label="添加数量" v-if="baTable.form.operate === 'Add'">
                        <el-input-number
                            v-model="baTable.form.items!.quantity"
                            :min="1"
                            :max="100"
                            :step="1"
                            controls-position="right"
                            placeholder="批量添加数量"
                        />
                        <span style="margin-left: 10px; color: #999;">每个藏品自动生成唯一编号和存证指纹</span>
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
import { inject, ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfig } from '/@/stores/config'
import FormItem from '/@/components/formItem/index.vue'
import { baTableApi } from '/@/api/common'

const baTable = inject<any>('baTable')
const { t } = useI18n()
const config = useConfig()
const formRef = ref()

interface CollectionSessionOption {
    id: number
    title: string
}

const sessionOptions = ref<CollectionSessionOption[]>([])
const packageNameOptions = ref<string[]>([])
const zoneOptions = ref<{ id: number; name: string; min_price?: number; max_price?: number }[]>([])
const packageOptions = ref<{ id: number; name: string; session_id: number }[]>([])

// 专门用于获取专场列表的表格 API
const sessionApi = new baTableApi('/admin/collection.Session/')
const assetPackageApi = new baTableApi('/admin/collection.AssetPackage/')
const zoneApi = new baTableApi('/admin/PriceZoneConfig/')

const selectZoneByPrice = (price: any) => {
    if (!zoneOptions.value.length || !baTable.form.items) return
    const p = Number(price)
    if (Number.isNaN(p)) return

    // 根据价格区间匹配；max_price<=0 视为无上限
    const matched = zoneOptions.value.find((z) => {
        const min = Number(z.min_price ?? 0)
        const max = Number(z.max_price ?? 0)
        if (max > 0) {
            return p >= min && p <= max
        }
        return p >= min
    })

    const target = matched ?? zoneOptions.value[0]
    baTable.form.items.zone_id = target?.id ?? 0
}

const generateFingerprint = () => {
    const array = new Uint8Array(16)
    if (window.crypto?.getRandomValues) {
        window.crypto.getRandomValues(array)
    } else {
        for (let i = 0; i < array.length; i++) {
            array[i] = Math.floor(Math.random() * 256)
        }
    }
    return '0x' + Array.from(array, (b) => b.toString(16).padStart(2, '0')).join('')
}

const handleGenerateFingerprint = () => {
    baTable.form.items!.fingerprint = generateFingerprint()
}

const loadSessions = async () => {
    const res = await sessionApi.index({
        select: 1,
        limit: 999,
    })
    if (res.code === 1 && res.data?.list) {
        sessionOptions.value = res.data.list as CollectionSessionOption[]
    }
}

const loadZones = async () => {
    const res = await zoneApi.index({
        select: 1,
        limit: 999,
    })
    if (res.code === 1 && res.data?.list) {
        zoneOptions.value = res.data.list as any[]
        // 如有价格则按价格自动匹配分区，否则默认第一个
        if (baTable.form.items) {
            if (baTable.form.items.price) {
                selectZoneByPrice(baTable.form.items.price)
            } else if (!baTable.form.items.zone_id && zoneOptions.value.length > 0) {
                baTable.form.items.zone_id = zoneOptions.value[0].id
            }
        }
    }
}

const loadPackageNames = async () => {
    const res = await assetPackageApi.index({
        select: 1,
        limit: 999,
    })
    if (res.code === 1 && res.data?.list) {
        // 保存完整的资产包列表
        packageOptions.value = (res.data.list as any[]).map((pkg) => ({
            id: pkg.id,
            name: pkg.name,
            session_id: pkg.session_id,
        }))
        
        // 去重获取所有资产包名称（兼容旧逻辑）
        const names = new Set<string>()
        for (const pkg of res.data.list as any[]) {
            if (pkg.name) {
                names.add(pkg.name)
            }
        }
        packageNameOptions.value = Array.from(names).sort()
    }
}

// 按当前选择的场次过滤资产包
const filteredPackageOptions = computed(() => {
    const sessionId = baTable.form.items?.session_id
    if (!sessionId) return packageOptions.value
    return packageOptions.value.filter((pkg) => pkg.session_id === sessionId)
})

// 资产包选择变化时，自动填充 package_name
const onPackageChange = (packageId: number) => {
    const pkg = packageOptions.value.find((p) => p.id === packageId)
    if (pkg && baTable.form.items) {
        baTable.form.items.package_name = pkg.name
    }
}

// 监听弹窗打开，加载专场列表和资产包名称
watch(
    () => baTable.form.operate,
    (newVal) => {
        if (['Add', 'Edit'].includes(newVal)) {
            loadSessions()
            loadZones()
            loadPackageNames()
            // 新建时若指纹为空先生成一个默认值
            if (newVal === 'Add' && !baTable.form.items?.fingerprint) {
                baTable.form.items!.fingerprint = generateFingerprint()
            }
            // 新建时设置默认添加数量为1，库存也默认为1
            if (newVal === 'Add') {
                baTable.form.items!.quantity = 1
                if (!baTable.form.items!.stock) {
                    baTable.form.items!.stock = 1
                }
            }
        }
    },
    { immediate: true }
)

// 价格变化时自动匹配分区
watch(
    () => baTable.form.items?.price,
    (price) => {
        selectZoneByPrice(price)
    }
)

// 场次变化时，清空资产包选择（因为资产包按场次过滤）
watch(
    () => baTable.form.items?.session_id,
    (newSessionId, oldSessionId) => {
        if (newSessionId !== oldSessionId && baTable.form.items) {
            // 检查当前选择的资产包是否属于新场次
            const currentPackageId = baTable.form.items.package_id
            if (currentPackageId) {
                const pkg = packageOptions.value.find((p) => p.id === currentPackageId)
                if (pkg && pkg.session_id !== newSessionId) {
                    // 资产包不属于新场次，清空选择
                    baTable.form.items.package_id = 0
                    baTable.form.items.package_name = ''
                }
            }
        }
    }
)

const rules = {
    session_id: [{ required: true, message: '请选择专场', trigger: 'change' }],
    package_id: [{ required: true, message: '请选择资产包', trigger: 'change' }],
    zone_id: [{ required: true, message: '请选择价格分区', trigger: 'change' }],
    title: [{ required: true, message: '藏品标题不能为空', trigger: 'blur' }],
    price: [{ required: true, message: '价格不能为空', trigger: 'change' }],
    stock: [{ required: true, message: '库存数量不能为空', trigger: 'change' }],
    status: [{ required: true, message: '状态不能为空', trigger: 'change' }],
}
</script>

<style scoped lang="scss"></style>

