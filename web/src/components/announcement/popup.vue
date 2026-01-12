<template>
    <div v-if="visible" class="announcement-popup-overlay" @click.self="handleOverlayClick">
        <div class="announcement-popup" :style="{ animationDelay: delay + 'ms' }">
            <div class="announcement-popup-header">
                <h3 class="announcement-popup-title">{{ currentAnnouncement.title }}</h3>
                <button
                    v-if="canClose"
                    class="announcement-popup-close"
                    @click="closePopup"
                    title="关闭"
                >
                    <svg viewBox="0 0 24 24" width="20" height="20">
                        <path
                            fill="currentColor"
                            d="M19,6.41L17.59,5L12,10.59L6.41,5L5,6.41L10.59,12L5,17.59L6.41,19L12,13.41L17.59,19L19,17.59L13.41,12L19,6.41Z"
                        />
                    </svg>
                </button>
            </div>
            <div class="announcement-popup-content">
                <div v-html="currentAnnouncement.content"></div>
            </div>
            <div class="announcement-popup-footer">
                <button
                    v-if="canClose"
                    class="announcement-popup-btn announcement-popup-btn-primary"
                    @click="closePopup"
                >
                    我知道了
                </button>
                <div v-else class="announcement-popup-countdown">
                    {{ countdown }}秒后可关闭
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch } from 'vue'
import axios from '/@/utils/axios'

interface AnnouncementItem {
    id: number
    title: string
    content: string
    type: string
    is_popup: number
    popup_delay: number
    status: string
    sort: number
    view_count: number
    start_time?: string
    end_time?: string
}

const visible = ref(false)
const canClose = ref(false)
const countdown = ref(0)
const currentAnnouncement = ref<AnnouncementItem | null>(null)
const popupAnnouncements = ref<AnnouncementItem[]>([])
const currentIndex = ref(0)
const delay = ref(0)
let countdownTimer: number | null = null
let delayTimer: number | null = null

// 获取弹出公告列表
const getPopupAnnouncements = async () => {
    try {
        const response = await axios.get('/api/Announcement/getPopupAnnouncements')
        if (response.data.code === 1) {
            popupAnnouncements.value = response.data.data.list
            if (popupAnnouncements.value.length > 0) {
                showNextAnnouncement()
            }
        }
    } catch (error) {
        console.error('获取弹出公告失败:', error)
    }
}

// 显示下一个公告
const showNextAnnouncement = () => {
    if (currentIndex.value >= popupAnnouncements.value.length) {
        return
    }

    currentAnnouncement.value = popupAnnouncements.value[currentIndex.value]
    delay.value = currentAnnouncement.value.popup_delay || 3000

    visible.value = true
    canClose.value = false
    countdown.value = Math.ceil(delay.value / 1000)

    // 延迟显示关闭按钮
    delayTimer = window.setTimeout(() => {
        canClose.value = true
        startCountdown()
    }, delay.value)
}

// 开始倒计时
const startCountdown = () => {
    countdownTimer = window.setInterval(() => {
        countdown.value--
        if (countdown.value <= 0) {
            stopCountdown()
        }
    }, 1000)
}

// 停止倒计时
const stopCountdown = () => {
    if (countdownTimer) {
        clearInterval(countdownTimer)
        countdownTimer = null
    }
}

// 关闭弹出框
const closePopup = () => {
    visible.value = false
    stopCountdown()
    if (delayTimer) {
        clearTimeout(delayTimer)
        delayTimer = null
    }

    // 显示下一个公告
    currentIndex.value++
    setTimeout(() => {
        showNextAnnouncement()
    }, 500) // 等待关闭动画完成
}

// 处理遮罩点击
const handleOverlayClick = () => {
    if (canClose.value) {
        closePopup()
    }
}

// 检查是否已显示过公告
const checkAnnouncementShown = () => {
    const shownAnnouncements = localStorage.getItem('shown_announcements')
    if (shownAnnouncements) {
        const shownIds = JSON.parse(shownAnnouncements)
        const today = new Date().toDateString()

        // 清除过期的记录（不是今天的）
        const filteredIds = shownIds.filter((item: any) => item.date === today)
        localStorage.setItem('shown_announcements', JSON.stringify(filteredIds))

        // 过滤掉已经显示过的公告
        popupAnnouncements.value = popupAnnouncements.value.filter(announcement => {
            return !filteredIds.some((item: any) => item.id === announcement.id)
        })
    }
}

// 记录已显示的公告
const recordAnnouncementShown = (announcementId: number) => {
    const shownAnnouncements = localStorage.getItem('shown_announcements') || '[]'
    const shownIds = JSON.parse(shownAnnouncements)
    const today = new Date().toDateString()

    shownIds.push({ id: announcementId, date: today })
    localStorage.setItem('shown_announcements', JSON.stringify(shownIds))
}

// 初始化
const init = () => {
    getPopupAnnouncements()
}

// 监听公告列表变化
watch(popupAnnouncements, (newList) => {
    checkAnnouncementShown()
    if (newList.length > 0 && !visible.value) {
        showNextAnnouncement()
    }
}, { immediate: true })

onMounted(() => {
    init()
})

onUnmounted(() => {
    stopCountdown()
    if (delayTimer) {
        clearTimeout(delayTimer)
    }
})

// 监听关闭事件，记录已显示的公告
watch(visible, (newVisible) => {
    if (!newVisible && currentAnnouncement.value) {
        recordAnnouncementShown(currentAnnouncement.value.id)
    }
})
</script>

<style scoped lang="scss">
.announcement-popup-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background-color: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    backdrop-filter: blur(2px);
}

.announcement-popup {
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
    max-width: 500px;
    width: 90%;
    max-height: 80vh;
    overflow: hidden;
    animation: popupEnter 0.3s ease-out;
    transform: scale(0.9);
    opacity: 0;

    &.popup-enter-active {
        animation: popupEnter 0.3s ease-out;
    }
}

@keyframes popupEnter {
    0% {
        transform: scale(0.9);
        opacity: 0;
    }
    100% {
        transform: scale(1);
        opacity: 1;
    }
}

.announcement-popup-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px 16px;
    border-bottom: 1px solid #e5e5e5;

    .announcement-popup-title {
        margin: 0;
        font-size: 18px;
        font-weight: 600;
        color: #303133;
        flex: 1;
        margin-right: 12px;
    }

    .announcement-popup-close {
        background: none;
        border: none;
        cursor: pointer;
        padding: 4px;
        border-radius: 4px;
        color: #909399;
        transition: all 0.2s;

        &:hover {
            background-color: #f5f5f5;
            color: #606266;
        }

        &:disabled {
            cursor: not-allowed;
            opacity: 0.5;
        }
    }
}

.announcement-popup-content {
    padding: 20px 24px;
    max-height: 400px;
    overflow-y: auto;
    line-height: 1.6;
    color: #606266;

    :deep(p) {
        margin: 0 0 12px 0;

        &:last-child {
            margin-bottom: 0;
        }
    }

    :deep(ul), :deep(ol) {
        margin: 12px 0;
        padding-left: 20px;
    }

    :deep(li) {
        margin: 4px 0;
    }

    :deep(strong), :deep(b) {
        font-weight: 600;
        color: #303133;
    }

    :deep(a) {
        color: #409eff;
        text-decoration: none;

        &:hover {
            text-decoration: underline;
        }
    }
}

.announcement-popup-footer {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    padding: 16px 24px 20px;
    border-top: 1px solid #e5e5e5;

    .announcement-popup-btn {
        padding: 8px 16px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
        transition: all 0.2s;

        &.announcement-popup-btn-primary {
            background-color: #409eff;
            color: white;

            &:hover {
                background-color: #337ecc;
            }
        }

        &:disabled {
            cursor: not-allowed;
            opacity: 0.5;
        }
    }

    .announcement-popup-countdown {
        color: #909399;
        font-size: 14px;
        font-weight: 500;
    }
}

// 响应式设计
@media screen and (max-width: 768px) {
    .announcement-popup {
        width: 95%;
        max-height: 90vh;
    }

    .announcement-popup-header,
    .announcement-popup-content,
    .announcement-popup-footer {
        padding-left: 16px;
        padding-right: 16px;
    }

    .announcement-popup-content {
        max-height: 300px;
    }
}

@media screen and (max-width: 480px) {
    .announcement-popup-header,
    .announcement-popup-content,
    .announcement-popup-footer {
        padding-left: 12px;
        padding-right: 12px;
    }

    .announcement-popup-content {
        max-height: 250px;
        padding-top: 16px;
        padding-bottom: 16px;
    }
}
</style>
