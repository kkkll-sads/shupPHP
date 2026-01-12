<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <!-- 表格顶部菜单 -->
        <TableHeader
            :buttons="['refresh', 'add', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="'快速搜索：用户名/用户昵称'"
        >
            <el-button v-if="!isEmpty(state.userInfo)" v-blur class="table-header-operate">
                <span class="table-header-operate-text">
                    {{ state.userInfo.username + '(ID:' + state.userInfo.id + ') | 可用余额:' + state.userInfo.balance_available + ' | 总资产:' + state.userInfo.money }}
                </span>
            </el-button>
            
            <!-- 资金类型快速筛选 -->
            <div class="mlr-12">
                <el-tooltip content="筛选资金类型" placement="top">
                    <el-select
                        v-model="state.selectedFieldType"
                        @change="onFieldTypeChange"
                        placeholder="全部类型"
                        clearable
                        style="width: 150px"
                    >
                        <el-option label="全部类型" value="" />
                        <el-option label="可用金额" value="money" />
                        <el-option label="可提现金额" value="withdrawable_money" />
                        <el-option label="确权金" value="service_fee_balance" />
                        <el-option label="拓展提现" value="static_income" />
                        <el-option label="待激活确权金" value="pending_activation_gold" />
                        <el-option label="消费金" value="score" />
                    </el-select>
                </el-tooltip>
            </div>
        </TableHeader>

        <!-- 表格 -->
        <!-- 要使用`el-table`组件原有的属性，直接加在Table标签上即可 -->
        <Table />

        <!-- 表单 -->
        <PopupForm />
    </div>
</template>

<script setup lang="ts">
import { debounce, isEmpty, parseInt } from 'lodash-es'
import { provide, reactive, watch } from 'vue'
import { useRoute } from 'vue-router'
import PopupForm from './popupForm.vue'
import { add, url } from '/@/api/backend/user/moneyLog'
import { baTableApi } from '/@/api/common'
import TableHeader from '/@/components/table/header/index.vue'
import Table from '/@/components/table/index.vue'
import baTableClass from '/@/utils/baTable'

defineOptions({
    name: 'user/moneyLog',
})

const route = useRoute()
const defalutUser = (route.query.user_id ?? '') as string
const state = reactive({
    userInfo: {} as anyObj,
    selectedFieldType: '', // 选中的字段类型
})

const baTable = new baTableClass(
    new baTableApi(url),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', operator: '=', operatorPlaceholder: 'ID', width: 70 },
            { label: '用户ID', prop: 'user_id', align: 'center', width: 70 },
            { label: '用户名', prop: 'user.username', align: 'center', operator: 'LIKE', operatorPlaceholder: '模糊查询' },
            {
                label: '用户昵称',
                prop: 'user.nickname',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '模糊查询',
            },
            {
                label: '字段类型',
                prop: 'field_type',
                align: 'center',
                operator: 'select' as any,
                operatorOptions: [
                    { label: '可用金额', value: 'money' },
                    { label: '可提现金额', value: 'withdrawable_money' },
                    { label: '确权金', value: 'service_fee_balance' },
                    { label: '拓展提现', value: 'static_income' },
                    { label: '待激活确权金', value: 'pending_activation_gold' },
                    { label: '消费金', value: 'score' },
                ] as any,
                render: 'tag',
                custom: {
                    'money': '',
                    'withdrawable_money': 'success',
                    'service_fee_balance': 'warning',
                    'static_income': 'info',
                    'pending_activation_gold': 'danger',
                    'score': 'primary',
                },
                replaceValue: {
                    'money': '可用金额',
                    'withdrawable_money': '可提现金额',
                    'service_fee_balance': '确权金',
                    'static_income': '拓展提现',
                    'pending_activation_gold': '待激活确权金',
                    'score': '消费金',
                },
                width: 130,
            } as any,
            { label: '变动金额', prop: 'money', align: 'center', operator: 'RANGE', sortable: 'custom', width: 120 },
            { label: '变动前', prop: 'before', align: 'center', operator: 'RANGE', sortable: 'custom', width: 120 },
            { label: '变动后', prop: 'after', align: 'center', operator: 'RANGE', sortable: 'custom', width: 120 },
            {
                label: '备注',
                prop: 'memo',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '模糊查询',
                showOverflowTooltip: true,
            },
            { label: '创建时间', prop: 'create_time', align: 'center', render: 'datetime', sortable: 'custom', operator: 'RANGE', width: 160 },
        ],
        dblClickNotEditColumn: ['all'],
    },
    {
        defaultItems: {
            user_id: defalutUser,
            memo: '',
        },
    }
)

// 表单提交后
baTable.after.onSubmit = () => {
    getUserInfo(baTable.comSearch.form.user_id)
}

baTable.before.onTableAction = ({ event }) => {
    // 公共搜索
    if (event === 'com-search') {
        baTable.table.filter!.search = baTable.getComSearchData()

        for (const key in baTable.table.filter!.search) {
            if (['money', 'before', 'after'].includes(baTable.table.filter!.search[key].field)) {
                const val = (baTable.table.filter!.search[key].val as string).split(',')
                const newVal: (string | number)[] = []
                for (const k in val) {
                    newVal.push(isNaN(parseFloat(val[k])) ? '' : parseFloat(val[k]) * 100)
                }
                baTable.table.filter!.search[key].val = newVal.join(',')
            }
        }

        baTable.onTableHeaderAction('refresh', { event: 'com-search', data: baTable.table.filter!.search })
        return false
    }
}

baTable.mount()
baTable.getData()

provide('baTable', baTable)

const getUserInfo = debounce((userId: string) => {
    if (userId && parseInt(userId) > 0) {
        add(userId).then((res) => {
            state.userInfo = res.data.user
        })
    } else {
        state.userInfo = {}
    }
}, 300)

// 字段类型筛选变化
const onFieldTypeChange = (value: string) => {
    // 更新表格筛选条件
    if (!baTable.table.filter) {
        baTable.table.filter = { search: [] }
    }
    if (!Array.isArray(baTable.table.filter.search)) {
        baTable.table.filter.search = []
    }
    
    // 移除之前的field_type筛选
    baTable.table.filter.search = baTable.table.filter.search.filter((item: any) => item.field !== 'field_type')
    
    // 如果选择了类型，添加筛选条件
    if (value) {
        baTable.table.filter.search.push({
            field: 'field_type',
            val: value,
            operator: '=',
        })
    }
    
    // 刷新表格数据
    baTable.onTableHeaderAction('refresh', { event: 'field-type-filter' })
}

getUserInfo(baTable.comSearch.form.user_id)

watch(
    () => baTable.comSearch.form.user_id,
    (newVal) => {
        baTable.form.defaultItems!.user_id = newVal
        getUserInfo(newVal)
    }
)
</script>

<style scoped lang="scss"></style>
