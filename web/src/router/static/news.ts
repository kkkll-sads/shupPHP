import type { RouteRecordRaw } from 'vue-router'

const newsListRoute: RouteRecordRaw = {
    path: '/news',
    name: 'newsList',
    component: () => import('/@/views/frontend/news/list.vue'),
    meta: {
        title: 'pagesTitle.newsList',
    },
}

export default newsListRoute


