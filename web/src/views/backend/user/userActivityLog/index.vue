<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <TableHeader
            :buttons="['refresh', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="'用户 / 备注 / 类型'"
        >
            <el-popconfirm
                @confirm="onClearAll"
                confirm-button-text="确定"
                cancel-button-text="取消"
                confirmButtonType="danger"
                title="确定要清除全部日志吗？此操作不可恢复！"
            >
                <template #reference>
                    <div class="mlr-12">
                        <el-tooltip content="清除全部日志" placement="top">
                            <el-button v-blur class="table-header-operate" type="danger">
                                <Icon name="fa fa-trash-o" />
                                <span class="table-header-operate-text">清除全部日志</span>
                            </el-button>
                        </el-tooltip>
                    </div>
                </template>
            </el-popconfirm>
            <el-popconfirm
                @confirm="onClearAllUsers"
                confirm-button-text="确定"
                cancel-button-text="取消"
                confirmButtonType="danger"
                title="确定要清除全部用户（保留最新用户）吗？此操作不可恢复！"
            >
                <template #reference>
                    <div class="mlr-12">
                        <el-tooltip content="清除全部用户（保留最新）" placement="top">
                            <el-button v-blur class="table-header-operate" type="danger">
                                <Icon name="fa fa-users" />
                                <span class="table-header-operate-text">清除全部用户</span>
                            </el-button>
                        </el-tooltip>
                    </div>
                </template>
            </el-popconfirm>
        </TableHeader>

        <Table />

        <el-drawer v-model="detailVisible" title="日志详情" size="460px">
            <el-descriptions v-if="detailRow" :column="1" border>
                <el-descriptions-item label="日志ID">{{ detailRow.id }}</el-descriptions-item>
                <el-descriptions-item label="用户昵称">{{ detailRow.user?.nickname || '-' }}</el-descriptions-item>
                <el-descriptions-item label="用户名">{{ detailRow.user?.username || '-' }}</el-descriptions-item>
                <el-descriptions-item label="关联用户昵称">{{ detailRow.related_user?.nickname || '-' }}</el-descriptions-item>
                <el-descriptions-item label="关联用户用户名">{{ detailRow.related_user?.username || '-' }}</el-descriptions-item>
                <el-descriptions-item label="记录类型">{{ actionTypeMap[detailRow.action_type as keyof typeof actionTypeMap] || detailRow.action_type }}</el-descriptions-item>
                <el-descriptions-item label="变动字段">{{ detailRow.change_field || '-' }}</el-descriptions-item>
                <el-descriptions-item label="变动值">{{ formatNumber(detailRow.change_value) }}</el-descriptions-item>
                <el-descriptions-item label="原值">{{ formatNumber(detailRow.before_value) }}</el-descriptions-item>
                <el-descriptions-item label="现值">{{ formatNumber(detailRow.after_value) }}</el-descriptions-item>
                <el-descriptions-item label="关联用户 ID">{{ detailRow.related_user_id || '-' }}</el-descriptions-item>
                <el-descriptions-item label="备注">{{ detailRow.remark || '-' }}</el-descriptions-item>
                <el-descriptions-item label="创建时间">{{ formatDate(detailRow.create_time) }}</el-descriptions-item>
            </el-descriptions>

            <template v-if="extraEntries.length">
                <h4 class="extra-title">扩展信息</h4>
                <el-descriptions :column="1" border>
                    <el-descriptions-item v-for="(item, idx) in extraEntries" :key="idx" :label="formatExtraLabel(item[0] as string)">
                        <span v-if="detailRow?.action_type === 'register_reward' && (item[0] as string).startsWith('reward_') && typeof item[1] === 'number' && item[1] > 0" class="reward-value">
                            {{ formatExtraValue(item[1]) }}
                            <span v-if="item[0] === 'reward_money' || item[0] === 'reward_withdrawable_money'">元</span>
                            <span v-else-if="item[0] === 'reward_score'">分</span>
                        </span>
                        <span v-else>{{ formatExtraValue(item[1]) }}</span>
                    </el-descriptions-item>
                </el-descriptions>
            </template>
        </el-drawer>
    </div>
</template>

<script setup lang="ts">
import { provide, ref, computed } from 'vue'
import baTableClass from '/@/utils/baTable'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import { baTableApi } from '/@/api/common'
import createAxios from '/@/utils/axios'
import { ElMessage } from 'element-plus'
import Icon from '/@/components/icon/index.vue'

defineOptions({
    name: 'user/activityLog',
})

const actionTypeMap = {
    invite: '邀请记录',
    draw_count: '抽奖次数变更',
    balance: '可用金额变更',
    withdrawable_money: '可提现金额变更',
    usdt: 'USDT变更',
    static_income: '拓展提现变更',
    dynamic_income: '服务金额变更',
    sign_in: '每日签到',
    sign_in_referral: '直推签到奖励',
    register_reward: '注册奖励',
    invite_reward: '邀请好友奖励',
    lucky_draw: '抽奖行为',
    lucky_draw_prize: '抽奖奖品发放',
    finance_purchase: '理财产品购买',
    finance_settle: '理财产品到期结算',
    shop_purchase: '商城购物',
    collection_purchase: '藏品购买',
    order_cancel: '订单取消退款',
    user_type_upgrade: '用户状态升级',
    score: '消费金变更',
    consignment_coupon: '寄售券变更',
    consignment_purchase: '寄售购买',
    consignment_income: '寄售收益',
    consignment_fee: '寄售服务费',
    consignment_expire: '寄售流拍失败',
    consignment_resend: '免费重发寄售',
    service_fee_recharge: '服务费充值',
    balance_transfer: '余额划转',
    green_power: '绿色算力变更',
    rights_distribute: '权益交割',
    rights_declaration_submit: '确权申报提交',
    rights_declaration_approved: '确权申报通过',
    rights_declaration_rejected: '确权申报拒绝',
    rights_declaration_cancelled: '确权申报撤销',
    rights_declaration_reward_balance: '确权申报可用金额奖励',
    rights_declaration_reward_green_power: '确权申报绿色能量奖励',
    rights_declaration_reward_consignment_coupon: '确权申报寄售卷奖励',
}

const detailVisible = ref(false)
const detailRow = ref<any>(null)

const extraEntries = computed(() => {
    if (!detailRow.value || !detailRow.value.extra) {
        return []
    }
    return Object.entries(detailRow.value.extra)
})

const formatNumber = (value: string | number) => {
    if (value === null || value === undefined || value === '') return '-'
    const num = Number(value)
    return Number.isNaN(num) ? value : num
}

const formatDate = (timestamp: number | string) => {
    if (!timestamp) return '-'
    const num = Number(timestamp)
    if (Number.isNaN(num) || num <= 0) return '-'
    const date = new Date(num * 1000)
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')} ${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}:${String(date.getSeconds()).padStart(2, '0')}`
}

const formatExtraValue = (value: any) => {
    if (value === null || value === undefined || value === '') return '-'
    if (typeof value === 'object') {
        try {
            return JSON.stringify(value, null, 2)
        } catch {
            return String(value)
        }
    }
    // 如果是数字，格式化显示
    if (typeof value === 'number') {
        // 判断是否为整数
        if (Number.isInteger(value)) {
            return value.toString()
        }
        // 浮点数保留2位小数
        return value.toFixed(2)
    }
    return value
}

// 格式化扩展信息的标签显示
const formatExtraLabel = (key: string) => {
    const labelMap: Record<string, string> = {
        'source': '奖励来源',
        'activity_id': '活动ID',
        'activity_name': '活动名称',
        'reward_money': '可用金额奖励',
        'reward_score': '消费金奖励',
        'reward_withdrawable_money': '可提现金额奖励',
        'reward_green_power': '绿色算力奖励',
        'fund_source': '资金来源',
        'register_reward': '注册奖励金额',
        'invite_reward': '邀请奖励金额',
        'invited_user_id': '被邀请用户ID',
    }
    return labelMap[key] || key
}

const onShowDetail = (row: any) => {
    detailRow.value = row
    detailVisible.value = true
}

const onClearAll = async () => {
    try {
        const res = await createAxios({
            url: '/admin/user.UserActivityLog/clearAll',
            method: 'post',
        })
        console.log('清除日志响应:', res)
        if (res.code === 1) {
            ElMessage.success(res.msg || '清除成功')
            baTable.getData()
        } else {
            ElMessage.error(res.msg || '清除失败')
        }
    } catch (error: any) {
        console.error('清除日志错误:', error)
        ElMessage.error(error?.msg || error?.response?.data?.msg || '清除失败，请稍后重试')
    }
}

const onClearAllUsers = async () => {
    try {
        const res = await createAxios({
            url: '/admin/user.User/clearAllExceptLatest',
            method: 'post',
        })
        console.log('清除用户响应:', res)
        if (res.code === 1) {
            ElMessage.success(res.msg || '清除成功')
            baTable.getData()
        } else {
            ElMessage.error(res.msg || '清除失败')
        }
    } catch (error: any) {
        console.error('清除用户错误:', error)
        ElMessage.error(error?.msg || error?.response?.data?.msg || '清除失败，请稍后重试')
    }
}

const baTable = new baTableClass(
    new baTableApi('/admin/user.UserActivityLog/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', operator: '=', width: 80 },
            {
                label: '用户昵称',
                prop: 'user.nickname',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '用户昵称',
                render: 'tag',
            },
            {
                label: '用户名',
                prop: 'user.username',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '用户名',
                render: 'tag',
            },
            {
                label: '记录类型',
                prop: 'action_type',
                align: 'center',
                operator: '=',
                operatorPlaceholder: '记录类型',
                render: 'tag',
                custom: {
                    invite: 'success',
                    draw_count: 'warning',
                    balance: 'info',
                    withdrawable_money: 'success',
                    usdt: 'warning',
                    static_income: 'primary',
                    dynamic_income: 'success',
                    sign_in: 'primary',
                    sign_in_referral: 'success',
                    register_reward: 'success',
                    invite_reward: 'success',
                    lucky_draw: 'primary',
                    lucky_draw_prize: 'warning',
                    finance_purchase: 'danger',
                    finance_settle: 'success',
                    shop_purchase: 'danger',
                    collection_purchase: 'danger',
                    order_cancel: 'warning',
                    user_type_upgrade: 'success',
                    score: 'info',
                    consignment_coupon: 'primary',
                    consignment_purchase: 'danger',
                    consignment_income: 'success',
                    consignment_fee: 'warning',
                    consignment_expire: 'info',
                    consignment_resend: 'success',
                    service_fee_recharge: 'primary',
                    balance_transfer: 'info',
                    green_power: 'success',
                    rights_distribute: 'success',
                    rights_declaration_submit: 'primary',
                    rights_declaration_approved: 'success',
                    rights_declaration_rejected: 'danger',
                    rights_declaration_cancelled: 'warning',
                    rights_declaration_reward_balance: 'success',
                    rights_declaration_reward_green_power: 'success',
                    rights_declaration_reward_consignment_coupon: 'success',
                },
                replaceValue: actionTypeMap,
            },
            {
                label: '变动字段',
                prop: 'change_field',
                align: 'center',
                operator: '=',
                operatorPlaceholder: '变动字段',
            },
            {
                label: '变动值',
                prop: 'change_value',
                align: 'center',
                operator: '=',
                operatorPlaceholder: '变动值',
                width: 110,
            },
            {
                label: '原值',
                prop: 'before_value',
                align: 'center',
                width: 110,
            },
            {
                label: '现值',
                prop: 'after_value',
                align: 'center',
                width: 110,
            },
            {
                label: '备注',
                prop: 'remark',
                align: 'left',
                operator: 'LIKE',
                operatorPlaceholder: '备注',
                showOverflowTooltip: true,
                formatter: (row: any) => {
                    // 如果是注册奖励，显示更详细的信息
                    if (row.action_type === 'register_reward' && row.extra) {
                        const parts: string[] = []
                        if (row.extra.reward_money > 0) {
                            parts.push(`可用金额：${row.extra.reward_money}元`)
                        }
                        if (row.extra.reward_score > 0) {
                            parts.push(`消费金：${row.extra.reward_score}分`)
                        }
                        if (row.extra.reward_withdrawable_money > 0) {
                            parts.push(`可提现金额：${row.extra.reward_withdrawable_money}元`)
                        }
                        if (row.extra.reward_green_power > 0) {
                            parts.push(`绿色算力：${row.extra.reward_green_power}`)
                        }
                        if (parts.length > 0) {
                            return `注册奖励（${parts.join('，')}）`
                        }
                    }
                    // 如果是权益交割，显示更详细的信息
                    if (row.action_type === 'rights_distribute' && row.extra) {
                        const parts: string[] = []
                        if (row.extra.user_collection_id) {
                            parts.push(`藏品ID：${row.extra.user_collection_id}`)
                        }
                        if (row.extra.item_id) {
                            parts.push(`商品ID：${row.extra.item_id}`)
                        }
                        if (row.extra.distribution_type === 'platform') {
                            parts.push('平台收益')
                        } else if (row.extra.distribution_type !== 'platform') {
                            parts.push('卖家收益')
                        }
                        if (parts.length > 0) {
                            return `权益交割（${parts.join('，')}）`
                        }
                    }
                    return row.remark || '-'
                },
            },
            {
                label: '创建时间',
                prop: 'create_time',
                align: 'center',
                render: 'datetime',
                operator: 'RANGE',
                width: 170,
            },
            {
                label: '操作',
                align: 'center',
                width: 110,
                render: 'buttons',
                buttons: [
                    {
                        name: 'detail',
                        text: '详情',
                        type: 'primary',
                        icon: 'fa fa-info-circle',
                        render: 'basicButton',
                        click: (row: any) => onShowDetail(row),
                    },
                ],
                operator: false,
            },
        ],
    }
)

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

<style scoped lang="scss">
.extra-title {
    margin: 16px 0 8px;
    font-size: 14px;
    font-weight: 600;
}

.reward-value {
    color: #67c23a;
    font-weight: 500;
    font-size: 14px;
}
</style>
