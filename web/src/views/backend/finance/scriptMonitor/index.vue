
<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" title="自动化脚本监控" type="info" show-icon>
            <template #default>
                <div>监控以下自动化脚本的运行状态，支持手动执行脚本</div>
            </template>
        </el-alert>

        <el-row :gutter="20" class="script-cards">
            <el-col :span="12" v-for="script in scriptList" :key="script.key" style="margin-bottom: 20px">
                <el-card shadow="hover" class="script-card">
                    <template #header>
                        <div class="card-header">
                            <span class="script-name">{{ script.name }}</span>
                            <el-tag :type="getStatusType(script.status)" size="small">
                                {{ script.status_text }}
                            </el-tag>
                        </div>
                    </template>
                    <div class="script-info">
                        <div class="info-item">
                            <span class="label">执行命令：</span>
                            <span class="value">{{ script.command }}</span>
                        </div>
                        <div class="info-item">
                            <span class="label">定时计划：</span>
                            <span class="value">{{ script.cron }}</span>
                        </div>
                        <div class="info-item">
                            <span class="label">说明：</span>
                            <span class="value">{{ script.description }}</span>
                        </div>
                        <div class="info-item">
                            <span class="label">最后运行：</span>
                            <span class="value">{{ script.last_run_time_text }}</span>
                        </div>
                        <div class="info-item" v-if="script.log_exists">
                            <span class="label">日志文件：</span>
                            <span class="value">{{ script.log_file }} ({{ script.log_size_text }})</span>
                        </div>
                        <div class="info-item" v-else>
                            <span class="label">日志文件：</span>
                            <span class="value text-danger">文件不存在</span>
                        </div>
                    </div>
                    <div class="script-actions">
                        <el-button 
                            type="primary" 
                            @click="runScript(script)" 
                            :loading="runningScripts[script.key]"
                            :disabled="script.status === 'running' || script.is_running"
                        >
                            <el-icon><VideoPlay /></el-icon>
                            立即运行
                        </el-button>
                        <el-button
                            v-if="script.key === 'collection_matching'"
                            type="warning"
                            plain
                            @click="runScript(script, true)"
                            :loading="runningScripts[script.key + '_force']"
                        >
                            <el-icon><VideoPlay /></el-icon>
                            强制撮合
                        </el-button>
                        <el-button 
                            type="danger" 
                            @click="stopScript(script)" 
                            :loading="stoppingScripts[script.key]"
                            v-if="script.status === 'running' || script.is_running"
                        >
                            <el-icon><VideoPause /></el-icon>
                            停止运行
                        </el-button>
                        <el-button type="info" @click="viewLog(script)" v-if="script.log_exists">
                            <el-icon><Document /></el-icon>
                            查看日志
                        </el-button>
                        <el-button @click="refreshStatus">
                            <el-icon><Refresh /></el-icon>
                            刷新状态
                        </el-button>
                    </div>
                </el-card>
            </el-col>
        </el-row>

        <!-- 日志查看对话框 -->
        <el-dialog v-model="logDialogVisible" :title="'查看日志 - ' + currentScript?.name" width="80%" destroy-on-close>
            <div class="log-content" v-if="logData">
                <div class="log-info">
                    <el-tag size="small">总行数：{{ logData.total_lines }}</el-tag>
                    <el-tag size="small">显示行数：{{ logData.show_lines }}</el-tag>
                    <el-tag size="small">文件大小：{{ logData.file_size_text }}</el-tag>
                    <el-tag size="small">最后修改：{{ logData.last_modified_text }}</el-tag>
                </div>
                <el-scrollbar height="500px" class="log-scrollbar">
                    <pre class="log-text">{{ logData.content }}</pre>
                </el-scrollbar>
            </div>
            <template #footer>
                <el-button @click="logDialogVisible = false">关闭</el-button>
                <el-button type="primary" @click="refreshLog" v-if="currentScript">刷新日志</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { VideoPlay, VideoPause, Document, Refresh } from '@element-plus/icons-vue'
import createAxios from '/@/utils/axios'
import { ElMessage, ElMessageBox } from 'element-plus'

defineOptions({
    name: 'finance/scriptMonitor',
})

const { t } = useI18n()
const scriptList = ref<any[]>([])
const runningScripts = ref<Record<string, boolean>>({})
const stoppingScripts = ref<Record<string, boolean>>({})
const logDialogVisible = ref(false)
const currentScript = ref<any>(null)
const logData = ref<any>(null)

// 获取状态类型
const getStatusType = (status: string): 'success' | 'warning' | 'danger' | 'info' => {
    const typeMap: Record<string, 'success' | 'warning' | 'danger' | 'info'> = {
        normal: 'success',
        warning: 'warning',
        error: 'danger',
        running: 'warning', // 运行中显示为警告色（橙色）
    }
    return typeMap[status] || 'info'
}

// 加载脚本列表
const loadScriptList = async () => {
    try {
        const res = await createAxios({
            url: '/admin/finance.ScriptMonitor/index',
            method: 'get',
        })
        if (res.code === 1) {
            scriptList.value = res.data.list || []
        }
    } catch (error) {
        console.error('加载脚本列表失败:', error)
    }
}

// 运行脚本
const runScript = async (script: any, force = false) => {
    try {
        await ElMessageBox.confirm(
            force ? `确定强制执行 "${script.name}" 吗？（忽略场次时间检查）` : `确定要立即执行 "${script.name}" 脚本吗？`,
            force ? '确认强制执行' : '确认执行',
            {
                confirmButtonText: '确定',
                cancelButtonText: '取消',
                type: 'warning',
            }
        )

        const loadingKey = force ? `${script.key}_force` : script.key
        runningScripts.value[loadingKey] = true
        const res = await createAxios({
            url: '/admin/finance.ScriptMonitor/run',
            method: 'post',
            data: { key: script.key, force: force ? 1 : 0 },
        })
        
        if (res.code === 1) {
            ElMessage.success('脚本执行成功')
            // 显示执行结果
            if (res.data.output) {
                ElMessageBox.alert(res.data.output, '执行结果', {
                    confirmButtonText: '确定',
                    type: 'success',
                })
            }
            // 刷新状态
            await loadScriptList()
        } else {
            ElMessage.error(res.msg || '执行失败')
        }
    } catch (error: any) {
        if (error !== 'cancel') {
            ElMessage.error(error.msg || '执行失败')
        }
    } finally {
        const loadingKey = force ? `${script.key}_force` : script.key
        runningScripts.value[loadingKey] = false
    }
}

// 查看日志
const viewLog = async (script: any) => {
    currentScript.value = script
    logDialogVisible.value = true
    await refreshLog()
}

// 刷新日志
const refreshLog = async () => {
    if (!currentScript.value) return
    
    try {
        const res = await createAxios({
            url: '/admin/finance.ScriptMonitor/log',
            method: 'get',
            params: { key: currentScript.value.key },
        })
        if (res.code === 1) {
            logData.value = res.data
        } else {
            ElMessage.error(res.msg || '获取日志失败')
        }
    } catch (error) {
        ElMessage.error('获取日志失败')
    }
}

// 停止脚本
const stopScript = async (script: any) => {
    try {
        await ElMessageBox.confirm(
            `确定要停止 "${script.name}" 脚本吗？`,
            '确认停止',
            {
                confirmButtonText: '确定',
                cancelButtonText: '取消',
                type: 'warning',
            }
        )

        stoppingScripts.value[script.key] = true
        const res = await createAxios({
            url: '/admin/finance.ScriptMonitor/stop',
            method: 'post',
            data: { key: script.key },
        })
        
        if (res.code === 1) {
            ElMessage.success('脚本已停止')
            // 刷新状态
            await loadScriptList()
        } else {
            ElMessage.error(res.msg || '停止失败')
        }
    } catch (error: any) {
        if (error !== 'cancel') {
            ElMessage.error(error.msg || '停止失败')
        }
    } finally {
        stoppingScripts.value[script.key] = false
    }
}

// 刷新状态
const refreshStatus = async () => {
    ElMessage.info('正在刷新状态...')
    await loadScriptList()
    ElMessage.success('状态已刷新')
}

onMounted(() => {
    loadScriptList()
})
</script>

<style scoped lang="scss">
.script-cards {
    margin-top: 20px;
}

.script-card {
    height: 100%;

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;

        .script-name {
            font-size: 16px;
            font-weight: bold;
        }
    }

    .script-info {
        margin-bottom: 15px;

        .info-item {
            margin-bottom: 10px;
            display: flex;
            align-items: flex-start;

            .label {
                min-width: 80px;
                color: #606266;
                font-weight: 500;
            }

            .value {
                flex: 1;
                color: #303133;
                word-break: break-all;

                &.text-danger {
                    color: #f56c6c;
                }
            }
        }
    }

    .script-actions {
        display: flex;
        gap: 10px;
        margin-top: 15px;
    }
}

.log-content {
    .log-info {
        margin-bottom: 15px;
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .log-scrollbar {
        border: 1px solid #dcdfe6;
        border-radius: 4px;
        padding: 10px;
        background-color: #f5f7fa;

        .log-text {
            margin: 0;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.6;
            color: #303133;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
    }
}
</style>

