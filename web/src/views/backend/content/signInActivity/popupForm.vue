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
                {{ baTable.form.operate === 'Add' ? '添加签到活动' : baTable.form.operate === 'Edit' ? '编辑签到活动' : '' }}
            </div>
        </template>
        <el-scrollbar v-loading="baTable.form.loading" class="ba-table-form-scrollbar">
            <div
                class="ba-operate-form"
                :class="'ba-' + baTable.form.operate + '-form'"
                :style="config?.layout?.shrink ? '' : 'width: calc(100% - ' + (baTable.form.labelWidth || 160) / 2 + 'px)'"
            >
                <el-form
                    ref="formRef"
                    @keyup.enter="baTable.onSubmit(formRef)"
                    :model="baTable.form.items"
                    :label-position="config?.layout?.shrink ? 'top' : 'right'"
                    :label-width="(baTable.form.labelWidth || 160) + 'px'"
                    :rules="rules"
                    v-if="!baTable.form.loading"
                >
                    <el-form-item prop="name" label="活动名称">
                        <el-input
                            v-model="baTable.form.items!.name"
                            type="string"
                            placeholder="请输入活动名称"
                            maxlength="255"
                            show-word-limit
                        ></el-input>
                    </el-form-item>

                    <el-form-item prop="start_time" label="开始时间">
                        <el-date-picker
                            class="w100"
                            value-format="YYYY-MM-DD HH:mm:ss"
                            v-model="baTable.form.items!.start_time"
                            type="datetime"
                            placeholder="请选择开始时间"
                        />
                    </el-form-item>

                    <el-form-item prop="end_time" label="结束时间">
                        <el-date-picker
                            class="w100"
                            value-format="YYYY-MM-DD HH:mm:ss"
                            v-model="baTable.form.items!.end_time"
                            type="datetime"
                            placeholder="请选择结束时间"
                        />
                        <div class="form-item-tip">活动结束时间，建议设置为较远的日期以保持活动长期有效</div>
                    </el-form-item>

                    <el-form-item prop="fund_source" label="资金来源">
                        <el-input
                            v-model="baTable.form.items!.fund_source"
                            type="string"
                            placeholder="请输入资金来源说明"
                            maxlength="255"
                            show-word-limit
                        ></el-input>
                        <div class="form-item-tip">例如：集团公司专项营销资金</div>
                    </el-form-item>

                    <el-form-item prop="sign_reward_min" label="签到奖励最小金额（元）">
                        <el-input-number
                            v-model="baTable.form.items!.sign_reward_min"
                            :min="0"
                            :max="9999.99"
                            :precision="2"
                            :step="0.01"
                            controls-position="right"
                            placeholder="请输入最小金额"
                            style="width: 100%"
                        />
                        <div class="form-item-tip">每日签到奖励的随机范围最小值，建议设置为0.20元</div>
                    </el-form-item>

                    <el-form-item prop="sign_reward_max" label="签到奖励最大金额（元）">
                        <el-input-number
                            v-model="baTable.form.items!.sign_reward_max"
                            :min="0"
                            :max="9999.99"
                            :precision="2"
                            :step="0.01"
                            controls-position="right"
                            placeholder="请输入最大金额"
                            style="width: 100%"
                        />
                        <div class="form-item-tip">每日签到奖励的随机范围最大值，建议设置为0.50元</div>
                    </el-form-item>

                    <el-form-item prop="invite_reward_min" label="邀请奖励最小金额（元）">
                        <el-input-number
                            v-model="baTable.form.items!.invite_reward_min"
                            :min="0"
                            :max="9999.99"
                            :precision="2"
                            :step="0.01"
                            controls-position="right"
                            placeholder="请输入最小金额"
                            style="width: 100%"
                        />
                        <div class="form-item-tip">邀请好友奖励的随机范围最小值，建议设置为1.50元</div>
                    </el-form-item>

                    <el-form-item prop="invite_reward_max" label="邀请奖励最大金额（元）">
                        <el-input-number
                            v-model="baTable.form.items!.invite_reward_max"
                            :min="0"
                            :max="9999.99"
                            :precision="2"
                            :step="0.01"
                            controls-position="right"
                            placeholder="请输入最大金额"
                            style="width: 100%"
                        />
                        <div class="form-item-tip">邀请好友奖励的随机范围最大值，建议设置为2.00元</div>
                    </el-form-item>

                    <FormItem
                        label="状态"
                        v-model="baTable.form.items!.status"
                        type="radio"
                        prop="status"
                        :input-attr="{
                            border: true,
                            content: { '1': '启用', '0': '禁用' },
                        }"
                    />

                    <el-form-item prop="remark" label="备注说明">
                        <el-input
                            v-model="baTable.form.items!.remark"
                            type="textarea"
                            :rows="4"
                            placeholder="请输入备注说明"
                            maxlength="500"
                            show-word-limit
                        ></el-input>
                        <div class="form-item-tip">活动的补充说明信息</div>
                    </el-form-item>
                </el-form>
            </div>
        </el-scrollbar>

        <template #footer>
            <div class="ba-operate-footer">
                <el-button @click="baTable.toggleForm">取消</el-button>
                <el-button
                    type="primary"
                    @click="baTable.onSubmit(formRef)"
                    :loading="baTable.form.loading"
                    :disabled="baTable.form.loading"
                >
                    确定
                </el-button>
            </div>
        </template>
    </el-dialog>
</template>

<script setup lang="ts">
import { inject, ref, reactive, watch } from 'vue'
import FormItem from '/@/components/formItem/index.vue'
import { useConfig } from '/@/stores/config'

const config = useConfig()

const baTable = inject<any>('baTable')!

const formRef = ref()

const rules = reactive({
    name: [
        { required: true, message: '请输入活动名称', trigger: 'blur' },
        { max: 255, message: '活动名称最大长度为255个字符', trigger: 'blur' },
    ],
    start_time: [
        { required: true, message: '请选择开始时间', trigger: 'change' },
    ],
    end_time: [
        { required: true, message: '请选择结束时间', trigger: 'change' },
    ],
    fund_source: [
        { required: true, message: '请输入资金来源说明', trigger: 'blur' },
        { max: 255, message: '资金来源说明最大长度为255个字符', trigger: 'blur' },
    ],
    sign_reward_min: [
        { required: true, message: '请输入签到奖励最小金额', trigger: 'blur' },
        { type: 'number', min: 0, max: 9999.99, message: '最小金额范围为0-9999.99元', trigger: 'change' },
    ],
    sign_reward_max: [
        { required: true, message: '请输入签到奖励最大金额', trigger: 'blur' },
        { type: 'number', min: 0, max: 9999.99, message: '最大金额范围为0-9999.99元', trigger: 'change' },
    ],
    invite_reward_min: [
        { required: true, message: '请输入邀请奖励最小金额', trigger: 'blur' },
        { type: 'number', min: 0, max: 9999.99, message: '最小金额范围为0-9999.99元', trigger: 'change' },
    ],
    invite_reward_max: [
        { required: true, message: '请输入邀请奖励最大金额', trigger: 'blur' },
        { type: 'number', min: 0, max: 9999.99, message: '最大金额范围为0-9999.99元', trigger: 'change' },
    ],
    status: [
        { required: true, message: '请选择状态', trigger: 'change' },
    ],
} as any)

// 监听签到奖励范围，确保最大值不小于最小值
watch(
    () => [baTable.form.items?.sign_reward_min, baTable.form.items?.sign_reward_max],
    ([min, max]) => {
        if (min !== undefined && max !== undefined && parseFloat(min) > parseFloat(max)) {
            // 如果最小值大于最大值，自动调整最大值
            baTable.form.items!.sign_reward_max = min
        }
    },
    { deep: true }
)

// 监听邀请奖励范围，确保最大值不小于最小值
watch(
    () => [baTable.form.items?.invite_reward_min, baTable.form.items?.invite_reward_max],
    ([min, max]) => {
        if (min !== undefined && max !== undefined && parseFloat(min) > parseFloat(max)) {
            // 如果最小值大于最大值，自动调整最大值
            baTable.form.items!.invite_reward_max = min
        }
    },
    { deep: true }
)

// 自定义验证：确保签到奖励最大值不小于最小值
const validateSignRewardRange = (rule: any, value: any, callback: any) => {
    const min = parseFloat(baTable.form.items?.sign_reward_min || 0)
    const max = parseFloat(value || 0)
    if (max < min) {
        callback(new Error('最大金额不能小于最小金额'))
    } else {
        callback()
    }
}

// 自定义验证：确保邀请奖励最大值不小于最小值
const validateInviteRewardRange = (rule: any, value: any, callback: any) => {
    const min = parseFloat(baTable.form.items?.invite_reward_min || 0)
    const max = parseFloat(value || 0)
    if (max < min) {
        callback(new Error('最大金额不能小于最小金额'))
    } else {
        callback()
    }
}

// 添加自定义验证规则
rules.sign_reward_max.push({ validator: validateSignRewardRange, trigger: 'change' } as any)
rules.invite_reward_max.push({ validator: validateInviteRewardRange, trigger: 'change' } as any)
</script>

<style scoped lang="scss">
.form-item-tip {
    font-size: 12px;
    color: #909399;
    margin-top: 4px;
    line-height: 1.4;
}
</style>

