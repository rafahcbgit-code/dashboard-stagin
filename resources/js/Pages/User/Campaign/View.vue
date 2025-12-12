<template>
    <AppLayout>
        <div class="p-4 md:p-8 rounded-[5px] h-full overflow-y-auto">
            <div class="flex justify-between capitalize">
                <div>
                    <h2 class="text-xl mb-1">{{ $t('Campaign details') }}</h2>
                    <p class="mb-6 flex items-center text-sm leading-6">
                        <span class="ml-1 mt-1">{{ $t('Ref') }}: {{ props.campaign.uuid }}</span>
                    </p>
                </div>
                <div class="space-s-2 flex items-center gap-2">
                    <!-- Story 2.6: AC-2.6.1, AC-2.6.5 - Cancel Campaign button (only for ongoing campaigns) -->
                    <button
                        v-if="isOngoing && !isCancelling"
                        @click="showCancelConfirmation = true"
                        class="rounded-md bg-red-600 hover:bg-red-700 px-3 py-2 text-sm text-white shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-600"
                    >
                        {{ $t('Cancel Campaign') }}
                    </button>
                    <span v-if="isCancelling" class="text-sm text-gray-600">{{ $t('Cancelling...') }}</span>

                    <a :href="'/campaigns/export/' + props.campaign.uuid" class="rounded-md bg-secondary px-3 py-2 text-sm text-white shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                        {{ $t('Export as CSV') }}
                    </a>

                    <Link href="/campaigns" class="rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">
                        {{ $t('Back') }}
                    </Link>
                </div>
            </div>

            <div class="md:flex md:space-s-4">
                <div class="md:w-[70%] capitalize">
                    <!-- Story 2.5: AC-2.5.4 - Real-time progress bar for ongoing campaigns -->
                    <div v-if="isOngoing" class="mb-6 bg-white rounded-lg border p-4">
                        <div class="flex justify-between items-center mb-2">
                            <h3 class="text-sm font-medium">{{ $t('Campaign Progress') }}</h3>
                            <span class="text-sm text-gray-600">{{ progress.percent }}%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-3 mb-3">
                            <div
                                class="bg-primary h-3 rounded-full transition-all duration-300"
                                :style="{ width: `${progress.percent}%` }"
                            ></div>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-green-600">{{ $t('Sent') }}: {{ progress.sent }}</span>
                            <span class="text-red-600">{{ $t('Failed') }}: {{ progress.failed }}</span>
                            <span class="text-gray-600">{{ $t('Pending') }}: {{ progress.pending }}</span>
                        </div>
                        <!-- Story 2.5: AC-2.5.5 - Estimated completion time -->
                        <div v-if="progress.estimatedCompletion" class="mt-2 text-sm text-gray-500 text-center">
                            {{ progress.estimatedCompletion }}
                        </div>
                    </div>

                    <!-- Story 2.5: AC-2.5.6 - Campaign completion success message -->
                    <div v-if="campaignCompleted" class="mb-6 bg-green-50 border border-green-200 rounded-lg p-4">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 text-green-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <span class="text-green-700 font-medium">{{ $t('Campaign completed successfully!') }}</span>
                        </div>
                    </div>

                    <!-- Story 2.6: AC-2.6.5 - Campaign cancelled message with final stats -->
                    <div v-if="campaignCancelled" class="mb-6 bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                        <div class="flex items-center mb-2">
                            <svg class="w-5 h-5 text-yellow-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                            </svg>
                            <span class="text-yellow-700 font-medium">{{ $t('Campaign cancelled') }}</span>
                        </div>
                        <div v-if="cancelStats" class="text-sm text-yellow-600 ml-7">
                            <p>{{ $t('Sent') }}: {{ cancelStats.sent }} | {{ $t('Cancelled') }}: {{ cancelStats.cancelled }} | {{ $t('Failed') }}: {{ cancelStats.failed }}</p>
                        </div>
                    </div>

                    <!-- Story 2.6: AC-2.6.3 - Cancellation confirmation dialog -->
                    <div v-if="showCancelConfirmation" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
                        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" @click="showCancelConfirmation = false"></div>
                            <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
                            <div class="inline-block align-bottom bg-white rounded-lg px-4 pt-5 pb-4 text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full sm:p-6">
                                <div class="sm:flex sm:items-start">
                                    <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10">
                                        <svg class="h-6 w-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                        </svg>
                                    </div>
                                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                                        <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">{{ $t('Cancel Campaign') }}</h3>
                                        <div class="mt-2">
                                            <p class="text-sm text-gray-500">{{ $t('Are you sure you want to cancel this campaign? All pending messages will be cancelled. Messages already sent will not be affected.') }}</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse">
                                    <button
                                        type="button"
                                        @click="cancelCampaign"
                                        class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-red-600 text-base font-medium text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 sm:ml-3 sm:w-auto sm:text-sm"
                                    >
                                        {{ $t('Yes, Cancel Campaign') }}
                                    </button>
                                    <button
                                        type="button"
                                        @click="showCancelConfirmation = false"
                                        class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:w-auto sm:text-sm"
                                    >
                                        {{ $t('No, Keep Running') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex w-[100%] mb-8 rounded-lg">
                        <div class="w-full rounded-tl-lg rounded-bl-lg text-center bg-white py-8 border">
                            <h2 class="text-xl">{{ displayStats.total }}</h2>
                            <h4 class="text-sm">{{ $t('Messages') }}</h4>
                        </div>
                        <div class="w-full text-center bg-white py-8 border">
                            <h2 class="text-xl">{{ displayStats.sent }}</h2>
                            <h4 class="text-sm">{{ $t('Sent') }}</h4>
                        </div>
                        <div class="w-full text-center bg-white py-8 border">
                            <h2 class="text-xl">{{ props.campaign.total_delivered_count }}</h2>
                            <h4 class="text-sm">{{ $t('Delivered') }}</h4>
                        </div>
                        <div class="w-full bg-white text-center py-8 border">
                            <h2 class="text-xl">{{ props.campaign.total_read_count }}</h2>
                            <h4 class="text-sm">{{ $t('Read') }}</h4>
                        </div>
                        <div class="w-full rounded-tr-lg rounded-br-lg bg-white text-center py-8 border">
                            <h2 class="text-xl">{{ displayStats.failed }}</h2>
                            <h4 class="text-sm">{{ $t('Failed') }}</h4>
                        </div>
                    </div>
                    <!-- Table Component-->
                    <CampaignLogTable :rows="props.rows" :filters="props.filters" :uuid="props.campaign.uuid"/>
                </div>
                <div class="md:w-[30%]">
                    <div class="w-full rounded-lg bg-white pt-4 pb-8 border px-4 space-y-1 capitalize">
                        <h2 class="mb-2">{{ $t('Campaign details') }}</h2>
                        <div class="text-sm bg-slate-100 p-3 rounded-lg">
                            <h3>{{ $t('Campaign name') }}</h3>
                            <p>{{ props.campaign?.name }}</p>
                        </div>
                        <div class="text-sm bg-slate-100 p-3 rounded-lg">
                            <h3>{{ $t('Template') }}</h3>
                            <p>{{ props.campaign?.template?.name }}</p>
                        </div>
                        <div class="text-sm bg-slate-100 p-3 rounded-lg">
                            <h3>{{ $t('Recipients') }}</h3>
                            <p>{{ props.campaign.contact_group_id === '0' ? 'All Contacts' : props.campaign?.contact_group?.name }}</p>
                        </div>
                        <div class="text-sm bg-slate-100 p-3 rounded-lg">
                            <h3>{{ $t('Time scheduled') }}</h3>
                            <p>{{ props.campaign.scheduled_at }}</p>
                        </div>
                        <div class="text-sm bg-slate-100 p-3 rounded-lg">
                            <h3>{{ $t('Status') }}</h3>
                            <p class="capitalize">{{ props.campaign.status }}</p>
                        </div>
                    </div>

                    <div class="w-full rounded-lg p-5 mt-5 border chat-bg">
                        <WhatsappTemplate :parameters="JSON.parse(props.campaign.metadata)" :placeholder="false" :visible="true"/>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./../Layout/App.vue";
    import CampaignLogTable from '@/Components/Tables/CampaignLogTable.vue';
    import WhatsappTemplate from '@/Components/WhatsappTemplate.vue';
    import { Link, router } from "@inertiajs/vue3";
    import { ref, computed, onMounted, onUnmounted } from 'vue';
    import Echo from 'laravel-echo';
    import Pusher from 'pusher-js';

    const props = defineProps(['campaign', 'rows', 'filters', 'pusherSettings', 'organizationId']);

    // Story 2.5: AC-2.5.4 - Real-time progress state
    const progress = ref({
        total: props.campaign.total_message_count || 0,
        sent: props.campaign.total_sent_count || 0,
        failed: props.campaign.total_failed_count || 0,
        pending: 0,
        percent: 0,
        estimatedCompletion: null,
    });

    // Track if campaign completed during this session
    const campaignCompleted = ref(false);

    // Story 2.6: Cancellation state
    const showCancelConfirmation = ref(false);
    const isCancelling = ref(false);
    const campaignCancelled = ref(props.campaign.status === 'cancelled');
    const cancelStats = ref(null);
    const cancelError = ref(null);

    // Check if campaign is ongoing
    const isOngoing = computed(() => props.campaign.status === 'ongoing' && !campaignCancelled.value);

    // Display stats that update in real-time for ongoing campaigns
    const displayStats = computed(() => {
        if (isOngoing.value) {
            return {
                total: progress.value.total,
                sent: progress.value.sent,
                failed: progress.value.failed,
            };
        }
        return {
            total: props.campaign.total_message_count,
            sent: props.campaign.total_sent_count,
            failed: props.campaign.total_failed_count,
        };
    });

    let echoChannel = null;

    // Story 2.6: AC-2.6.1, AC-2.6.4, AC-2.6.5, AC-2.6.6 - Cancel campaign function
    const cancelCampaign = async () => {
        isCancelling.value = true;
        showCancelConfirmation.value = false;
        cancelError.value = null;

        try {
            const response = await fetch(`/campaigns/${props.campaign.uuid}/cancel`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    'Accept': 'application/json',
                },
            });

            const data = await response.json();

            if (response.ok) {
                // Success - show cancelled message with stats
                campaignCancelled.value = true;
                cancelStats.value = data.stats;

                // Clean up Echo channel since campaign is no longer ongoing
                if (echoChannel) {
                    echoChannel.stopListening('.CampaignProgressEvent');
                    window.Echo.leave(`campaign.${props.campaign.uuid}`);
                    echoChannel = null;
                }

                // Refresh page data after short delay
                setTimeout(() => {
                    router.reload({ only: ['campaign', 'rows'] });
                }, 1500);
            } else {
                // Error - show error message
                cancelError.value = data.error || 'Failed to cancel campaign';
                alert(cancelError.value);
            }
        } catch (error) {
            cancelError.value = 'Network error occurred while cancelling campaign';
            alert(cancelError.value);
        } finally {
            isCancelling.value = false;
        }
    };

    onMounted(() => {
        // Story 2.5: AC-2.5.4 - Subscribe to private channel for ongoing campaigns
        if (isOngoing.value && props.pusherSettings?.pusher_app_key && props.pusherSettings?.pusher_app_cluster) {
            window.Pusher = Pusher;

            window.Echo = new Echo({
                broadcaster: 'pusher',
                key: props.pusherSettings.pusher_app_key,
                cluster: props.pusherSettings.pusher_app_cluster,
                encrypted: true,
            });

            // Subscribe to private campaign channel
            echoChannel = window.Echo.private(`campaign.${props.campaign.uuid}`)
                .listen('.CampaignProgressEvent', (data) => {
                    // Update progress with real-time data
                    progress.value = {
                        total: data.total,
                        sent: data.sent,
                        failed: data.failed,
                        pending: data.pending,
                        percent: data.percentComplete,
                        estimatedCompletion: data.estimatedCompletion,
                    };

                    // Story 2.5: AC-2.5.6 - Handle campaign completion
                    if (data.percentComplete >= 100 || data.pending === 0) {
                        campaignCompleted.value = true;
                        // Optionally refresh the page after a short delay to get final stats
                        setTimeout(() => {
                            router.reload({ only: ['campaign', 'rows'] });
                        }, 2000);
                    }
                });
        }
    });

    onUnmounted(() => {
        // Story 2.5: Clean up Echo channel subscription to prevent memory leaks
        if (echoChannel) {
            echoChannel.stopListening('.CampaignProgressEvent');
            window.Echo.leave(`campaign.${props.campaign.uuid}`);
        }
    });
</script>
