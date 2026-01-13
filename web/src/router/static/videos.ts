import type { RouteRecordRaw } from 'vue-router'

const videoListRoute: RouteRecordRaw = {
    path: '/videos',
    name: 'videoList',
    component: () => import('/@/views/frontend/video/list.vue'),
    meta: {
        title: 'pagesTitle.videoList',
    },
}

export default videoListRoute


