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
                {{ baTable.form.operate === 'Add' ? '新增' : baTable.form.operate === 'Edit' ? '编辑' : '' }}
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
                    <el-form-item prop="username" label="用户名">
                        <el-input
                            v-model="baTable.form.items!.username"
                            type="string"
                            placeholder="请输入用户名（登录账户名）"
                        ></el-input>
                    </el-form-item>
                    <el-form-item prop="nickname" label="昵称">
                        <el-input
                            v-model="baTable.form.items!.nickname"
                            type="string"
                            placeholder="请输入昵称"
                        ></el-input>
                    </el-form-item>
                    <FormItem
                        type="remoteSelect"
                        label="分组"
                        v-model="baTable.form.items!.group_id"
                        prop="group_id"
                        placeholder="请选择分组"
                        :input-attr="{
                            params: { isTree: true, search: [{ field: 'status', val: '1', operator: 'eq' }] },
                            field: 'name',
                            remoteUrl: '/admin/user.Group/index',
                        }"
                    />
                    <FormItem label="头像" type="image" v-model="baTable.form.items!.avatar" />
                    <el-form-item prop="email" label="邮箱">
                        <el-input
                            v-model="baTable.form.items!.email"
                            type="string"
                            placeholder="请输入邮箱"
                        ></el-input>
                    </el-form-item>
                    <el-form-item prop="mobile" label="手机号">
                        <el-input
                            v-model="baTable.form.items!.mobile"
                            type="string"
                            placeholder="请输入手机号"
                        ></el-input>
                    </el-form-item>
                    <el-form-item v-if="baTable.form.operate == 'Edit'" label="邀请码">
                        <el-input
                            :model-value="baTable.form.items!.inviteCode?.code || ''"
                            type="text"
                            readonly
                            disabled
                            placeholder="邀请码"
                        ></el-input>
                    </el-form-item>
                    <FormItem
                        v-if="false"
                        label="性别"
                        v-model="baTable.form.items!.gender"
                        type="radio"
                        :input-attr="{
                            border: true,
                            content: { 0: '未知', 1: '男', 2: '女' },
                        }"
                    />
                    <el-form-item label="生日">
                        <el-date-picker
                            class="w100"
                            value-format="YYYY-MM-DD"
                            v-model="baTable.form.items!.birthday"
                            type="date"
                            placeholder="请选择生日"
                        />
                    </el-form-item>
                    <el-form-item v-if="baTable.form.operate == 'Edit'" label="总余额">
                        <el-input 
                            v-model="baTable.form.items!.money" 
                            type="number" 
                            step="0.01"
                            min="0"
                            placeholder="请输入总余额"
                        ></el-input>
                    </el-form-item>
                    <el-form-item v-if="baTable.form.operate == 'Edit'" label="可用余额 (专项金)">
                        <el-input 
                            v-model="baTable.form.items!.balance_available" 
                            type="number" 
                            step="0.01"
                            min="0"
                            placeholder="可用余额 (专项金)"
                        ></el-input>
                    </el-form-item>
                    <el-form-item v-if="baTable.form.operate == 'Edit'" label="可提现金额">
                        <el-input 
                            v-model="baTable.form.items!.withdrawable_money" 
                            type="number" 
                            step="0.01"
                            min="0"
                            placeholder="可提现金额"
                        ></el-input>
                    </el-form-item>
                    <el-form-item v-if="baTable.form.operate == 'Edit'" label="拓展提现">
                        <el-input 
                            v-model="baTable.form.items!.static_income" 
                            type="number" 
                            step="0.01"
                            min="0"
                            placeholder="拓展提现"
                        ></el-input>
                    </el-form-item>
                    <el-form-item v-if="baTable.form.operate == 'Edit'" label="确权金（交易手续费）">
                        <el-input 
                            v-model="baTable.form.items!.service_fee_balance" 
                            type="number" 
                            step="0.01"
                            min="0"
                            placeholder="确权金"
                        ></el-input>
                    </el-form-item>
                    <el-form-item v-if="baTable.form.operate == 'Edit'" label="寄售卷">
                        <el-input 
                            v-model="baTable.form.items!.consignment_coupon" 
                            type="number" 
                            step="1"
                            min="0"
                            placeholder="寄售卷"
                        ></el-input>
                    </el-form-item>
                    <el-form-item v-if="baTable.form.operate == 'Edit'" label="绿色算力">
                        <el-input
                            v-model="baTable.form.items!.green_power"
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="绿色算力"
                        ></el-input>
                    </el-form-item>
                    <FormItem
                        v-if="baTable.form.operate == 'Edit'"
                        label="旧资产状态"
                        v-model="baTable.form.items!.old_assets_status"
                        type="radio"
                        :input-attr="{
                            border: true,
                            content: { 0: '未解锁', 1: '已解锁' },
                        }"
                    />
                    <el-form-item v-if="baTable.form.operate == 'Edit'" label="解锁次数">
                        <el-input 
                            v-model="baTable.form.items!.old_assets_unlock_count" 
                            type="number" 
                            step="1"
                            min="0"
                            placeholder="旧资产解锁次数"
                        ></el-input>
                    </el-form-item>
                    <el-form-item v-if="baTable.form.operate == 'Edit'" label="额外解锁资格">
                        <el-input 
                            v-model="baTable.form.items!.bonus_unlock_quota" 
                            type="number" 
                            step="1"
                            min="0"
                            placeholder="管理员手动调整的额外资格"
                        ></el-input>
                    </el-form-item>
                    <el-form-item v-if="baTable.form.operate == 'Edit'" label="待激活确权金">
                        <el-input
                            v-model="baTable.form.items!.pending_activation_gold"
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="请输入待激活确权金"
                        ></el-input>
                    </el-form-item>
                    <el-form-item v-if="baTable.form.operate == 'Edit'" label="消费金">
                        <el-input 
                            v-model="baTable.form.items!.score" 
                            type="number" 
                            step="0.01"
                            min="0"
                            placeholder="请输入消费金"
                        ></el-input>
                    </el-form-item>
                    <el-form-item label="剩余抽奖次数" prop="draw_count">
                        <el-input v-model="baTable.form.items!.draw_count" type="number" min="0" readonly disabled></el-input>
                    </el-form-item>
                    <FormItem
                        label="实名状态"
                        v-model="baTable.form.items!.real_name_status"
                        type="radio"
                        :input-attr="{
                            border: true,
                            content: { 0: '未实名', 1: '待审核', 2: '已通过', 3: '已拒绝' },
                        }"
                    />
                    <FormItem
                        label="用户状态"
                        v-model="baTable.form.items!.user_type"
                        type="radio"
                        :input-attr="{
                            border: true,
                            content: { 0: '新用户', 1: '普通用户', 2: '交易用户' },
                        }"
                    />
                    <el-form-item prop="password" label="登录密码">
                        <el-input
                            v-model="baTable.form.items!.password"
                            type="password"
                            autocomplete="new-password"
                            :placeholder="baTable.form.operate == 'Add' ? '请输入登录密码' : '不修改请留空'"
                        ></el-input>
                    </el-form-item>
                    <el-form-item v-if="baTable.form.operate == 'Edit'" prop="pay_password" label="支付密码">
                        <el-input
                            v-model="baTable.form.items!.pay_password"
                            type="password"
                            autocomplete="new-password"
                            maxlength="6"
                            show-word-limit
                            placeholder="请输入6位数字支付密码，留空则不修改"
                            @input="handlePayPasswordInput"
                        ></el-input>
                    </el-form-item>
                    <el-form-item prop="motto" label="个性签名">
                        <el-input
                            @keyup.enter.stop=""
                            @keyup.ctrl.enter="baTable.onSubmit(formRef)"
                            v-model="baTable.form.items!.motto"
                            type="textarea"
                            placeholder="请输入个性签名"
                        ></el-input>
                    </el-form-item>
                    <FormItem
                        label="状态"
                        v-model="baTable.form.items!.status"
                        type="radio"
                        :input-attr="{
                            border: true,
                            content: { disable: '禁用', enable: '启用' },
                        }"
                    />
                </el-form>
            </div>
        </el-scrollbar>
        <template #footer>
            <div :style="'width: calc(100% - ' + baTable.form.labelWidth! / 1.8 + 'px)'">
                <el-button @click="baTable.toggleForm('')">取消</el-button>
                <el-button v-blur :loading="baTable.form.submitLoading" @click="baTable.onSubmit(formRef)" type="primary">
                    {{
                        baTable.form.operateIds && baTable.form.operateIds.length > 1
                            ? '保存并编辑下一条'
                            : '保存'
                    }}
                </el-button>
            </div>
        </template>
    </el-dialog>
</template>

<script setup lang="ts">
import { reactive, inject, watch, useTemplateRef } from 'vue'
import type baTableClass from '/@/utils/baTable'
import { regularPassword } from '/@/utils/validate'
import type { FormItemRule } from 'element-plus'
import FormItem from '/@/components/formItem/index.vue'
import { buildValidatorData } from '/@/utils/validate'
import { useConfig } from '/@/stores/config'

const config = useConfig()
const formRef = useTemplateRef('formRef')
const baTable = inject('baTable') as baTableClass

const rules: Partial<Record<string, FormItemRule[]>> = reactive({
    username: [buildValidatorData({ name: 'required', title: '用户名' }), buildValidatorData({ name: 'account' })],
    nickname: [buildValidatorData({ name: 'required', title: '昵称' })],
    group_id: [buildValidatorData({ name: 'required', message: '请选择分组' })],
    email: [buildValidatorData({ name: 'email', title: '邮箱' })],
    mobile: [buildValidatorData({ name: 'mobile' })],
    password: [
        {
            validator: (rule: any, val: string, callback: Function) => {
                if (baTable.form.operate == 'Add') {
                    if (!val) {
                        return callback(new Error('请输入登录密码'))
                    }
                } else {
                    if (!val) {
                        return callback()
                    }
                }
                if (!regularPassword(val)) {
                    return callback(new Error('请输入格式正确的密码'))
                }
                return callback()
            },
            trigger: 'blur',
        },
    ],
    pay_password: [
        {
            validator: (rule: any, val: string, callback: Function) => {
                if (!val) {
                    // 留空则不修改
                    return callback()
                }
                // 验证是否为6位数字
                if (!/^\d{6}$/.test(val)) {
                    return callback(new Error('支付密码必须为6位数字'))
                }
                return callback()
            },
            trigger: 'blur',
        },
    ],
})


// 限制支付密码输入只能为数字
const handlePayPasswordInput = (value: string) => {
    // 只保留数字
    const numericValue = value.replace(/\D/g, '')
    if (baTable.form.items) {
        baTable.form.items.pay_password = numericValue
    }
}

watch(
    () => baTable.form.operate,
    (newVal) => {
        rules.password![0].required = newVal == 'Add'
    }
)
</script>

<style scoped lang="scss">
.avatar-uploader {
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    border-radius: var(--el-border-radius-small);
    box-shadow: var(--el-box-shadow-light);
    border: 1px dashed var(--el-border-color);
    cursor: pointer;
    overflow: hidden;
    width: 110px;
    height: 110px;
}
.avatar-uploader:hover {
    border-color: var(--el-color-primary);
}
.avatar {
    width: 110px;
    height: 110px;
    display: block;
}
.image-slot {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
}
</style>
