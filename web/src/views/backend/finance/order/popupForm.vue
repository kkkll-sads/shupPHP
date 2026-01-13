<template>
    <el-dialog
        class="ba-operate-dialog"
        :close-on-click-modal="false"
        :destroy-on-close="true"
        :model-value="baTable.form.operate === 'Edit'"
        @close="baTable.toggleForm"
        width="50%"
    >
        <template #header>
            <div class="title" v-drag="['.ba-operate-dialog', '.el-dialog__header']" v-zoom="'.ba-operate-dialog'">
                订单详情
            </div>
        </template>
        <el-scrollbar v-loading="baTable.form.loading" class="ba-table-form-scrollbar">
            <div class="ba-operate-form">
                <el-form :model="baTable.form.items" label-position="right" label-width="120px" v-if="!baTable.form.loading">
                    <!-- 基本信息 -->
                    <el-divider content-position="left">订单信息</el-divider>
                    <el-row :gutter="20">
                        <el-col :span="12">
                            <el-form-item label="订单号">
                                <span class="order-info-text">{{ baTable.form.items?.order_no || '--' }}</span>
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="订单状态">
                                <el-tag :type="getStatusType(baTable.form.items?.status)" effect="plain">
                                    {{ baTable.form.items?.status_text || '--' }}
                                </el-tag>
                            </el-form-item>
                        </el-col>
                    </el-row>

                    <el-row :gutter="20">
                        <el-col :span="12">
                            <el-form-item label="用户">
                                <span class="order-info-text">{{ baTable.form.items?.user_nickname || '--' }}</span>
                                <span v-if="baTable.form.items?.user_mobile" class="order-info-secondary">
                                    ({{ baTable.form.items.user_mobile }})
                                </span>
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="支付方式">
                                <span class="order-info-text">{{ baTable.form.items?.payment_channel_text || '--' }}</span>
                            </el-form-item>
                        </el-col>
                    </el-row>

                    <!-- 产品信息 -->
                    <el-divider content-position="left">产品信息</el-divider>
                    <el-row :gutter="20">
                        <el-col :span="24">
                            <el-form-item label="产品名称">
                                <span class="order-info-text">{{ baTable.form.items?.product_name || '--' }}</span>
                            </el-form-item>
                        </el-col>
                    </el-row>

                    <el-row :gutter="20">
                        <el-col :span="12">
                            <el-form-item label="购买金额">
                                <span class="order-info-amount">¥{{ formatAmount(baTable.form.items?.amount) }}</span>
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="年化收益率">
                                <span class="order-info-text">{{ baTable.form.items?.yield_rate || 0 }}%</span>
                            </el-form-item>
                        </el-col>
                    </el-row>

                    <el-row :gutter="20">
                        <el-col :span="12">
                            <el-form-item label="收益周期">
                                <span class="order-info-text">{{ baTable.form.items?.cycle_days || 0 }} 天</span>
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="预期收益">
                                <span class="order-info-income">¥{{ formatAmount(baTable.form.items?.expected_income) }}</span>
                            </el-form-item>
                        </el-col>
                    </el-row>

                    <!-- 时间信息 -->
                    <el-divider content-position="left">时间信息</el-divider>
                    <el-row :gutter="20">
                        <el-col :span="12">
                            <el-form-item label="创建时间">
                                <span class="order-info-text">{{ baTable.form.items?.create_time_text || '--' }}</span>
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="支付时间">
                                <span class="order-info-text">{{ baTable.form.items?.pay_time_text || '--' }}</span>
                            </el-form-item>
                        </el-col>
                    </el-row>

                    <el-row :gutter="20">
                        <el-col :span="12">
                            <el-form-item label="到期时间">
                                <span class="order-info-text">{{ baTable.form.items?.expire_time_text || '--' }}</span>
                            </el-form-item>
                        </el-col>
                        <el-col :span="12">
                            <el-form-item label="完成时间">
                                <span class="order-info-text">{{ baTable.form.items?.complete_time_text || '--' }}</span>
                            </el-form-item>
                        </el-col>
                    </el-row>

                    <!-- 备注信息 -->
                    <el-divider content-position="left" v-if="baTable.form.items?.remark">备注信息</el-divider>
                    <el-row :gutter="20" v-if="baTable.form.items?.remark">
                        <el-col :span="24">
                            <el-form-item label="备注">
                                <span class="order-info-text">{{ baTable.form.items.remark }}</span>
                            </el-form-item>
                        </el-col>
                    </el-row>
                </el-form>
            </div>
        </el-scrollbar>
        <template #footer>
            <div class="dialog-footer">
                <el-button @click="baTable.toggleForm()">关闭</el-button>
            </div>
        </template>
    </el-dialog>
</template>

<script setup lang="ts">
import { inject } from 'vue'
import type baTableClass from '/@/utils/baTable'

const baTable = inject('baTable') as baTableClass

const formatAmount = (amount: any) => {
    const num = Number(amount || 0)
    return isNaN(num) ? '0.00' : num.toFixed(2)
}

const getStatusType = (status: string) => {
    const statusMap: Record<string, any> = {
        pending: 'info',
        paying: 'warning',
        paid: 'primary',
        earning: 'success',
        completed: '',
        cancelled: 'danger',
        refunded: 'warning',
    }
    return statusMap[status] || 'info'
}
</script>

<style scoped lang="scss">
.order-info-text {
    font-size: 14px;
    color: #303133;
}

.order-info-secondary {
    font-size: 12px;
    color: #909399;
    margin-left: 8px;
}

.order-info-amount {
    font-size: 16px;
    font-weight: bold;
    color: #409EFF;
}

.order-info-income {
    font-size: 16px;
    font-weight: bold;
    color: #67C23A;
}

.el-divider {
    margin: 20px 0;
}

.dialog-footer {
    display: flex;
    justify-content: flex-end;
}
</style>

