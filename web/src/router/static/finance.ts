import type { RouteRecordRaw } from 'vue-router'

const financeRoute: RouteRecordRaw = {
    path: '/finance',
    name: 'finance',
    component: () => import('/@/views/frontend/finance/index.vue'),
    meta: {
        title: 'pagesTitle.finance',
    },
}

export default financeRoute


