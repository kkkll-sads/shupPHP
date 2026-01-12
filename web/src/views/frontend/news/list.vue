<template>
    <div class="news-page" v-loading="state.loading">
        <Header />
        <el-main class="news-main">
            <div class="news-header">
                <h1>{{ t('index.Hot News') }}</h1>
                <p>{{ t('news.Subtitle') }}</p>
            </div>
            <el-row :gutter="24">
                <el-col v-for="item in state.list" :key="item.id" :xs="24" :md="12">
                    <el-card class="news-card" shadow="hover" @click="goDetail(item)">
                        <div class="news-card-cover">
                            <img v-if="item.cover_image" :src="item.cover_image" alt="" />
                            <div v-else class="news-card-placeholder">
                                <i class="fa fa-newspaper-o"></i>
                            </div>
                        </div>
                        <div class="news-card-body">
                            <span class="news-date">{{ formatDate(item.publish_time) }}</span>
                            <h3 class="news-title" :title="item.title">{{ item.title }}</h3>
                            <p class="news-summary" :title="item.summary">{{ item.summary }}</p>
                            <el-button link type="primary">
                                {{ t('index.Read More') }}
                                <i class="fa fa-angle-right"></i>
                            </el-button>
                        </div>
                    </el-card>
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
import { reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ElMessage, dayjs } from 'element-plus'
import Header from '/@/layouts/frontend/components/header.vue'
import Footer from '/@/layouts/frontend/components/footer.vue'
import { fetchNewsList } from '/@/api/frontend/home'

interface NewsItem {
    id: number
    title: string
    summary: string | null
    cover_image: string | null
    publish_time: number
    link_url: string | null
}

const { t } = useI18n()
const router = useRouter()

const state = reactive<{
    loading: boolean
    list: NewsItem[]
    total: number
    page: number
    pageSize: number
}>({
    loading: false,
    list: [],
    total: 0,
    page: 1,
    pageSize: 10,
})

const loadNews = async () => {
    state.loading = true
    try {
        const { data } = await fetchNewsList({ page: state.page, limit: state.pageSize })
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
    loadNews()
}

const formatDate = (timestamp: number) => {
    if (!timestamp) return ''
    return dayjs.unix(timestamp).format('YYYY-MM-DD')
}

const goDetail = (item: NewsItem) => {
    if (item.link_url) {
        window.open(item.link_url, '_blank')
    } else {
        router.push({ name: 'newsDetail', params: { id: item.id } })
    }
}

onMounted(() => {
    loadNews()
})
</script>

<style scoped lang="scss">
.news-page {
    min-height: 100vh;
    background: #f1f5f9;
    display: flex;
    flex-direction: column;
}
.news-main {
    flex: 1;
    width: 100%;
    max-width: 1100px;
    margin: 120px auto 40px;
    padding: 0 20px;
}
.news-header {
    text-align: center;
    margin-bottom: 40px;
    h1 {
        font-size: 36px;
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 12px;
    }
    p {
        color: #64748b;
        font-size: 16px;
    }
}
.news-card {
    margin-bottom: 24px;
    display: flex;
    flex-direction: column;
    cursor: pointer;
    transition: box-shadow 0.2s ease, transform 0.2s ease;
    &:hover {
        transform: translateY(-4px);
        box-shadow: 0 16px 36px rgba(15, 23, 42, 0.15);
    }
}
.news-card-cover {
    width: 100%;
    padding-top: 56.25%;
    position: relative;
    border-radius: 10px;
    overflow: hidden;
    background-color: #e2e8f0;
    img {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .news-card-placeholder {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 42px;
        color: #94a3b8;
    }
}
.news-card-body {
    padding: 18px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    .news-date {
        color: #94a3b8;
        font-size: 13px;
    }
    .news-title {
        font-size: 20px;
        color: #1f2937;
        font-weight: 600;
        line-height: 1.4;
    }
    .news-summary {
        color: #64748b;
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
    .news-main {
        margin-top: 100px;
    }
}
</style>


