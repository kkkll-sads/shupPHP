<template>
    <div class="ba-editor md-editor-v3">
        <md-editor
            ref="editorRef"
            :theme="theme ? theme : config.layout.isDark ? 'dark' : 'light'"
            :preview="preview"
            :language="language ? language : config.lang.defaultLang == 'zh-cn' ? 'zh-CN' : 'en-US'"
            :toolbarsExclude="state.toolbarsExclude"
            v-bind="$attrs"
            @uploadImg="onUploadImg"
            v-loading="state.uploadLoading"
            element-loading-text="Uploading..."
            showCodeRowNumber
        />
    </div>
</template>

<script setup lang="ts">
import { nextTick, reactive, ref } from 'vue'
import { MdEditor, Themes, ToolbarNames, MdPreviewProps, config as mdConfig, ExposeParam } from 'md-editor-v3'
import screenfull from 'screenfull'
import prettier from 'prettier'
import { useConfig } from '/@/stores/config'
import { fileUpload } from '/@/api/common'
import { uuid } from '/@/utils/random'
import 'md-editor-v3/lib/style.css'

interface Props extends /* @vue-ignore */ Partial<MdPreviewProps> {
    theme?: Themes
    preview?: boolean
    language?: string
    toolbarsExclude?: ToolbarNames[]
    // 安装了云存储之后，图片/文件任然只上传到服务器而不是云存储
    fileForceLocal?: boolean
}

const editorRef = ref<ExposeParam>()
const props = withDefaults(defineProps<Props>(), {
    preview: false,
    language: '',
    toolbarsExclude: () => [],
    fileForceLocal: false,
})

const excludeNames: ToolbarNames[] = [
    'sub',
    'sup',
    'mermaid',
    'katex',
    'revoke',
    'next',
    'save',
    'fullscreen',
    'task',
    '=',
    'codeRow',
    'htmlPreview',
    'catalog',
    'github',
]

const config = useConfig()
const state: {
    toolbarsExclude: ToolbarNames[]
    uploadLoading: boolean
} = reactive({
    toolbarsExclude: props.toolbarsExclude.length ? props.toolbarsExclude : excludeNames,
    uploadLoading: false,
})

mdConfig({
    editorExtensions: {
        screenfull: {
            instance: screenfull,
        },
        prettier: {
            prettierInstance: prettier,
        },
    },
})

const onUploadImg = async (files: File[], callback: (urls: string[]) => void) => {
    state.uploadLoading = true
    nextTick(async () => {
        const uploadLoadingTextEl = document.querySelector('.ba-editor .el-loading-mask .el-loading-text')
        const res = await Promise.all(
            files.map((file) => {
                return new Promise((rev, rej) => {
                    if (uploadLoadingTextEl) uploadLoadingTextEl.innerHTML = 'Uploading...'
                    const fd = new FormData()
                    fd.append('file', file)
                    fileUpload(fd, { uuid: uuid() }, props.fileForceLocal, {
                        onUploadProgress: (evt) => {
                            if (uploadLoadingTextEl) uploadLoadingTextEl.innerHTML = parseFloat((evt.progress! * 100).toString()).toFixed(2) + '%'
                        },
                    })
                        .then((res) => {
                            if (res.code == 1) {
                                rev(res)
                            } else {
                                rej(res.msg)
                            }
                        })
                        .catch((error) => {
                            rej(error.msg)
                        })
                })
            })
        ).finally(() => {
            state.uploadLoading = false
        })
        callback(
            res.map((item) => {
                let url = (item as ApiResponse).data.file.full_url
                url = url.replace(/\(/g, '\\(').replace(/\)/g, '\\)')
                return url
            })
        )
    })
}

const getRef = () => {
    return editorRef.value
}

defineExpose({
    getRef,
})
</script>

<style scoped lang="scss">
.ba-editor.md-editor-v3 {
    width: 100%;
    :deep(svg.md-editor-icon) {
        box-sizing: content-box;
    }
}
.ba-editor.md-editor-v3 :deep(.md-editor-footer) {
    height: unset;
}
</style>
