<template>
  <section :class="['cb-card', paddingClass, $attrs.class]">
    <slot />
  </section>
</template>

<script setup>
import { computed, useAttrs } from 'vue';

defineOptions({ inheritAttrs: false });
const props = defineProps({ padding: { type: String, default: 'md' } });
const attrs = useAttrs();
const paddingClass = computed(() => ({ sm: 'p-3', md: 'p-4', lg: 'p-6', none: '' }[props.padding] || 'p-4'));
</script>
