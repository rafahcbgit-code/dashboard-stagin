<script setup>
    import Pagination from '@/Components/Pagination.vue';

    const props = defineProps({
        rows: Object,
        className: String,
    })
</script>
<template>
    <div class="cb-section overflow-hidden" :class="className">
        <table class="w-full">
            <slot />
        </table>
    </div>

    <Pagination class="mt-3" :pagination="rows.meta"/>
</template>
