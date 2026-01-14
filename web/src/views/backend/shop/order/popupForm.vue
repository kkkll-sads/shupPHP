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
                            <span v-else-if="baTable.form.items?.pay_type === 'score'">
                                {{ baTable.form.items?.total_score }}消费金
                            </span>
                            <span v-else>
                                {{ Number(baTable.form.items?.total_amount).toFixed(2) }}元 + {{ baTable.form.items?.total_score }}消费金
                            </span>
                        </el-descriptions-item>
                        <el-descriptions-item label="支付方式">
                            <el-tag v-if="baTable.form.items?.pay_type === 'money'" type="success">余额支付</el-tag>
                            <el-tag v-else-if="baTable.form.items?.pay_type === 'score'" type="warning">消费金支付</el-tag>
                            <el-tag v-else type="info">组合支付</el-tag>
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
                        <el-descriptions-item label="发货时间">
                            {{ formatTime(baTable.form.items?.ship_time) }}
                        </el-descriptions-item>
                        <el-descriptions-item label="用户备注" :span="2">
                            {{ baTable.form.items?.remark || '无' }}
                        </el-descriptions-item>
                    </el-descriptions>
                </el-card>

                <!-- 收货信息 (仅实物商品) -->
                <el-card class="box-card" style="margin-bottom: 20px;" v-if="baTable.form.items?.recipient_name && hasPhysicalProduct">
                    <template #header>
                        <div class="card-header">
                            <span>收货信息</span>
                        </div>
                    </template>
                    <el-descriptions :column="2" border>
                        <el-descriptions-item label="收货人">{{ baTable.form.items?.recipient_name }}</el-descriptions-item>
                        <el-descriptions-item label="收货电话">{{ baTable.form.items?.recipient_phone }}</el-descriptions-item>
                        <el-descriptions-item label="收货地址" :span="2">
                            {{ baTable.form.items?.recipient_address }}
                        </el-descriptions-item>
                    </el-descriptions>
                </el-card>

                <!-- 商品信息 -->
                <el-card class="box-card" style="margin-bottom: 20px;">
                    <template #header>
                        <div class="card-header">
                            <span>商品信息</span>
                        </div>
                    </template>
                    <el-table :data="baTable.form.items?.items" border style="width: 100%">
                        <el-table-column label="商品图片" width="100">
                            <template #default="scope">
                                <el-image
                                    v-if="scope.row.product_thumbnail"
                                    :src="scope.row.product_thumbnail"
                                    :preview-src-list="[scope.row.product_thumbnail]"
                                    style="width: 60px; height: 60px;"
                                    fit="cover"
                                />
                            </template>
                        </el-table-column>
                        <el-table-column prop="product_name" label="商品名称" />
                        <el-table-column label="商品类型" width="100">
                            <template #default="scope">
                                <el-tag v-if="scope.row.is_physical == '1'" type="success">实物</el-tag>
                                <el-tag v-else-if="scope.row.is_card_product == '1'" type="warning">卡密</el-tag>
                                <el-tag v-else type="primary">虚拟</el-tag>
                            </template>
                        </el-table-column>
                        <el-table-column label="单价" width="120">
                            <template #default="scope">
                                <span v-if="baTable.form.items?.pay_type === 'money'">
                                    {{ Number(scope.row.price).toFixed(2) }}元
                                </span>
                                <span v-else-if="baTable.form.items?.pay_type === 'score'">
                                    {{ scope.row.score_price }}消费金
                                </span>
                                <span v-else>
                                    {{ Number(scope.row.price).toFixed(2) }}元 + {{ scope.row.score_price }}消费金
                                </span>
                            </template>
                        </el-table-column>
                        <el-table-column prop="quantity" label="数量" width="100" />
                        <el-table-column label="小计" width="120">
                            <template #default="scope">
                                <span v-if="baTable.form.items?.pay_type === 'money'">
                                    {{ Number(scope.row.subtotal).toFixed(2) }}元
                                </span>
                                <span v-else-if="baTable.form.items?.pay_type === 'score'">
                                    {{ scope.row.subtotal_score }}消费金
                                </span>
                                <span v-else>
                                    {{ Number(scope.row.subtotal).toFixed(2) }}元 + {{ scope.row.subtotal_score }}消费金
                                </span>
                            </template>
                        </el-table-column>
                        <el-table-column label="虚拟产品信息" width="200">
                            <template #default="scope">
                                <div v-if="scope.row.is_physical == '0'">
                                    <!-- 卡密商品 -->
                                    <div v-if="scope.row.is_card_product == '1'">
                                        <el-tag v-if="baTable.form.items?.status === 'shipped' || baTable.form.items?.status === 'completed'" 
                                                type="success" effect="dark">
                                            已发卡
                                        </el-tag>
                                        <el-tag v-else type="info">待发卡</el-tag>
                                    </div>
                                    <!-- 普通虚拟商品 -->
                                    <div v-else>
                                        <el-tag v-if="baTable.form.items?.status === 'paid' || baTable.form.items?.status === 'completed'" 
                                                type="success" effect="dark">
                                            已成功充值
                                        </el-tag>
                                    </div>
                                </div>
                                <span v-else>-</span>
                            </template>
                        </el-table-column>
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
                            <el-option label="已发货" value="shipped" />
                            <el-option label="已完成" value="completed" />
                            <el-option label="已取消" value="cancelled" />
                            <el-option label="已退款" value="refunded" />
                        </el-select>
                    </el-form-item>

                    <el-form-item label="物流公司" prop="shipping_company" v-if="hasPhysicalProduct">
                        <el-input
                            v-model="baTable.form.items!.shipping_company"
                            placeholder="请输入物流公司"
                        />
                    </el-form-item>

                    <el-form-item label="物流单号" prop="shipping_no" v-if="hasPhysicalProduct">
                        <el-input
                            v-model="baTable.form.items!.shipping_no"
                            placeholder="请输入物流单号"
                        />
                    </el-form-item>

                    <el-form-item label="管理员备注" prop="admin_remark">
                        <el-input
                            v-model="baTable.form.items!.admin_remark"
                            type="textarea"
                            :rows="3"
                            :placeholder="hasCardProduct ? '请输入卡密信息（填写后订单将自动标记为已发货）' : '请输入管理员备注'"
                        />
                        <el-alert 
                            v-if="hasCardProduct && baTable.form.items?.status === 'paid'"
                            type="warning" 
                            :closable="false"
                            style="margin-top: 10px;">
                            <template #title>
                                <span style="font-size: 12px;">💡 提示：此订单包含卡密商品，填写备注信息后保存将自动更新为"已发货"状态</span>
                            </template>
                        </el-alert>
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
import { inject, ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'

const baTable = inject<any>('baTable')
const { t } = useI18n()
const formRef = ref()

// 判断订单中是否有实物商品
const hasPhysicalProduct = computed(() => {
    if (!baTable.form.items?.items) return false
    return baTable.form.items.items.some((item: any) => item.is_physical == '1')
})

// 判断订单中是否有卡密商品
const hasCardProduct = computed(() => {
    if (!baTable.form.items?.items) return false
    return baTable.form.items.items.some((item: any) => item.is_card_product == '1')
})

const getStatusText = (status: string) => {
    const map: any = {
        pending: '待支付',
        paid: '已支付',
        shipped: '已发货',
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
        shipped: 'primary',
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

