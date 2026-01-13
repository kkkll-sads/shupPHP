<template>
    <div>
        {{
            !cellValue || cellValue === '0' || cellValue === 0 || cellValue === '' ?
                '-' :
                (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(cellValue) ?
                    cellValue :
                    timeFormat(cellValue, field.timeFormat ?? 'yyyy-mm-dd hh:MM:ss')
                )
        }}
    </div>
</template>

<script setup lang="ts">
import { TableColumnCtx } from 'element-plus'
import { getCellValue } from '/@/components/table/index'
import { timeFormat } from '/@/utils/common'

interface Props {
    row: TableRow
    field: TableColumn
    column: TableColumnCtx<TableRow>
    index: number
}

const props = defineProps<Props>()

const cellValue = getCellValue(props.row, props.field, props.column, props.index)
</script>
