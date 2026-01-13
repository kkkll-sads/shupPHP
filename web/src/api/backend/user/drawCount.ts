import createAxios from '/@/utils/axios'

const url = '/admin/content.DrawCountConfig/'

// 计算用户的抽奖次数
export function calculateUserDrawCount(data: anyObj) {
    return createAxios({
        url: url + 'calculateUserDrawCount',
        method: 'post',
        data: data,
    })
}

// 获取用户详情中的抽奖次数信息
export function getUserDrawCountInfo(data: anyObj) {
    return createAxios({
        url: url + 'calculateUserDrawCount',
        method: 'post',
        data: data,
    })
}

