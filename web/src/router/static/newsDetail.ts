import type { RouteRecordRaw } from 'vue-router'

const newsDetailRoute: RouteRecordRaw = {
    path: '/news/:id',
    name: 'newsDetail',
    component: () => import('/@/views/frontend/news/detail.vue'),
    meta: {
        title: 'pagesTitle.newsDetail',
    },
}

export default newsDetailRoute


