<template>
    <div class="ba-app-container">
        <el-card shadow="hover" :body-style="{ paddingBottom: '0' }">
            <template #header>
                <div class="card-header">
                    <span>用户抽奖次数查询</span>
                    <el-button type="primary" @click="onSearch">查询</el-button>
                </div>
            </template>

            <!-- 搜索表单 -->
            <el-form :model="searchForm" label-width="100px" style="margin-bottom: 20px">
                <el-row :gutter="20">
                    <el-col :xs="24" :sm="12" :md="8" :lg="6">
                        <el-form-item label="用户ID">
                            <el-input
                                v-model.number="searchForm.user_id"
                                type="number"
                                placeholder="输入用户ID"
                                @keyup.enter="onSearch"
                            />
                        </el-form-item>
                    </el-col>
                    <el-col :xs="24" :sm="12" :md="8" :lg="6">
                        <el-form-item label="用户名">
                            <el-input
                                v-model="searchForm.username"
                                placeholder="输入用户名"
                                @keyup.enter="onSearch"
                            />
                        </el-form-item>
                    </el-col>
                </el-row>
            </el-form>

            <!-- 用户列表 -->
            <el-table
                v-loading="loading"
                :data="userList"
                size="default"
                style="width: 100%"
            >
                <el-table-column prop="id" label="用户ID" width="80" />
                <el-table-column prop="username" label="用户名" width="150" />
                <el-table-column prop="nickname" label="昵称" width="150" show-overflow-tooltip />
                <el-table-column prop="direct_invite_count" label="直推人数" width="120" align="center" />
                <el-table-column prop="draw_count" label="剩余抽奖次数" width="150" align="center">
                    <template #default="{ row }">
                        <el-tag :type="row.draw_count > 0 ? 'success' : 'info'">
                            {{ row.draw_count }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column prop="join_time" label="注册时间" width="180" :formatter="() => ''" />
                <el-table-column label="操作" align="center" width="150" fixed="right">
                    <template #default="{ row }">
                        <el-button link type="primary" @click="onViewDetail(row)">
                            详情
                        </el-button>
                    </template>
                </el-table-column>
            </el-table>

            <!-- 分页 -->
            <el-pagination
                v-show="userList.length > 0"
                class="pagination"
                :current-page="pageData.current"
                :page-size="pageData.size"
                :page-sizes="[10, 20, 50, 100]"
                :total="pageData.total"
                layout="total, sizes, prev, pager, next, jumper"
                @size-change="pageData.size = $event"
                @current-page-change="pageData.current = $event"
                @pagination="onSearch"
            />
        </el-card>

        <!-- 用户详情弹窗 -->
        <el-dialog v-model="detailVisible" title="用户抽奖次数详情" width="50%">
            <el-descriptions v-if="selectedUser" :column="1" border>
                <el-descriptions-item label="用户ID">{{ selectedUser.id }}</el-descriptions-item>
                <el-descriptions-item label="用户名">{{ selectedUser.username }}</el-descriptions-item>
                <el-descriptions-item label="昵称">{{ selectedUser.nickname }}</el-descriptions-item>
                <el-descriptions-item label="直推人数">{{ selectedUser.direct_invite_count }}</el-descriptions-item>
                <el-descriptions-item label="剩余抽奖次数">
                    <el-tag :type="selectedUser.draw_count > 0 ? 'success' : 'info'">
                        {{ selectedUser.draw_count }}
                    </el-tag>
                </el-descriptions-item>
                <el-descriptions-item label="邀请码">{{ selectedUser.invite_code }}</el-descriptions-item>
                <el-descriptions-item label="邀请人ID">{{ selectedUser.inviter_id }}</el-descriptions-item>
                <el-descriptions-item label="注册时间">{{ selectedUser.join_time }}</el-descriptions-item>
            </el-descriptions>

            <!-- 直推人员列表 -->
            <div style="margin-top: 20px">
                <h4>直推人员列表</h4>
                <el-table
                    v-loading="inviteListLoading"
                    :data="directInviteList"
                    size="small"
                    style="width: 100%"
                >
                    <el-table-column prop="id" label="用户ID" width="80" />
                    <el-table-column prop="username" label="用户名" width="120" />
                    <el-table-column prop="nickname" label="昵称" width="120" show-overflow-tooltip />
                    <el-table-column prop="join_time" label="注册时间" width="160" :formatter="() => ''" />
                </el-table>
            </div>

            <template #footer>
                <el-button @click="detailVisible = false">关闭</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { calculateUserDrawCount } from '/@/api/backend/user/drawCount'
import createAxios from '/@/utils/axios'

const loading = ref(false)
const userList = ref<any[]>([])
const pageData = reactive({
    current: 1,
    size: 10,
    total: 0
})

const searchForm = reactive({
    user_id: '',
    username: ''
})

const detailVisible = ref(false)
const selectedUser = ref<any>(null)
const inviteListLoading = ref(false)
const directInviteList = ref<any[]>([])

const onSearch = async () => {
    loading.value = true
    try {
        const params: any = {
            page: pageData.current,
            limit: pageData.size
        }

        if (searchForm.user_id) {
            params.id = searchForm.user_id
        }
        if (searchForm.username) {
            params.username = searchForm.username
        }

        // 调用后端接口获取用户列表
        const res = await createAxios({
            url: '/admin/user.User/index',
            method: 'get',
            params: params
        })

        // 为每个用户计算抽奖次数
        const list = res.data.list || []
        for (const user of list) {
            try {
                const drawRes = await calculateUserDrawCount({ user_id: user.id })
                user.direct_invite_count = drawRes.data.direct_count || 0
                user.draw_count = drawRes.data.draw_count || 0
            } catch (error) {
                user.direct_invite_count = 0
                user.draw_count = 0
            }
        }

        userList.value = list
        pageData.total = res.data.total || 0
    } catch (error) {
        ElMessage.error((error as any).message || '查询失败')
    } finally {
        loading.value = false
    }
}

const onViewDetail = async (row: any) => {
    selectedUser.value = row
    detailVisible.value = true

    // 获取直推人员列表
    inviteListLoading.value = true
    try {
        const res = await createAxios({
            url: '/admin/user.User/index',
            method: 'get',
            params: {
                'inviter_id': row.id,
                limit: 100
            }
        })
        directInviteList.value = res.data.list || []
    } catch (error) {
        ElMessage.error('获取直推人员列表失败')
    } finally {
        inviteListLoading.value = false
    }
}
</script>

<style scoped>
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.pagination {
    text-align: right;
    margin-top: 20px;
}
</style>

