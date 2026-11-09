<script lang="ts" setup>
import { Handle, Position } from '@vue-flow/core'
import type { NodeProps } from '@vue-flow/core'
import { useI18n } from 'vue-i18n'

const props = defineProps<NodeProps>()
const { locale } = useI18n()

const handleConnectable: HandleConnectableFunc = (_node, connectedEdges) => {
  return connectedEdges.length <= 0
}
</script>

<template>
  <div :dir="locale.startsWith('ar') ? 'rtl' : 'ltr'" class="rounded-sm border border-emerald-200 bg-emerald-50 px-5 py-3 text-start shadow-md">
    <div class="flex items-center gap-x-2">
      <img src="~@/assets/images/icon_Start.png" class="h-4 w-4" alt="" aria-hidden="true" />
      <span class="text-sm font-medium text-emerald-700">{{ $t('Flow start') }}</span>
    </div>
    <p class="mt-1 text-xs text-emerald-600">{{ $t('Triggers are configured in the Triggers page') }}</p>
    <Handle type="source" :position="Position.Right" :connectable="handleConnectable" />
  </div>
</template>
