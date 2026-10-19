<script setup>
    const props = defineProps({
        position: {
            type: String,
            default: null
        },
    })

    const getRowClass = () => {
        if (props.position === 'first') {
            return 'ps-4 py-4';
        } else if (props.position === 'last') {
            return 'pe-4';
        }
    };
</script>
<template>
    <td :class="getRowClass()">
        <div class="text-start w-full h-full"><slot /></div>
    </td>
</template>
