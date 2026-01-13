<template>
    <div class="consignment-config">
        <el-row :gutter="20">
            <el-col :xs="24" :lg="12">
                <el-card class="config-card" shadow="hover">
                    <template #header>
                        <div class="card-header">
                            <span class="card-title">藏品价格上涨配置</span>
                            <div class="card-actions">
                                <el-button size="small" @click="fetchCollectionPriceIncreaseConfig" :loading="priceIncreaseConfigLoading" :icon="Refresh">刷新</el-button>
                                <el-button size="small" type="primary" @click="saveCollectionPriceIncreaseConfig" :loading="savingPriceIncreaseConfig">保存</el-button>
                            </div>
                        </div>
                    </template>
                    <el-form :model="collectionPriceIncreaseConfig" label-width="140px" v-loading="priceIncreaseConfigLoading">
                        <el-form-item label="配置说明">
                            <el-alert type="info" :closable="false" show-icon>
                                <template #default>
                                    <div>商品每次被买走后，价格会自动上涨（在最小涨幅和最大涨幅之间随机）</div>
                                    <div class="alert-tip">例如：最小涨幅4%，最大涨幅6%，则每次购买后价格会在原价基础上上涨4%-6%之间（随机，平均5.5%）</div>
                                    <div class="alert-tip">示例：1000元买入，下次卖出价在 1040~1060元之间</div>
                                </template>
                            </el-alert>
                        </el-form-item>
                        <el-form-item label="最小涨幅">
                            <el-input-number v-model="collectionPriceIncreaseConfig.min_increase" :min="0" :max="1" :step="0.01" :precision="2" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">例如：0.04 表示 4%</span>
                        </el-form-item>
                        <el-form-item label="最大涨幅">
                            <el-input-number v-model="collectionPriceIncreaseConfig.max_increase" :min="0" :max="1" :step="0.01" :precision="2" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">例如：0.06 表示 6%</span>
                        </el-form-item>
                        <el-form-item label="平均涨幅">
                            <el-tag type="info" size="large">{{ averageIncrease.toFixed(2) }}%</el-tag>
                            <span class="form-tip">平均涨幅 = (最小涨幅 + 最大涨幅) / 2</span>
                        </el-form-item>
                        <el-form-item v-if="collectionPriceIncreaseConfig.min_increase > collectionPriceIncreaseConfig.max_increase">
                            <el-alert type="warning" :closable="false" show-icon>警告：最小涨幅不能大于最大涨幅</el-alert>
                        </el-form-item>
                        <el-divider />
                        <el-form-item label="寄售服务费费率">
                            <el-input-number v-model="collectionPriceIncreaseConfig.service_fee_rate" :min="0" :max="1" :step="0.01" :precision="2" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">例如：0.03 表示 3%</span>
                        </el-form-item>
                        <el-form-item label="寄售券有效期">
                            <el-input-number v-model="collectionPriceIncreaseConfig.expire_days" :min="1" :max="365" :step="1" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">天（用户购买商品后获得的寄售券有效期天数）</span>
                        </el-form-item>
                    </el-form>
                </el-card>
            </el-col>
            <el-col :xs="24" :lg="12">
                <el-card class="config-card" shadow="hover">
                    <template #header>
                        <div class="card-header">
                            <span class="card-title">寄售解锁配置</span>
                            <div class="card-actions">
                                <el-button size="small" @click="fetchConsignmentUnlockConfig" :loading="consignmentUnlockConfigLoading" :icon="Refresh">刷新</el-button>
                                <el-button size="small" type="primary" @click="saveConsignmentUnlockConfig" :loading="savingConsignmentUnlockConfig">保存</el-button>
                            </div>
                        </div>
                    </template>
                    <el-form :model="consignmentUnlockConfig" label-width="140px" v-loading="consignmentUnlockConfigLoading">
                        <el-form-item label="配置说明">
                            <el-alert type="info" :closable="false" show-icon>
                                <template #default>
                                    <div>购买后需等待指定小时数才能上架寄售。此配置必须在后台显式设置，系统不使用默认硬编码。</div>
                                    <div class="alert-tip">例如：填写48表示购买后48小时可寄售</div>
                                </template>
                            </el-alert>
                        </el-form-item>
                        <el-form-item label="解锁小时数">
                            <el-input-number v-model="consignmentUnlockConfig.unlock_hours" :min="0" :max="8760" :step="1" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">单位：小时，必须显式设置（0-8760）</span>
                        </el-form-item>
                    </el-form>
                </el-card>
            </el-col>
            <el-col :xs="24" :lg="12">
                <el-card class="config-card" shadow="hover">
                    <template #header>
                        <div class="card-header">
                            <span class="card-title">寄售失败（流拍）配置</span>
                            <div class="card-actions">
                                <el-button size="small" @click="fetchConsignmentExpireConfig" :loading="consignmentExpireConfigLoading" :icon="Refresh">刷新</el-button>
                                <el-button size="small" type="primary" @click="saveConsignmentExpireConfig" :loading="savingConsignmentExpireConfig">保存</el-button>
                            </div>
                        </div>
                    </template>
                    <el-form :model="consignmentExpireConfig" label-width="140px" v-loading="consignmentExpireConfigLoading">
                        <el-form-item label="配置说明">
                            <el-alert type="info" :closable="false" show-icon>
                                <template #default>
                                    <div>寄售商品超过指定天数未售出时，系统会自动标记为流拍失败</div>
                                    <div class="alert-tip">例如：设置为7天，则寄售超过7天未售出的商品会自动标记为流拍失败</div>
                                    <div class="alert-tip">注意：此配置由定时任务每天执行，建议设置为1-365天之间</div>
                                </template>
                            </el-alert>
                        </el-form-item>
                        <el-form-item label="流拍天数">
                            <el-input-number v-model="consignmentExpireConfig.expire_days" :min="1" :max="365" :step="1" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">天（寄售超过此天数未售出时，自动标记为流拍失败）</span>
                        </el-form-item>
                    </el-form>
                </el-card>
            </el-col>
        </el-row>
        <el-row :gutter="20" style="margin-top: 20px;">
            <el-col :xs="24">
                <el-card class="config-card" shadow="hover">
                    <template #header>
                        <div class="card-header">
                            <span class="card-title">交易结算配置</span>
                            <div class="card-actions">
                                <el-button size="small" @click="fetchConsignmentSettlementConfig" :loading="settlementConfigLoading" :icon="Refresh">刷新</el-button>
                                <el-button size="small" type="primary" @click="saveConsignmentSettlementConfig" :loading="savingSettlementConfig">保存</el-button>
                            </div>
                        </div>
                    </template>
                    <el-form :model="consignmentSettlementConfig" label-width="180px" v-loading="settlementConfigLoading">
                        <el-form-item label="配置说明">
                            <el-alert type="info" :closable="false" show-icon>
                                <template #default>
                                    <div>成功卖出后，系统会自动进行交易结算：</div>
                                    <div class="alert-tip">1. 本金：全额退回给卖家余额</div>
                                    <div class="alert-tip">2. 利润（卖出价 - 买入价）：按配置比例分配，一部分进余额，一部分进积分</div>
                                </template>
                            </el-alert>
                        </el-form-item>
                        <el-form-item label="利润余额分配比例">
                            <el-input-number v-model="consignmentSettlementConfig.profit_balance_rate" :min="0" :max="1" :step="0.01" :precision="2" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">例如：0.5 表示 50%</span>
                        </el-form-item>
                        <el-form-item label="利润积分分配比例">
                            <el-input-number v-model="consignmentSettlementConfig.profit_score_rate" :min="0" :max="1" :step="0.01" :precision="2" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">例如：0.5 表示 50%</span>
                        </el-form-item>
                        <el-form-item label="比例总和">
                            <el-tag :type="totalSettlementRate > 1.01 || totalSettlementRate < 0.99 ? 'danger' : 'success'" size="large">
                                {{ (totalSettlementRate * 100).toFixed(2) }}%
                            </el-tag>
                            <span class="form-tip" v-if="totalSettlementRate > 1.01 || totalSettlementRate < 0.99" style="color: #f56c6c;">
                                警告：两个比例之和必须等于1（100%）
                            </span>
                            <span class="form-tip" v-else style="color: #67c23a;">正确：比例总和为100%</span>
                        </el-form-item>
                    </el-form>
                </el-card>
            </el-col>
        </el-row>
    </div>
</template>

<script setup lang="ts">
import { reactive, ref, computed } from 'vue'
import { ElMessage } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import createAxios from '/@/utils/axios'

const priceIncreaseConfigLoading = ref(false)
const savingPriceIncreaseConfig = ref(false)
const collectionPriceIncreaseConfig = reactive({
    min_increase: 0.04,
    max_increase: 0.06,
    service_fee_rate: 0.03,
    expire_days: 7,
})

const consignmentExpireConfigLoading = ref(false)
const savingConsignmentExpireConfig = ref(false)
const consignmentExpireConfig = reactive({
    expire_days: 7,
})

const consignmentUnlockConfigLoading = ref(false)
const savingConsignmentUnlockConfig = ref(false)
const consignmentUnlockConfig = reactive({
    unlock_hours: null,
})

const settlementConfigLoading = ref(false)
const savingSettlementConfig = ref(false)
const consignmentSettlementConfig = reactive({
    profit_balance_rate: 0.5,
    profit_score_rate: 0.5,
})

const averageIncrease = computed(() => {
    const min = collectionPriceIncreaseConfig.min_increase || 0
    const max = collectionPriceIncreaseConfig.max_increase || 0
    return ((min + max) / 2) * 100
})

const totalSettlementRate = computed(() => {
    const balance = consignmentSettlementConfig.profit_balance_rate || 0
    const score = consignmentSettlementConfig.profit_score_rate || 0
    return balance + score
})

const fetchCollectionPriceIncreaseConfig = () => {
    priceIncreaseConfigLoading.value = true
    Promise.all([
        createAxios({ url: '/admin/content.DrawCountConfig/collectionPriceIncreaseConfig', method: 'GET' }),
        createAxios({ url: '/admin/content.DrawCountConfig/consignmentServiceFeeConfig', method: 'GET' }),
        createAxios({ url: '/admin/content.DrawCountConfig/consignmentCouponExpireConfig', method: 'GET' })
    ])
        .then((responses: any[]) => {
            collectionPriceIncreaseConfig.min_increase = responses[0].data.min_increase ?? 0.04
            collectionPriceIncreaseConfig.max_increase = responses[0].data.max_increase ?? 0.06
            collectionPriceIncreaseConfig.service_fee_rate = responses[1].data.service_fee_rate ?? 0.03
            collectionPriceIncreaseConfig.expire_days = responses[2].data.expire_days ?? 7
        })
        .finally(() => {
            priceIncreaseConfigLoading.value = false
        })
}

const saveCollectionPriceIncreaseConfig = () => {
    if (collectionPriceIncreaseConfig.min_increase > collectionPriceIncreaseConfig.max_increase) {
        ElMessage.warning('最小涨幅不能大于最大涨幅')
        return
    }
    savingPriceIncreaseConfig.value = true
    Promise.all([
        createAxios({ url: '/admin/content.DrawCountConfig/collectionPriceIncreaseConfig', method: 'POST', data: { min_increase: collectionPriceIncreaseConfig.min_increase, max_increase: collectionPriceIncreaseConfig.max_increase } }, { showSuccessMessage: false }),
        createAxios({ url: '/admin/content.DrawCountConfig/consignmentServiceFeeConfig', method: 'POST', data: { service_fee_rate: collectionPriceIncreaseConfig.service_fee_rate } }, { showSuccessMessage: false }),
        createAxios({ url: '/admin/content.DrawCountConfig/consignmentCouponExpireConfig', method: 'POST', data: { expire_days: collectionPriceIncreaseConfig.expire_days } }, { showSuccessMessage: false })
    ])
        .then(() => {
            ElMessage.success('配置更新成功')
            fetchCollectionPriceIncreaseConfig()
        })
        .finally(() => {
            savingPriceIncreaseConfig.value = false
        })
}

const fetchConsignmentExpireConfig = () => {
    consignmentExpireConfigLoading.value = true
    createAxios({ url: '/admin/content.DrawCountConfig/consignmentExpireDaysConfig', method: 'GET' })
        .then((res) => {
            consignmentExpireConfig.expire_days = res.data.expire_days ?? 7
        })
        .finally(() => {
            consignmentExpireConfigLoading.value = false
        })
}

const saveConsignmentExpireConfig = () => {
    if (consignmentExpireConfig.expire_days < 1 || consignmentExpireConfig.expire_days > 365) {
        ElMessage.warning('寄售失败天数必须在1-365天之间')
        return
    }
    savingConsignmentExpireConfig.value = true
    createAxios({ url: '/admin/content.DrawCountConfig/consignmentExpireDaysConfig', method: 'POST', data: { expire_days: consignmentExpireConfig.expire_days } }, { showSuccessMessage: true })
        .then(() => {
            fetchConsignmentExpireConfig()
        })
        .finally(() => {
            savingConsignmentExpireConfig.value = false
        })
}

const fetchConsignmentUnlockConfig = () => {
    consignmentUnlockConfigLoading.value = true
    createAxios({ url: '/admin/content.DrawCountConfig/consignmentUnlockHoursConfig', method: 'GET' })
        .then((res) => {
            // 不使用默认值，若后台未设置则保留 null/undefined，前端会提示管理员设置
            consignmentUnlockConfig.unlock_hours = res.data.unlock_hours ?? null
        })
        .finally(() => {
            consignmentUnlockConfigLoading.value = false
        })
}

const saveConsignmentUnlockConfig = () => {
    const hours = consignmentUnlockConfig.unlock_hours
    if (hours === null || hours === undefined || hours < 0 || hours > 8760) {
        ElMessage.warning('请填写有效的寄售解锁小时数（0-8760）')
        return
    }
    savingConsignmentUnlockConfig.value = true
    createAxios({ url: '/admin/content.DrawCountConfig/consignmentUnlockHoursConfig', method: 'POST', data: { unlock_hours: hours } }, { showSuccessMessage: true })
        .then(() => {
            fetchConsignmentUnlockConfig()
        })
        .finally(() => {
            savingConsignmentUnlockConfig.value = false
        })
}

const fetchConsignmentSettlementConfig = () => {
    settlementConfigLoading.value = true
    createAxios({ url: '/admin/content.DrawCountConfig/consignmentSettlementConfig', method: 'GET' })
        .then((res) => {
            consignmentSettlementConfig.profit_balance_rate = res.data.profit_balance_rate ?? 0.5
            consignmentSettlementConfig.profit_score_rate = res.data.profit_score_rate ?? 0.5
        })
        .finally(() => {
            settlementConfigLoading.value = false
        })
}

const saveConsignmentSettlementConfig = () => {
    const total = totalSettlementRate.value
    if (total > 1.01 || total < 0.99) {
        ElMessage.warning('利润余额分配比例和利润积分分配比例之和必须等于1（100%）')
        return
    }
    savingSettlementConfig.value = true
    createAxios({ url: '/admin/content.DrawCountConfig/consignmentSettlementConfig', method: 'POST', data: { profit_balance_rate: consignmentSettlementConfig.profit_balance_rate, profit_score_rate: consignmentSettlementConfig.profit_score_rate } }, { showSuccessMessage: true })
        .then(() => {
            fetchConsignmentSettlementConfig()
        })
        .finally(() => {
            savingSettlementConfig.value = false
        })
}

fetchCollectionPriceIncreaseConfig()
fetchConsignmentExpireConfig()
fetchConsignmentSettlementConfig()
fetchConsignmentUnlockConfig()
</script>

<style scoped lang="scss">
.consignment-config {
    .config-card {
        margin-bottom: 20px;
        border-radius: 8px;
        transition: all 0.3s;
        
        &:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
    }
    
    .card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        
        .card-title {
            font-size: 16px;
            font-weight: 600;
            color: var(--el-text-color-primary);
        }
        
        .card-actions {
            display: flex;
            gap: 10px;
        }
    }
    
    .alert-tip {
        margin-top: 5px;
        color: #909399;
        font-size: 12px;
    }
    
    .form-tip {
        margin-left: 12px;
        color: var(--el-text-color-secondary);
        font-size: 12px;
    }
}
</style>

