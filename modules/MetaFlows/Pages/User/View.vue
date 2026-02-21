<template>
    <AppLayout>
        <!-- Page header -->
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <a href="/automation/meta-flows" class="text-slate-400 hover:text-slate-600">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m15 18l-6-6l6-6"/></svg>
                </a>
                <div>
                    <h4 class="text-[16px] font-medium">{{ flow.name }}</h4>
                    <span
                        :class="['inline-block mt-1 py-0.5 px-2 rounded-full text-xs font-medium capitalize', statusColor(currentStatus)]"
                    >
                        {{ currentStatus }}
                    </span>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="flex items-center gap-2">
                <!-- Sync pulls the latest state from Meta -->
                <button
                    @click="handleSync"
                    :disabled="syncing"
                    class="rounded-md border border-slate-200 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50 disabled:opacity-50"
                >
                    {{ syncing ? $t('Syncing...') : $t('Sync from Meta') }}
                </button>

                <!-- Deprecate is only available for published flows -->
                <button
                    v-if="currentStatus === 'published'"
                    @click="handleDeprecate"
                    :disabled="acting"
                    class="rounded-md border border-orange-200 px-3 py-2 text-sm text-orange-600 hover:bg-orange-50 disabled:opacity-50"
                >
                    {{ $t('Deprecate') }}
                </button>

                <!-- Publish is available for draft flows -->
                <button
                    v-if="currentStatus === 'draft'"
                    @click="handlePublish"
                    :disabled="acting"
                    class="rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                >
                    {{ acting ? $t('Publishing...') : $t('Publish') }}
                </button>

                <!-- Save uploads the flow JSON to Meta -->
                <button
                    @click="handleSave"
                    :disabled="saving"
                    class="rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 disabled:opacity-50"
                >
                    {{ saving ? $t('Saving...') : $t('Save') }}
                </button>
            </div>
        </div>

        <!-- Inline feedback message -->
        <p v-if="feedbackMessage" :class="['mb-4 text-sm', feedbackIsError ? 'text-red-600' : 'text-green-600']">
            {{ feedbackMessage }}
        </p>

        <!-- Two-column layout: details panel on the left, editor on the right -->
        <div class="flex gap-6">
            <!-- Left panel: flow metadata -->
            <div class="w-64 shrink-0">
                <div class="bg-white border border-slate-200 rounded-lg p-4 text-sm space-y-4">
                    <div>
                        <p class="text-slate-400 text-xs uppercase tracking-wide mb-1">{{ $t('Meta Flow ID') }}</p>
                        <p class="font-mono text-xs break-all text-slate-700">{{ flow.meta_flow_id ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-slate-400 text-xs uppercase tracking-wide mb-1">{{ $t('Categories') }}</p>
                        <p class="text-slate-700">{{ (flow.categories ?? []).join(', ') || '—' }}</p>
                    </div>
                    <div v-if="flow.preview_url">
                        <p class="text-slate-400 text-xs uppercase tracking-wide mb-1">{{ $t('Preview') }}</p>
                        <a :href="flow.preview_url" target="_blank" class="text-primary hover:underline text-xs">
                            {{ $t('Open preview') }}
                        </a>
                    </div>
                    <div>
                        <p class="text-slate-400 text-xs uppercase tracking-wide mb-1">{{ $t('Responses') }}</p>
                        <p class="text-slate-700">{{ flow.responses_count ?? 0 }}</p>
                    </div>
                    <div>
                        <p class="text-slate-400 text-xs uppercase tracking-wide mb-1">{{ $t('Last updated') }}</p>
                        <p class="text-slate-700">{{ flow.updated_at }}</p>
                    </div>
                </div>

                <!-- How-to helper for new users -->
                <div class="bg-slate-50 border border-slate-200 rounded-lg p-4 text-xs text-slate-500 mt-4 space-y-2">
                    <p class="font-medium text-slate-600">{{ $t('How to use') }}</p>
                    <ol class="list-decimal list-inside space-y-1">
                        <li>{{ $t('Add screens and components using the editor') }}</li>
                        <li>{{ $t('Click Save to upload to Meta') }}</li>
                        <li>{{ $t('Click Publish when ready to send') }}</li>
                    </ol>
                </div>

                <!-- Deletion rules note -->
                <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-xs text-amber-700 mt-4 space-y-1">
                    <p class="font-medium text-amber-800">{{ $t('Deleting a flow') }}</p>
                    <p>{{ $t('Meta only allows deleting flows in Draft or Deprecated status.') }}</p>
                    <p>{{ $t('To delete a Published flow: Deprecate it first, then delete.') }}</p>
                </div>
            </div>

            <!-- Right panel: visual screen builder -->
            <div class="flex-1 min-w-0">
                <MetaFlowBuilder v-model="assetContent" />

                <!-- Collapsible raw JSON section (hidden by default) -->
                <div class="mt-4 border border-slate-200 rounded-lg overflow-hidden">
                    <button
                        @click="showJson = !showJson"
                        class="w-full flex items-center justify-between px-4 py-2 bg-slate-50 border-b border-slate-100 text-xs text-slate-500 hover:bg-slate-100"
                    >
                        <span class="font-mono">flow.json</span>
                        <span>{{ showJson ? '▲ Hide JSON' : '▶ Show raw JSON' }}</span>
                    </button>

                    <div v-if="showJson">
                        <div class="flex justify-end px-4 py-1 border-b border-slate-100 bg-slate-50">
                            <button @click="formatJson" class="text-xs text-slate-400 hover:text-slate-600">
                                {{ $t('Format') }}
                            </button>
                        </div>
                        <textarea
                            v-model="assetContent"
                            class="w-full font-mono text-sm p-4 outline-none resize-none bg-white text-slate-800"
                            style="min-height: 400px;"
                            spellcheck="false"
                            :placeholder="$t('Flow JSON will appear here...')"
                        ></textarea>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
    import AppLayout from '@/Pages/User/Layout/App.vue';
    import { ref } from 'vue';
    import axios from 'axios';
    import MetaFlowBuilder from '@modules/MetaFlows/Pages/User/Components/MetaFlowBuilder.vue';

    const props = defineProps(['flow']);

    // Local reactive copy of the flow status so buttons update without a full page reload
    const currentStatus = ref(props.flow.status);

    // Whether the raw JSON section is expanded
    const showJson = ref(false);

    // Start with the stored asset content or a minimal blank flow template
    const assetContent = ref(
        props.flow.asset_content
            ? tryPrettyPrint(props.flow.asset_content)
            : JSON.stringify({ version: '6.3', screens: [] }, null, 2)
    );

    const saving  = ref(false);
    const acting  = ref(false);
    const syncing = ref(false);

    const feedbackMessage = ref(null);
    const feedbackIsError = ref(false);

    // Map Meta status values to Tailwind badge colors
    const statusColor = (status) => {
        const map = {
            published:  'bg-green-100 text-green-700',
            draft:      'bg-gray-100 text-gray-600',
            deprecated: 'bg-orange-100 text-orange-700',
            blocked:    'bg-red-100 text-red-700',
            throttled:  'bg-yellow-100 text-yellow-700',
        };
        return map[status] ?? 'bg-gray-100 text-gray-600';
    };

    // Try to pretty-print stored JSON without losing it if the input is already formatted or invalid
    function tryPrettyPrint(content) {
        try {
            return JSON.stringify(JSON.parse(content), null, 2);
        } catch {
            return content;
        }
    }

    // Re-format the JSON in the collapsible editor textarea
    const formatJson = () => {
        try {
            assetContent.value = JSON.stringify(JSON.parse(assetContent.value), null, 2);
            showFeedback('Formatted.', false);
        } catch {
            showFeedback('Invalid JSON — cannot format.', true);
        }
    };

    // Upload the current flow JSON to Meta via the update endpoint
    const handleSave = async () => {
        saving.value = true;
        clearFeedback();

        try {
            await axios.put(`/automation/meta-flows/${props.flow.uuid}`, {
                asset_content: assetContent.value,
            });
            showFeedback('Flow saved successfully.', false);
        } catch (err) {
            const msg = err.response?.data?.message ?? 'Failed to save flow.';
            showFeedback(msg, true);
        } finally {
            saving.value = false;
        }
    };

    // Publish the flow on Meta — once published it can be sent to contacts
    const handlePublish = async () => {
        acting.value = true;
        clearFeedback();

        try {
            const res = await axios.post(`/automation/meta-flows/${props.flow.uuid}/publish`);
            currentStatus.value = res.data.status ?? 'published';
            showFeedback('Flow published successfully!', false);
        } catch (err) {
            const msg = err.response?.data?.message ?? 'Failed to publish flow.';
            showFeedback(msg, true);
        } finally {
            acting.value = false;
        }
    };

    // Deprecate the flow so it can no longer be sent to new contacts
    const handleDeprecate = async () => {
        acting.value = true;
        clearFeedback();

        try {
            const res = await axios.post(`/automation/meta-flows/${props.flow.uuid}/deprecate`);
            currentStatus.value = res.data.status ?? 'deprecated';
            showFeedback('Flow deprecated.', false);
        } catch (err) {
            const msg = err.response?.data?.message ?? 'Failed to deprecate flow.';
            showFeedback(msg, true);
        } finally {
            acting.value = false;
        }
    };

    // Pull the latest state and content from Meta, then refresh the builder
    const handleSync = async () => {
        syncing.value = true;
        clearFeedback();

        try {
            const res     = await axios.post(`/automation/meta-flows/${props.flow.uuid}/sync`);
            const updated = res.data.flow;

            currentStatus.value = updated.status ?? currentStatus.value;

            if (updated.asset_content) {
                assetContent.value = tryPrettyPrint(updated.asset_content);
            }

            showFeedback('Synced from Meta.', false);
        } catch (err) {
            const msg = err.response?.data?.message ?? 'Failed to sync from Meta.';
            showFeedback(msg, true);
        } finally {
            syncing.value = false;
        }
    };

    const showFeedback = (message, isError) => {
        feedbackMessage.value = message;
        feedbackIsError.value = isError;
    };

    const clearFeedback = () => {
        feedbackMessage.value = null;
        feedbackIsError.value = false;
    };
</script>
