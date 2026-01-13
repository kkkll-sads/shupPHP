import createAxios from '/@/utils/axios'

export const appVersionUrl = '/api/AppVersion/'

/**
 * 检查APP更新
 * @param platform 平台: android=安卓, ios=苹果
 * @param currentVersion 当前版本号
 */
export function checkAppUpdate(platform: 'android' | 'ios', currentVersion: string) {
    return createAxios({
        url: appVersionUrl + 'checkUpdate',
        method: 'GET',
        params: {
            platform,
            current_version: currentVersion,
        },
    })
}

/**
 * 获取最新版本信息（不检查是否需要更新）
 * @param platform 平台: android=安卓, ios=苹果
 */
export function getLatestVersion(platform: 'android' | 'ios') {
    return createAxios({
        url: appVersionUrl + 'getLatestVersion',
        method: 'GET',
        params: {
            platform,
        },
    })
}

