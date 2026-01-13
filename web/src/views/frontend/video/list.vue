<template>
    <div class="video-page" v-loading="state.loading">
        <Header />
        <el-main class="video-main">
            <div class="video-header">
                <h1>{{ t('index.Hot Videos') }}</h1>
                <p>{{ t('video.Subtitle') }}</p>
            </div>
            <el-row :gutter="24">
                <el-col v-for="item in state.list" :key="item.id" :xs="24" :md="12">
                    <div class="video-item" @click="openVideo(item.media_url)">
                        <div class="video-thumb">
                            <img v-if="item.cover_image" :src="item.cover_image" alt="" />
                            <div v-else class="video-placeholder">
                                <i class="fa fa-video-camera"></i>
                            </div>
                            <div class="video-play">
                                <i class="fa fa-play"></i>
                            </div>
                        </div>
                        <div class="video-info">
                            <h3 class="video-title" :title="item.title">{{ item.title }}</h3>
                            <p class="video-desc">{{ item.description }}</p>
                        </div>
                    </div>
                </el-col>
            </el-row>

            <div class="pagination" v-if="state.total > state.pageSize">
                <el-pagination
                    background
                    layout="prev, pager, next"
                    :total="state.total"
                    :page-size="state.pageSize"
                    :current-page="state.page"
                    @current-change="handlePageChange"
                />
            </div>
        </el-main>
        <Footer />
    </div>
</template>

<script setup lang="ts">
import { onMounted, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import Header from '/@/layouts/frontend/components/header.vue'
import Footer from '/@/layouts/frontend/components/footer.vue'
import { fetchVideoList } from '/@/api/frontend/home'

interface VideoItem {
    id: number
    title: string
    description: string | null
    cover_image: string | null
    media_url: string | null
    media_type: string
}

const { t } = useI18n()

const state = reactive<{
    loading: boolean
    list: VideoItem[]
    total: number
    page: number
    pageSize: number
}>({
    loading: false,
    list: [],
    total: 0,
    page: 1,
    pageSize: 6,
})

const loadVideos = async () => {
    state.loading = true
    try {
        const { data } = await fetchVideoList({ page: state.page, limit: state.pageSize })
        if (data.code === 1) {
            state.list = data.data.list || []
            state.total = data.data.total || 0
        } else {
        ElMessage.error(data.msg || t('Load failed'))
        }
    } catch (error: any) {
        ElMessage.error(error?.message || t('Load failed'))
    } finally {
        state.loading = false
    }
}

const handlePageChange = (page: number) => {
    state.page = page
    loadVideos()
}

const openVideo = (url?: string | null) => {
    if (!url) {
        ElMessage.warning(t('video.Video missing'))
        return
    }
    window.open(url, '_blank')
}

onMounted(() => {
    loadVideos()
})
</script>

<style scoped lang="scss">
.video-page {
    min-height: 100vh;
    background: linear-gradient(135deg, #0f172a, #1e293b);
    color: #fff;
    display: flex;
    flex-direction: column;
}
.video-main {
    flex: 1;
    width: 100%;
    max-width: 1100px;
    margin: 120px auto 40px;
    padding: 0 20px;
}
.video-header {
    text-align: center;
    margin-bottom: 36px;
    h1 {
        font-size: 38px;
        font-weight: 700;
    }
    p {
        margin-top: 8px;
        color: rgba(226, 232, 240, 0.7);
    }
}
.video-item {
    background: rgba(15, 23, 42, 0.78);
    border-radius: 16px;
    overflow: hidden;
    margin-bottom: 24px;
    box-shadow: 0 20px 48px rgba(8, 47, 73, 0.35);
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    &:hover {
        transform: translateY(-4px);
        box-shadow: 0 28px 56px rgba(8, 47, 73, 0.4);
    }
}
.video-thumb {
    position: relative;
    padding-top: 56.25%;
    background: rgba(30, 41, 59, 0.9);
    img {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .video-placeholder {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 48px;
        color: rgba(148, 163, 184, 0.75);
    }
    .video-play {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(59, 130, 246, 0.4);
        opacity: 0;
        transition: opacity 0.2s ease;
        i {
            font-size: 32px;
        }
    }
}
.video-item:hover .video-play {
    opacity: 1;
}
.video-info {
    padding: 20px;
    .video-title {
        font-size: 20px;
        font-weight: 600;
        margin-bottom: 8px;
    }
    .video-desc {
        color: rgba(226, 232, 240, 0.75);
        height: 48px;
        overflow: hidden;
        line-height: 1.5;
    }
}
.pagination {
    display: flex;
    justify-content: center;
    margin-top: 16px;
}
@media screen and (max-width: 768px) {
    .video-main {
        margin-top: 100px;
    }
}
</style>


