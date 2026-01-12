<template>
    <div v-if="bannerList.length > 0" class="banner-container">
        <el-carousel
            :interval="5000"
            type="card"
            height="400px"
            :autoplay="true"
            indicator-position="none"
            arrow="always"
        >
            <el-carousel-item v-for="banner in bannerList" :key="banner.id">
                <div class="banner-item" @click="handleBannerClick(banner)">
                    <img :src="banner.image" :alt="banner.title" class="banner-image" />
                    <div class="banner-overlay">
                        <div class="banner-content">
                            <h2 class="banner-title">{{ banner.title }}</h2>
                            <p v-if="banner.description" class="banner-description">{{ banner.description }}</p>
                        </div>
                    </div>
                </div>
            </el-carousel-item>
        </el-carousel>
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import axios from '/@/utils/axios'

interface BannerItem {
    id: number
    title: string
    image: string
    url: string
    description: string
    sort: number
    status: string
    start_time?: string
    end_time?: string
}

const bannerList = ref<BannerItem[]>([])

// 获取轮番图列表
const getBannerList = async () => {
    try {
        const response = await axios.get('/api/Banner/getBannerList')
        if (response.data.code === 1) {
            bannerList.value = response.data.data.list
        }
    } catch (error) {
        console.error('获取轮番图失败:', error)
    }
}

// 处理轮番图点击
const handleBannerClick = (banner: BannerItem) => {
    if (banner.url) {
        if (banner.url.startsWith('http://') || banner.url.startsWith('https://')) {
            window.open(banner.url, '_blank')
        } else {
            // 内部链接
            window.location.href = banner.url
        }
    }
}

onMounted(() => {
    getBannerList()
})
</script>

<style scoped lang="scss">
.banner-container {
    width: 100%;
    position: relative;

    :deep(.el-carousel) {
        .el-carousel__container {
            border-radius: 8px;
            overflow: hidden;
        }

        .el-carousel__item {
            border-radius: 8px;
        }

        .el-carousel__arrow {
            background-color: rgba(0, 0, 0, 0.5);
            color: white;

            &:hover {
                background-color: rgba(0, 0, 0, 0.8);
            }
        }
    }
}

.banner-item {
    position: relative;
    width: 100%;
    height: 100%;
    cursor: pointer;
    border-radius: 8px;
    overflow: hidden;

    .banner-image {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s ease;
    }

    &:hover .banner-image {
        transform: scale(1.05);
    }

    .banner-overlay {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        background: linear-gradient(transparent, rgba(0, 0, 0, 0.7));
        padding: 40px 30px 30px;
        color: white;

        .banner-content {
            .banner-title {
                margin: 0 0 10px 0;
                font-size: 24px;
                font-weight: 600;
                text-shadow: 0 2px 4px rgba(0, 0, 0, 0.5);
            }

            .banner-description {
                margin: 0;
                font-size: 16px;
                line-height: 1.5;
                opacity: 0.9;
                text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
            }
        }
    }
}

// 响应式设计
@media screen and (max-width: 768px) {
    .banner-container {
        :deep(.el-carousel) {
            height: 250px !important;
        }
    }

    .banner-item .banner-overlay {
        padding: 20px;

        .banner-content {
            .banner-title {
                font-size: 18px;
            }

            .banner-description {
                font-size: 14px;
            }
        }
    }
}

@media screen and (max-width: 480px) {
    .banner-container {
        :deep(.el-carousel) {
            height: 200px !important;
        }
    }

    .banner-item .banner-overlay {
        padding: 15px;

        .banner-content {
            .banner-title {
                font-size: 16px;
            }

            .banner-description {
                font-size: 12px;
            }
        }
    }
}
</style>
