<template>
    <div>
        <el-dialog
            v-model="baTable.form.operate"
            :close-on-click-modal="false"
            :destroy-on-close="true"
            :title="t(baTable.form.operateIds.length ? 'Edit' : 'Add') + t('collection.consignment.Consignment')"
            width="60%"
            @close="baTable.toggleForm"
        >
            <el-scrollbar v-loading="baTable.form.loading" class="ba-table-form-scrollbar">
                <div
                    class="ba-operate-form"
                    :class="'ba-' + baTable.form.operate + '-form'"
                >
                    <el-form
                        ref="formRef"
                        @keyup.enter="baTable.form.operate === 'edit' ? baTable.onSubmit(formRef) : null"
                        :model="baTable.form.items"
                        label-position="right"
                        label-width="auto"
                        v-if="!baTable.form.loading"
                    >
                        <el-form-item :label="t('collection.consignment.Id')" prop="id" v-if="baTable.form.operate === 'edit'">
                            <el-input v-model="baTable.form.items.id" type="number" :disabled="true" />
                        </el-form-item>
                        
                        <el-form-item :label="t('collection.consignment.User')" prop="username">
                            <el-input v-model="baTable.form.items.username" :disabled="true" />
                        </el-form-item>
                        
                        <el-form-item :label="t('collection.consignment.UserMobile')" prop="user_mobile">
                            <el-input v-model="baTable.form.items.user_mobile" :disabled="true" />
                        </el-form-item>
                        
                        <el-form-item :label="t('collection.consignment.ItemTitle')" prop="item_title">
                            <el-input v-model="baTable.form.items.item_title" :disabled="true" />
                        </el-form-item>
                        
                        <el-form-item :label="t('collection.consignment.Price')" prop="price">
                            <el-input v-model="baTable.form.items.price" type="number" :disabled="true">
                                <template #prefix>¥</template>
                            </el-input>
                        </el-form-item>
                        
                        <el-form-item :label="t('collection.consignment.OriginalPrice')" prop="original_price">
                            <el-input v-model="baTable.form.items.original_price" type="number" :disabled="true">
                                <template #prefix>¥</template>
                            </el-input>
                        </el-form-item>
                        
                        <el-form-item :label="t('collection.consignment.ServiceFee')" prop="service_fee">
                            <el-input v-model="baTable.form.items.service_fee" type="number" :disabled="true">
                                <template #prefix>¥</template>
                            </el-input>
                        </el-form-item>
                        
                        <el-form-item :label="t('collection.consignment.Status')" prop="status" v-if="baTable.form.operate === 'edit'">
                            <el-select
                                v-model.number="baTable.form.items.status"
                                :placeholder="t('Please select') + t('collection.consignment.Status')"
                                style="width: 100%"
                            >
                                <el-option
                                    :label="t('collection.consignment.StatusConsigning')"
                                    :value="1"
                                />
                                <el-option
                                    :label="t('collection.consignment.StatusSold')"
                                    :value="2"
                                    :disabled="baTable.form.items.status == 2"
                                />
                                <el-option
                                    :label="t('collection.consignment.StatusOffshelf')"
                                    :value="3"
                                />
                                <el-option
                                    :label="t('collection.consignment.StatusCancelled')"
                                    :value="4"
                                />
                            </el-select>
                            <div class="el-form-item-tip" v-if="baTable.form.items.status == 2">
                                <el-text type="warning">{{ t('collection.consignment.StatusSoldCannotEdit') }}</el-text>
                            </div>
                        </el-form-item>
                        
                        <el-form-item :label="t('collection.consignment.Status')" prop="status_text" v-else>
                            <el-input v-model="baTable.form.items.status_text" :disabled="true" />
                        </el-form-item>
                        
                        <el-form-item :label="t('collection.consignment.PackageName')" prop="package_name_display">
                            <el-input v-model="baTable.form.items.package_name_display" :disabled="true" />
                        </el-form-item>
                        
                        <el-form-item :label="t('CreateTime')" prop="create_time_text" v-if="baTable.form.operate === 'edit'">
                            <el-input v-model="baTable.form.items.create_time_text" :disabled="true" />
                        </el-form-item>
                        
                        <el-form-item :label="t('UpdateTime')" prop="update_time_text" v-if="baTable.form.operate === 'edit'">
                            <el-input v-model="baTable.form.items.update_time_text" :disabled="true" />
                        </el-form-item>
                    </el-form>
                </div>
            </el-scrollbar>
            <template #footer>
                <div>
                    <el-button @click="baTable.toggleForm('')">{{ baTable.form.operate === 'edit' ? t('Cancel') : t('Close') }}</el-button>
                    <el-button v-blur :loading="baTable.form.submitLoading" @click="baTable.onSubmit(formRef)" type="primary" v-if="baTable.form.operate === 'edit'">
                        {{ t('Save') }}
                    </el-button>
                </div>
            </template>
        </el-dialog>
    </div>
</template>

<script setup lang="ts">
import { inject, ref } from 'vue'
import type baTableClass from '/@/utils/baTable'
import { useI18n } from 'vue-i18n'

defineOptions({
    name: 'collection/consignment/popupForm',
})

const formRef = ref()
const baTable = inject('baTable') as baTableClass
const { t } = useI18n()
</script>

<style scoped lang="scss"></style>

