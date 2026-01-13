<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <TableHeader
            :buttons="['refresh', 'add', 'edit', 'delete', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="
                t('Quick search placeholder', {
                    fields: t('collection.item.Title') + '/' + t('Id'),
                })
            "
        />

        <Table />

        <PopupForm />
        
        <!-- 藏品统计弹窗 -->
        <el-dialog v-model="statisticsDialog.visible" title="藏品统计信息" width="90%" top="5vh">
            <div v-loading="statisticsDialog.loading">
                <el-tabs v-model="statisticsDialog.activeTab">
                    <!-- 基本信息 -->
                    <el-tab-pane label="基本信息" name="basic">
                        <el-descriptions :column="2" border v-if="statisticsDialog.data">
                            <el-descriptions-item label="藏品ID">{{ statisticsDialog.data.basic_info?.id }}</el-descriptions-item>
                            <el-descriptions-item label="藏品标题">{{ statisticsDialog.data.basic_info?.title }}</el-descriptions-item>
                            <el-descriptions-item label="当前价格">¥{{ statisticsDialog.data.basic_info?.price }}</el-descriptions-item>
                            <el-descriptions-item label="发行价格">¥{{ statisticsDialog.data.basic_info?.issue_price }}</el-descriptions-item>
                            <el-descriptions-item label="库存">{{ statisticsDialog.data.basic_info?.stock }}</el-descriptions-item>
                            <el-descriptions-item label="销量">{{ statisticsDialog.data.basic_info?.sales }}</el-descriptions-item>
                            <el-descriptions-item label="状态">{{ statisticsDialog.data.basic_info?.status_text }}</el-descriptions-item>
                            <el-descriptions-item label="创建时间">{{ statisticsDialog.data.basic_info?.create_time }}</el-descriptions-item>
                            <el-descriptions-item label="确权编号" :span="2">{{ statisticsDialog.data.basic_info?.asset_code || '未生成' }}</el-descriptions-item>
                            <el-descriptions-item label="存证指纹" :span="2">
                                <span style="font-size: 12px; word-break: break-all;">{{ statisticsDialog.data.basic_info?.fingerprint || '未生成' }}</span>
                            </el-descriptions-item>
                        </el-descriptions>
                    </el-tab-pane>
                    
                    <!-- 交易统计 -->
                    <el-tab-pane label="交易统计" name="trade">
                        <el-row :gutter="20" v-if="statisticsDialog.data">
                            <el-col :span="6">
                                <el-statistic title="交易次数" :value="statisticsDialog.data.trade_statistics?.total_trades || 0" />
                            </el-col>
                            <el-col :span="6">
                                <el-statistic title="不同买家数" :value="statisticsDialog.data.trade_statistics?.unique_buyers || 0" />
                            </el-col>
                            <el-col :span="6">
                                <el-statistic title="交易总额" :value="statisticsDialog.data.trade_statistics?.total_amount || 0" :precision="2" prefix="¥" />
                            </el-col>
                            <el-col :span="6">
                                <div class="el-statistic">
                                    <div class="el-statistic__head">首次交易</div>
                                    <div class="el-statistic__content" style="font-size: 14px;">
                                        {{ statisticsDialog.data.trade_statistics?.first_trade_time || '-' }}
                                    </div>
                                </div>
                            </el-col>
                        </el-row>
                    </el-tab-pane>
                    
                    <!-- 交易用户明细 -->
                    <el-tab-pane label="交易用户明细" name="trade_users">
                        <el-table :data="statisticsDialog.data?.trade_users || []" stripe border max-height="500">
                            <el-table-column prop="user_id" label="用户ID" width="80" align="center" />
                            <el-table-column prop="username" label="用户名" width="120" align="center" />
                            <el-table-column prop="nickname" label="昵称" width="120" align="center" />
                            <el-table-column prop="price" label="购买价格" width="100" align="center">
                                <template #default="{ row }">¥{{ row.price }}</template>
                            </el-table-column>
                            <el-table-column prop="buy_time_text" label="购买时间" width="160" align="center" />
                            <el-table-column prop="delivery_status_text" label="交付状态" width="100" align="center">
                                <template #default="{ row }">
                                    <el-tag :type="row.delivery_status === 0 ? 'success' : 'warning'" size="small">
                                        {{ row.delivery_status_text }}
                                    </el-tag>
                                </template>
                            </el-table-column>
                            <el-table-column prop="consignment_status_text" label="寄售状态" width="100" align="center">
                                <template #default="{ row }">
                                    <el-tag 
                                        :type="row.consignment_status === 0 ? 'info' : row.consignment_status === 1 ? 'warning' : 'success'"
                                        size="small"
                                    >
                                        {{ row.consignment_status_text }}
                                    </el-tag>
                                </template>
                            </el-table-column>
                            <el-table-column prop="is_old_asset_package_text" label="旧资产包" width="100" align="center">
                                <template #default="{ row }">
                                    <el-tag :type="row.is_old_asset_package === 1 ? 'danger' : ''" size="small">
                                        {{ row.is_old_asset_package_text }}
                                    </el-tag>
                                </template>
                            </el-table-column>
                        </el-table>
                    </el-tab-pane>
                    
                    <!-- 寄售统计 -->
                    <el-tab-pane label="寄售统计" name="consignment">
                        <el-row :gutter="20" v-if="statisticsDialog.data" style="margin-bottom: 20px;">
                            <el-col :span="4">
                                <el-statistic title="寄售总次数" :value="statisticsDialog.data.consignment_statistics?.total_consignments || 0" />
                            </el-col>
                            <el-col :span="4">
                                <el-statistic title="寄售中" :value="statisticsDialog.data.consignment_statistics?.consigning || 0" />
                            </el-col>
                            <el-col :span="4">
                                <el-statistic title="已售出" :value="statisticsDialog.data.consignment_statistics?.sold || 0" />
                            </el-col>
                            <el-col :span="4">
                                <el-statistic title="已下架" :value="statisticsDialog.data.consignment_statistics?.offshelf || 0" />
                            </el-col>
                            <el-col :span="4">
                                <el-statistic title="已取消" :value="statisticsDialog.data.consignment_statistics?.cancelled || 0" />
                            </el-col>
                            <el-col :span="4">
                                <el-statistic title="失败次数" :value="statisticsDialog.data.consignment_statistics?.failed || 0">
                                    <template #suffix>
                                        <el-tooltip content="失败次数 = 已下架 + 已取消">
                                            <el-icon><QuestionFilled /></el-icon>
                                        </el-tooltip>
                                    </template>
                                </el-statistic>
                            </el-col>
                        </el-row>
                        <el-row :gutter="20" v-if="statisticsDialog.data">
                            <el-col :span="8">
                                <el-statistic title="平均寄售价格" :value="statisticsDialog.data.consignment_statistics?.avg_consignment_price || 0" :precision="2" prefix="¥" />
                            </el-col>
                            <el-col :span="8">
                                <el-statistic title="最低寄售价格" :value="statisticsDialog.data.consignment_statistics?.min_consignment_price || 0" :precision="2" prefix="¥" />
                            </el-col>
                            <el-col :span="8">
                                <el-statistic title="最高寄售价格" :value="statisticsDialog.data.consignment_statistics?.max_consignment_price || 0" :precision="2" prefix="¥" />
                            </el-col>
                        </el-row>
                    </el-tab-pane>
                    
                    <!-- 寄售明细 -->
                    <el-tab-pane label="寄售明细" name="consignment_list">
                        <el-table :data="statisticsDialog.data?.consignment_list || []" stripe border max-height="500">
                            <el-table-column prop="consignment_id" label="寄售ID" width="80" align="center" />
                            <el-table-column prop="user_id" label="用户ID" width="80" align="center" />
                            <el-table-column prop="username" label="用户名" width="120" align="center" />
                            <el-table-column prop="consignment_price" label="寄售价格" width="100" align="center">
                                <template #default="{ row }">¥{{ row.consignment_price }}</template>
                            </el-table-column>
                            <el-table-column prop="service_fee" label="服务费" width="100" align="center">
                                <template #default="{ row }">¥{{ row.service_fee }}</template>
                            </el-table-column>
                            <el-table-column prop="total_cost" label="总成本" width="100" align="center">
                                <template #default="{ row }">¥{{ row.total_cost }}</template>
                            </el-table-column>
                            <el-table-column prop="status_text" label="状态" width="100" align="center">
                                <template #default="{ row }">
                                    <el-tag 
                                        :type="row.status === 2 ? 'success' : row.status === 1 ? 'warning' : row.status === 3 ? 'info' : 'danger'"
                                        size="small"
                                    >
                                        {{ row.status_text }}
                                    </el-tag>
                                </template>
                            </el-table-column>
                            <el-table-column prop="is_old_asset_package_text" label="旧资产包" width="100" align="center">
                                <template #default="{ row }">
                                    <el-tag :type="row.is_old_asset_package === 1 ? 'danger' : ''" size="small">
                                        {{ row.is_old_asset_package_text }}
                                    </el-tag>
                                </template>
                            </el-table-column>
                            <el-table-column prop="create_time_text" label="创建时间" width="160" align="center" />
                        </el-table>
                    </el-tab-pane>
                    
                    <!-- 盲盒预约统计 -->
                    <el-tab-pane label="盲盒预约" name="blind_box">
                        <el-row :gutter="20" v-if="statisticsDialog.data">
                            <el-col :span="6">
                                <el-statistic title="预约总数" :value="statisticsDialog.data.blind_box_statistics?.total_reservations || 0" />
                            </el-col>
                            <el-col :span="6">
                                <el-statistic title="中签数" :value="statisticsDialog.data.blind_box_statistics?.won || 0" />
                            </el-col>
                            <el-col :span="6">
                                <el-statistic title="未中签" :value="statisticsDialog.data.blind_box_statistics?.not_won || 0" />
                            </el-col>
                            <el-col :span="6">
                                <el-statistic title="待处理" :value="statisticsDialog.data.blind_box_statistics?.pending || 0" />
                            </el-col>
                        </el-row>
                    </el-tab-pane>
                </el-tabs>
            </div>
        </el-dialog>
    </div>
</template>

<script setup lang="ts">
import { provide, ref, reactive } from 'vue'
import { ElMessage } from 'element-plus'
import { QuestionFilled } from '@element-plus/icons-vue'
import baTableClass from '/@/utils/baTable'
import PopupForm from './popupForm.vue'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import { defaultOptButtons } from '/@/components/table'
import { baTableApi } from '/@/api/common'
import { useI18n } from 'vue-i18n'

defineOptions({
    name: 'collection/item',
})

const { t } = useI18n()

interface CollectionSession {
    id: number
    title: string
}

const sessionMap = ref<Record<number, CollectionSession>>({})
const zoneMap = ref<Record<number, { id: number; name: string }>>({})

// 加载专场列表用于显示名称
const sessionApi = new baTableApi('/admin/collection.Session/')
const zoneApi = new baTableApi('/admin/PriceZoneConfig/')
const loadSessions = async () => {
    const res = await sessionApi.index({
        select: 1,
        limit: 999,
    })
    if (res.code === 1 && res.data?.list) {
        const sessions = res.data.list as CollectionSession[]
        sessionMap.value = {}
        sessions.forEach((session) => {
            sessionMap.value[session.id] = session
        })
    }
}

// 页面加载时获取专场列表
loadSessions()
const loadZones = async () => {
    const res = await zoneApi.index({
        select: 1,
        limit: 999,
    })
    if (res.code === 1 && res.data?.list) {
        const zones = res.data.list as { id: number; name: string }[]
        zoneMap.value = {}
        zones.forEach((z) => {
            zoneMap.value[z.id] = z
        })
    }
}
loadZones()

const baTable = new baTableClass(
    new baTableApi('/admin/collection.Item/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: t('Id'), prop: 'id', align: 'center', operator: '=', operatorPlaceholder: t('Id'), width: 70 },
            {
                label: '专场',
                prop: 'session_id',
                align: 'center',
                operator: '=',
                operatorPlaceholder: '专场ID',
                width: 150,
                render: (row: any) => {
                    const session = sessionMap.value[row.session_id]
                    return session ? session.title + '（ID：' + row.session_id + '）' : 'ID：' + row.session_id
                },
            },
            {
                label: '藏品标题',
                prop: 'title',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: t('Fuzzy query'),
            },
            {
                label: '价格分区',
                prop: 'zone_id',
                align: 'center',
                operator: '=',
                operatorPlaceholder: '分区ID',
                width: 140,
                render: (row: any) => {
                    const zone = zoneMap.value[row.zone_id]
                    return zone ? zone.name + '（ID：' + row.zone_id + '）' : 'ID：' + row.zone_id
                },
            },
            {
                label: '藏品图片',
                prop: 'image',
                align: 'center',
                render: 'image',
                operator: false,
                width: 120,
            },
            {
                label: '价格',
                prop: 'price',
                align: 'center',
                operator: 'BETWEEN',
                width: 120,
                render: (row: any) => {
                    const price = Number(row.price)
                    return isNaN(price) ? row.price : price.toFixed(2) + '元'
                },
            },
            {
                label: '资产锚定',
                prop: 'asset_anchor',
                align: 'center',
                operator: 'LIKE',
                minWidth: 150,
            },
            {
                label: '存证指纹',
                prop: 'fingerprint',
                align: 'center',
                operator: 'LIKE',
                minWidth: 220,
                render: (row: any) => {
                    const text = row.fingerprint || ''
                    return text.length > 24 ? text.slice(0, 24) + '…' : text
                },
            },
            {
                label: '库存数量',
                prop: 'stock',
                align: 'center',
                operator: 'BETWEEN',
                width: 110,
            },
            {
                label: '销量',
                prop: 'sales',
                align: 'center',
                operator: 'BETWEEN',
                width: 90,
            },
            {
                label: '状态',
                prop: 'status',
                align: 'center',
                render: 'switch',
                // 使用数字值 1 / 0，确保前端传递数值类型
                options: [
                    { label: t('Enable'), value: 1 },
                    { label: t('Disable'), value: 0 },
                ],
                operator: 'select',
                operatorOptions: [
                    { label: t('Enable'), value: 1 },
                    { label: t('Disable'), value: 0 },
                ],
                width: 90,
            },
            {
                label: '排序',
                prop: 'sort',
                align: 'center',
                operator: 'BETWEEN',
                width: 90,
            },
            {
                label: t('Create time'),
                prop: 'create_time',
                align: 'center',
                render: 'datetime',
                sortable: 'custom',
                operator: 'RANGE',
                width: 160,
            },
            {
                label: t('Operate'),
                prop: 'operate',
                align: 'center',
                width: 140,
                render: 'buttons',
                buttons: [],
                operator: false,
            },
        ],
    },
    {
        defaultItems: {
            // 默认使用数字 1（启用）
            status: 1,
            sort: 0,
            stock: 1,
            sales: 0,
            price: 0,
            session_id: 0,
            zone_id: 0,
        },
    }
)

// 统计弹窗状态
const statisticsDialog = reactive({
    visible: false,
    loading: false,
    activeTab: 'basic',
    data: null as any,
})

// 查看藏品统计
const viewStatistics = async (row: any) => {
    statisticsDialog.visible = true
    statisticsDialog.loading = true
    statisticsDialog.activeTab = 'basic'
    statisticsDialog.data = null
    
    try {
        const res = await baTable.api.postData('statistics', { id: row.id })
        if (res.code === 1) {
            statisticsDialog.data = res.data
        } else {
            ElMessage.error(res.msg || '获取统计信息失败')
        }
    } catch (error: any) {
        ElMessage.error(error.message || '获取统计信息失败')
    } finally {
        statisticsDialog.loading = false
    }
}

const optButtons = defaultOptButtons(['edit', 'delete'])

// 添加统计按钮
optButtons.unshift({
    render: 'tipButton',
    name: 'statistics',
    title: '查看统计',
    text: '统计',
    type: 'primary',
    icon: 'fa fa-bar-chart',
    class: 'table-row-statistics',
    click: (row: any) => {
        viewStatistics(row)
    },
    display: () => true,
})

optButtons.forEach((btn) => {
    if (btn.name !== 'statistics') {
        btn.display = () => true
    }
})

baTable.table.column[baTable.table.column.length - 1].buttons = optButtons
baTable.table.column[baTable.table.column.length - 1].width = 200 // 增加操作列宽度

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>

<style scoped lang="scss"></style>

