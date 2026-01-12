<template>
    <div>
        <Header />
        <Banner />
        <el-container class="container">
            <el-main class="main">
                <div class="main-container">
                    <div class="main-left">
                        <div class="main-title">{{ siteConfig.siteName }}</div>
                        <div class="main-content">
                            {{ $t('index.Steve Jobs') }}
                        </div>
                        <el-button
                            v-if="memberCenter.state.open"
                            @click="$router.push(memberCenterBaseRoutePath)"
                            class="container-button"
                            color="#ffffff"
                            size="large"
                        >
                            {{ $t('Member Center') }}
                        </el-button>
                        <el-button type="primary" size="large" class="container-button" @click="router.push({ name: 'finance' })">
                            {{ t('index.Go Finance') }}
                        </el-button>
                    </div>
                    <div class="main-right">
                        <img :src="indexCover" alt="" />
                    </div>
                </div>
                <div class="home-sections" v-loading="state.loading">
                    <section v-if="state.promoVideo" class="section promo-video">
                        <div class="section-header">
                            <h2>{{ t('index.Company Promo Video') }}</h2>
                        </div>
                        <div class="promo-video-wrapper">
                            <video
                                v-if="isVideo(state.promoVideo)"
                                class="promo-video-player"
                                controls
                                preload="none"
                                :poster="state.promoVideo?.cover_image || ''"
                            >
                                <source :src="state.promoVideo?.media_url" type="video/mp4" />
                                {{ t('index.Video unsupported') }}
                            </video>
                            <div v-else class="promo-video-cover" @click="openLink(state.promoVideo?.media_url)">
                                <img :src="state.promoVideo?.cover_image" alt="" />
                                <div class="promo-video-overlay">
                                    <i class="fa fa-play-circle"></i>
                                </div>
                            </div>
                            <div class="promo-video-info">
                                <h3 class="promo-video-title">{{ state.promoVideo?.title }}</h3>
                                <p class="promo-video-desc">{{ state.promoVideo?.description }}</p>
                                <el-button type="primary" size="large" @click="openLink(state.promoVideo?.media_url)">
                                    {{ t('index.Play Now') }}
                                </el-button>
                            </div>
                        </div>
                    </section>

                    <section v-if="state.resources.length" class="section resources">
                        <div class="section-header">
                            <h2>{{ t('index.Resources Title') }}</h2>
                        </div>
                        <el-row :gutter="24">
                            <el-col
                                v-for="item in state.resources"
                                :key="item.id"
                                :xs="24"
                                :sm="12"
                                :md="8"
                            >
                                <el-card shadow="hover" class="resource-card">
                                    <div class="resource-cover" @click="openLink(item.media_url)">
                                        <img v-if="item.media_type === 'image' && item.cover_image" :src="item.cover_image" alt="" />
                                        <div v-else class="resource-placeholder">
                                            <i :class="getResourceIcon(item)"></i>
                                        </div>
                                    </div>
                                    <div class="resource-body">
                                        <h3 class="resource-title" :title="item.title">{{ item.title }}</h3>
                                        <p class="resource-desc" :title="item.description">{{ item.description }}</p>
                                        <el-button text type="primary" @click="openLink(item.media_url)">
                                            {{ t('index.View Detail') }}
                                        </el-button>
                                    </div>
                                </el-card>
                            </el-col>
                        </el-row>
                    </section>

                    <section v-if="state.hotNews.length" class="section hot-news">
                        <div class="section-header">
                            <h2>{{ t('index.Hot News') }}</h2>
                            <el-button text type="primary" @click="goToNews">
                                {{ t('index.View More') }}
                                <i class="fa fa-angle-right"></i>
                            </el-button>
                        </div>
                        <el-row :gutter="20">
                            <el-col v-for="item in state.hotNews" :key="item.id" :xs="24" :sm="12">
                                <div class="news-item" @click="openNews(item)">
                                    <div class="news-cover">
                                        <img v-if="item.cover_image" :src="item.cover_image" alt="" />
                                        <div v-else class="news-placeholder">
                                            <i class="fa fa-newspaper-o"></i>
                                        </div>
                                    </div>
                                    <div class="news-content">
                                        <h3 class="news-title" :title="item.title">{{ item.title }}</h3>
                                        <p class="news-summary" :title="item.summary">{{ item.summary }}</p>
                                        <div class="news-meta">
                                            <span>{{ formatDate(item.publish_time) }}</span>
                                            <el-button text type="primary">
                                                {{ t('index.Read More') }}
                                            </el-button>
                                        </div>
                                    </div>
                                </div>
                            </el-col>
                        </el-row>
                    </section>

                    <section v-if="state.hotVideos.length" class="section hot-videos">
                        <div class="section-header">
                            <h2>{{ t('index.Hot Videos') }}</h2>
                            <el-button text type="primary" @click="goToVideos">
                                {{ t('index.View More') }}
                                <i class="fa fa-angle-right"></i>
                            </el-button>
                        </div>
                        <el-row :gutter="24">
                            <el-col
                                v-for="item in state.hotVideos"
                                :key="item.id"
                                :xs="24"
                                :sm="12"
                            >
                                <div class="video-card" @click="openLink(item.media_url)">
                                    <div class="video-cover">
                                        <img v-if="item.cover_image" :src="item.cover_image" alt="" />
                                        <div v-else class="video-placeholder">
                                            <i class="fa fa-video-camera"></i>
                                        </div>
                                        <div class="video-overlay">
                                            <i class="fa fa-play"></i>
                                        </div>
                                    </div>
                                    <div class="video-info">
                                        <h3 class="video-title">{{ item.title }}</h3>
                                        <p class="video-desc">{{ item.description }}</p>
                                    </div>
                                </div>
                            </el-col>
                        </el-row>
                    </section>
                </div>
            </el-main>
        </el-container>
        <Footer />
    </div>
</template>

<script setup lang="ts">
import { onMounted, reactive } from 'vue'
import { ElMessage, dayjs } from 'element-plus'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import indexCover from '/@/assets/index/index-cover.svg'
import { useSiteConfig } from '/@/stores/siteConfig'
import { useMemberCenter } from '/@/stores/memberCenter'
import Header from '/@/layouts/frontend/components/header.vue'
import Footer from '/@/layouts/frontend/components/footer.vue'
import Banner from '/@/components/banner/index.vue'
import { memberCenterBaseRoutePath } from '/@/router/static/memberCenterBase'
import { fetchHomeContent } from '/@/api/frontend/home'

interface MediaItem {
    id: number
    category: string
    title: string
    description: string
    cover_image: string | null
    media_url: string | null
    media_type: string
}

interface NewsItem {
    id: number
    title: string
    summary: string | null
    cover_image: string | null
    content: string | null
    link_url: string | null
    publish_time: number
}

const siteConfig = useSiteConfig()
const memberCenter = useMemberCenter()
const { t } = useI18n()
const router = useRouter()

const state = reactive<{
    loading: boolean
    promoVideo: MediaItem | null
    resources: MediaItem[]
    hotNews: NewsItem[]
    hotVideos: MediaItem[]
}>({
    loading: false,
    promoVideo: null,
    resources: [],
    hotNews: [],
    hotVideos: [],
})

const loadHomeContent = async () => {
    state.loading = true
    try {
        const { data } = await fetchHomeContent()
        if (data.code === 1) {
            state.promoVideo = data.data.promoVideo || null
            state.resources = (data.data.resources || []).map((item: any) => ({
                ...item,
                description: item.description || t('index.Resource default desc'),
            }))
            state.hotNews = (data.data.hotNews || []).map((item: any) => ({
                ...item,
                summary: item.summary || item.description || t('index.News default desc'),
            }))
            state.hotVideos = (data.data.hotVideos || []).map((item: any) => ({
                ...item,
                description: item.description || t('index.Video default desc'),
            }))
        } else {
            ElMessage.error(data.msg || t('Load failed'))
        }
    } catch (error: any) {
        ElMessage.error(error?.message || t('Load failed'))
    } finally {
        state.loading = false
    }
}

const isVideo = (media: MediaItem | null) => {
    if (!media?.media_url) return false
    if (media.media_type === 'video') return true
    return /\.(mp4|mov|webm|ogg)$/i.test(media.media_url)
}

const openLink = (url?: string | null) => {
    if (!url) return
    window.open(url, '_blank')
}

const getResourceIcon = (item: MediaItem) => {
    if (item.media_type === 'video') return 'fa fa-play-circle'
    if (item.media_type === 'document') return 'fa fa-file-text-o'
    return 'fa fa-picture-o'
}

const formatDate = (timestamp: number) => {
    if (!timestamp) return ''
    return dayjs.unix(timestamp).format('YYYY-MM-DD')
}

const openNews = (item: NewsItem) => {
    if (item.link_url) {
        openLink(item.link_url)
    } else {
        router.push({ name: 'newsDetail', params: { id: item.id } })
    }
}

const goToNews = () => {
    router.push({ name: 'newsList' })
}

const goToVideos = () => {
    router.push({ name: 'videoList' })
}

onMounted(() => {
    loadHomeContent()
})
</script>

<style scoped lang="scss">
.container-button {
    margin: 0 15px 15px 0;
}
.container {
    width: 100vw;
    min-height: 100vh;
    background: url(/@/assets/bg.jpg) repeat;
    color: var(--el-color-white);
    .main {
        min-height: calc(100vh - 260px);
        padding: 40px 0 80px;
        .main-container {
            display: flex;
            flex-direction: column;
            width: 76%;
            margin: 0 auto;
            align-items: center;
            text-align: center;
            .main-left {
                max-width: 680px;
                .main-title {
                    font-size: 45px;
                    font-weight: 600;
                }
                .main-content {
                    padding-top: 20px;
                    padding-bottom: 30px;
                    font-size: var(--el-font-size-large);
                    line-height: 1.6;
                }
            }
            .main-right {
                margin-top: 40px;
                img {
                    width: 360px;
                }
            }
        }
        .home-sections {
            width: 76%;
            margin: 60px auto 0;
            color: var(--el-text-color-primary);
        }
    }
}
.section {
    background-color: rgba(255, 255, 255, 0.96);
    border-radius: 16px;
    padding: 32px;
    margin-bottom: 32px;
    box-shadow: 0 18px 40px rgba(15, 23, 42, 0.15);
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    &:hover {
        transform: translateY(-4px);
        box-shadow: 0 24px 48px rgba(15, 23, 42, 0.18);
    }
}
.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
    h2 {
        font-size: 28px;
        font-weight: 600;
        color: #1f2d3d;
    }
    :deep(.el-button.is-text) {
        font-weight: 500;
        i {
            margin-left: 6px;
        }
    }
}
.promo-video-wrapper {
    display: flex;
    flex-wrap: wrap;
    gap: 24px;
    align-items: center;
}
.promo-video-player,
.promo-video-cover {
    width: 100%;
    max-width: 640px;
    border-radius: 16px;
    box-shadow: 0 16px 32px rgba(15, 23, 42, 0.2);
    background-color: #000;
    cursor: pointer;
    position: relative;
    overflow: hidden;
}
.promo-video-cover img {
    width: 100%;
    height: auto;
    display: block;
}
.promo-video-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.45);
    color: #fff;
    font-size: 54px;
}
.promo-video-info {
    flex: 1;
    min-width: 240px;
    .promo-video-title {
        font-size: 26px;
        font-weight: 600;
        color: #1f2d3d;
    }
    .promo-video-desc {
        margin: 18px 0 24px;
        color: #475569;
        line-height: 1.7;
    }
}
.resource-card {
    height: 100%;
    display: flex;
    flex-direction: column;
    .resource-cover {
        height: 180px;
        border-radius: 10px;
        overflow: hidden;
        background-color: #f5f7fa;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .resource-placeholder {
            font-size: 40px;
            color: #a0aec0;
        }
    }
    .resource-body {
        margin-top: 16px;
        .resource-title {
            font-size: 18px;
            font-weight: 600;
            color: #1f2933;
            margin-bottom: 6px;
        }
        .resource-desc {
            min-height: 48px;
            color: #606f7b;
            line-height: 1.5;
            margin-bottom: 8px;
        }
    }
}
.news-item {
    display: flex;
    gap: 18px;
    padding: 16px;
    border-radius: 12px;
    background-color: rgba(248, 250, 252, 0.85);
    cursor: pointer;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    &:hover {
        transform: translateY(-4px);
        box-shadow: 0 16px 34px rgba(15, 23, 42, 0.18);
    }
    .news-cover {
        width: 120px;
        height: 90px;
        border-radius: 10px;
        overflow: hidden;
        background-color: #e2e8f0;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .news-placeholder {
            font-size: 32px;
            color: #94a3b8;
        }
    }
    .news-content {
        flex: 1;
        display: flex;
        flex-direction: column;
        .news-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 6px;
            color: #1f2937;
        }
        .news-summary {
            flex: 1;
            color: #64748b;
            line-height: 1.5;
            margin-bottom: 8px;
        }
        .news-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #94a3b8;
        }
    }
}
.video-card {
    background-color: rgba(15, 23, 42, 0.75);
    color: #fff;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 18px 36px rgba(15, 23, 42, 0.4);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    cursor: pointer;
    &:hover {
        transform: translateY(-4px);
        box-shadow: 0 22px 44px rgba(15, 23, 42, 0.45);
    }
    .video-cover {
        position: relative;
        padding-top: 56.5%;
        background-color: #1f2937;
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
            font-size: 40px;
            color: rgba(255, 255, 255, 0.6);
        }
        .video-overlay {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.4), rgba(139, 92, 246, 0.45));
            opacity: 0;
            transition: opacity 0.2s ease;
            .fa {
                font-size: 36px;
            }
        }
    }
    &:hover .video-overlay {
        opacity: 1;
    }
    .video-info {
        padding: 20px;
        .video-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 6px;
        }
        .video-desc {
            color: rgba(255, 255, 255, 0.75);
            line-height: 1.6;
        }
    }
}
.header {
    background-color: transparent !important;
    box-shadow: none !important;
    position: fixed;
    width: 100%;
    :deep(.header-logo) {
        span {
            padding-left: 4px;
            color: var(--el-color-white);
        }
    }
    :deep(.frontend-header-menu) {
        background: transparent;
        .el-menu-item,
        .el-sub-menu .el-sub-menu__title {
            color: var(--el-color-white);
            &.is-active {
                color: var(--el-color-white) !important;
            }
            &:hover {
                background-color: transparent !important;
                color: var(--el-menu-hover-text-color);
            }
        }
    }
}
.footer {
    color: var(--el-text-color-secondary);
    background-color: transparent !important;
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
}

@media screen and (max-width: 1200px) {
    .container {
        .main {
            .main-container {
                width: 88%;
            }
            .home-sections {
                width: 88%;
            }
        }
    }
}
@media screen and (max-width: 768px) {
    .container {
        .main {
            padding: 24px 0 60px;
            .main-container {
                width: 92%;
                .main-left .main-title {
                    font-size: 32px;
                }
                .main-right img {
                    width: 260px;
                }
            }
            .home-sections {
                width: 92%;
            }
        }
    }
    .promo-video-wrapper {
        flex-direction: column;
    }
    .section {
        padding: 24px;
    }
}
@media screen and (max-width: 375px) {
    .main-right img {
        width: 220px !important;
    }
}
@media screen and (max-height: 650px) {
    .main-right img {
        display: none;
    }
}
@at-root html.dark {
    .container {
        background: url(/@/assets/bg-dark.jpg) repeat;
    }
    .section {
        background: rgba(15, 23, 42, 0.88);
        color: rgba(226, 232, 240, 0.95);
        .section-header h2 {
            color: #e2e8f0;
        }
    }
    .news-item {
        background: rgba(30, 41, 59, 0.85);
        .news-title {
            color: #f8fafc;
        }
        .news-summary {
            color: rgba(226, 232, 240, 0.72);
        }
    }
    .resource-card {
        background: rgba(30, 41, 59, 0.88);
        .resource-title {
            color: #f8fafc;
        }
        .resource-desc {
            color: rgba(203, 213, 225, 0.8);
        }
    }
}
</style>
