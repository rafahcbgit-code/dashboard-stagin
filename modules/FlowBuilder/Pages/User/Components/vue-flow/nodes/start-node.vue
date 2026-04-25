<script lang="ts" setup>
import { Handle, Position } from '@vue-flow/core'
import type { NodeProps } from '@vue-flow/core'

const props = defineProps<NodeProps>()

const handleConnectable: HandleConnectableFunc = (_node, connectedEdges) => {
  return connectedEdges.length <= 0
}
</script>

<template>
  <div class="rounded-sm border border-emerald-200 bg-emerald-50 px-5 py-3 shadow-md">
    <div class="flex items-center gap-x-2">
      <img src="~@/assets/images/icon_Start.png" class="h-4 w-4" alt="Start icon" />
      <span class="text-sm font-medium text-emerald-700">Start</span>
    </div>
    <p class="mt-1 text-xs text-emerald-600">Triggers are configured in the Triggers page</p>
    <Handle type="source" :position="Position.Right" :connectable="handleConnectable" />
  </div>
</template>