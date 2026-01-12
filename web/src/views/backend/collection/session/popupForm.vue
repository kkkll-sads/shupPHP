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
                <div class="pool-preview" v-if="poolPreview">
                    <div class="pool-preview__head" :class="poolPreview.themeClass">
                        <div class="pool-preview__title">
                            <span class="pool-preview__code">{{ poolPreview.code }}</span>
                            <span class="pool-preview__name">{{ poolPreview.name }}</span>
                        </div>
                        <div class="pool-preview__sub">{{ poolPreview.subName }}</div>
                    </div>
                    <div class="pool-preview__body" :style="{ backgroundColor: poolPreview.dataBg }">
                        <div class="pool-preview__metric">
                            <span class="label">目标年化</span>
                            <span class="value">{{ poolPreview.roi }}</span>
                        </div>
                        <div class="pool-preview__metric">
                            <span class="label">额度</span>
                            <span class="value">{{ poolPreview.quota }}</span>
                        </div>
                        <div class="pool-preview__metric">
                            <span class="label">时间区间</span>
                            <span class="value">
                                {{ baTable.form.items?.start_time || '--' }} - {{ baTable.form.items?.end_time || '--' }}
                            </span>
                        </div>
                    </div>
                </div>

                <el-form
                    ref="formRef"
                    @keyup.enter="baTable.onSubmit(formRef)"
                    :model="baTable.form.items"
                    :label-position="config?.layout?.shrink ? 'top' : 'right'"
                    :label-width="(baTable.form.labelWidth || 160) + 'px'"
                    :rules="rules"
                    v-if="!baTable.form.loading"
                >
                    <el-form-item prop="title" label="专场标题">
                        <el-input
                            v-model="baTable.form.items!.title"
                            type="string"
                            placeholder="请输入专场标题"
                        ></el-input>
                    </el-form-item>

                    <FormItem
                        label="专场图片"
                        type="image"
                        v-model="baTable.form.items!.image"
                        prop="image"
                        :input-attr="{ returnFullUrl: true, limit: 1 }"
                    />

                    <el-form-item label="时间区间">
                        <el-select
                            v-model="selectedTimeConfigId"
                            placeholder="选择时间区间（选择后自动填充开始和结束时间）"
                            clearable
                            filterable
                            style="width: 100%"
                            @change="onTimeConfigChange"
                        >
                            <el-option
                                v-for="item in timeConfigOptions"
                                :key="item.id"
                                :label="item.label"
                                :value="item.id"
                            />
                        </el-select>
                        <span style="margin-left: 10px; color: #999;">或手动输入下方时间</span>
                    </el-form-item>

                    <el-form-item prop="start_time" label="开始时间">
                        <el-time-picker
                            v-model="baTable.form.items!.start_time"
                            format="HH:mm"
                            value-format="HH:mm"
                            placeholder="选择开始时间"
                            style="width: 100%"
                        />
                    </el-form-item>

                    <el-form-item prop="end_time" label="结束时间">
                        <el-time-picker
                            v-model="baTable.form.items!.end_time"
                            format="HH:mm"
                            value-format="HH:mm"
                            placeholder="选择结束时间"
                            style="width: 100%"
                        />
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

                    <el-form-item prop="code" label="资产池代码">
                        <el-input
                            v-model="baTable.form.items!.code"
                            type="string"
                            placeholder="请输入资产池代码，如：Pool-A"
                        />
                    </el-form-item>

                    <el-form-item prop="roi" label="年化收益率">
                        <el-input
                            v-model="baTable.form.items!.roi"
                            type="string"
                            placeholder="请输入年化收益率，如：+5.5%"
                        />
                        <span style="margin-left: 10px; color: #999;">格式示例：+5.5%</span>
                    </el-form-item>

                    <el-form-item prop="quota" label="额度">
                        <el-input
                            v-model="baTable.form.items!.quota"
                            type="string"
                            placeholder="请输入额度，如：100万"
                        />
                        <span style="margin-left: 10px; color: #999;">格式示例：100万、500万、不限</span>
                    </el-form-item>

                    <el-form-item prop="sub_name" label="副标题">
                        <el-input
                            v-model="baTable.form.items!.sub_name"
                            type="string"
                            placeholder="请输入副标题"
                        />
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
import { inject, ref, watch, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfig } from '/@/stores/config'
import FormItem from '/@/components/formItem/index.vue'
import { baTableApi } from '/@/api/common'

const baTable = inject<any>('baTable')
const { t } = useI18n()
const config = useConfig()
const formRef = ref()

interface TimeConfigOption {
    id: number
    label: string
    start_time: string
    end_time: string
}

const timeConfigOptions = ref<TimeConfigOption[]>([])
const selectedTimeConfigId = ref<number | undefined>(undefined)
const timeConfigApi = new baTableApi('/admin/TradingTimeConfig/')

// 从表单数据生成资产池配置
const getPoolConfig = (formData: any) => {
    const start = formData?.start_time || ''
    const end = formData?.end_time || ''
    const startHour = Number((start || '00:00').split(':')[0])
    const endHour = Number((end || '00:00').split(':')[0])

    let themeClass = 'pool-card--purple'
    let dataBg = '#FAF5FF'

    if (startHour >= 5 && endHour <= 12) {
        themeClass = 'pool-card--blue'
        dataBg = '#F0F7FF'
    } else if (startHour >= 12 && endHour <= 18) {
        themeClass = 'pool-card--orange'
        dataBg = '#FFF7F0'
    } else if (startHour >= 18 || endHour <= 5) {
        themeClass = 'pool-card--green'
        dataBg = '#F0FDF4'
    }

    return {
        code: formData?.code || 'D-Asset',
        name: formData?.title || '资产池',
        subName: formData?.sub_name || '资产池',
        roi: formData?.roi || '+3.0%',
        quota: formData?.quota || '不限',
        themeClass: themeClass,
        dataBg: dataBg,
    }
}

const poolPreview = computed(() => {
    return getPoolConfig(baTable.form.items)
})

// 加载时间区间配置列表
const loadTimeConfigs = async () => {
    try {
        const res = await timeConfigApi.index({
            select: 1,
            limit: 999,
            where: JSON.stringify([['status', '=', '1']]),
        })
        if (res.code === 1 && res.data?.list) {
            timeConfigOptions.value = res.data.list.map((item: any) => ({
                id: item.id,
                label: `${item.name} (${item.start_time} - ${item.end_time})`,
                start_time: item.start_time,
                end_time: item.end_time,
            }))
        }
    } catch (error) {
        console.error('加载时间区间配置失败:', error)
    }
}

// 时间区间选择变化时自动填充开始和结束时间
const onTimeConfigChange = (configId: number | undefined) => {
    if (configId) {
        const selectedConfig = timeConfigOptions.value.find((item) => item.id === configId)
        if (selectedConfig) {
            baTable.form.items!.start_time = selectedConfig.start_time
            baTable.form.items!.end_time = selectedConfig.end_time
        }
    }
}

// 监听表单数据变化，如果开始时间和结束时间匹配某个配置，则选中该配置
const syncTimeConfigSelection = () => {
    if (!baTable.form.items?.start_time || !baTable.form.items?.end_time) {
        selectedTimeConfigId.value = undefined
        return
    }

    const matchedConfig = timeConfigOptions.value.find(
        (item) => item.start_time === baTable.form.items!.start_time && item.end_time === baTable.form.items!.end_time
    )
    selectedTimeConfigId.value = matchedConfig ? matchedConfig.id : undefined
}

// 监听弹窗打开，加载时间区间配置
watch(
    () => baTable.form.operate,
    (newVal) => {
        if (['Add', 'Edit'].includes(newVal)) {
            loadTimeConfigs()
            // 延迟一下，确保表单数据已加载
            setTimeout(() => {
                syncTimeConfigSelection()
            }, 100)
        }
    },
    { immediate: true }
)

// 监听开始时间和结束时间变化
watch(
    () => [baTable.form.items?.start_time, baTable.form.items?.end_time],
    () => {
        syncTimeConfigSelection()
    },
    { deep: true }
)

onMounted(() => {
    loadTimeConfigs()
})

const rules = {
    title: [{ required: true, message: '专场标题不能为空', trigger: 'blur' }],
    code: [{ required: true, message: '资产池代码不能为空', trigger: 'blur' }],
    roi: [{ required: true, message: '年化收益率不能为空', trigger: 'blur' }],
    quota: [{ required: true, message: '额度不能为空', trigger: 'blur' }],
    sub_name: [{ required: true, message: '副标题不能为空', trigger: 'blur' }],
    start_time: [{ required: true, message: '开始时间不能为空', trigger: 'change' }],
    end_time: [{ required: true, message: '结束时间不能为空', trigger: 'change' }],
    status: [{ required: true, message: '状态不能为空', trigger: 'change' }],
}
</script>

<style scoped lang="scss">
.pool-preview {
    border-radius: 12px;
    overflow: hidden;
    margin-bottom: 14px;
    border: 1px solid #eef1f5;
}
.pool-preview__head {
    color: #fff;
    padding: 14px 16px;
}
.pool-preview__title {
    display: flex;
    gap: 10px;
    align-items: center;
    font-weight: 600;
    font-size: 16px;
}
.pool-preview__code {
    padding: 4px 8px;
    border-radius: 20px;
    background: rgba(255, 255, 255, 0.2);
    font-size: 12px;
}
.pool-preview__name {
    font-size: 15px;
}
.pool-preview__sub {
    margin-top: 6px;
    font-size: 13px;
    opacity: 0.9;
}
.pool-preview__body {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    padding: 12px 14px;
}
.pool-preview__metric {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 10px;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.04);
}
.pool-preview__metric .label {
    color: #6b7280;
    font-size: 12px;
}
.pool-preview__metric .value {
    color: #111827;
    font-weight: 600;
    font-size: 15px;
}
.pool-card--blue {
    background: linear-gradient(120deg, #2563eb, #06b6d4);
    box-shadow: 0 10px 20px rgba(37, 99, 235, 0.15);
}
.pool-card--orange {
    background: linear-gradient(120deg, #f97316, #ef4444);
    box-shadow: 0 10px 20px rgba(249, 115, 22, 0.15);
}
.pool-card--green {
    background: linear-gradient(120deg, #059669, #14b8a6);
    box-shadow: 0 10px 20px rgba(5, 150, 105, 0.15);
}
.pool-card--purple {
    background: linear-gradient(120deg, #7c3aed, #ec4899);
    box-shadow: 0 10px 20px rgba(124, 58, 237, 0.15);
}
</style>

