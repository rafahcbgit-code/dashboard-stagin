<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-10 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] h-full md:overflow-y-auto">
            <div class="flex justify-between">
                <div>
                    <h1 class="text-xl mb-1">{{ $t('Otp Config') }}</h1>
                </div>
                <div>
                    <Link href="/admin/otp-config/create" class="rounded-md bg-indigo-600 px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Add New Config') }}</Link>
                </div>
            </div>
            <OtpTable :rows="props.rows" :filters="props.filters" />
        </div>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./../Layout/App.vue";
    import { Link } from "@inertiajs/vue3";
    import OtpTable from '@/Components/Tables/OtpTable.vue';

    const props = defineProps({ title: String, rows: Object, filters: Object });
</script>