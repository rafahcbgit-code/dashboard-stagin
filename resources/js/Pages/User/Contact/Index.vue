<template>
    <AppLayout>
        <div class="p-4 md:p-6">
            <div v-if="isAddMode || editContact" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                <ContactForm :contactGroups="contactGroups" :contact="contact" :fields="fields" :locationSettings="locationSettings" />
            </div>

            <div v-else-if="isDetailMode" class="space-y-4">
                <Link href="/contacts" class="inline-flex items-center rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-700">
                    {{ $t('Back to contacts') }}
                </Link>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <ContactInfo :contact="contact" :fields="fields" :locationSettings="locationSettings" />
                </div>
            </div>

            <AdvancedContactTable
                v-else
                :rows="rows"
                :filters="filters"
                :rowCount="rowCount"
                :filteredCount="filteredCount"
                :contactGroups="contactGroups"
                :labels="labels"
                :fields="fields"
                :role="role"
            />
        </div>
    </AppLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import AppLayout from './../Layout/App.vue';
import ContactForm from '@/Components/ContactComponents/CreateForm.vue';
import ContactInfo from '@/Components/ContactInfo.vue';
import AdvancedContactTable from '@/Components/ContactManagement/AdvancedContactTable.vue';

const props = defineProps({
    rows: Object,
    filters: Object,
    rowCount: Number,
    filteredCount: Number,
    contactGroups: Array,
    labels: Array,
    contact: Object,
    editContact: Boolean,
    fields: Array,
    locationSettings: String,
    role: String,
});

const page = usePage();
const isAddMode = computed(() => page.url.startsWith('/contacts/add'));
const isDetailMode = computed(() => !isAddMode.value && !props.editContact && !!props.contact && !!props.filters?.id);
</script>
