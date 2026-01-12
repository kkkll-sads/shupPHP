<template>
    <!-- 对话框表单 -->
    <el-dialog
        class="ba-operate-dialog"
        :close-on-click-modal="false"
        :destroy-on-close="true"
        :model-value="['Add', 'Edit'].includes(baTable.form.operate!)"
        @close="baTable.toggleForm"
    >
        <template #header>
            <div class="title" v-drag="['.ba-operate-dialog', '.el-dialog__header']" v-zoom="'.ba-operate-dialog'">
                {{ baTable.form.operate ? t(baTable.form.operate) : '' }}
            </div>
        </template>
        <el-scrollbar v-loading="baTable.form.loading" class="ba-table-form-scrollbar">
            <div
                class="ba-operate-form"
                :class="'ba-' + baTable.form.operate + '-form'"
                :style="config.layout.shrink ? '' : 'width: calc(100% - ' + baTable.form.labelWidth! / 2 + 'px)'"
            >
                <el-form
                    ref="formRef"
                    @keyup.enter="baTable.onSubmit(formRef)"
                    :model="baTable.form.items"
                    :label-position="config.layout.shrink ? 'top' : 'right'"
                    :label-width="baTable.form.labelWidth + 'px'"
                    :rules="rules"
                    v-if="!baTable.form.loading"
                >
                    <el-form-item prop="type" label="账户类型">
                        <el-select
                            v-model="baTable.form.items!.type"
                            placeholder="请选择账户类型"
                            @change="handleTypeChange"
                        >
                            <el-option label="银行卡" value="bank_card" />
                            <el-option label="支付宝" value="alipay" />
                            <el-option label="微信" value="wechat" />
                            <el-option label="USDT" value="usdt" />
                        </el-select>
                    </el-form-item>
                    <el-form-item prop="account_name" label="账户名">
                        <el-input
                            v-model="baTable.form.items!.account_name"
                            placeholder="请输入账户名/持卡人姓名"
                        ></el-input>
                    </el-form-item>
                    <el-form-item prop="account_number" label="账号/卡号/地址">
                        <el-input
                            v-model="baTable.form.items!.account_number"
                            placeholder="请输入账号/卡号/USDT地址"
                        ></el-input>
                    </el-form-item>
                    <el-form-item
                        prop="bank_name"
                        label="银行名称"
                        v-if="baTable.form.items!.type === 'bank_card'"
                    >
                        <el-input
                            v-model="baTable.form.items!.bank_name"
                            placeholder="请输入银行名称"
                        ></el-input>
                    </el-form-item>
                    <el-form-item
                        prop="bank_branch"
                        :label="baTable.form.items!.type === 'usdt' ? 'USDT网络类型' : '开户行'"
                        v-if="baTable.form.items!.type === 'bank_card' || baTable.form.items!.type === 'usdt'"
                    >
                        <el-select
                            v-if="baTable.form.items!.type === 'usdt'"
                            v-model="baTable.form.items!.bank_branch"
                            placeholder="请选择USDT网络类型"
                        >
                            <el-option label="TRC20" value="TRC20" />
                            <el-option label="ERC20" value="ERC20" />
                            <el-option label="BEP20" value="BEP20" />
                            <el-option label="OMNI" value="OMNI" />
                        </el-select>
                        <el-input
                            v-else
                            v-model="baTable.form.items!.bank_branch"
                            placeholder="请输入开户行"
                        ></el-input>
                    </el-form-item>
                    <el-form-item prop="icon" label="支付图标">
                        <FormItem type="image" v-model="baTable.form.items!.icon" />
                        <div class="form-tip">建议上传正方形图标，尺寸64x64像素，支持jpg、png、gif格式</div>
                    </el-form-item>
                    <el-form-item prop="qrcode" label="收款二维码" v-if="baTable.form.items!.type !== 'bank_card'">
                        <FormItem type="image" v-model="baTable.form.items!.qrcode" />
                    </el-form-item>
                    <el-form-item prop="status" label="状态">
                        <el-radio-group v-model="baTable.form.items!.status">
                            <el-radio :label="1">充值可用</el-radio>
                            <el-radio :label="2">提现可用</el-radio>
                            <el-radio :label="3">充值提现可用</el-radio>
                            <el-radio :label="0">关闭</el-radio>
                        </el-radio-group>
                    </el-form-item>
                    <el-form-item prop="category" label="分类标签">
                        <el-select
                            v-model="baTable.form.items!.category"
                            multiple
                            placeholder="请选择分类标签（可多选）"
                            style="width: 100%"
                        >
                            <el-option label="推荐通道" value="recommended" />
                            <el-option label="快速到账" value="fast" />
                            <el-option label="大额通道" value="large_amount" />
                            <el-option label="小额通道" value="small_amount" />
                            <el-option label="备用通道" value="backup" />
                            <el-option label="测试通道" value="test" />
                        </el-select>
                        <div class="form-tip">用于标记账户特性，便于筛选和管理</div>
                    </el-form-item>
                    <el-form-item prop="sort" label="排序">
                        <el-input-number
                            v-model="baTable.form.items!.sort"
                            :min="0"
                            :max="9999"
                            placeholder="排序值，数字越大越靠前"
                        ></el-input-number>
                    </el-form-item>
                    <el-form-item prop="remark" label="备注">
                        <el-input
                            v-model="baTable.form.items!.remark"
                            type="textarea"
                            :rows="4"
                            placeholder="请输入备注"
                            maxlength="500"
                            show-word-limit
                        ></el-input>
                    </el-form-item>
                </el-form>
            </div>
        </el-scrollbar>
        <template #footer>
            <div :style="'width: calc(100% - ' + baTable.form.labelWidth! / 1.8 + 'px)'">
                <el-button @click="baTable.toggleForm('')">{{ t('Cancel') }}</el-button>
                <el-button v-blur :loading="baTable.form.submitLoading" @click="baTable.onSubmit(formRef)" type="primary">
                    {{ baTable.form.operateIds && baTable.form.operateIds.length > 1 ? t('Save and edit next item') : t('Save') }}
                </el-button>
            </div>
        </template>
    </el-dialog>
</template>

<script setup lang="ts">
import { reactive, inject, useTemplateRef } from 'vue'
import { useI18n } from 'vue-i18n'
import type baTableClass from '/@/utils/baTable'
import type { FormItemRule } from 'element-plus'
import FormItem from '/@/components/formItem/index.vue'
import { buildValidatorData } from '/@/utils/validate'
import { useConfig } from '/@/stores/config'

const config = useConfig()
const formRef = useTemplateRef('formRef')
const baTable = inject('baTable') as baTableClass

const { t } = useI18n()

const rules: Partial<Record<string, FormItemRule[]>> = reactive({
    type: [buildValidatorData({ name: 'required', title: '账户类型' })],
    account_name: [buildValidatorData({ name: 'required', title: '账户名' })],
    account_number: [buildValidatorData({ name: 'required', title: '账号/卡号/地址' })],
    status: [buildValidatorData({ name: 'required', title: '状态' })],
})

// 处理账户类型变化
const handleTypeChange = () => {
    // 切换类型时清空相关字段
    if (baTable.form.items!.type !== 'bank_card') {
        baTable.form.items!.bank_name = ''
    }
    if (baTable.form.items!.type !== 'usdt' && baTable.form.items!.type !== 'bank_card') {
        baTable.form.items!.bank_branch = ''
    }
}
</script>

<style scoped lang="scss"></style>

