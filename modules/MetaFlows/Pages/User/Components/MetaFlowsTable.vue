<script setup>
    import { ref } from 'vue';
    import debounce from 'lodash/debounce';
    import { router, useForm, Link } from '@inertiajs/vue3';
    import AlertModal from '@/Components/AlertModal.vue';
    import { useAlertModal } from '@/Composables/useAlertModal';
    import Dropdown from '@/Components/Dropdown.vue';
    import DropdownItemGroup from '@/Components/DropdownItemGroup.vue';
    import DropdownItem from '@/Components/DropdownItem.vue';

    const props = defineProps({
        rows:    { type: Object, required: true },
        filters: { type: Object },
    });

    const { isOpenAlert: isOpenDeleteAlert, openAlert: openDeleteAlert, confirmAlert: confirmDeleteAlert } = useAlertModal();
    const form = useForm({ _method: null });

    const statusColor = (status) => {
        const map = {
            published:  'bg-green-100 text-green-700 border-green-200',
            draft:      'bg-slate-100 text-slate-500 border-slate-200',
            deprecated: 'bg-orange-100 text-orange-600 border-orange-200',
            blocked:    'bg-red-100 text-red-600 border-red-200',
            throttled:  'bg-yellow-100 text-yellow-700 border-yellow-200',
        };
        return map[status] ?? 'bg-slate-100 text-slate-500 border-slate-200';
    };

    const statusDot = (status) => {
        const map = {
            published:  'bg-green-500',
            draft:      'bg-slate-400',
            deprecated: 'bg-orange-500',
            blocked:    'bg-red-500',
            throttled:  'bg-yellow-500',
        };
        return map[status] ?? 'bg-slate-400';
    };

    const handleDeleteFlow = (uuid) => openDeleteAlert(uuid);
    const deleteAction     = (uuid) => form.delete('/automation/meta-flows/' + uuid);

    const params      = ref({ search: props.filters?.search ?? null });
    const isSearching = ref(false);

    const clearSearch = () => {
        params.value.search = null;
        runSearch();
    };

    const search = debounce(() => {
        isSearching.value = true;
        runSearch();
    }, 1000);

    const runSearch = () => {
        router.visit('/automation/meta-flows', {
            method: 'get',
            data:   params.value,
        });
    };
</script>

<template>
    <!-- Search bar -->
    <div class="flex items-center border border-slate-200 h-10 w-full md:w-80 rounded-lg mb-5 text-sm bg-white shadow-sm">
        <span class="ps-3 text-slate-400">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        </span>
        <input
            @input="search"
            v-model="params.search"
            type="text"
            class="outline-none px-3 w-full bg-transparent text-slate-700 placeholder-slate-400 text-sm"
            :placeholder="$t('Search flows...')"
        />
        <button v-if="!isSearching && params.search" @click="clearSearch" type="button" class="pe-3 text-slate-400 hover:text-slate-600">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2zm3.7 12.3c.4.4.4 1 0 1.4-.4.4-1 .4-1.4 0L12 13.4l-2.3 2.3c-.4.4-1 .4-1.4 0-.4-.4-.4-1 0-1.4l2.3-2.3-2.3-2.3c-.4-.4-.4-1 0-1.4.4-.4 1-.4 1.4 0l2.3 2.3 2.3-2.3c.4-.4 1-.4 1.4 0 .4.4.4 1 0 1.4L13.4 12l2.3 2.3z"/></svg>
        </button>
        <span v-if="isSearching" class="pe-3 text-slate-400">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="0 12 12;90 12 12;180 12 12;270 12 12"/><animate attributeName="opacity" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" begin="0.2s" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="30 12 12;120 12 12;210 12 12;300 12 12"/><animate attributeName="opacity" begin="0.2s" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle><circle cx="12" cy="3.5" r="1.5" fill="currentColor" opacity="0"><animateTransform attributeName="transform" begin="0.4s" calcMode="discrete" dur="2.4s" repeatCount="indefinite" type="rotate" values="60 12 12;150 12 12;240 12 12;330 12 12"/><animate attributeName="opacity" begin="0.4s" dur="0.6s" keyTimes="0;0.5;1" repeatCount="indefinite" values="1;1;0"/></circle></svg>
        </span>
    </div>

    <!-- Card grid -->
    <div
        v-if="rows.data && rows.data.length > 0"
        class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4"
    >
        <div
            v-for="item in rows.data"
            :key="item.uuid"
            class="cb-hover-lift group bg-white border border-slate-200 rounded-xl p-4 hover:shadow-md hover:border-slate-300 transition-all duration-200 flex flex-col gap-3 relative"
        >
            <!-- Top row: status + action menu -->
            <div class="flex items-center justify-between">
                <span :class="['inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-1 rounded-full border capitalize', statusColor(item.status)]">
                    <span :class="['w-1.5 h-1.5 rounded-full shrink-0', statusDot(item.status)]"></span>
                     {{ $t(item.status) }}
                </span>
                <Dropdown :align="'right'">
                    <button class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm0 6a2 2 0 1 0 0-4 2 2 0 0 0 0 4zm0 6a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/></svg>
                    </button>
                    <template #items>
                        <DropdownItemGroup>
                            <DropdownItem :href="'/automation/meta-flows/' + item.uuid">
                                {{ $t('Edit') }}
                            </DropdownItem>
                            <DropdownItem as="button" @click="handleDeleteFlow(item.uuid)">
                                <span class="text-red-500">{{ $t('Delete') }}</span>
                            </DropdownItem>
                        </DropdownItemGroup>
                    </template>
                </Dropdown>
            </div>

            <!-- Flow name (clickable → editor) -->
            <Link
                :href="'/automation/meta-flows/' + item.uuid"
                class="block"
            >
                <h3 class="text-sm font-semibold text-slate-800 capitalize group-hover:text-primary transition-colors leading-snug line-clamp-2">
                    {{ item.name }}
                </h3>
            </Link>

            <!-- Category chip -->
            <div v-if="item.categories && item.categories.length" class="flex flex-wrap gap-1">
                <span
                    v-for="cat in item.categories"
                    :key="cat"
                    class="text-[11px] px-2 py-0.5 rounded-md bg-slate-100 text-slate-500 font-medium"
                >
                    {{ cat.replace(/_/g, ' ') }}
                </span>
            </div>

            <!-- Stats row -->
            <div class="flex items-center justify-between text-xs text-slate-400 border-t border-slate-100 pt-3 mt-auto">
                <!-- Responses count (links to the responses page) -->
                <Link
                    :href="'/automation/meta-flows/' + item.uuid + '/responses'"
                    class="flex items-center gap-1.5 hover:text-primary transition-colors"
                    @click.stop
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <span>{{ item.responses_count ?? 0 }} {{ $t('responses') }}</span>
                </Link>
                <!-- Updated at -->
                <span class="flex items-center gap-1">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                    {{ item.updated_at }}
                </span>
            </div>
        </div>
    </div>

    <!-- Empty state -->
    <div v-else class="flex flex-col items-center justify-center py-20 text-center">
        <div class="w-16 h-16 rounded-2xl bg-slate-100 flex items-center justify-center mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/><path d="M7 7h10M7 11h4"/></svg>
        </div>
        <p class="text-sm font-medium text-slate-600 mb-1">{{ $t('No flows yet') }}</p>
        <p class="text-xs text-slate-400">{{ $t('Create your first flow or pull existing ones from Meta.') }}</p>
    </div>

    <!-- Delete confirmation -->
    <AlertModal
        v-model="isOpenDeleteAlert"
        @confirm="() => confirmDeleteAlert(deleteAction)"
        :label="$t('Delete Meta Flow')"
        :description="$t('Are you sure you want to delete this flow? It will also be removed from Meta.')"
        :confirm-button-text="$t('Delete')"
        confirm-button-class="bg-red-600 hover:bg-red-500"
        icon="warning"
    />
</template>
