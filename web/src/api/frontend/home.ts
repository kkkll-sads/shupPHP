import axios from '/@/utils/axios'

export function fetchHomeContent() {
    return axios.get('/api/home/index')
}

export function fetchFinanceProducts() {
    return axios.get('/api/finance/index')
}

export function purchaseFinanceProduct(payload: { product_id: number; quantity: number }) {
    return axios.post('/api/finance/purchase', payload)
}

export function fetchFinanceProductDetail(id: number) {
    return axios.get('/api/finance/detail', {
        params: {
            id,
        },
    })
}

export function fetchNewsList(params: { page?: number; limit?: number } = {}) {
    return axios.get('/api/home/newsList', {
        params,
    })
}

export function fetchNewsDetail(id: number) {
    return axios.get('/api/home/newsDetail', {
        params: { id },
    })
}

export function fetchVideoList(params: { page?: number; limit?: number; category?: string } = {}) {
    return axios.get('/api/home/videoList', {
        params,
    })
}


