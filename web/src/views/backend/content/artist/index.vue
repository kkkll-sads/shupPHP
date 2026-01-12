<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" v-if="baTable.table.remark" :title="baTable.table.remark" type="info" show-icon />

        <!-- 表格顶部菜单 -->
        <TableHeader
            :buttons="['refresh', 'add', 'edit', 'delete', 'comSearch', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="'快速搜索：姓名/ID'"
        />

        <!-- 表格 -->
        <Table />

        <!-- 表单 -->
        <PopupForm />

        <!-- 作品管理弹窗 -->
        <el-dialog
            v-model="workDialogVisible"
            class="ba-detail-dialog"
            :close-on-click-modal="false"
            :destroy-on-close="true"
            width="70%"
        >
            <template #header>
                <div class="title">
                    艺术家作品管理：{{ currentArtist?.name || '' }}
                </div>
            </template>

            <el-scrollbar class="ba-detail-scrollbar">
                <div class="ba-detail-content">
                    <div class="work-header">
                        <el-button type="primary" @click="openAddWork">新增作品</el-button>
                    </div>

                    <el-table :data="workList" v-loading="workLoading" stripe>
                        <el-table-column prop="id" label="ID" width="70" align="center" />
                        <el-table-column label="作品图" width="120" align="center">
                            <template #default="{ row }">
                                <el-image
                                    v-if="row.image"
                                    :src="row.image"
                                    style="width: 80px; height: 80px; object-fit: cover"
                                    :preview-src-list="[row.image]"
                                />
                                <span v-else>-</span>
                            </template>
                        </el-table-column>
                        <el-table-column prop="title" label="作品名称" align="center" />
                        <el-table-column prop="status" label="状态" width="100" align="center">
                            <template #default="{ row }">
                                <el-tag :type="row.status === '1' ? 'success' : 'info'">
                                    {{ row.status === '1' ? '显示' : '隐藏' }}
                                </el-tag>
                            </template>
                        </el-table-column>
                        <el-table-column prop="sort" label="排序" width="80" align="center" />
                        <el-table-column label="作品描述" align="left" min-width="200">
                            <template #default="{ row }">
                                <span>{{ row.description || '-' }}</span>
                            </template>
                        </el-table-column>
                        <el-table-column label="操作" width="120" align="center">
                            <template #default="{ row }">
                                <el-button type="danger" size="small" @click="deleteWork(row)">删除</el-button>
                            </template>
                        </el-table-column>
                    </el-table>
                </div>
            </el-scrollbar>

            <template #footer>
                <div class="dialog-footer">
                    <el-button @click="workDialogVisible = false">关闭</el-button>
                </div>
            </template>
        </el-dialog>

        <!-- 新增作品表单弹窗 -->
        <el-dialog
            v-model="workFormVisible"
            class="ba-operate-dialog"
            :close-on-click-modal="false"
            :destroy-on-close="true"
            width="600px"
        >
            <template #header>
                <div class="title">
                    新增作品
                </div>
            </template>

            <el-scrollbar class="ba-table-form-scrollbar">
                <div class="ba-operate-form">
                    <el-form ref="workFormRef" :model="workForm" label-width="90px">
                        <el-form-item label="作品名称" prop="title">
                            <el-input v-model="workForm.title" placeholder="请输入作品名称" />
                        </el-form-item>

                        <el-form-item label="作品图片" prop="image">
                            <FormItem
                                label=""
                                type="image"
                                v-model="workForm.image"
                                prop="image"
                                :placeholder="'请上传作品图片'"
                            />
                        </el-form-item>

                        <el-form-item label="排序" prop="sort">
                            <el-input-number
                                v-model="workForm.sort"
                                :min="0"
                                :max="999"
                                controls-position="right"
                                placeholder="排序值，越大越靠前"
                            />
                        </el-form-item>

                        <el-form-item label="状态" prop="status">
                            <el-radio-group v-model="workForm.status">
                                <el-radio label="1">显示</el-radio>
                                <el-radio label="0">隐藏</el-radio>
                            </el-radio-group>
                        </el-form-item>

                        <el-form-item label="作品描述" prop="description">
                            <el-input
                                v-model="workForm.description"
                                type="textarea"
                                :rows="4"
                                placeholder="请输入作品详情描述"
                            />
                        </el-form-item>
                    </el-form>
                </div>
            </el-scrollbar>

            <template #footer>
                <div class="dialog-footer">
                    <el-button @click="workFormVisible = false">取消</el-button>
                    <el-button type="primary" @click="submitWork">确定</el-button>
                </div>
            </template>
        </el-dialog>
    </div>
</template>

<script setup lang="ts">
import { provide, ref } from 'vue'
import baTableClass from '/@/utils/baTable'
import PopupForm from './popupForm.vue'
import Table from '/@/components/table/index.vue'
import TableHeader from '/@/components/table/header/index.vue'
import { defaultOptButtons } from '/@/components/table'
import { baTableApi } from '/@/api/common'
import { ElMessageBox, ElMessage } from 'element-plus'
import FormItem from '/@/components/formItem/index.vue'

defineOptions({
    name: 'content/artist',
})

const baTable = new baTableClass(
    new baTableApi('/admin/content.Artist/'),
    {
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', operator: '=', operatorPlaceholder: 'ID', width: 70 },
            {
                label: '姓名',
                prop: 'name',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '姓名',
            },
            {
                label: '头像',
                prop: 'image',
                align: 'center',
                operator: false,
                render: 'image',
            },
            {
                label: '头衔',
                prop: 'title',
                align: 'center',
                operator: 'LIKE',
                operatorPlaceholder: '头衔',
            },
            {
                label: '状态',
                prop: 'status',
                align: 'center',
                operator: 'IN',
                operatorPlaceholder: '状态',
                render: 'switch',
                replaceValue: { '0': '禁用', '1': '启用' },
            },
            {
                label: '排序',
                prop: 'sort',
                align: 'center',
                operator: 'RANGE',
                operatorPlaceholder: '排序',
                width: 80,
                sortable: 'custom',
            },
            {
                label: '创建时间',
                prop: 'create_time',
                align: 'center',
                operator: 'RANGE',
                operatorPlaceholder: '创建时间',
                width: 160,
                render: 'datetime',
                sortable: 'custom',
            },
            {
                label: '更新时间',
                prop: 'update_time',
                align: 'center',
                operator: 'RANGE',
                operatorPlaceholder: '更新时间',
                width: 160,
                render: 'datetime',
                sortable: 'custom',
            },
            {
                label: '操作',
                prop: 'operate',
                align: 'center',
                width: 260,
                render: 'buttons',
                buttons: [
                    ...defaultOptButtons(['edit', 'delete']),
                    {
                        render: 'basicButton',
                        name: 'artistWork',
                        type: 'primary',
                        icon: 'fa fa-image',
                        text: '作品管理',
                        click: (row: any) => handleArtistWork(row),
                    },
                ],
                operator: false,
            },
        ],
    },
    {
        defaultItems: {},
    }
)

const workDialogVisible = ref(false)
const currentArtist = ref<any>(null)
const workList = ref<any[]>([])
const workLoading = ref(false)
const workFormVisible = ref(false)
const workForm = ref<any>({
    title: '',
    image: '',
    description: '',
    sort: 0,
    status: '1',
})

const handleArtistWork = async (row: any) => {
    currentArtist.value = row
    await loadWorks()
    workDialogVisible.value = true
}

const loadWorks = async () => {
    if (!currentArtist.value) return
    workLoading.value = true
    try {
        // 使用后台 Artist::read 接口，返回 row + works
        const res: any = await baTable.api.postData('read', { id: currentArtist.value.id })
        workList.value = res.data.works || []
    } catch (e) {
        // ignore
    } finally {
        workLoading.value = false
    }
}

const openAddWork = () => {
    workForm.value = {
        title: '',
        image: '',
        description: '',
        sort: 0,
        status: '1',
    }
    workFormVisible.value = true
}

const submitWork = async () => {
    if (!currentArtist.value) return
    try {
        await baTable.api.postData('addWork', {
            artist_id: currentArtist.value.id,
            ...workForm.value,
        })
        ElMessage.success('作品已添加')
        workFormVisible.value = false
        await loadWorks()
    } catch (e: any) {
        ElMessage.error(e?.msg || '添加失败')
    }
}

const deleteWork = (row: any) => {
    ElMessageBox.confirm('确认删除该作品吗？', '提示', {
        type: 'warning',
    })
        .then(async () => {
            try {
                await baTable.api.postData('delWork', { ids: [row.id] })
                ElMessage.success('删除成功')
                await loadWorks()
            } catch (e: any) {
                ElMessage.error(e?.msg || '删除失败')
            }
        })
        .catch(() => {})
}

baTable.mount()
baTable.getData()

provide('baTable', baTable)
</script>


