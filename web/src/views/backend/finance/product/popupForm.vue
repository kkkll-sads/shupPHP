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
                    <el-form-item prop="name" :label="t('finance.product.Name')">
                        <el-input
                            v-model="baTable.form.items!.name"
                            type="string"
                            :placeholder="t('Please input field', { field: t('finance.product.Name') })"
                        ></el-input>
                    </el-form-item>

                    <FormItem
                        :label="t('finance.product.Thumbnail')"
                        type="image"
                        v-model="baTable.form.items!.thumbnail"
                        prop="thumbnail"
                        :input-attr="{ returnFullUrl: true, limit: 1 }"
                    />

                    <el-form-item prop="summary" :label="t('finance.product.Summary')">
                        <el-input
                            v-model="baTable.form.items!.summary"
                            type="textarea"
                            :rows="3"
                            :placeholder="t('Please input field', { field: t('finance.product.Summary') })"
                        ></el-input>
                    </el-form-item>

                    <el-form-item prop="price" :label="t('finance.product.Price')">
                        <el-input-number
                            v-model="baTable.form.items!.price"
                            :min="0"
                            :step="0.01"
                            controls-position="right"
                            :placeholder="t('Please input field', { field: t('finance.product.Price') })"
                        />
                    </el-form-item>

                    <el-form-item label="收益周期" required>
                        <el-row :gutter="10">
                            <el-col :span="12">
                                <el-input-number
                                    v-model="baTable.form.items!.cycle_value"
                                    :min="1"
                                    controls-position="right"
                                    placeholder="请输入周期数值"
                                    style="width: 100%"
                                />
                            </el-col>
                            <el-col :span="12">
                                <el-select v-model="baTable.form.items!.cycle_type" placeholder="选择周期类型" style="width: 100%">
                                    <el-option label="天" value="day" />
                                    <el-option label="月" value="month" />
                                    <el-option label="年" value="year" />
                                </el-select>
                            </el-col>
                        </el-row>
                        <div style="color: #999; font-size: 12px; margin-top: 5px;">
                            自动计算天数：{{ calculatedDays }} 天
                        </div>
                    </el-form-item>

                    <el-form-item prop="yield_rate" :label="t('finance.product.Yield Rate')">
                        <el-input-number
                            v-model="baTable.form.items!.yield_rate"
                            :min="0"
                            :step="0.01"
                            :precision="2"
                            controls-position="right"
                            :placeholder="t('Please input field', { field: t('finance.product.Yield Rate') })"
                        />
                        <span style="margin-left: 10px; color: #999;">%（年化收益率，用于展示）</span>
                    </el-form-item>

                    <el-divider content-position="left">收益发放配置</el-divider>

                    <!-- 收益模式选择 -->
                    <el-form-item label="收益模式" required prop="income_mode">
                        <el-radio-group v-model="baTable.form.items!.income_mode">
                            <el-radio-button label="daily">每日返息</el-radio-button>
                            <el-radio-button label="period">周期返息</el-radio-button>
                            <el-radio-button label="stage">阶段返息</el-radio-button>
                        </el-radio-group>
                        <div style="color: #999; font-size: 12px; margin-top: 8px;">
                            <span v-if="baTable.form.items!.income_mode === 'daily'">每天自动发放收益</span>
                            <span v-if="baTable.form.items!.income_mode === 'period'">按固定周期发放收益</span>
                            <span v-if="baTable.form.items!.income_mode === 'stage'">按时间阶段发放不同收益</span>
                        </div>
                    </el-form-item>

                    <!-- 收益值类型 -->
                    <el-form-item label="收益值类型" required prop="income_value_type">
                        <el-radio-group v-model="baTable.form.items!.income_value_type">
                            <el-radio label="percent">百分比</el-radio>
                            <el-radio label="fixed">固定金额</el-radio>
                        </el-radio-group>
                        <span style="margin-left: 10px; color: #999;">
                            {{ baTable.form.items!.income_value_type === 'percent' ? '按投资金额百分比计算' : '固定金额' }}
                        </span>
                    </el-form-item>

                    <!-- 每日返息配置 -->
                    <template v-if="baTable.form.items!.income_mode === 'daily'">
                        <el-form-item label="每日收益" required prop="daily_income_value">
                            <el-input-number
                                v-model="baTable.form.items!.daily_income_value"
                                :min="0"
                                :step="0.01"
                                :precision="2"
                                controls-position="right"
                                placeholder="每日返息数值"
                                style="width: 200px"
                            />
                            <span style="margin-left: 10px;">
                                {{ baTable.form.items!.income_value_type === 'percent' ? '%' : '元' }}
                            </span>
                            <el-alert type="info" :closable="false" style="margin-top: 10px;">
                                <template #title>
                                    <span style="font-size: 12px;">
                                        示例：投资10000元，每日{{ baTable.form.items!.income_value_type === 'percent' ? '0.1%返10元' : '固定返息' }}
                                    </span>
                                </template>
                            </el-alert>
                        </el-form-item>
                    </template>

                    <!-- 周期返息配置 -->
                    <template v-if="baTable.form.items!.income_mode === 'period'">
                        <el-form-item label="返息周期" required prop="period_days">
                            <el-input-number
                                v-model="baTable.form.items!.period_days"
                                :min="1"
                                controls-position="right"
                                placeholder="返息周期天数"
                                style="width: 200px"
                            />
                            <span style="margin-left: 10px;">天</span>
                        </el-form-item>

                        <el-form-item label="周期收益" required prop="period_income_value">
                            <el-input-number
                                v-model="baTable.form.items!.period_income_value"
                                :min="0"
                                :step="0.01"
                                :precision="2"
                                controls-position="right"
                                placeholder="每周期返息数值"
                                style="width: 200px"
                            />
                            <span style="margin-left: 10px;">
                                {{ baTable.form.items!.income_value_type === 'percent' ? '%' : '元' }}
                            </span>
                            <el-alert type="info" :closable="false" style="margin-top: 10px;">
                                <template #title>
                                    <span style="font-size: 12px;">
                                        每{{ baTable.form.items!.period_days }}天发放一次收益，
                                        {{ baTable.form.items!.income_value_type === 'percent' ? '按投资金额百分比' : '固定金额' }}
                                    </span>
                                </template>
                            </el-alert>
                        </el-form-item>
                    </template>

                    <!-- 阶段返息配置 -->
                    <template v-if="baTable.form.items!.income_mode === 'stage'">
                        <el-form-item label="阶段收益配置" required>
                            <div style="width: 100%;">
                                <el-button @click="addStage" type="primary" size="small" icon="Plus" style="margin-bottom: 10px;">
                                    添加阶段
                                </el-button>
                                
                                <div v-for="(stage, index) in stageList" :key="index" 
                                     style="border: 1px solid #e4e7ed; border-radius: 4px; padding: 15px; margin-bottom: 10px; background: #f9fafc;">
                                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                        <span style="color: #606266; font-weight: 500;">第{{ index + 1 }}阶段</span>
                                        <el-input-number
                                            v-model="stage.start"
                                            :min="1"
                                            placeholder="开始天"
                                            size="small"
                                            style="width: 100px"
                                        />
                                        <span>至</span>
                                        <el-input-number
                                            v-model="stage.end"
                                            :min="stage.start || 1"
                                            placeholder="结束天"
                                            size="small"
                                            style="width: 100px"
                                        />
                                        <span>天，收益：</span>
                                        <el-input-number
                                            v-model="stage.value"
                                            :min="0"
                                            :step="0.01"
                                            :precision="2"
                                            placeholder="收益值"
                                            size="small"
                                            style="width: 120px"
                                        />
                                        <el-select v-model="stage.type" size="small" style="width: 100px">
                                            <el-option label="百分比" value="percent" />
                                            <el-option label="固定" value="fixed" />
                                        </el-select>
                                        <span style="margin-right: auto;">{{ stage.type === 'percent' ? '%' : '元' }}</span>
                                        <el-button @click="removeStage(index)" type="danger" size="small" icon="Delete">删除</el-button>
                                    </div>
                                    <el-input
                                        v-model="stage.description"
                                        placeholder="阶段说明（选填）"
                                        size="small"
                                        style="margin-top: 10px;"
                                    />
                                </div>

                                <el-alert v-if="stageList.length === 0" type="warning" :closable="false">
                                    <template #title>
                                        <span style="font-size: 12px;">请至少添加一个收益阶段</span>
                                    </template>
                                </el-alert>
                            </div>
                        </el-form-item>
                    </template>

                    <el-divider content-position="left">到期设置</el-divider>

                    <!-- 到期返本 -->
                    <el-form-item label="到期返本" prop="return_principal">
                        <el-switch
                            v-model="baTable.form.items!.return_principal"
                            :active-value="1"
                            :inactive-value="0"
                            active-text="是"
                            inactive-text="否"
                        />
                        <span style="margin-left: 10px; color: #999;">产品到期后是否返还本金</span>
                    </el-form-item>

                    <!-- 到期复利 -->
                    <el-form-item label="到期复利" prop="compound_interest">
                        <el-switch
                            v-model="baTable.form.items!.compound_interest"
                            :active-value="1"
                            :inactive-value="0"
                            active-text="是"
                            inactive-text="否"
                        />
                        <span style="margin-left: 10px; color: #999;">到期后本金+收益自动再投资</span>
                        <el-alert v-if="baTable.form.items!.compound_interest === 1" type="warning" :closable="false" style="margin-top: 10px;">
                            <template #title>
                                <span style="font-size: 12px;">开启复利后，到期时会自动创建新订单，金额=本金+累计收益</span>
                            </template>
                        </el-alert>
                    </el-form-item>

                    <el-divider content-position="left">赠送规则</el-divider>

                    <!-- 赠送规则 -->
                    <el-form-item label="启用赠送" prop="gift_enabled">
                        <el-switch
                            v-model="giftRuleEnabled"
                            active-text="启用"
                            inactive-text="关闭"
                        />
                    </el-form-item>

                    <template v-if="giftRuleEnabled">
                        <el-form-item label="赠送规则">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <span>买</span>
                                <el-input-number
                                    v-model="giftRule.buy"
                                    :min="1"
                                    controls-position="right"
                                    style="width: 100px"
                                />
                                <span>送</span>
                                <el-input-number
                                    v-model="giftRule.gift"
                                    :min="1"
                                    controls-position="right"
                                    style="width: 100px"
                                />
                            </div>
                            <el-alert type="success" :closable="false" style="margin-top: 10px;">
                                <template #title>
                                    <span style="font-size: 12px;">
                                        示例：买{{ giftRule.buy }}送{{ giftRule.gift }}，购买{{ giftRule.buy }}份将额外赠送{{ giftRule.gift }}份
                                    </span>
                                </template>
                            </el-alert>
                        </el-form-item>

                        <el-form-item label="赠送产品">
                            <el-input-number
                                v-model="giftRule.gift_product_id"
                                :min="0"
                                controls-position="right"
                                placeholder="产品ID"
                                style="width: 200px"
                            />
                            <span style="margin-left: 10px; color: #999;">0表示赠送当前产品</span>
                        </el-form-item>
                    </template>

                    <el-divider content-position="left">额度设置</el-divider>

                    <el-form-item prop="total_amount" :label="t('finance.product.Total Amount')">
                        <el-input-number
                            v-model="baTable.form.items!.total_amount"
                            :min="0"
                            controls-position="right"
                            :placeholder="t('Please input field', { field: t('finance.product.Total Amount') })"
                        />
                    </el-form-item>

                    <el-form-item prop="sold_amount" :label="t('finance.product.Sold Amount')">
                        <el-input-number
                            v-model="baTable.form.items!.sold_amount"
                            :min="0"
                            controls-position="right"
                            :placeholder="t('Please input field', { field: t('finance.product.Sold Amount') })"
                        />
                    </el-form-item>

                    <el-form-item prop="progress" label="项目进度">
                        <el-input-number
                            v-model="baTable.form.items!.progress"
                            :min="0"
                            :max="100"
                            :step="0.01"
                            :precision="2"
                            controls-position="right"
                            placeholder="手动设置项目进度"
                        />
                        <span style="margin-left: 10px; color: #999;">%（0-100）</span>
                        <el-alert 
                            type="info" 
                            :closable="false"
                            style="margin-top: 10px;">
                            <template #title>
                                <span style="font-size: 12px;">可手动设置项目进度百分比，不设置则自动根据销售额计算</span>
                            </template>
                        </el-alert>
                    </el-form-item>

                    <el-form-item prop="min_purchase" :label="t('finance.product.Min Purchase')">
                        <el-input-number
                            v-model="baTable.form.items!.min_purchase"
                            :min="1"
                            controls-position="right"
                            :placeholder="t('Please input field', { field: t('finance.product.Min Purchase') })"
                        />
                    </el-form-item>

                    <el-form-item prop="max_purchase" :label="t('finance.product.Max Purchase')">
                        <el-input-number
                            v-model="baTable.form.items!.max_purchase"
                            :min="0"
                            controls-position="right"
                            :placeholder="t('Please input field', { field: t('finance.product.Max Purchase') })"
                        />
                        <span style="margin-left: 10px; color: #999;">0表示不限</span>
                    </el-form-item>

                    <el-form-item prop="per_user_limit" label="每人限购份数">
                        <el-input-number
                            v-model="baTable.form.items!.per_user_limit"
                            :min="0"
                            controls-position="right"
                            placeholder="每人限购份数"
                        />
                        <span style="margin-left: 10px; color: #999;">0表示不限购</span>
                        <el-alert 
                            type="warning" 
                            :closable="false"
                            style="margin-top: 10px;">
                            <template #title>
                                <span style="font-size: 12px;">设置每个用户最多可购买的份数，0表示不限制</span>
                            </template>
                        </el-alert>
                    </el-form-item>

                    <FormItem
                        :label="t('finance.product.Status')"
                        v-model="baTable.form.items!.status"
                        type="radio"
                        prop="status"
                        :input-attr="{
                            border: true,
                            content: { '1': t('Enable'), '0': t('Disable') },
                        }"
                    />

                    <el-form-item prop="sort" :label="t('finance.product.Sort')">
                        <el-input-number
                            v-model="baTable.form.items!.sort"
                            :min="0"
                            :max="999"
                            controls-position="right"
                            :placeholder="t('Please input field', { field: t('finance.product.Sort') })"
                        />
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
import { inject, ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfig } from '/@/stores/config'
import FormItem from '/@/components/formItem/index.vue'

const baTable = inject<any>('baTable')
const { t } = useI18n()
const config = useConfig()
const formRef = ref()

// 阶段收益列表
const stageList = ref<any[]>([])

// 赠送规则
const giftRuleEnabled = ref(false)
const giftRule = ref({
    buy: 1,
    gift: 1,
    gift_product_id: 0
})

// 计算周期对应的天数
const calculatedDays = computed(() => {
    const value = baTable.form.items?.cycle_value || 0
    const type = baTable.form.items?.cycle_type || 'day'
    
    if (type === 'day') return value
    if (type === 'month') return value * 30
    if (type === 'year') return value * 365
    return 0
})

// 监听周期变化，自动更新 cycle_days
watch([() => baTable.form.items?.cycle_value, () => baTable.form.items?.cycle_type], () => {
    if (baTable.form.items) {
        baTable.form.items.cycle_days = calculatedDays.value
    }
}, { immediate: true })

// 监听表单数据变化，初始化阶段列表和赠送规则
watch(() => baTable.form.items, (newVal) => {
    if (newVal) {
        // 初始化阶段配置
        if (newVal.stage_income_config) {
            try {
                const config = typeof newVal.stage_income_config === 'string' 
                    ? JSON.parse(newVal.stage_income_config) 
                    : newVal.stage_income_config
                stageList.value = Array.isArray(config) ? config : []
            } catch (e) {
                stageList.value = []
            }
        } else {
            stageList.value = []
        }

        // 初始化赠送规则
        if (newVal.gift_rule) {
            try {
                const rule = typeof newVal.gift_rule === 'string' 
                    ? JSON.parse(newVal.gift_rule) 
                    : newVal.gift_rule
                if (rule && rule.enabled) {
                    giftRuleEnabled.value = true
                    giftRule.value = {
                        buy: rule.buy || 1,
                        gift: rule.gift || 1,
                        gift_product_id: rule.gift_product_id || 0
                    }
                }
            } catch (e) {
                giftRuleEnabled.value = false
            }
        }

        // 初始化默认值
        if (!newVal.income_mode) newVal.income_mode = 'period'
        if (!newVal.income_value_type) newVal.income_value_type = 'percent'
        if (newVal.return_principal === undefined) newVal.return_principal = 1
        if (newVal.compound_interest === undefined) newVal.compound_interest = 0
        if (newVal.daily_income_value === undefined) newVal.daily_income_value = 0
        if (newVal.period_days === undefined) newVal.period_days = 30
        if (newVal.period_income_value === undefined) newVal.period_income_value = 0
    }
}, { immediate: true, deep: true })

// 监听阶段列表变化，同步到表单数据
watch(stageList, (newVal) => {
    if (baTable.form.items) {
        baTable.form.items.stage_income_config = JSON.stringify(newVal)
    }
}, { deep: true })

// 监听赠送规则变化，同步到表单数据
watch([giftRuleEnabled, giftRule], () => {
    if (baTable.form.items) {
        if (giftRuleEnabled.value) {
            baTable.form.items.gift_rule = JSON.stringify({
                enabled: true,
                buy: giftRule.value.buy,
                gift: giftRule.value.gift,
                gift_product_id: giftRule.value.gift_product_id
            })
        } else {
            baTable.form.items.gift_rule = null
        }
    }
}, { deep: true })

// 添加阶段
const addStage = () => {
    const lastStage = stageList.value[stageList.value.length - 1]
    const startDay = lastStage ? lastStage.end + 1 : 1
    
    stageList.value.push({
        start: startDay,
        end: startDay + 29,
        value: 5.0,
        type: 'percent',
        description: ''
    })
}

// 删除阶段
const removeStage = (index: number) => {
    stageList.value.splice(index, 1)
}

const rules = {
    name: [{ required: true, message: t('finance.product.Name is required'), trigger: 'blur' }],
    price: [{ required: true, message: t('finance.product.Price must be numeric'), trigger: 'change' }],
    cycle_value: [{ required: true, message: '请输入周期数值', trigger: 'change' }],
    cycle_type: [{ required: true, message: '请选择周期类型', trigger: 'change' }],
    yield_rate: [{ required: true, message: t('Please input field', { field: t('finance.product.Yield Rate') }), trigger: 'change' }],
    income_mode: [{ required: true, message: '请选择收益模式', trigger: 'change' }],
    income_value_type: [{ required: true, message: '请选择收益值类型', trigger: 'change' }],
    total_amount: [{ required: true, message: t('finance.product.Total amount must be integer'), trigger: 'change' }],
    min_purchase: [{ required: true, message: t('finance.product.Min purchase must be greater than zero'), trigger: 'change' }],
    status: [{ required: true, message: t('finance.product.Status value is incorrect'), trigger: 'change' }],
}
</script>

<style scoped lang="scss"></style>


