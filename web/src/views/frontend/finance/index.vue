<template>
    <div class="finance-page" v-loading="state.loading">
        <Header />
        <div class="finance-hero" v-if="state.banner">
            <img :src="state.banner" alt="finance banner" />
            <div class="hero-overlay">
                <h1>{{ t('finance.Title') }}</h1>
                <p>{{ t('finance.Subtitle') }}</p>
                <el-button type="primary" size="large" @click="scrollToProducts">
                    {{ t('finance.Explore products') }}
                </el-button>
            </div>
        </div>
        <el-main class="finance-main">
            <div class="finance-header">
                <h2>{{ t('finance.Product list title') }}</h2>
                <p>{{ t('finance.Product list desc') }}</p>
            </div>
            <el-row ref="productContainerRef" class="product-container" :gutter="24">
                <el-col v-for="product in state.products" :key="product.id" :xs="24" :sm="12" :lg="8">
                    <el-card class="product-card" shadow="hover">
                        <div class="product-thumb">
                            <img v-if="product.thumbnail" :src="product.thumbnail" :alt="product.name" />
                            <div v-else class="product-placeholder">
                                <i class="fa fa-line-chart"></i>
                            </div>
                        </div>
                        <div class="product-body">
                            <h3 class="product-name">{{ product.name }}</h3>
                            <p class="product-summary">{{ product.summary }}</p>
                            <div class="product-info">
                                <div class="info-item">
                                    <span class="info-label">{{ t('finance.Price') }}</span>
                                    <span class="info-value">¥{{ product.price.toFixed(2) }}</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">{{ t('finance.Cycle') }}</span>
                                    <span class="info-value">{{ product.cycle_days }} {{ t('finance.Days') }}</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">{{ t('finance.Yield') }}</span>
                                    <span class="info-value">{{ product.yield_rate }}%</span>
                                </div>
                            </div>
                            <div class="product-progress">
                                <div class="progress-header">
                                    <span>
                                        {{
                                            product.total_amount > 0
                                                ? t('finance.Sold out', { sold: product.sold_amount })
                                                : t('finance.Sold total', { sold: product.sold_amount })
                                        }}
                                    </span>
                                    <span>
                                        {{
                                            product.total_amount > 0
                                                ? t('finance.Remaining', { remaining: product.remaining_amount })
                                                : t('finance.Unlimited')
                                        }}
                                    </span>
                                </div>
                                <el-progress :show-text="false" :stroke-width="10" :percentage="Math.min(100, product.progress)" />
                            </div>
                            <el-button
                                type="primary"
                                size="large"
                                :disabled="product.total_amount > 0 && product.remaining_amount <= 0"
                                @click="openPurchase(product)"
                                class="purchase-btn"
                            >
                                {{ t('finance.Buy now') }}
                            </el-button>
                        </div>
                    </el-card>
                </el-col>
            </el-row>
        </el-main>
        <Footer />

        <el-dialog v-model="state.purchase.visible" :title="t('finance.Purchase dialog title')" width="420px" :close-on-click-modal="false">
            <div v-if="state.purchase.product" class="purchase-dialog">
                <div class="purchase-dialog-header">
                    <h3>{{ state.purchase.product.name }}</h3>
                    <span class="price">¥{{ state.purchase.product.price.toFixed(2) }}</span>
                </div>
                <p class="purchase-summary">{{ state.purchase.product.summary }}</p>
                <div class="purchase-field">
                    <span>{{ t('finance.Quantity') }}</span>
                    <el-input-number
                        v-model="state.purchase.quantity"
                        :min="state.purchase.product.min_purchase || 1"
                        :max="state.purchase.product.max_purchase > 0 ? state.purchase.product.max_purchase : undefined"
                        :step="1"
                        controls-position="right"
                    />
                </div>
                <div class="purchase-tips">
                    <span>{{ t('finance.Min purchase tip', { min: state.purchase.product.min_purchase }) }}</span>
                    <span v-if="state.purchase.product.max_purchase > 0">
                        {{ t('finance.Max purchase tip', { max: state.purchase.product.max_purchase }) }}
                    </span>
                </div>
                <div class="purchase-amount">
                    {{ t('finance.Total amount') }}：<strong>¥{{ (state.purchase.product.price * state.purchase.quantity).toFixed(2) }}</strong>
                </div>
            </div>
            <template #footer>
                <el-button @click="state.purchase.visible = false">{{ t('Cancel') }}</el-button>
                <el-button type="primary" :loading="state.purchase.loading" @click="submitPurchase">
                    {{ t('finance.Confirm purchase') }}
                </el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ElMessage } from 'element-plus'
import Header from '/@/layouts/frontend/components/header.vue'
import Footer from '/@/layouts/frontend/components/footer.vue'
import { fetchFinanceProducts, purchaseFinanceProduct } from '/@/api/frontend/home'
import { useUserInfo } from '/@/stores/userInfo'

interface FinanceProduct {
    id: number
    name: string
    thumbnail: string | null
    summary: string | null
    price: number
    cycle_days: number
    yield_rate: number
    total_amount: number
    sold_amount: number
    remaining_amount: number
    min_purchase: number
    max_purchase: number
    progress: number
}

const { t } = useI18n()
const userInfo = useUserInfo()
const productContainerRef = ref()

const state = reactive<{
    loading: boolean
    banner: string | null
    products: FinanceProduct[]
    purchase: {
        visible: boolean
        product: FinanceProduct | null
        quantity: number
        loading: boolean
    }
}>({
    loading: false,
    banner: null,
    products: [],
    purchase: {
        visible: false,
        product: null,
        quantity: 1,
        loading: false,
    },
})

const loadFinanceData = async () => {
    state.loading = true
    try {
        const { data } = await fetchFinanceProducts()
        if (data.code === 1) {
            state.products = (data.data.list || []).map((item: FinanceProduct) => ({
                ...item,
                summary: item.summary || t('finance.Default summary'),
            }))
            state.banner = data.data.banner || null
        } else {
            ElMessage.error(data.msg || t('Load failed'))
        }
    } catch (error: any) {
        ElMessage.error(error?.message || t('Load failed'))
    } finally {
        state.loading = false
    }
}

const scrollToProducts = () => {
    const el = productContainerRef.value?.$el || productContainerRef.value
    if (el?.scrollIntoView) {
        el.scrollIntoView({ behavior: 'smooth' })
    }
}

const openPurchase = (product: FinanceProduct) => {
    if (!userInfo.isLogin()) {
        ElMessage.warning(t('finance.Login required'))
        return
    }
    state.purchase.product = product
    state.purchase.visible = true
    state.purchase.quantity = product.min_purchase || 1
}

const submitPurchase = async () => {
    if (!state.purchase.product) return
    state.purchase.loading = true
    try {
        const { data } = await purchaseFinanceProduct({
            product_id: state.purchase.product.id,
            quantity: state.purchase.quantity,
        })
        if (data.code === 1) {
            ElMessage.success(t('finance.Purchase success'))
            state.purchase.visible = false
            await loadFinanceData()
        } else {
            ElMessage.error(data.msg || t('finance.Purchase failed'))
        }
    } catch (error: any) {
        ElMessage.error(error?.message || t('finance.Purchase failed'))
    } finally {
        state.purchase.loading = false
    }
}

onMounted(() => {
    loadFinanceData()
})
</script>

<style scoped lang="scss">
.finance-page {
    min-height: 100vh;
    background: #0f172a;
    color: #fff;
    display: flex;
    flex-direction: column;
}
.finance-hero {
    position: relative;
    height: 420px;
    overflow: hidden;
    img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        filter: brightness(0.7);
    }
    .hero-overlay {
        position: absolute;
        inset: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        gap: 16px;
        padding: 0 20px;
        background: linear-gradient(135deg, rgba(15, 23, 42, 0.2), rgba(59, 130, 246, 0.35));
        h1 {
            font-size: 42px;
            font-weight: 700;
        }
        p {
            max-width: 640px;
            font-size: 18px;
            color: rgba(226, 232, 240, 0.9);
            line-height: 1.6;
        }
    }
}
.finance-main {
    flex: 1;
    width: 100%;
    max-width: 1200px;
    margin: 40px auto 60px;
    padding: 0 24px;
}
.finance-header {
    text-align: center;
    margin-bottom: 40px;
    h2 {
        font-size: 34px;
        font-weight: 700;
        color: #f1f5f9;
    }
    p {
        margin-top: 12px;
        color: rgba(203, 213, 225, 0.75);
    }
}
.product-card {
    background: rgba(15, 23, 42, 0.85);
    border-radius: 18px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    height: 100%;
    border: none;
    box-shadow: 0 28px 56px rgba(8, 47, 73, 0.4);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    &:hover {
        transform: translateY(-6px);
        box-shadow: 0 32px 64px rgba(8, 47, 73, 0.45);
    }
}
.product-thumb {
    position: relative;
    padding-top: 60%;
    background: rgba(30, 41, 59, 0.8);
    img {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .product-placeholder {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 48px;
        color: rgba(148, 163, 184, 0.8);
    }
}
.product-body {
    padding: 24px;
    display: flex;
    flex-direction: column;
    flex: 1;
    .product-name {
        font-size: 22px;
        font-weight: 600;
        margin-bottom: 8px;
        color: #f8fafc;
    }
    .product-summary {
        color: rgba(203, 213, 225, 0.75);
        margin-bottom: 18px;
        line-height: 1.6;
        min-height: 48px;
    }
}
.product-info {
    display: flex;
    justify-content: space-between;
    margin-bottom: 18px;
    .info-item {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        .info-label {
            font-size: 12px;
            color: rgba(148, 163, 184, 0.8);
        }
        .info-value {
            font-size: 18px;
            font-weight: 600;
            color: #38bdf8;
        }
    }
}
.product-progress {
    margin-bottom: 18px;
    .progress-header {
        display: flex;
        justify-content: space-between;
        font-size: 12px;
        color: rgba(148, 163, 184, 0.75);
        margin-bottom: 6px;
    }
}
.purchase-btn {
    margin-top: auto;
}
.purchase-dialog {
    display: flex;
    flex-direction: column;
    gap: 14px;
    .purchase-dialog-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        h3 {
            font-size: 20px;
            font-weight: 600;
        }
        .price {
            font-size: 18px;
            font-weight: 600;
            color: var(--el-color-primary);
        }
    }
    .purchase-summary {
        color: var(--el-text-color-secondary);
        line-height: 1.6;
    }
    .purchase-field {
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .purchase-tips {
        display: flex;
        flex-direction: column;
        gap: 4px;
        color: var(--el-text-color-secondary);
        font-size: 12px;
    }
    .purchase-amount {
        font-size: 16px;
        strong {
            color: var(--el-color-primary);
        }
    }
}
@media screen and (max-width: 768px) {
    .finance-hero {
        height: 320px;
        .hero-overlay h1 {
            font-size: 28px;
        }
    }
    .finance-main {
        margin-top: 24px;
    }
}
</style>


