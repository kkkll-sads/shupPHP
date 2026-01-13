<template>
    <div class="news-detail-page" v-loading="state.loading">
        <Header />
        <el-main class="detail-main">
            <el-breadcrumb class="breadcrumb" separator="/">
                <el-breadcrumb-item @click="goList" class="breadcrumb-link">{{ t('index.Hot News') }}</el-breadcrumb-item>
                <el-breadcrumb-item>{{ detail?.title || '--' }}</el-breadcrumb-item>
            </el-breadcrumb>

            <div v-if="detail" class="detail-container">
                <h1 class="detail-title">{{ detail.title }}</h1>
                <div class="detail-meta">
                    <span>{{ formatDate(detail.publish_time) }}</span>
                    <span>{{ t('news.View count', { count: detail.view_count ?? 0 }) }}</span>
                </div>
                <div class="detail-cover" v-if="detail.cover_image">
                    <img :src="detail.cover_image" alt="" />
                </div>
                <div class="detail-content" v-html="detail.content || detail.summary"></div>
                <div class="detail-actions">
                    <el-button type="primary" @click="goList">{{ t('news.Back to list') }}</el-button>
                </div>
            </div>
            <el-empty v-else :description="t('No data')" />
        </el-main>
        <Footer />
    </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage, dayjs } from 'element-plus'
import Header from '/@/layouts/frontend/components/header.vue'
import Footer from '/@/layouts/frontend/components/footer.vue'
import { fetchNewsDetail } from '/@/api/frontend/home'

interface NewsDetail {
    id: number
    title: string
    summary: string | null
    content: string | null
    cover_image: string | null
    publish_time: number
    view_count: number
}

const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const state = reactive<{
    loading: boolean
    detail: NewsDetail | null
}>({
    loading: false,
    detail: null,
})

const detail = computed(() => state.detail)

const loadDetail = async () => {
    const id = Number(route.params.id || 0)
    if (!id) {
        ElMessage.error(t('news.Not found'))
        goList()
        return
    }
    state.loading = true
    try {
        const { data } = await fetchNewsDetail(id)
        if (data.code === 1) {
            state.detail = data.data.detail
        } else {
            ElMessage.error(data.msg || t('news.Not found'))
        }
    } catch (error: any) {
        ElMessage.error(error?.message || t('news.Not found'))
    } finally {
        state.loading = false
    }
}

const formatDate = (timestamp: number) => {
    if (!timestamp) return ''
    return dayjs.unix(timestamp).format('YYYY-MM-DD HH:mm')
}

const goList = () => {
    router.push({ name: 'newsList' })
}

onMounted(() => {
    loadDetail()
})
</script>

<style scoped lang="scss">
.news-detail-page {
    min-height: 100vh;
    background: #f8fafc;
    display: flex;
    flex-direction: column;
}
.detail-main {
    flex: 1;
    width: 100%;
    max-width: 900px;
    margin: 120px auto 40px;
    padding: 0 20px;
}
.breadcrumb {
    margin-bottom: 24px;
    .breadcrumb-link {
        cursor: pointer;
        color: var(--el-color-primary);
    }
}
.detail-container {
    background: #fff;
    border-radius: 16px;
    padding: 40px;
    box-shadow: 0 20px 50px rgba(15, 23, 42, 0.12);
}
.detail-title {
    font-size: 32px;
    font-weight: 700;
    margin-bottom: 16px;
    color: #1e293b;
}
.detail-meta {
    display: flex;
    gap: 20px;
    color: #94a3b8;
    margin-bottom: 24px;
}
.detail-cover {
    margin-bottom: 28px;
    img {
        width: 100%;
        border-radius: 12px;
        object-fit: cover;
    }
}
.detail-content {
    font-size: 16px;
    line-height: 1.8;
    color: #334155;
    :deep(img) {
        max-width: 100%;
        display: block;
        margin: 16px auto;
        border-radius: 10px;
    }
    :deep(p) {
        margin-bottom: 16px;
    }
}
.detail-actions {
    margin-top: 32px;
    text-align: center;
}
@media screen and (max-width: 768px) {
    .detail-main {
        margin-top: 100px;
    }
    .detail-container {
        padding: 24px;
    }
}
</style>


