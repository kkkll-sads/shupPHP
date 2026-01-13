<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <TableHeader
            :buttons="['refresh', 'comSearch', 'quickSearch', 'columnDisplay']"
            quick-search-placeholder="快速搜索：ID/资产包名称"
        />

        <Table />

        <!-- 详情对话框 -->
        <el-dialog
            v-model="detailDialog.visible"
            title="寄售详情"
            width="800px"
            :close-on-click-modal="false"
        >
            <el-descriptions v-if="detailDialog.data" :column="2" border>
                <el-descriptions-item label="寄售ID">{{ detailDialog.data.id }}</el-descriptions-item>
                <el-descriptions-item label="状态">
                    <el-tag :type="getStatusType(detailDialog.data.status)">
                        {{ detailDialog.data.status_text }}
                    </el-tag>
                </el-descriptions-item>
                
                <el-descriptions-item label="用户名">{{ detailDialog.data.username }}</el-descriptions-item>
                <el-descriptions-item label="手机号">{{ detailDialog.data.user_mobile }}</el-descriptions-item>
                
                <el-descriptions-item label="藏品标题" :span="2">{{ detailDialog.data.item_title }}</el-descriptions-item>
                
                <el-descriptions-item label="场次ID">{{ detailDialog.data.session_id || '无' }}</el-descriptions-item>
                <el-descriptions-item label="价格分区ID">{{ detailDialog.data.zone_id || '无' }}</el-descriptions-item>
                
                <el-descriptions-item label="资产包ID">{{ detailDialog.data.package_id || '无' }}</el-descriptions-item>
                <el-descriptions-item label="资产包名称">{{ detailDialog.data.package_name_display || '无' }}</el-descriptions-item>
                
                <el-descriptions-item label="寄售价格">¥{{ parseFloat(detailDialog.data.price).toFixed(2) }}</el-descriptions-item>
                <el-descriptions-item label="原价">¥{{ parseFloat(detailDialog.data.original_price).toFixed(2) }}</el-descriptions-item>
                
                <el-descriptions-item label="手续费">¥{{ parseFloat(detailDialog.data.service_fee).toFixed(2) }}</el-descriptions-item>
                <el-descriptions-item label="使用寄售券">{{ detailDialog.data.coupon_used ? '是' : '否' }}</el-descriptions-item>
                
                <el-descriptions-item label="成交价格">
                    {{ detailDialog.data.sold_price > 0 ? '¥' + parseFloat(detailDialog.data.sold_price).toFixed(2) : '-' }}
                </el-descriptions-item>
                <el-descriptions-item label="成交时间">{{ detailDialog.data.sold_time_text || '-' }}</el-descriptions-item>
                
                <el-descriptions-item label="创建时间">{{ detailDialog.data.create_time_text }}</el-descriptions-item>
                <el-descriptions-item label="更新时间">{{ detailDialog.data.update_time_text }}</el-descriptions-item>
            </el-descriptions>
        </el-dialog>
    </div>
</template>

<script setup lang="ts">
import { provide, ref } from 'vue'
import baTableClass from '/@/utils/baTable'
import { baTableApi } from '/@/api/common'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import createAxios from '/@/utils/axios'
import { ElMessage } from 'element-plus'

defineOptions({
    name: 'collection/consignment',
})

// 详情对话框
const detailDialog = ref({
    visible: false,
    data: null as any,
})

// 查看详情
const viewDetail = async (row: any) => {
    try {
        const res = await createAxios({
            url: '/admin/collection.Consignment/detail',
            method: 'get',
            params: { id: row.id },
        })

        if (res.code === 1) {
            detailDialog.value.data = res.data.data
            detailDialog.value.visible = true
        } else {
            ElMessage.error(res.msg || '获取详情失败')
        }
    } catch (error: any) {
        console.error('获取详情失败:', error)
        ElMessage.error(error?.msg || error?.response?.data?.msg || '获取详情失败')
    }
}

// 状态标签类型
const getStatusType = (status: number): 'warning' | 'success' | 'info' | 'danger' => {
    const typeMap: Record<number, 'warning' | 'success' | 'info' | 'danger'> = {
        1: 'warning',
        2: 'success',
        3: 'info',
        4: 'danger',
    }
    return typeMap[status] || 'info'
}

const baTable = new baTableClass(
    new baTableApi('/admin/collection.Consignment/'),
    {
        pk: 'id',
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', width: 70, operator: 'RANGE', sortable: 'custom' },
            { label: '用户名', prop: 'username', align: 'center', operator: 'LIKE', operatorPlaceholder: '模糊查询' },
            { label: '手机号', prop: 'user_mobile', align: 'center', operator: 'LIKE', operatorPlaceholder: '模糊查询' },
            { label: '藏品标题', prop: 'item_title', align: 'center', operator: 'LIKE', operatorPlaceholder: '模糊查询', width: 200 },
            { label: '资产包', prop: 'package_name', align: 'center', operator: 'LIKE', operatorPlaceholder: '模糊查询', width: 150 },
            { 
                label: '寄售价格', 
                prop: 'price', 
                align: 'center', 
                operator: 'RANGE',
                render: 'customTemplate',
                customTemplate: (row: any) => {
                    return `¥${parseFloat(row.price).toFixed(2)}`
                },
                width: 100
            },
            { 
                label: '原价', 
                prop: 'original_price', 
                align: 'center', 
                operator: 'RANGE',
                render: 'customTemplate',
                customTemplate: (row: any) => {
                    return `¥${parseFloat(row.original_price).toFixed(2)}`
                },
                width: 100
            },
            { 
                label: '手续费', 
                prop: 'service_fee', 
                align: 'center',
                render: 'customTemplate',
                customTemplate: (row: any) => {
                    return `¥${parseFloat(row.service_fee).toFixed(2)}`
                },
                width: 90
            },
            { 
                label: '使用券', 
                prop: 'coupon_used', 
                align: 'center',
                render: 'tag',
                replaceValue: {
                    0: '否',
                    1: '是'
                },
                custom: {
                    0: 'info',
                    1: 'success'
                },
                width: 80
            },
            {
                label: '状态',
                prop: 'status',
                align: 'center',
                operator: '=',
                render: 'tag',
                replaceValue: {
                    1: '寄售中',
                    2: '已售出',
                    3: '流拍',
                    4: '已取消'
                },
                custom: {
                    1: 'warning',
                    2: 'success',
                    3: 'info',
                    4: 'danger'
                },
                width: 90
            },
            { label: '创建时间', prop: 'create_time', align: 'center', render: 'datetime', operator: 'RANGE', sortable: 'custom', width: 160 },
            { label: '成交时间', prop: 'sold_time', align: 'center', render: 'datetime', operator: 'RANGE', sortable: 'custom', width: 160 },
            {
                label: '操作',
                align: 'center',
                width: 100,
                render: 'buttons',
                buttons: ((row: any) => {
                    return [
                        {
                            name: 'detail',
                            text: '详情',
                            type: 'primary',
                            icon: 'fa fa-info-circle',
                            render: 'basicButton',
                            click: () => viewDetail(row),
                        },
                    ]
                }) as any,
                operator: false,
            },
        ],
        dblClickNotEditColumn: ['all'],
    },
    {
        defaultItems: {},
    }
)

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

<style scoped lang="scss"></style>
