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
                    {{ state.userInfo.username + '(ID:' + state.userInfo.id + ') 积分:' + state.userInfo.score }}
                </span>
            </el-button>
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
import { add, url } from '/@/api/backend/user/scoreLog'
import { baTableApi } from '/@/api/common'
import TableHeader from '/@/components/table/header/index.vue'
import Table from '/@/components/table/index.vue'
import baTableClass from '/@/utils/baTable'

defineOptions({
    name: 'user/scoreLog',
})

const route = useRoute()
const defalutUser = (route.query.user_id ?? '') as string
const state = reactive({
    userInfo: {} as anyObj,
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
            { label: '变动积分', prop: 'score', align: 'center', operator: 'RANGE', sortable: 'custom' },
            { label: '变动前', prop: 'before', align: 'center', operator: 'RANGE', sortable: 'custom' },
            { label: '变动后', prop: 'after', align: 'center', operator: 'RANGE', sortable: 'custom' },
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
