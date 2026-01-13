<template>
    <div v-if="announcements.length > 0" class="announcement-scroll-container">
        <div class="announcement-scroll-wrapper">
            <div class="announcement-scroll-content" :style="{ transform: `translateX(${scrollPosition}px)` }">
                <div
                    v-for="(announcement, index) in duplicatedAnnouncements"
                    :key="`${announcement.id}-${index}`"
                    class="announcement-scroll-item"
                >
                    <svg class="announcement-scroll-icon" viewBox="0 0 24 24" width="16" height="16">
                        <path
                            fill="currentColor"
                            d="M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M12,4A8,8 0 0,1 20,12A8,8 0 0,1 12,20A8,8 0 0,1 4,12A8,8 0 0,1 12,4M11,6V8H13V6H11M11,10V18H13V10H11Z"
                        />
                    </svg>
                    <span class="announcement-scroll-text">{{ announcement.title }}</span>
                    <span class="announcement-scroll-separator">·</span>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed, watch } from 'vue'
import axios from '/@/utils/axios'

interface AnnouncementItem {
    id: number
    title: string
    content: string
    type: string
    status: string
    sort: number
    view_count: number
    start_time?: string
    end_time?: string
}

const announcements = ref<AnnouncementItem[]>([])
const scrollPosition = ref(0)
const animationId = ref<number | null>(null)
const speed = ref(1) // 滚动速度，像素/帧
const isPaused = ref(false)

// 复制公告列表以实现无缝滚动
const duplicatedAnnouncements = computed(() => {
    if (announcements.value.length === 0) return []
    return [...announcements.value, ...announcements.value, ...announcements.value]
})

// 获取滚动公告列表
const getScrollAnnouncements = async () => {
    try {
        const response = await axios.get('/api/Announcement/getScrollAnnouncements')
        if (response.data.code === 1) {
            announcements.value = response.data.data.list
        }
    } catch (error) {
        console.error('获取滚动公告失败:', error)
    }
}

// 开始滚动动画
const startScroll = () => {
    if (animationId.value) return

    const animate = () => {
        if (!isPaused.value && announcements.value.length > 0) {
            scrollPosition.value -= speed.value

            // 当滚动到第一个副本的末尾时，重置位置
            const itemWidth = 300 // 估算每个公告项的宽度
            const totalWidth = announcements.value.length * itemWidth
            if (Math.abs(scrollPosition.value) >= totalWidth) {
                scrollPosition.value = 0
            }
        }

        animationId.value = requestAnimationFrame(animate)
    }

    animationId.value = requestAnimationFrame(animate)
}

// 停止滚动动画
const stopScroll = () => {
    if (animationId.value) {
        cancelAnimationFrame(animationId.value)
        animationId.value = null
    }
}

// 鼠标悬停暂停滚动
const pauseScroll = () => {
    isPaused.value = true
}

const resumeScroll = () => {
    isPaused.value = false
}

// 初始化
const init = () => {
    getScrollAnnouncements()
    startScroll()
}

onMounted(() => {
    init()
})

onUnmounted(() => {
    stopScroll()
})

// 监听公告列表变化，重新开始滚动
watch(announcements, () => {
    scrollPosition.value = 0
    if (announcements.value.length > 0) {
        startScroll()
    } else {
        stopScroll()
    }
})

// 定义组件事件
defineEmits<{
    pause: []
    resume: []
}>()

// 定义组件暴露的方法
defineExpose({
    pauseScroll,
    resumeScroll,
    refresh: getScrollAnnouncements
})
</script>

<style scoped lang="scss">
.announcement-scroll-container {
    width: 100%;
    background: linear-gradient(90deg, #409eff 0%, #66b1ff 100%);
    border-radius: 4px;
    overflow: hidden;
    position: relative;

    &:hover {
        background: linear-gradient(90deg, #337ecc 0%, #5cadff 100%);
    }
}

.announcement-scroll-wrapper {
    position: relative;
    height: 40px;
    overflow: hidden;
}

.announcement-scroll-content {
    display: flex;
    align-items: center;
    height: 100%;
    white-space: nowrap;
    transition: none;
    position: absolute;
    left: 0;
    top: 0;
}

.announcement-scroll-item {
    display: flex;
    align-items: center;
    padding: 0 16px;
    color: white;
    font-size: 14px;
    font-weight: 500;
    flex-shrink: 0;
    animation: none;

    &:hover {
        background-color: rgba(255, 255, 255, 0.1);
        border-radius: 4px;
    }
}

.announcement-scroll-icon {
    margin-right: 8px;
    flex-shrink: 0;
}

.announcement-scroll-text {
    flex-shrink: 0;
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.announcement-scroll-separator {
    margin-left: 12px;
    opacity: 0.7;
    flex-shrink: 0;
}

// 响应式设计
@media screen and (max-width: 768px) {
    .announcement-scroll-text {
        max-width: 150px;
    }

    .announcement-scroll-item {
        padding: 0 12px;
        font-size: 13px;
    }
}

@media screen and (max-width: 480px) {
    .announcement-scroll-text {
        max-width: 120px;
    }

    .announcement-scroll-item {
        padding: 0 8px;
        font-size: 12px;
    }

    .announcement-scroll-icon {
        width: 14px;
        height: 14px;
        margin-right: 6px;
    }

    .announcement-scroll-separator {
        margin-left: 8px;
    }
}
</style>
