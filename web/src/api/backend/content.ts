import createAxios from '/@/utils/axios'

const url = '/admin/content.'

/**
 * 抽奖次数配置接口
 */
export const drawCountConfigUrl = url + 'DrawCountConfig/'

// 列表
export function getDrawCountConfigList(params?: anyObj) {
    return createAxios({
        url: drawCountConfigUrl + 'index',
        method: 'get',
        params: params,
    })
}

// 新增
export function addDrawCountConfig(data: anyObj) {
    return createAxios(
        {
            url: drawCountConfigUrl + 'add',
            method: 'post',
            data: data,
        },
        {
            showSuccessMessage: true,
        }
    )
}

// 编辑
export function editDrawCountConfig(data: anyObj) {
    return createAxios(
        {
            url: drawCountConfigUrl + 'edit',
            method: 'post',
            data: data,
        },
        {
            showSuccessMessage: true,
        }
    )
}

// 删除
export function deleteDrawCountConfig(data: anyObj) {
    return createAxios(
        {
            url: drawCountConfigUrl + 'delete',
            method: 'post',
            data: data,
        },
        {
            showSuccessMessage: true,
        }
    )
}

// 计算用户的抽奖次数
export function calculateUserDrawCount(data: anyObj) {
    return createAxios({
        url: drawCountConfigUrl + 'calculateUserDrawCount',
        method: 'post',
        data: data,
    })
}

// 重新计算所有用户的抽奖次数
export function recalculateAllDrawCount() {
    return createAxios(
        {
            url: drawCountConfigUrl + 'recalculateAllDrawCount',
            method: 'post',
        },
        {
            showSuccessMessage: true,
        }
    )
}

// 获取配置统计信息
export function getStatistics() {
    return createAxios({
        url: drawCountConfigUrl + 'getStatistics',
        method: 'get',
    })
}

