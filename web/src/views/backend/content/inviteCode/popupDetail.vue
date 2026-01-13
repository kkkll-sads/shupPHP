<template>
    <!-- 邀请码详情弹窗 -->
    <el-dialog
        class="ba-detail-dialog"
        :close-on-click-modal="false"
        :destroy-on-close="true"
        :model-value="baTable.form.operate === 'view'"
        @close="baTable.toggleForm"
        width="70%"
        title="邀请码详情"
    >
        <el-scrollbar class="ba-detail-scrollbar">
            <div class="ba-detail-content">
                <!-- 基本信息 -->
                <el-card class="box-card" shadow="hover">
                    <template #header>
                        <div class="card-header">
                            <span>邀请码信息</span>
                        </div>
                    </template>
                    <el-row :gutter="20">
                        <el-col :xs="24" :sm="12" :md="8">
                            <div class="detail-item">
                                <div class="detail-label">邀请码:</div>
                                <div class="detail-value">{{ baTable.form.items?.code }}</div>
                            </div>
                        </el-col>
                        <el-col :xs="24" :sm="12" :md="8">
                            <div class="detail-item">
                                <div class="detail-label">状态:</div>
                                <el-tag :type="baTable.form.items?.status === '1' ? 'success' : 'danger'">
                                    {{ baTable.form.items?.status === '1' ? '启用' : '禁用' }}
                                </el-tag>
                            </div>
                        </el-col>
                        <el-col :xs="24" :sm="12" :md="8">
                            <div class="detail-item">
                                <div class="detail-label">已使用次数:</div>
                                <div class="detail-value">{{ baTable.form.items?.use_count }} 次</div>
                            </div>
                        </el-col>
                    </el-row>
                </el-card>

                <!-- 创建者信息 -->
                <el-card class="box-card" shadow="hover">
                    <template #header>
                        <div class="card-header">
                            <span>创建者信息 (当前)</span>
                        </div>
                    </template>
                    <el-row :gutter="20" v-if="baTable.form.items?.user">
                        <el-col :xs="24" :sm="12" :md="6">
                            <div class="detail-item">
                                <div class="detail-label">ID:</div>
                                <div class="detail-value">{{ baTable.form.items.user.id }}</div>
                            </div>
                        </el-col>
                        <el-col :xs="24" :sm="12" :md="6">
                            <div class="detail-item">
                                <div class="detail-label">昵称:</div>
                                <div class="detail-value">{{ baTable.form.items.user.nickname }}</div>
                            </div>
                        </el-col>
                        <el-col :xs="24" :sm="12" :md="6">
                            <div class="detail-item">
                                <div class="detail-label">用户名:</div>
                                <div class="detail-value">{{ baTable.form.items.user.username }}</div>
                            </div>
                        </el-col>
                        <el-col :xs="24" :sm="12" :md="6">
                            <div class="detail-item">
                                <div class="detail-label">手机号:</div>
                                <div class="detail-value">{{ baTable.form.items.user.mobile }}</div>
                            </div>
                        </el-col>
                    </el-row>
                    <el-empty v-else description="暂无创建者信息" />
                </el-card>

                <!-- 上级用户信息 -->
                <el-card class="box-card" shadow="hover">
                    <template #header>
                        <div class="card-header">
                            <span>上级用户信息</span>
                        </div>
                    </template>
                    <div v-if="baTable.form.items?.upuser">
                        <el-row :gutter="20">
                            <el-col :xs="24" :sm="12" :md="6">
                                <div class="detail-item">
                                    <div class="detail-label">ID:</div>
                                    <div class="detail-value">{{ baTable.form.items.upuser.id }}</div>
                                </div>
                            </el-col>
                            <el-col :xs="24" :sm="12" :md="6">
                                <div class="detail-item">
                                    <div class="detail-label">昵称:</div>
                                    <div class="detail-value">{{ baTable.form.items.upuser.nickname }}</div>
                                </div>
                            </el-col>
                            <el-col :xs="24" :sm="12" :md="6">
                                <div class="detail-item">
                                    <div class="detail-label">用户名:</div>
                                    <div class="detail-value">{{ baTable.form.items.upuser.username }}</div>
                                </div>
                            </el-col>
                            <el-col :xs="24" :sm="12" :md="6">
                                <div class="detail-item">
                                    <div class="detail-label">手机号:</div>
                                    <div class="detail-value">{{ baTable.form.items.upuser.mobile }}</div>
                                </div>
                            </el-col>
                        </el-row>
                    </div>
                    <el-empty v-else description="暂无上级用户" />
                </el-card>

                <!-- 上级的上级用户信息 -->
                <el-card class="box-card" shadow="hover" v-if="baTable.form.items?.upupuser">
                    <template #header>
                        <div class="card-header">
                            <span>上级的上级用户信息</span>
                        </div>
                    </template>
                    <el-row :gutter="20">
                        <el-col :xs="24" :sm="12" :md="6">
                            <div class="detail-item">
                                <div class="detail-label">ID:</div>
                                <div class="detail-value">{{ baTable.form.items.upupuser.id }}</div>
                            </div>
                        </el-col>
                        <el-col :xs="24" :sm="12" :md="6">
                            <div class="detail-item">
                                <div class="detail-label">昵称:</div>
                                <div class="detail-value">{{ baTable.form.items.upupuser.nickname }}</div>
                            </div>
                        </el-col>
                        <el-col :xs="24" :sm="12" :md="6">
                            <div class="detail-item">
                                <div class="detail-label">用户名:</div>
                                <div class="detail-value">{{ baTable.form.items.upupuser.username }}</div>
                            </div>
                        </el-col>
                        <el-col :xs="24" :sm="12" :md="6">
                            <div class="detail-item">
                                <div class="detail-label">手机号:</div>
                                <div class="detail-value">{{ baTable.form.items.upupuser.mobile }}</div>
                            </div>
                        </el-col>
                    </el-row>
                </el-card>

                <!-- 下级用户列表 -->
                <el-card class="box-card" shadow="hover">
                    <template #header>
                        <div class="card-header">
                            <span>下级用户列表 (共 {{ baTable.form.items?.child_count || 0 }} 人)</span>
                        </div>
                    </template>
                    <el-table :data="baTable.form.items?.child_users || []" stripe>
                        <el-table-column prop="id" label="用户ID" width="100" align="center" />
                        <el-table-column prop="nickname" label="昵称" align="center" />
                        <el-table-column prop="mobile" label="手机号" align="center" />
                        <el-table-column prop="username" label="用户名" align="center" />
                    </el-table>
                    <el-empty v-if="!baTable.form.items?.child_users?.length" description="暂无下级用户" />
                </el-card>
            </div>
        </el-scrollbar>

        <template #footer>
            <div>
                <el-button @click="baTable.toggleForm('')">关闭</el-button>
            </div>
        </template>
    </el-dialog>
</template>

<script setup lang="ts">
import { inject } from 'vue'
import type baTableClass from '/@/utils/baTable'

const baTable = inject('baTable') as baTableClass
</script>

<style scoped lang="scss">
.ba-detail-dialog {
    :deep(.el-dialog__body) {
        padding: 20px;
    }
}

.ba-detail-scrollbar {
    height: 600px;
}

.ba-detail-content {
    padding: 10px;
}

.box-card {
    margin-bottom: 20px;

    :deep(.el-card__body) {
        padding: 15px;
    }
}

.card-header {
    display: flex;
    align-items: center;
    font-weight: bold;
    font-size: 14px;
}

.detail-item {
    margin-bottom: 10px;
}

.detail-label {
    font-weight: 600;
    margin-bottom: 5px;
    color: #606266;
}

.detail-value {
    color: #303133;
    word-break: break-all;
}
</style>

