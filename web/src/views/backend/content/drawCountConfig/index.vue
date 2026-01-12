<template>
    <div class="default-main ba-table-box config-main">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <el-tabs v-model="activeTab" type="border-card" class="config-tabs">
            <el-tab-pane label="注册与验证" name="register">
                <RegisterConfig />
            </el-tab-pane>
            <el-tab-pane label="签到与奖励" name="signin">
                <SignInConfig />
            </el-tab-pane>
            <el-tab-pane label="签到活动" name="signinActivity">
                <SignInActivityConfig />
            </el-tab-pane>
            <el-tab-pane label="寄售与交易" name="consignment">
                <ConsignmentConfig />
            </el-tab-pane>
            <el-tab-pane label="分红与矿机" name="mining">
                <MiningConfig />
            </el-tab-pane>
            <el-tab-pane label="代理与佣金" name="agent">
                <AgentConfig />
            </el-tab-pane>
            <el-tab-pane label="消费金兑换" name="exchange">
                <ScoreExchangeConfig />
            </el-tab-pane>
            <el-tab-pane label="算力权重配置" name="powerWeight">
                <PowerWeightConfig />
            </el-tab-pane>
            <el-tab-pane label="撮合设置" name="matching">
                <MatchingConfig />
            </el-tab-pane>
            <el-tab-pane label="第三方服务" name="thirdparty">
                <ThirdPartyConfig />
            </el-tab-pane>
        </el-tabs>

        <!-- 弹窗表单 -->
        <PopupForm />
    </div>
</template>

<script setup lang="ts">
import { ref, provide } from 'vue'
import baTableClass from '/@/utils/baTable'
import { baTableApi } from '/@/api/common'
import PopupForm from './popupForm.vue'
import RegisterConfig from './components/RegisterConfig.vue'
import SignInConfig from './components/SignInConfig.vue'
import SignInActivityConfig from './components/SignInActivityConfig.vue'
import ConsignmentConfig from './components/ConsignmentConfig.vue'
import MiningConfig from './components/MiningConfig.vue'
import AgentConfig from './components/AgentConfig.vue'
import ScoreExchangeConfig from './components/ScoreExchangeConfig.vue'
import PowerWeightConfig from './components/PowerWeightConfig.vue'
import ThirdPartyConfig from './components/ThirdPartyConfig.vue'
import MatchingConfig from './components/MatchingConfig.vue'

defineOptions({
    name: 'content/drawCountConfig',
})

const activeTab = ref('consignment')

const baTable = new baTableClass(
    new baTableApi('/admin/content.DrawCountConfig/'),
    {
        column: [],
    },
    {
        defaultItems: {},
    }
)

provide('baTable', baTable)
</script>

<style scoped lang="scss">
.config-main {
    padding: 20px;
}

.config-tabs {
    border-radius: 8px;
    overflow: hidden;
    
    :deep(.el-tabs__header) {
        margin: 0;
        background-color: var(--el-bg-color);
    }
    
    :deep(.el-tabs__content) {
        padding: 20px;
        background-color: var(--el-bg-color-page);
    }
    
    :deep(.el-tab-pane) {
        min-height: 400px;
    }
}
</style>
