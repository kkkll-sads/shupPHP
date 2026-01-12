<template>
    <div class="sign-in-page" v-loading="state.loading">
        <el-row :gutter="20">
            <el-col :xs="24" :sm="12">
                <el-card shadow="hover" class="stats-card">
                    <template #header>
                        <span>{{ t('user.account.signIn.Stats title') }}</span>
                    </template>
                    <div class="stats-body">
                        <div class="stat-item">
                            <div class="stat-title">{{ t('user.account.signIn.Total points') }}</div>
                            <div class="stat-value">{{ info?.total_reward ?? 0 }}</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-title">{{ t('user.account.signIn.Today points') }}</div>
                            <div class="stat-value">{{ info?.today_reward ?? 0 }}</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-title">{{ t('user.account.signIn.Streak days') }}</div>
                            <div class="stat-value">{{ info?.streak ?? 0 }}</div>
                        </div>
                    </div>
                    <div class="stats-footer">
                        <span>{{
                            t('user.account.signIn.Sign days summary', {
                                days: info?.sign_days ?? 0,
                            })
                        }}</span>
                    </div>
                </el-card>
            </el-col>
            <el-col :xs="24" :sm="12">
                <el-card shadow="hover" class="action-card">
                    <template #header>
                        <span>{{ t('user.account.signIn.Action title') }}</span>
                    </template>
                    <div class="action-body">
                        <div class="action-info">
                            <p class="action-status">
                                <Icon
                                    :name="info?.today_signed ? 'fa fa-check-circle' : 'fa fa-clock-o'"
                                    size="22"
                                    :color="info?.today_signed ? 'var(--el-color-success)' : 'var(--el-color-primary)'"
                                />
                                <span>{{ signStatusText }}</span>
                            </p>
                            <p class="action-tip">
                                {{
                                    t('user.account.signIn.Daily reward tip', {
                                        score: info?.daily_reward ?? 0,
                                    })
                                }}
                            </p>
                            <p class="action-tip">
                                {{
                                    t('user.account.signIn.Referrer reward tip', {
                                        score: info?.config?.referrer_reward ?? 0,
                                    })
                                }}
                            </p>
                        </div>
                        <el-button type="primary" size="large" :loading="state.signLoading" :disabled="!!info?.today_signed" @click="handleSign">
                            {{ signButtonText }}
                        </el-button>
                    </div>
                </el-card>
            </el-col>
        </el-row>

        <el-card shadow="hover" class="calendar-card">
            <template #header>
                <div class="calendar-header">
                    <span>{{ t('user.account.signIn.Calendar title') }}</span>
                    <div class="calendar-toolbar">
                        <el-button round @click="changeMonth(-1)" :disabled="!canChangeMonth(-1)">
                            <Icon name="fa fa-angle-left" />
                        </el-button>
                        <span class="calendar-current">{{ displayMonth }}</span>
                        <el-button round @click="changeMonth(1)" :disabled="!canChangeMonth(1)">
                            <Icon name="fa fa-angle-right" />
                        </el-button>
                    </div>
                </div>
            </template>
            <el-calendar
                ref="calendarRef"
                v-model="state.calendarDate"
                :range="[calendarRange.start, calendarRange.end]"
                @panel-change="onPanelChange"
            >
                <template #date-cell="{ data }">
                    <div
                        class="calendar-cell"
                        :class="{
                            'is-selected': signedDateSet.has(data.day),
                            'is-today': data.isToday,
                        }"
                    >
                        <span class="calendar-day">{{ data.day.split('-').pop() }}</span>
                    </div>
                </template>
            </el-calendar>
        </el-card>

        <el-card shadow="hover" class="records-card">
            <template #header>
                <span>{{ t('user.account.signIn.Record title') }}</span>
            </template>
            <el-table :data="state.records" v-loading="state.recordsLoading" :empty-text="t('No data')">
                <el-table-column prop="date" :label="t('user.account.signIn.Record date')" width="160" />
                <el-table-column prop="reward_score" :label="t('user.account.signIn.Record reward')" width="160" align="center" />
                <el-table-column
                    prop="create_time"
                    :label="t('user.account.signIn.Record time')"
                    :formatter="(_, __, value) => timeFormat(value)"
                    min-width="180"
                />
            </el-table>
            <div class="pagination" v-if="state.total > state.pageSize">
                <el-pagination
                    layout="prev, pager, next"
                    :total="state.total"
                    :page-size="state.pageSize"
                    :current-page="state.page"
                    @current-change="onPageChange"
                    small
                />
            </div>
        </el-card>

        <el-dialog v-model="state.successVisible" :title="t('user.account.signIn.Success title')" width="360px" :close-on-click-modal="false">
            <div class="success-dialog">
                <Icon name="fa fa-gift" size="36" color="var(--el-color-warning)" />
                <p class="success-text">
                    {{
                        t('user.account.signIn.Success reward', {
                            score: state.successInfo.reward,
                        })
                    }}
                </p>
                <p v-if="state.successInfo.referrerReward > 0" class="success-tip">
                    {{
                        t('user.account.signIn.Success referrer', {
                            score: state.successInfo.referrerReward,
                        })
                    }}
                </p>
            </div>
            <template #footer>
                <el-button type="primary" @click="state.successVisible = false">{{ t('Confirm') }}</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage, dayjs } from 'element-plus'
import { timeFormat } from '/@/utils/common'
import { getSignInInfo, postSignIn, getSignInRecords } from '/@/api/frontend/user/index'
import { useUserInfo } from '/@/stores/userInfo'

interface SignInRecord {
    id: number
    date: string
    reward_score: number
    create_time: number
}

interface SignInStatistics {
    today_signed: boolean
    today_reward: number
    daily_reward: number
    total_reward: number
    sign_days: number
    streak: number
    calendar: {
        start: string
        end: string
        signed_dates: string[]
        records: { date: string; reward_score: number; record_id: number }[]
    }
    recent_records: SignInRecord[]
    config: {
        daily_reward: number
        referrer_reward: number
    }
    referrer_reward?: number
    sign_record_id?: number
    sign_date?: string
}

const { t } = useI18n()
const userInfo = useUserInfo()

const info = ref<SignInStatistics | null>(null)
const signedDates = ref<string[]>([])
const calendarRef = ref()

const state = reactive({
    loading: false,
    signLoading: false,
    calendarDate: dayjs().format('YYYY-MM-DD'),
    records: [] as SignInRecord[],
    recordsLoading: false,
    page: 1,
    pageSize: 10,
    total: 0,
    successVisible: false,
    successInfo: {
        reward: 0,
        referrerReward: 0,
    },
})

const signedDateSet = computed(() => {
    return new Set(signedDates.value)
})

const calendarRange = computed(() => {
    if (!info.value?.calendar) {
        const current = dayjs().startOf('month').format('YYYY-MM-DD')
        return { start: current, end: dayjs().endOf('month').format('YYYY-MM-DD') }
    }
    return {
        start: info.value.calendar.start,
        end: info.value.calendar.end,
    }
})

const displayMonth = computed(() => dayjs(state.calendarDate).format('YYYY-MM'))
const signStatusText = computed(() => {
    return info.value?.today_signed ? t('user.account.signIn.Signed today') : t('user.account.signIn.Not signed today')
})
const signButtonText = computed(() => {
    return info.value?.today_signed ? t('user.account.signIn.Signed today') : t('user.account.signIn.Sign now')
})

const loadInfo = async () => {
    state.loading = true
    try {
        const res = await getSignInInfo()
        info.value = res.data
        signedDates.value = res.data.calendar?.signed_dates ?? []
        state.calendarDate = dayjs().format('YYYY-MM-DD')
    } finally {
        state.loading = false
    }
}

const loadRecords = async () => {
    state.recordsLoading = true
    try {
        const res = await getSignInRecords(state.page, state.pageSize)
        state.records = res.data.records || []
        state.total = res.data.total || 0
    } finally {
        state.recordsLoading = false
    }
}

const handleSign = async () => {
    if (state.signLoading) return
    state.signLoading = true
    try {
        const res = await postSignIn()
        info.value = res.data
        signedDates.value = res.data.calendar?.signed_dates ?? []
        state.calendarDate = res.data.sign_date ?? state.calendarDate
        state.successInfo.reward = res.data.today_reward ?? 0
        state.successInfo.referrerReward = res.data.referrer_reward ?? 0
        state.successVisible = true
        if (res.data.today_reward) {
            userInfo.dataFill({
                score: userInfo.score + res.data.today_reward,
            })
        }
        ElMessage.success(res.msg || t('user.account.signIn.Success title'))
        await loadRecords()
    } finally {
        state.signLoading = false
    }
}

const changeMonth = (offset: number) => {
    if (!canChangeMonth(offset)) return
    state.calendarDate = dayjs(state.calendarDate).add(offset, 'month').format('YYYY-MM-DD')
}

const canChangeMonth = (offset: number) => {
    if (!info.value?.calendar) return true
    const target = dayjs(state.calendarDate).add(offset, 'month')
    const min = dayjs(info.value.calendar.start)
    const max = dayjs(info.value.calendar.end)
    const targetMonth = target.year() * 12 + target.month()
    const minMonth = min.year() * 12 + min.month()
    const maxMonth = max.year() * 12 + max.month()
    return targetMonth >= minMonth && targetMonth <= maxMonth
}

const onPanelChange = ({ date }: { date: Date }) => {
    state.calendarDate = dayjs(date).format('YYYY-MM-DD')
}

const onPageChange = (page: number) => {
    state.page = page
    loadRecords()
}

onMounted(async () => {
    await loadInfo()
    await loadRecords()
})
</script>

<style scoped lang="scss">
.sign-in-page {
    display: flex;
    flex-direction: column;
    gap: 20px;
}
.stats-card,
.action-card,
.calendar-card,
.records-card {
    width: 100%;
}
.stats-body {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 20px;
}
.stat-item {
    flex: 1;
    min-width: 120px;
    background-color: var(--el-color-info-light-9);
    padding: 16px;
    border-radius: 8px;
    text-align: center;
}
.stat-title {
    color: var(--el-text-color-secondary);
    margin-bottom: 6px;
    font-size: var(--el-font-size-small);
}
.stat-value {
    font-size: 24px;
    font-weight: bold;
    color: var(--el-text-color-primary);
}
.stats-footer {
    margin-top: 10px;
    color: var(--el-text-color-secondary);
}
.action-body {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.action-status {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 18px;
    margin: 0;
}
.action-tip {
    margin: 0;
    color: var(--el-text-color-secondary);
    font-size: var(--el-font-size-base);
}
.calendar-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.calendar-toolbar {
    display: flex;
    align-items: center;
    gap: 10px;
}
.calendar-current {
    font-weight: 600;
    font-size: 16px;
}
.calendar-cell {
    width: 100%;
    height: 70px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    transition: background-color 0.2s ease;
}
.calendar-cell.is-selected {
    background-color: var(--el-color-primary-light-7);
    color: var(--el-color-primary);
    font-weight: 600;
}
.calendar-cell.is-today {
    border: 1px solid var(--el-color-primary);
}
.calendar-day {
    font-size: 16px;
}
.records-card .pagination {
    display: flex;
    justify-content: flex-end;
    padding-top: 16px;
}
.success-dialog {
    text-align: center;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.success-text {
    font-size: 20px;
    color: var(--el-color-success);
    margin: 0;
}
.success-tip {
    margin: 0;
    color: var(--el-text-color-secondary);
}
@media screen and (max-width: 768px) {
    .stats-body {
        flex-direction: column;
    }
    .calendar-cell {
        height: 60px;
    }
}
</style>
