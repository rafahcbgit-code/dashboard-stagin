<template>
    <div
        v-if="isOpen"
        class="fixed inset-0 z-50 flex items-center justify-center"
        @click.self="$emit('close')"
    >
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black/40" @click="$emit('close')"></div>

        <!-- Modal panel -->
        <div class="relative bg-white rounded-xl shadow-xl w-full max-w-md mx-4 z-10">
            <!-- Header -->
            <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
                <h3 class="text-sm font-semibold text-slate-800">{{ $t('Send Meta Flow') }}</h3>
                <button @click="$emit('close')" class="text-slate-400 hover:text-slate-600">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
                        <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="p-5 space-y-4">
                <!-- Loading state -->
                <div v-if="loading" class="text-center py-6 text-slate-400 text-sm">
                    {{ $t('Loading flows...') }}
                </div>

                <!-- No published flows -->
                <div v-else-if="flows.length === 0" class="text-center py-6 text-slate-400 text-sm">
                    <p>{{ $t('No published flows found.') }}</p>
                    <a href="/automation/meta-flows" target="_blank" class="text-primary hover:underline text-xs mt-1 inline-block">
                        {{ $t('Manage Meta Flows') }}
                    </a>
                </div>

                <template v-else>
                    <!-- Flow picker -->
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1.5">{{ $t('Select Flow') }}</label>
                        <div class="space-y-1.5 max-h-44 overflow-y-auto pr-1">
                            <button
                                v-for="flow in flows"
                                :key="flow.uuid"
                                @click="selectedFlowUuid = flow.uuid"
                                :class="[
                                    'w-full text-left px-3 py-2.5 rounded-lg border text-sm transition-colors',
                                    selectedFlowUuid === flow.uuid
                                        ? 'border-primary bg-indigo-50 text-primary'
                                        : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50 text-slate-700'
                                ]"
                            >
                                <span class="font-medium">{{ flow.name }}</span>
                                <span class="ml-2 text-xs text-slate-400">{{ (flow.categories ?? []).join(', ') }}</span>
                            </button>
                        </div>
                    </div>

                    <!-- Message body -->
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1.5">
                            {{ $t('Message body') }}
                            <span class="text-slate-400 font-normal">({{ $t('shown above the form button') }})</span>
                        </label>
                        <textarea
                            v-model="body"
                            rows="2"
                            class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 outline-none resize-none focus:border-primary"
                            :placeholder="$t('Please complete the form.')"
                        ></textarea>
                    </div>

                    <!-- CTA button label -->
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1.5">{{ $t('Button label') }}</label>
                        <input
                            v-model="cta"
                            type="text"
                            maxlength="20"
                            class="w-full text-sm border border-slate-200 rounded-lg px-3 py-2 outline-none focus:border-primary"
                            :placeholder="$t('Open')"
                        />
                    </div>

                    <!-- Error -->
                    <p v-if="error" class="text-xs text-red-600">{{ error }}</p>

                    <!-- Actions -->
                    <div class="flex justify-end gap-2 pt-1">
                        <button
                            type="button"
                            @click="$emit('close')"
                            class="px-4 py-2 text-sm text-slate-500 bg-slate-50 hover:bg-slate-100 rounded-md border border-slate-200"
                        >
                            {{ $t('Cancel') }}
                        </button>
                        <button
                            @click="handleSend"
                            :disabled="!selectedFlowUuid || sending"
                            class="px-4 py-2 text-sm text-white bg-primary hover:bg-indigo-500 rounded-md disabled:opacity-50 flex items-center gap-2"
                        >
                            <svg v-if="sending" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
                                <path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/>
                                <path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path>
                            </svg>
                            {{ sending ? $t('Sending...') : $t('Send Flow') }}
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

<script setup>
    import { ref, watch } from 'vue';
    import axios from 'axios';

    const props = defineProps({
        isOpen:      { type: Boolean, required: true },
        contactUuid: { type: String,  required: true },
    });

    const emit = defineEmits(['close', 'sent']);

    const flows           = ref([]);
    const loading         = ref(false);
    const selectedFlowUuid = ref(null);
    const body            = ref('');
    const cta             = ref('');
    const sending         = ref(false);
    const error           = ref(null);

    // Fetch published flows when the modal opens
    watch(() => props.isOpen, async (open) => {
        if (!open) return;

        // Reset state on each open
        selectedFlowUuid.value = null;
        body.value             = '';
        cta.value              = '';
        error.value            = null;
        loading.value          = true;

        try {
            const res  = await axios.get('/automation/meta-flows/list');
            flows.value = res.data;
        } catch {
            flows.value = [];
        } finally {
            loading.value = false;
        }
    });

    const handleSend = async () => {
        if (!selectedFlowUuid.value) return;

        sending.value = true;
        error.value   = null;

        try {
            await axios.post(`/chat/${props.contactUuid}/send/meta-flow`, {
                meta_flow_uuid: selectedFlowUuid.value,
                body:           body.value  || 'Please complete the form.',
                cta:            cta.value   || 'Open',
            });

            emit('sent');
            emit('close');
        } catch (err) {
            error.value = err.response?.data?.message ?? 'Failed to send flow. Please try again.';
        } finally {
            sending.value = false;
        }
    };
</script>
