<script setup>
    const props = defineProps({
        position: {
            type: String,
            default: null
        },
    })

    const getRowClass = () => {
        if (props.position === 'first') {
            return 'font-normal ps-4 py-4 text-start';
        } else if (props.position === 'last') {
            return 'pe-4';
        } else {
            return 'font-normal py-4 text-start'
        }
    };
</script>
<template>
    <th :class="getRowClass()">
        <slot/>
    </th>
</template>
