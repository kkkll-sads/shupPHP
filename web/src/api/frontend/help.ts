import createAxios from '/@/utils/axios'

// 获取帮助中心问题分类列表
export function getHelpCategories() {
    return createAxios({
        url: '/api/Help/categories',
        method: 'GET',
    })
}

// 获取某个分类下的问题列表
export function getHelpQuestions(params: { category_id?: number; category_code?: string }) {
    return createAxios({
        url: '/api/Help/questions',
        method: 'GET',
        params,
    })
}


