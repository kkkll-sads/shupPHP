import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'
import type { ConfigEnv, UserConfig } from 'vite'
import { svgBuilder } from '/@/components/icon/svg/index'
import { customHotUpdate, isProd } from '/@/utils/vite'

const pathResolve = (dir: string): any => {
    return resolve(__dirname, '.', dir)
}

// https://vitejs.cn/config/
const viteConfig = ({ mode }: ConfigEnv): UserConfig => {
    // 使用默认配置值，避免读取.env文件
    const VITE_PORT = '3000'
    const VITE_OPEN = 'true'
    const VITE_BASE_PATH = '/'
    const VITE_OUT_DIR = 'dist'

    const alias: Record<string, string> = {
        '/@': pathResolve('./src/'),
        assets: pathResolve('./src/assets'),
        'vue-i18n': isProd(mode) ? 'vue-i18n/dist/vue-i18n.cjs.prod.js' : 'vue-i18n/dist/vue-i18n.cjs.js',
        // 修复 v-code-diff 包的入口点问题（Vue 3 使用 v3 目录）
        'v-code-diff': pathResolve('./node_modules/v-code-diff/dist/v3/index.es.js'),
    }

    return {
        plugins: [vue(), svgBuilder('./src/assets/icons/'), customHotUpdate()],
        root: process.cwd(),
        resolve: {
            alias,
            // 修复 v-code-diff 包的解析问题
            dedupe: ['vue', 'vue-router'],
        },
        base: VITE_BASE_PATH,
        optimizeDeps: {
            include: ['v-code-diff'],
        },
        server: {
            host: '0.0.0.0',
            port: parseInt(VITE_PORT),
            open: VITE_OPEN != 'false',
            allowedHosts: ['shu.gckot.cn', 'wap.dfahwk.cn', 'shu.fhsyz.cn', 'wap.bskhu.cn'],
        },
        build: {
            cssCodeSplit: false,
            sourcemap: false,
            outDir: VITE_OUT_DIR,
            emptyOutDir: true,
            chunkSizeWarningLimit: 1500,
            commonjsOptions: {
                include: [/v-code-diff/, /node_modules/],
            },
            rollupOptions: {
                output: {
                    manualChunks: {
                        // 分包配置，配置完成自动按需加载
                        vue: ['vue', 'vue-router', 'pinia', 'vue-i18n', 'element-plus'],
                        echarts: ['echarts'],
                    },
                },
            },
        },
    }
}

export default viteConfig
