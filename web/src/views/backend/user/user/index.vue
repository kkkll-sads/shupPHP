<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <!-- 表格顶部菜单 -->
        <TableHeader
            :buttons="['refresh', 'add', 'edit', 'delete', 'comSearch', 'quickSearch', 'columnDisplay']"
            quick-search-placeholder="搜索 用户名/昵称/邀请码"
        >
            <el-popconfirm
                @confirm="onCleanOrphanedInviteCodes"
                confirm-button-text="确定"
                cancel-button-text="取消"
                confirmButtonType="warning"
                title="确定要清理所有没有关联用户的邀请码吗？此操作不可恢复！"
            >
                <template #reference>
                    <div class="mlr-12">
                        <el-tooltip content="清理孤立的邀请码" placement="top">
                            <el-button v-blur class="table-header-operate" type="warning">
                                <Icon name="fa fa-trash-o" />
                                <span class="table-header-operate-text">清理孤立邀请码</span>
                            </el-button>
                        </el-tooltip>
                    </div>
                </template>
            </el-popconfirm>
        </TableHeader>

        <!-- 表格 -->
        <!-- 要使用`el-table`组件原有的属性，直接加在Table标签上即可 -->
        <Table />

        <!-- 表单 -->
        <PopupForm />
    </div>
</template>

<script setup lang="ts">
import { provide } from 'vue'
import baTableClass from '/@/utils/baTable'
import PopupForm from './popupForm.vue'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import { defaultOptButtons } from '/@/components/table'
import { baTableApi } from '/@/api/common'
import createAxios from '/@/utils/axios'
import { ElMessage } from 'element-plus'
import Icon from '/@/components/icon/index.vue'

defineOptions({
    name: 'user/user',
})

const baTable = new baTableClass(
    new baTableApi('/admin/user.User/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', operator: '=', operatorPlaceholder: 'ID', width: 70 },
            {
                label: '用户名',
                prop: 'username',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '模糊查询',
                show: false,
            },
            {
                label: '昵称',
                prop: 'nickname',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '模糊查询',
                show: false,
            },
            {
                label: '分组',
                prop: 'userGroup.name',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '模糊查询',
                render: 'tag',
                show: false,
            },
            { label: '头像', prop: 'avatar', align: 'center', render: 'image', operator: false },
            {
                label: '性别',
                prop: 'gender',
                align: 'center',
                render: 'tag',
                custom: { '0': 'info', '1': '', '2': 'success' },
                replaceValue: { '0': '未知', '1': '男', '2': '女' },
                show: false,
            },
            { label: '手机号', prop: 'mobile', align: 'center', operator: 'LIKE', operatorPlaceholder: '模糊查询' },
            {
                label: '邀请码',
                prop: 'inviteCode.code',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '模糊查询',
                render: 'tag',
            },
            {
                label: '实名状态',
                prop: 'real_name_status',
                align: 'center',
                operator: 'select' as any,
                operatorOptions: [
                    { label: '未实名', value: '0' },
                    { label: '待审核', value: '1' },
                    { label: '已通过', value: '2' },
                    { label: '已拒绝', value: '3' },
                ] as any,
                render: 'tag',
                custom: { '0': 'info', '1': 'warning', '2': 'success', '3': 'danger' },
                replaceValue: { '0': '未实名', '1': '待审核', '2': '已通过', '3': '已拒绝' },
                width: 100,
            } as any,
            {
                label: '用户状态',
                prop: 'user_type',
                align: 'center',
                operator: 'select' as any,
                operatorOptions: [
                    { label: '新用户', value: '0' },
                    { label: '普通用户', value: '1' },
                    { label: '交易用户', value: '2' },
                ] as any,
                render: 'tag',
                custom: { '0': 'info', '1': 'warning', '2': 'success' },
                replaceValue: { '0': '新用户', '1': '普通用户', '2': '交易用户' },
                width: 100,
            } as any,
            {
                label: '可用金额',
                prop: 'money',
                align: 'center',
                operator: '=',
                operatorPlaceholder: '可用金额',
                width: 120,
            },
            {
                label: '最后登录时间',
                prop: 'last_login_time',
                align: 'center',
                render: 'datetime',
                sortable: 'custom',
                operator: 'RANGE',
                width: 160,
                show: false,
            },
            {
                label: '可提现金额',
                prop: 'withdrawable_money',
                align: 'center',
                operator: '=',
                operatorPlaceholder: '可提现金额',
                width: 120,
            },
            {
                label: '拓展提现',
                prop: 'static_income',
                align: 'center',
                operator: '=',
                operatorPlaceholder: '拓展提现',
                width: 120,
            },
            {
                label: '绿色算力',
                prop: 'green_power',
                align: 'center',
                operator: '=',
                operatorPlaceholder: '绿色算力',
                width: 120,
            },
            {
                label: '旧资产',
                prop: 'old_assets_status',
                align: 'center',
                render: 'tag',
                custom: { '0': 'info', '1': 'success' },
                replaceValue: { '0': '未解锁', '1': '已解锁' },
                operator: 'select' as any,
                operatorOptions: [
                    { label: '未解锁', value: '0' },
                    { label: '已解锁', value: '1' },
                ] as any,
                width: 120,
            },
            {
                label: '旧资产解锁次数',
                prop: 'old_assets_unlock_count',
                align: 'center',
                operator: 'RANGE',
                width: 120,
            },
            {
                label: '可解锁次数',
                prop: 'old_assets_available_quota',
                align: 'center',
                operator: 'RANGE',
                render: 'tag',
                custom: (row: any) => {
                    const quota = parseInt(row.old_assets_available_quota) || 0
                    return quota > 0 ? 'success' : 'info'
                },
                width: 120,
            },
            {
                label: '确权金（交易手续费）',
                prop: 'service_fee_balance',
                align: 'center',
                operator: '=',
                operatorPlaceholder: '确权金',
                width: 150,
            },
            {
                label: '待激活确权金',
                prop: 'pending_activation_gold',
                align: 'center',
                operator: '=',
                operatorPlaceholder: '待激活确权金',
                width: 130,
            },
            {
                label: '寄售卷',
                prop: 'available_coupon_count',
                align: 'center',
                operator: false,
                width: 120,
            },
            {
                label: '剩余抽奖次数',
                prop: 'draw_count',
                align: 'center',
                operator: '=',
                operatorPlaceholder: '剩余抽奖次数',
                width: 120,
                show: false,
            },
            { label: '创建时间', prop: 'create_time', align: 'center', render: 'datetime', sortable: 'custom', operator: 'RANGE', width: 160 },
            {
                label: '状态',
                prop: 'status',
                align: 'center',
                render: 'tag',
                custom: { disable: 'danger', enable: 'success' },
                replaceValue: { disable: '禁用', enable: '启用' },
            },
            {
                label: '操作',
                align: 'center',
                width: '100',
                render: 'buttons',
                buttons: defaultOptButtons(['edit', 'delete']),
                operator: false,
            },
        ],
        dblClickNotEditColumn: [undefined],
    },
    {
        defaultItems: {
            gender: 0,
            money: '0',
            balance_available: '0',
            withdrawable_money: '0',
            static_income: '0',
            dynamic_income: '0',
            consignment_coupon: '0',
            score: '0',
            status: 'enable',
            draw_count: 0,
            real_name_status: 0,
            user_type: 0,
            green_power: '0',
            old_assets_status: 0,
            service_fee_balance: '0',
            pending_activation_gold: '0',
        },
    }
)

const onCleanOrphanedInviteCodes = async () => {
    try {
        const res = await createAxios({
            url: '/admin/user.User/cleanOrphanedInviteCodes',
            method: 'post',
        })
        console.log('清理孤立邀请码响应:', res)
        if (res.code === 1) {
            ElMessage.success(res.msg || '清理成功')
            baTable.getData()
        } else {
            ElMessage.error(res.msg || '清理失败')
        }
    } catch (error: any) {
        console.error('清理孤立邀请码错误:', error)
        ElMessage.error(error?.msg || error?.response?.data?.msg || '清理失败，请稍后重试')
    }
}

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

<style scoped lang="scss"></style>
