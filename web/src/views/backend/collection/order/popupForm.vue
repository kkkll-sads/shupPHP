<template>
    <el-dialog
        class="ba-operate-dialog"
        :close-on-click-modal="false"
        :destroy-on-close="true"
        :model-value="['Edit'].includes(baTable.form.operate!)"
        @close="baTable.toggleForm"
        width="70%"
    >
        <template #header>
            <div class="title" v-drag="['.ba-operate-dialog', '.el-dialog__header']" v-zoom="'.ba-operate-dialog'">
                订单详情
            </div>
        </template>
        <el-scrollbar v-loading="baTable.form.loading" class="ba-table-form-scrollbar">
            <div
                class="ba-operate-form"
                :class="'ba-' + baTable.form.operate + '-form'"
            >
                <!-- 订单信息 -->
                <el-card class="box-card" style="margin-bottom: 20px;">
                    <template #header>
                        <div class="card-header">
                            <span>订单信息</span>
                        </div>
                    </template>
                    <el-descriptions :column="2" border>
                        <el-descriptions-item label="订单号">{{ baTable.form.items?.order_no }}</el-descriptions-item>
                        <el-descriptions-item label="用户ID">{{ baTable.form.items?.user_id }}</el-descriptions-item>
                        <el-descriptions-item label="订单金额">
                            <span v-if="baTable.form.items?.pay_type === 'money'">
                                {{ Number(baTable.form.items?.total_amount).toFixed(2) }}元
                            </span>
                            <span v-else>
                                {{ baTable.form.items?.total_score }}积分
                            </span>
                        </el-descriptions-item>
                        <el-descriptions-item label="支付方式">
                            <el-tag v-if="baTable.form.items?.pay_type === 'money'" type="success">余额支付</el-tag>
                            <el-tag v-else type="warning">积分兑换</el-tag>
                        </el-descriptions-item>
                        <el-descriptions-item label="订单状态">
                            <el-tag :type="getStatusType(baTable.form.items?.status)">
                                {{ getStatusText(baTable.form.items?.status) }}
                            </el-tag>
                        </el-descriptions-item>
                        <el-descriptions-item label="创建时间">
                            {{ formatTime(baTable.form.items?.create_time) }}
                        </el-descriptions-item>
                        <el-descriptions-item label="支付时间">
                            {{ formatTime(baTable.form.items?.pay_time) }}
                        </el-descriptions-item>
                        <el-descriptions-item label="完成时间">
                            {{ formatTime(baTable.form.items?.complete_time) }}
                        </el-descriptions-item>
                        <el-descriptions-item label="用户备注" :span="2">
                            {{ baTable.form.items?.remark || '无' }}
                        </el-descriptions-item>
                    </el-descriptions>
                </el-card>

                <!-- 藏品信息 -->
                <el-card class="box-card" style="margin-bottom: 20px;">
                    <template #header>
                        <div class="card-header">
                            <span>藏品信息</span>
                        </div>
                    </template>
                    <el-table :data="baTable.form.items?.items" border style="width: 100%">
                        <el-table-column label="藏品图片" width="100">
                            <template #default="scope">
                                <el-image
                                    v-if="scope.row.item_image"
                                    :src="scope.row.item_image"
                                    :preview-src-list="[scope.row.item_image]"
                                    style="width: 60px; height: 60px;"
                                    fit="cover"
                                />
                            </template>
                        </el-table-column>
                        <el-table-column prop="item_title" label="藏品标题" />
                        <el-table-column label="单价" width="120">
                            <template #default="scope">
                                {{ Number(scope.row.price).toFixed(2) }}元
                            </template>
                        </el-table-column>
                        <el-table-column prop="quantity" label="数量" width="100" />
                        <el-table-column label="小计" width="120">
                            <template #default="scope">
                                {{ Number(scope.row.subtotal).toFixed(2) }}元
                            </template>
                        </el-table-column>
                        <el-table-column prop="product_id_record" label="产品ID记录" width="150" />
                    </el-table>
                </el-card>

                <!-- 编辑表单 -->
                <el-form
                    ref="formRef"
                    :model="baTable.form.items"
                    label-width="120px"
                    v-if="!baTable.form.loading"
                >
                    <el-form-item label="订单状态" prop="status">
                        <el-select v-model="baTable.form.items!.status" placeholder="请选择订单状态">
                            <el-option label="待支付" value="pending" />
                            <el-option label="已支付" value="paid" />
                            <el-option label="已完成" value="completed" />
                            <el-option label="已取消" value="cancelled" />
                            <el-option label="已退款" value="refunded" />
                        </el-select>
                    </el-form-item>

                    <el-form-item label="管理员备注" prop="remark">
                        <el-input
                            v-model="baTable.form.items!.remark"
                            type="textarea"
                            :rows="3"
                            placeholder="请输入管理员备注"
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
import { inject, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const baTable = inject<any>('baTable')
const { t } = useI18n()
const formRef = ref()

const getStatusText = (status: string) => {
    const map: any = {
        pending: '待支付',
        paid: '已支付',
        completed: '已完成',
        cancelled: '已取消',
        refunded: '已退款',
    }
    return map[status] || status
}

const getStatusType = (status: string) => {
    const map: any = {
        pending: 'info',
        paid: 'warning',
        completed: 'success',
        cancelled: 'info',
        refunded: 'danger',
    }
    return map[status] || ''
}

const formatTime = (timestamp: number) => {
    if (!timestamp || timestamp === 0) {
        return '-'
    }
    const date = new Date(timestamp * 1000)
    return date.toLocaleString('zh-CN', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
    })
}
</script>

<style scoped lang="scss">
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-weight: bold;
}
</style>

