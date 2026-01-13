<template>
    <div class="thirdparty-config">
        <el-tabs v-model="activeSubTab" type="card" class="sub-tabs">
            <el-tab-pane label="网易易盾OCR" name="ocr">
                <OcrConfig />
            </el-tab-pane>
            <el-tab-pane label="短信平台" name="sms">
                <SmsConfig />
            </el-tab-pane>
        </el-tabs>
    </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import OcrConfig from './ThirdParty/OcrConfig.vue'
import SmsConfig from './ThirdParty/SmsConfig.vue'

const activeSubTab = ref('ocr')
</script>

<style scoped lang="scss">
.thirdparty-config {
    .sub-tabs {
        :deep(.el-tabs__content) {
            padding: 20px 0;
        }
    }
}
</style>

