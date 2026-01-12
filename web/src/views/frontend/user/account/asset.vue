<template>
    <div class="user-views">
        <el-card class="user-views-card" shadow="hover">
            <template #header>
                <div class="card-header">
                    <span>资产变动记录</span>
                    <div class="filter-tabs">
                        <el-radio-group v-model="state.assetType" @change="onTypeChange">
                            <el-radio-button label="all">全部</el-radio-button>
                            <el-radio-button label="balance_available">可用余额</el-radio-button>
                            <el-radio-button label="withdrawable_money">可提现金额</el-radio-button>
                            <el-radio-button label="service_fee_balance">服务费余额</el-radio-button>
                            <el-radio-button label="score">消费金</el-radio-button>
                        </el-radio-group>
                    </div>
                </div>
            </template>
            <div v-loading="state.pageLoading" class="logs">
                <div class="log-item" v-for="(item, idx) in state.logs" :key="idx">
                    <div class="log-title">
                        <span class="asset-type" :class="getAssetTypeClass(item.asset_type)">
                            [{{ item.asset_type_text }}]
                        </span>
                        {{ item.memo }}
                    </div>
                    <div v-if="item.amount > 0" class="log-change-amount increase">
                        {{ item.asset_type_text }}：+{{ item.amount }}
                    </div>
                    <div v-else class="log-change-amount reduce">
                        {{ item.asset_type_text }}：{{ item.amount }}
                    </div>
                    <div class="log-after">变动后余额：{{ item.after_balance }}</div>
                    <div class="log-change-time">变动时间：{{ timeFormat(item.create_time) }}</div>
                </div>
            </div>
            <div v-if="state.total > 0" class="log-footer">
                <el-pagination
                    :currentPage="state.currentPage"
                    :page-size="state.pageSize"
                    :page-sizes="[10, 20, 50, 100]"
                    background
                    :layout="memberCenter.state.shrink ? 'prev, next, jumper' : 'sizes, ->, prev, pager, next, jumper'"
                    :total="state.total"
                    @size-change="onTableSizeChange"
                    @current-change="onTableCurrentChange"
                ></el-pagination>
            </div>
            <el-empty v-else />
        </el-card>
    </div>
</template>

<script setup lang="ts">
import { reactive, onMounted } from 'vue'
import { getAssetLog } from '/@/api/frontend/user/index'
import { useMemberCenter } from '/@/stores/memberCenter'
import { timeFormat } from '/@/utils/common'

const memberCenter = useMemberCenter()
const state: {
    logs: {
        id: number
        asset_type: string
        asset_type_text: string
        amount: number
        before_balance: number
        after_balance: number
        memo: string
        create_time: number
    }[]
    assetType: string
    currentPage: number
    total: number
    pageSize: number
    pageLoading: boolean
} = reactive({
    logs: [],
    assetType: 'all',
    currentPage: 1,
    total: 0,
    pageSize: 10,
    pageLoading: true,
})

const getAssetTypeClass = (type: string) => {
    const classes: { [key: string]: string } = {
        'balance_available': 'type-balance',
        'withdrawable_money': 'type-withdrawable',
        'service_fee_balance': 'type-service',
        'score': 'type-score'
    }
    return classes[type] || 'type-default'
}

const onTypeChange = () => {
    state.currentPage = 1
    loadData()
}

const onTableSizeChange = (val: number) => {
    state.pageSize = val
    loadData()
}

const onTableCurrentChange = (val: number) => {
    state.currentPage = val
    loadData()
}

const loadData = () => {
    getAssetLog(state.assetType, state.currentPage, state.pageSize).then((res) => {
        state.pageLoading = false
        state.logs = res.data.list
        state.total = res.data.total
    })
}

onMounted(() => {
    loadData()
})
</script>

<style scoped lang="scss">
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}

.filter-tabs {
    .el-radio-group {
        :deep(.el-radio-button__inner) {
            border-radius: 4px;
        }
    }
}

.user-views-card :deep(.el-card__body) {
    padding-top: 0;
}

.log-item {
    border-bottom: 1px solid var(--ba-bg-color);
    padding: 15px 0;

    div {
        padding: 4px 0;
    }
}

.log-title {
    font-size: var(--el-font-size-medium);

    .asset-type {
        font-weight: bold;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 12px;

        &.type-balance {
            background-color: #e6f7ff;
            color: #1890ff;
        }

        &.type-withdrawable {
            background-color: #f6ffed;
            color: #52c41a;
        }

        &.type-service {
            background-color: #fff7e6;
            color: #fa8c16;
        }

        &.type-score {
            background-color: #f9f0ff;
            color: #722ed1;
        }

        &.type-default {
            background-color: #f5f5f5;
            color: #666;
        }
    }
}

.log-change-amount.increase {
    color: var(--el-color-success);
}

.log-change-amount.reduce {
    color: var(--el-color-danger);
}

.log-after,
.log-change-time {
    font-size: var(--el-font-size-small);
    color: var(--el-text-color-secondary);
}

.log-footer {
    padding-top: 20px;
}

@media (max-width: 768px) {
    .card-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .filter-tabs {
        width: 100%;

        .el-radio-group {
            width: 100%;

            :deep(.el-radio-button) {
                flex: 1;
            }
        }
    }
}
</style>
