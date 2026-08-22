<script setup>
    import { computed, nextTick, ref, watch } from 'vue';
    import { useForm, usePage } from "@inertiajs/vue3";
    import Modal from '@/Components/Modal.vue';

    const props = defineProps(['type', 'modelValue']);
    const emit = defineEmits(['update:modelValue']);
    
    const isOpenModal = ref(props.modelValue);
    const nameInput = ref(null);
    const user = computed(() => usePage().props.auth.user);

    watch(() => props.modelValue, (newValue) => {
        isOpenModal.value = newValue;
        if (newValue) {
            nextTick(() => nameInput.value?.focus());
        }
    });

    const form = useForm({
        create_user: 0,
        email: null,
        name: null,
    })

    watch(user, (newUser) => {
        if (newUser) {
            form.email = newUser.email;
        }
    }, { immediate: true });

    const submitForm = async () => {
        if (!form.email && user.value?.email) {
            form.email = user.value.email;
        }

        form.post('/organization', {
            preserveScroll: true,
        })
    };

    function closeModal(){
        isOpenModal.value = false;
        form.reset('name');
        form.clearErrors();
        emit('update:modelValue', false);
    }
</script>
<template>
    <Modal :label="$t('Create Organization')" :isOpen="isOpenModal" @close="closeModal">
        <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4">
            <form @submit.prevent="submitForm()" class="gap-y-4">
                <div class="grid grid-cols gap-y-4">
                    <div class="col-span-6">
                        <label for="organization-name" class="block text-sm leading-6 text-gray-900">
                            {{ $t('Organization Name') }}
                        </label>
                        <input
                            id="organization-name"
                            ref="nameInput"
                            v-model="form.name"
                            name="name"
                            type="text"
                            autocomplete="organization"
                            class="block w-full rounded-md border-0 py-1.5 px-4 text-gray-900 shadow-sm outline-none ring-1 ring-inset placeholder:text-gray-400 sm:text-sm sm:leading-6"
                            :class="form.errors.name ? 'ring-[#b91c1c]' : 'ring-gray-300'"
                            :disabled="form.processing"
                            required
                        />
                        <div v-if="form.errors.name" class="form-error text-[#b91c1c] text-xs">
                            {{ form.errors.name }}
                        </div>
                    </div>
                </div>
                
                <div class="mt-4 flex">
                    <button type="button" @click="closeModal()" class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4">{{ $t('Cancel') }}</button>
                    <button 
                        :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': form.processing }]"
                        :disabled="form.processing"
                    >
                        <svg v-if="form.processing" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                        <span v-else>{{ $t('Save') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </Modal>
</template>
