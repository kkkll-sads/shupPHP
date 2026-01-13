<template>
    <el-dialog
        :model-value="baTable.form.operate === 'Edit'"
        :title="t(baTable.form.operate ? 'finance.incomeLog.' + baTable.form.operate : '')"
        destroy-on-close
        :close-on-click-modal="false"
        width="50%"
        @close="baTable.toggleForm"
    >
        <el-scrollbar v-loading="baTable.form.loading" class="ba-table-form-scrollbar">
            <div class="ba-table-form">
                <el-descriptions :column="2" border v-if="baTable.form.items">
                    <el-descriptions-item label="ID">{{ baTable.form.items.id }}</el-descriptions-item>
                    <el-descriptions-item label="订单号">{{ baTable.form.items.order_no || '--' }}</el-descriptions-item>
                    <el-descriptions-item label="用户">{{ baTable.form.items.user_nickname || '--' }}</el-descriptions-item>
                    <el-descriptions-item label="产品名称">{{ baTable.form.items.product_name || '--' }}</el-descriptions-item>
                    <el-descriptions-item label="返息类型">{{ baTable.form.items.income_type_text || '--' }}</el-descriptions-item>
                    <el-descriptions-item label="返息金额">
                        <span style="color: #67C23A; font-weight: bold;">
                            ¥{{ Number(baTable.form.items.income_amount || 0).toFixed(2) }}
                        </span>
                    </el-descriptions-item>
                    <el-descriptions-item label="返息日期">{{ baTable.form.items.income_date || '--' }}</el-descriptions-item>
                    <el-descriptions-item label="状态">
                        <el-tag :type="baTable.form.items.status == 1 ? 'success' : 'danger'">
                            {{ baTable.form.items.status_text || '--' }}
                        </el-tag>
                    </el-descriptions-item>
                    <el-descriptions-item label="周期/阶段" v-if="baTable.form.items.period_number || baTable.form.items.stage_info">
                        <span v-if="baTable.form.items.period_number">第{{ baTable.form.items.period_number }}周期</span>
                        <span v-else-if="baTable.form.items.stage_info">{{ baTable.form.items.stage_info }}天</span>
                        <span v-else>--</span>
                    </el-descriptions-item>
                    <el-descriptions-item label="发放时间">{{ baTable.form.items.settle_time_text || '--' }}</el-descriptions-item>
                    <el-descriptions-item label="备注" :span="2">{{ baTable.form.items.remark || '--' }}</el-descriptions-item>
                    <el-descriptions-item label="创建时间">{{ baTable.form.items.create_time_text || '--' }}</el-descriptions-item>
                </el-descriptions>
            </div>
        </el-scrollbar>
        <template #footer>
            <div class="dialog-footer">
                <el-button @click="baTable.toggleForm">{{ t('Close') }}</el-button>
            </div>
        </template>
    </el-dialog>
</template>

<script setup lang="ts">
import { inject } from 'vue'
import { useI18n } from 'vue-i18n'

const baTable = inject<any>('baTable')
const { t } = useI18n()
</script>

<style scoped lang="scss"></style>

