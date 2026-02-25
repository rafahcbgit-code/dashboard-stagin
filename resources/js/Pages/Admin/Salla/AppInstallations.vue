<template>
    <AppLayout>
        <div
            class="bg-white md:bg-inherit pt-10 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] h-full md:overflow-y-auto">
            <div class="flex justify-between items-start gap-4 mb-6">
                <div>
                    <h1 class="text-xl mb-1">Salla App Installations</h1>
                    <p class="mb-2 flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24">
                            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z" />
                        </svg>
                        <span class="ml-1 mt-1">Track stores that installed the Salla app.</span>
                    </p>
                </div>
            </div>

            <div class="bg-white border rounded-md p-4 mb-4">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div>
                        <label class="text-xs text-gray-600">Merchant ID</label>
                        <input v-model="form.merchant_id" type="text" class="w-full border rounded-md px-3 py-2 text-sm"
                            placeholder="1841950944" />
                    </div>

                    <div>
                        <label class="text-xs text-gray-600">App Name</label>
                        <input v-model="form.app_name" type="text" class="w-full border rounded-md px-3 py-2 text-sm"
                            placeholder="ChatBerry-R" />
                    </div>
                    <div>
                        <label class="text-xs text-gray-600">Per Page</label>
                        <select v-model="form.per_page" class="w-full border rounded-md px-3 py-2 text-sm">
                            <option :value="25">25</option>
                            <option :value="50">50</option>
                            <option :value="100">100</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs text-gray-600">Installed From</label>
                        <input v-model="form.installed_from" type="datetime-local"
                            class="w-full border rounded-md px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="text-xs text-gray-600">Installed To</label>
                        <input v-model="form.installed_to" type="datetime-local"
                            class="w-full border rounded-md px-3 py-2 text-sm" />
                    </div>
                </div>

                <div class="flex items-center gap-2 mt-3">
                    <button @click="applyFilters"
                        class="bg-primary text-white px-4 py-2 rounded-md text-sm">Apply</button>
                    <button @click="resetFilters" class="bg-slate-100 px-4 py-2 rounded-md text-sm">Reset</button>
                </div>
            </div>

            <div class="bg-white border rounded-md overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-gray-700">
                            <tr>
                                <th class="text-left px-4 py-3">Organization</th>
                                <th class="text-left px-4 py-3">Merchant ID</th>
                                <th class="text-left px-4 py-3">App</th>
                                <th class="text-left px-4 py-3">Type</th>
                                <th class="text-left px-4 py-3">Store Type</th>
                                <th class="text-left px-4 py-3">Scopes</th>
                                <th class="text-left px-4 py-3">Installed At</th>

                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="rows.data.length === 0">
                                <td colspan="8" class="px-4 py-6 text-center text-gray-500">No installations found.</td>
                            </tr>
                            <tr v-for="row in rows.data" :key="row.id" class="border-t">
                                <td class="px-4 py-3">
                                    <div class="font-medium">{{ row.organization?.name ?? '—' }}</div>
                                    <div class="text-xs text-gray-500" v-if="row.organization?.id">#{{
                                        row.organization.id }}</div>
                                </td>
                                <td class="px-4 py-3 font-mono">{{ row.merchant_id }}</td>
                                <td class="px-4 py-3">
                                    <div class="font-medium">{{ row.app_name ?? '—' }}</div>
                                    <div class="text-xs text-gray-500" v-if="row.salla_installation_id">Install ID: {{
                                        row.salla_installation_id }}</div>
                                </td>
                                <td class="px-4 py-3">{{ row.app_type ?? '—' }}</td>
                                <td class="px-4 py-3">{{ row.store_type ?? '—' }}</td>
                                <td class="px-4 py-3">{{ row.scopes_count ?? 0 }}</td>
                                <td class="px-4 py-3">{{ row.installation_date ?? row.webhook_created_at ?? '—' }}</td>

                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <Pagination class="mt-3" :pagination="rows.meta" />
        </div>
    </AppLayout>
</template>

<script setup>
import AppLayout from "../Layout/App.vue";
import Pagination from "@/Components/Pagination.vue";
import { useForm } from "@inertiajs/vue3";

const props = defineProps({
    title: String,
    rows: Object,
    filters: Object,
});

const form = useForm({
    merchant_id: props.filters?.merchant_id ?? "",
    organization_id: props.filters?.organization_id ?? "",
    app_name: props.filters?.app_name ?? "",
    per_page: props.filters?.per_page ?? 25,
    installed_from: props.filters?.installed_from ?? null,
    installed_to: props.filters?.installed_to ?? null,
});

const applyFilters = () => {
    form.get("/admin/salla/app-installations", {
        preserveState: true,
        preserveScroll: true,
    });
};

const resetFilters = () => {
    form.merchant_id = "";
    form.organization_id = "";
    form.app_name = "";
    form.per_page = 25;
    form.installed_from = null;
    form.installed_to = null;
    applyFilters();
};
</script>
