<script setup>
    import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
    import { Menu, MenuButton, MenuItems } from '@headlessui/vue';
    
    const props = defineProps({
        align: {
            type: String,
            default: "right"
        },
        portal: {
            type: Boolean,
            default: false
        }
    })

    const triggerRef = ref(null);
    const menuRef = ref(null);
    const menuStyle = ref({
        visibility: 'hidden',
    });

    const positionMenu = () => {
        if (!props.portal || !triggerRef.value || !menuRef.value) return;

        const triggerRect = triggerRef.value.getBoundingClientRect();
        const menuElement = menuRef.value.$el ?? menuRef.value;
        if (!menuElement?.getBoundingClientRect) return;

        const menuRect = menuElement.getBoundingClientRect();
        const gap = 8;
        const viewportPadding = 8;

        let top = triggerRect.bottom + gap;
        if (top + menuRect.height > window.innerHeight - viewportPadding) {
            top = triggerRect.top - menuRect.height - gap;
        }

        top = Math.max(viewportPadding, Math.min(top, window.innerHeight - menuRect.height - viewportPadding));

        let left = props.align === 'right'
            ? triggerRect.right - menuRect.width
            : triggerRect.left;

        left = Math.max(viewportPadding, Math.min(left, window.innerWidth - menuRect.width - viewportPadding));

        menuStyle.value = {
            position: 'fixed',
            top: `${top}px`,
            left: `${left}px`,
            visibility: 'visible',
        };
    };

    const scheduleMenuPosition = () => {
        if (!props.portal) return;

        menuStyle.value = {
            ...menuStyle.value,
            visibility: 'hidden',
        };

        nextTick(() => requestAnimationFrame(positionMenu));
    };

    onMounted(() => {
        if (!props.portal) return;

        window.addEventListener('resize', positionMenu);
        window.addEventListener('scroll', positionMenu, true);
    });

    onBeforeUnmount(() => {
        if (!props.portal) return;

        window.removeEventListener('resize', positionMenu);
        window.removeEventListener('scroll', positionMenu, true);
    });
</script>
<template>
    <Menu as="div" class="relative inline-block text-left">
        <div ref="triggerRef" @click="scheduleMenuPosition">
            <MenuButton as="template">
                <slot />
            </MenuButton>
        </div>

        <transition
            enter-active-class="transition duration-100 ease-out"
            enter-from-class="transform scale-95 opacity-0"
            enter-to-class="transform scale-100 opacity-100"
            leave-active-class="transition duration-75 ease-in"
            leave-from-class="transform scale-100 opacity-100"
            leave-to-class="transform scale-95 opacity-0"
        >
            <Teleport v-if="props.portal" to="body">
                <MenuItems
                    ref="menuRef"
                    :style="menuStyle"
                    class="z-50 fixed max-h-[calc(100vh-1rem)] w-32 overflow-y-auto divide-y divide-gray-100 rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none"
                >
                    <slot name="items" />
                </MenuItems>
            </Teleport>
            <MenuItems
                v-else
                :class="{
                    'right-0 origin-top-right' : props.align === 'right',
                    'left-0 origin-top-left' : props.align === 'left',
                    'top-[-135px] left-0 origin-top-left' : props.align === 'top-left',
                }"
                class="z-10 absolute mt-2 w-32 divide-y divide-gray-100 rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none"
            >
                <slot name="items" />
            </MenuItems>
        </transition>
    </Menu>
</template>
