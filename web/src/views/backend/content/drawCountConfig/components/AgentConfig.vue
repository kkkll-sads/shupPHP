<template>
    <div class="agent-config">
        <el-card class="config-card" shadow="hover">
            <template #header>
                <div class="card-header">
                    <span class="card-title">代理佣金配置</span>
                    <div class="card-actions">
                        <el-button size="small" @click="fetchAgentCommissionConfig" :loading="agentCommissionConfigLoading" :icon="Refresh">刷新</el-button>
                        <el-button size="small" type="primary" @click="saveAgentCommissionConfig" :loading="savingAgentCommissionConfig">保存</el-button>
                    </div>
                </div>
            </template>
            <el-form :model="agentCommissionConfig" label-width="180px" v-loading="agentCommissionConfigLoading">
                <el-form-item label="配置说明">
                    <el-alert type="info" :closable="false" show-icon>
                        <template #default>
                            <div>佣金计算基数为卖家的利润（卖出价 - 买入价）</div>
                            <div class="alert-tip">1. 直推佣金：卖家的邀请人（直推）拿利润的指定比例</div>
                            <div class="alert-tip">2. 间推佣金：直推的邀请人（间推）拿利润的指定比例</div>
                            <div class="alert-tip">3. 代理团队奖（累计制+同级特殊处理）：正常按级差分配，同级代理拿固定比例</div>
                            <div class="alert-tip">4. 同级奖：如果上级和下级是同一等级的代理，上级只拿同级奖（不按级差计算）</div>
                            <div class="alert-tip">5. 代理服务费折扣：代理在上架寄售时，缴纳的3%服务费可以打折</div>
                        </template>
                    </el-alert>
                </el-form-item>
                <el-divider />
                <el-row :gutter="20">
                    <el-col :xs="24" :sm="12">
                        <el-form-item label="直推佣金比例">
                            <el-input-number v-model="agentCommissionConfig.direct_rate" :min="0" :max="1" :step="0.01" :precision="2" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">例如：0.10 表示 10%</span>
                        </el-form-item>
                    </el-col>
                    <el-col :xs="24" :sm="12">
                        <el-form-item label="间推佣金比例">
                            <el-input-number v-model="agentCommissionConfig.indirect_rate" :min="0" :max="1" :step="0.01" :precision="2" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">例如：0.05 表示 5%</span>
                        </el-form-item>
                    </el-col>
                </el-row>
                <el-divider />
                <el-row :gutter="20">
                    <el-col :xs="24" :sm="12">
                        <el-form-item label="1级代理团队奖比例">
                            <el-input-number v-model="agentCommissionConfig.team_level1_rate" :min="0" :max="1" :step="0.01" :precision="2" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">例如：0.09 表示 9%</span>
                        </el-form-item>
                    </el-col>
                    <el-col :xs="24" :sm="12">
                        <el-form-item label="2级代理团队奖比例">
                            <el-input-number v-model="agentCommissionConfig.team_level2_rate" :min="0" :max="1" :step="0.01" :precision="2" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">例如：0.12 表示 12%</span>
                        </el-form-item>
                    </el-col>
                    <el-col :xs="24" :sm="12">
                        <el-form-item label="3级代理团队奖比例">
                            <el-input-number v-model="agentCommissionConfig.team_level3_rate" :min="0" :max="1" :step="0.01" :precision="2" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">例如：0.15 表示 15%</span>
                        </el-form-item>
                    </el-col>
                    <el-col :xs="24" :sm="12">
                        <el-form-item label="4级代理团队奖比例">
                            <el-input-number v-model="agentCommissionConfig.team_level4_rate" :min="0" :max="1" :step="0.01" :precision="2" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">例如：0.18 表示 18%</span>
                        </el-form-item>
                    </el-col>
                    <el-col :xs="24" :sm="12">
                        <el-form-item label="5级代理团队奖比例">
                            <el-input-number v-model="agentCommissionConfig.team_level5_rate" :min="0" :max="1" :step="0.01" :precision="2" controls-position="right" style="width: 200px;" />
                            <span class="form-tip">例如：0.21 表示 21%</span>
                        </el-form-item>
                    </el-col>
                </el-row>
                <el-divider />
                <el-form-item label="同级奖比例">
                    <el-input-number v-model="agentCommissionConfig.same_level_rate" :min="0" :max="1" :step="0.01" :precision="2" controls-position="right" style="width: 200px;" />
                    <span class="form-tip">例如：0.10 表示 10%（同级代理拿固定比例）</span>
                </el-form-item>
                <el-divider />
                <el-form-item label="代理服务费折扣">
                    <el-input-number v-model="agentCommissionConfig.service_fee_discount" :min="0" :max="1" :step="0.01" :precision="2" controls-position="right" style="width: 200px;" />
                    <span class="form-tip">例如：0.8 表示 8折，1 表示不打折</span>
                </el-form-item>
            </el-form>
        </el-card>
    </div>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Refresh } from '@element-plus/icons-vue'
import createAxios from '/@/utils/axios'

const agentCommissionConfigLoading = ref(false)
const savingAgentCommissionConfig = ref(false)
const agentCommissionConfig = reactive({
    direct_rate: 0.10,
    indirect_rate: 0.05,
    team_level1_rate: 0.09,
    team_level2_rate: 0.12,
    team_level3_rate: 0.15,
    team_level4_rate: 0.18,
    team_level5_rate: 0.21,
    same_level_rate: 0.10,
    service_fee_discount: 1.0,
})

const fetchAgentCommissionConfig = () => {
    agentCommissionConfigLoading.value = true
    createAxios({ url: '/admin/content.DrawCountConfig/agentCommissionConfig', method: 'GET' })
        .then((res) => {
            agentCommissionConfig.direct_rate = res.data.direct_rate ?? 0.10
            agentCommissionConfig.indirect_rate = res.data.indirect_rate ?? 0.05
            agentCommissionConfig.team_level1_rate = res.data.team_level1_rate ?? 0.09
            agentCommissionConfig.team_level2_rate = res.data.team_level2_rate ?? 0.12
            agentCommissionConfig.team_level3_rate = res.data.team_level3_rate ?? 0.15
            agentCommissionConfig.team_level4_rate = res.data.team_level4_rate ?? 0.18
            agentCommissionConfig.team_level5_rate = res.data.team_level5_rate ?? 0.21
            agentCommissionConfig.same_level_rate = res.data.same_level_rate ?? 0.10
            agentCommissionConfig.service_fee_discount = res.data.service_fee_discount ?? 1.0
        })
        .finally(() => {
            agentCommissionConfigLoading.value = false
        })
}

const saveAgentCommissionConfig = () => {
    // 验证级差制：每个等级的比例应该递增
    if (agentCommissionConfig.team_level1_rate > agentCommissionConfig.team_level2_rate ||
        agentCommissionConfig.team_level2_rate > agentCommissionConfig.team_level3_rate ||
        agentCommissionConfig.team_level3_rate > agentCommissionConfig.team_level4_rate ||
        agentCommissionConfig.team_level4_rate > agentCommissionConfig.team_level5_rate) {
        ElMessage.warning('代理团队奖比例应该按等级递增（1级 < 2级 < 3级 < 4级 < 5级）')
        return
    }
    savingAgentCommissionConfig.value = true
    createAxios({ url: '/admin/content.DrawCountConfig/agentCommissionConfig', method: 'POST', data: agentCommissionConfig }, { showSuccessMessage: true })
        .then(() => {
            fetchAgentCommissionConfig()
        })
        .finally(() => {
            savingAgentCommissionConfig.value = false
        })
}

fetchAgentCommissionConfig()
</script>

<style scoped lang="scss">
.agent-config {
    .config-card {
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

