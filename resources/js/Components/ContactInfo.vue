<script setup>
import axios from 'axios';
import { ref, watchEffect } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownItemGroup from '@/Components/DropdownItemGroup.vue';
import DropdownItem from '@/Components/DropdownItem.vue';
import { trans } from 'laravel-vue-i18n';

const props = defineProps(['contact', 'fields', 'locationSettings']);
const contact = ref(props.contact);
const metadata = (props.contact.metadata !== null && props.contact.metadata !== '') ? JSON.parse(props.contact.metadata) : {};

watchEffect(() => {
    contact.value = props.contact;
});

const favorite = async() => {
    contact.value.is_favorite = !contact.value.is_favorite;
    router.put('/contacts/favorite/' + contact.value.uuid, { favorite: contact.value.is_favorite });
}

const form = useForm({'test': null, is_subscribed: contact.value.is_subscribed ?? 1});

const deleteRow = async() => {
    router.visit('/contacts', {
        method: 'delete',
        data: { 'uuids': [ contact.value.uuid ]},
        preserveState: true
    })
}

const getAddressDetail = (value, key) => {
    const address = JSON.parse(value);
    return address?.[key] && address?.[key] != 'Not Set' ? address?.[key] : trans('not set');
}
</script>

<template>
<div class="px-20 overflow-y-scroll h-screen">
    <div class="flex justify-center space-x-8 items-center pb-6 pr-20 border-gray-300 border-b">
        <div>
            <div class="rounded-full p-1">
                <img v-if="contact.avatar" class="rounded-full w-40 h-40" :src="contact.avatar">
                <div v-else class="rounded-full w-40 h-40">
                    <svg class="text-gray-300" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M18.685 19.097A9.723 9.723 0 0021.75 12c0-5.385-4.365-9.75-9.75-9.75S2.25 6.615 2.25 12a9.723 9.723 0 003.065 7.097A9.716 9.716 0 0012 21.75a9.716 9.716 0 006.685-2.653zm-12.54-1.285A7.486 7.486 0 0112 15a7.486 7.486 0 015.855 2.812A8.224 8.224 0 0112 20.25a8.224 8.224 0 01-5.855-2.438zM15.75 9a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" clip-rule="evenodd" />
                    </svg>
                </div>
            </div>
        </div>
        <div>
            <h1 class="text-3xl">{{ contact.full_name }}</h1>
            <div class="text-slate-500 truncate flex items-center">
                <span class="text-sm">{{ contact.formatted_phone_number }}</span>
            </div>
            
            <!-- Labels Display under name -->
            <div v-if="contact.labels && contact.labels.length > 0" class="flex flex-wrap gap-2 mt-2">
                <div v-for="label in contact.labels" :key="label.id" 
                     class="inline-flex items-center space-x-1 px-2 py-1 rounded-full text-xs border"
                     :style="{ 
                        borderColor: label.color, 
                        backgroundColor: label.color + '20',
                        color: label.color
                     }">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24">
                        <path fill="none" :stroke="label.color" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M20.59 13.41 12 22 4 14V4h10l8.59 8.59zM7 7h.01" />
                    </svg>
                    <span class="font-medium">{{ label.title }}</span>
                </div>
            </div>
            
            <div class="flex space-x-3 mt-4">
                <button class="bg-gray-200 h-9 px-4 rounded-md flex items-center">
                    <Link :href="'/contacts/' + contact.uuid + '?edit=true'" class="text-[14px]">{{ $t('Edit') }}</Link>
                </button>
                <button class="bg-gray-200 h-9 px-4 rounded-md flex items-center">
                    <Link :href="'/chats/' + contact.uuid" class="text-[14px]">Chats</Link>
                </button>
                <button @click="favorite()" class="bg-gray-200 h-9 px-4 rounded-md">
                    <svg v-if="contact.is_favorite === 0 || contact.is_favorite === false" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="1.5" d="M9.153 5.408C10.42 3.136 11.053 2 12 2c.947 0 1.58 1.136 2.847 3.408l.328.588c.36.646.54.969.82 1.182c.28.213.63.292 1.33.45l.636.144c2.46.557 3.689.835 3.982 1.776c.292.94-.546 1.921-2.223 3.882l-.434.507c-.476.557-.715.836-.822 1.18c-.107.345-.071.717.001 1.46l.066.677c.253 2.617.38 3.925-.386 4.506c-.766.582-1.918.051-4.22-1.009l-.597-.274c-.654-.302-.981-.452-1.328-.452c-.347 0-.674.15-1.329.452l-.595.274c-2.303 1.06-3.455 1.59-4.22 1.01c-.767-.582-.64-1.89-.387-4.507l.066-.676c.072-.744.108-1.116 0-1.46c-.106-.345-.345-.624-.821-1.18l-.434-.508c-1.677-1.96-2.515-2.941-2.223-3.882c.293-.941 1.523-1.22 3.983-1.776l.636-.144c.699-.158 1.048-.237 1.329-.45c.28-.213.46-.536.82-1.182l.328-.588Z"/></svg>
                    <svg v-else xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="#031b4d" stroke="#031b4d" stroke-width="1.5" d="M9.153 5.408C10.42 3.136 11.053 2 12 2c.947 0 1.58 1.136 2.847 3.408l.328.588c.36.646.54.969.82 1.182c.28.213.63.292 1.33.45l.636.144c2.46.557 3.689.835 3.982 1.776c.292.94-.546 1.921-2.223 3.882l-.434.507c-.476.557-.715.836-.822 1.18c-.107.345-.071.717.001 1.46l.066.677c.253 2.617.38 3.925-.386 4.506c-.766.582-1.918.051-4.22-1.009l-.597-.274c-.654-.302-.981-.452-1.328-.452c-.347 0-.674.15-1.329.452l-.595.274c-2.303 1.06-3.455 1.59-4.22 1.01c-.767-.582-.64-1.89-.387-4.507l.066-.676c.072-.744.108-1.116 0-1.46c-.106-.345-.345-.624-.821-1.18l-.434-.508c-1.677-1.96-2.515-2.941-2.223-3.882c.293-.941 1.523-1.22 3.983-1.776l.636-.144c.699-.158 1.048-.237 1.329-.45c.28-.213.46-.536.82-1.182l.328-.588Z"/></svg>
                </button>
            </div>
        </div>
    </div>

    <div class="pr-20 border-gray-300 border-b py-4">
        <div class="grid grid-cols-2 space-x-8 text-[14px]">
            <div class="text-right text-slate-500 pb-2">
                <span>{{ $t('Full name') }}</span>
            </div>
            <div>
                <span>{{ contact.full_name }}</span>
            </div>

            <div class="text-right text-slate-500 pb-2">
                <span>{{ $t('Subscription Status') }}</span>
            </div>
            <div>
                <span>{{ contact.is_subscribed == 1 ? $t('subscribed') : $t('unSubscribed') }}</span>
            </div>

            <div class="text-right text-slate-500 pb-2">
                <span>{{ $t('Email') }}</span>
            </div>
            <div>
                <span>{{ contact.email ?? $t('not set') }}</span>
            </div>

            <div class="text-right text-slate-500 pb-2">
                <span>{{ $t('Contact') }}</span>
            </div>
            <div>
                <span>{{ contact.formatted_phone_number }}</span>
            </div>

            <div class="text-right text-slate-500 pb-2">
                <span>{{ $t('Group') }}</span>
            </div>
            <div>
                <span class="">
                    {{ contact.contact_groups?.length ? contact.contact_groups.map(group => group.name).join(', ') : $t('not set') }}
                </span>
            </div>

            <!-- Labels row in the details grid -->
            <div class="text-right text-slate-500 pb-2">
                <span>{{ $t('Labels') }}</span>
            </div>
            <div>
                <div v-if="contact.labels && contact.labels.length > 0" class="flex flex-wrap gap-1">
                    <div v-for="label in contact.labels" :key="label.id" 
                         class="inline-flex items-center space-x-1 px-2 py-1 rounded-full text-xs border"
                         :style="{ 
                            borderColor: label.color, 
                            backgroundColor: label.color + '15',
                            color: label.color
                         }">
                        <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24">
                            <path fill="none" :stroke="label.color" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M20.59 13.41 12 22 4 14V4h10l8.59 8.59zM7 7h.01" />
                        </svg>
                        <span class="text-xs">{{ label.title }}</span>
                    </div>
                </div>
                <span v-else class="text-gray-500 text-sm">{{ $t('not set') }}</span>
            </div>
        </div>
    </div>

    <!-- Additional Data (Before location) -->
    <div v-if="locationSettings === 'before' && fields.length > 0" class="pr-20 border-gray-300 border-b py-4">
        <div v-for="(input, index) in props.fields" class="grid grid-cols-2 space-x-8 text-[14px]">
            <div class="text-right text-slate-500 pb-2">
                <span>{{ $t(input.name) }}</span>
            </div>
            <div>
                <span v-if="metadata && metadata[input.name] != null">{{ metadata[input.name] }}</span>
                <span v-else class="p-1 bg-slate-100 text-xs rounded-md text-gray-600">{{ $t('not set') }}</span>
            </div>
        </div>
    </div>

    <!-- Address -->
    <div class="pr-20 py-4">
        <div class="grid grid-cols-2 space-x-8 text-[14px]">
            <div class="text-right text-slate-500 pb-2">
                <span>{{ $t('Street') }}</span>
            </div>
            <div>
                <span>{{ getAddressDetail(contact.address, 'street') }}</span>
            </div>
            <div class="text-right text-slate-500 pb-2">
                <span>{{ $t('City') }}</span>
            </div>
            <div>
                <span>{{ getAddressDetail(contact.address, 'city') }}</span>
            </div>
            <div class="text-right text-slate-500 pb-2">
                <span>{{ $t('State') }}</span>
            </div>
            <div>
                <span>{{ getAddressDetail(contact.address, 'state') }}</span>
            </div>
            <div class="text-right text-slate-500 pb-2">
                <span>{{ $t('Zip code') }}</span>
            </div>
            <div>
                <span>{{ getAddressDetail(contact.address, 'zip') }}</span>
            </div>
            <div class="text-right text-slate-500 pb-2">
                <span>{{ $t('Country') }}</span>
            </div>
            <div>
                <span>{{ getAddressDetail(contact.address, 'country') }}</span>
            </div>
        </div>
    </div>

    <!-- Additional Data (After location) -->
    <div v-if="locationSettings === 'after' && fields.length > 0" class="pr-20 border-gray-300 border-t py-4">
        <div v-for="(input, index) in props.fields" class="grid grid-cols-2 space-x-8 text-[14px]">
            <div class="text-right text-slate-500 pb-2">
                <span>{{ $t(input.name) }}</span>
            </div>
            <div>
                <span v-if="metadata && metadata[input.name] != null">{{ metadata[input.name] }}</span>
                <span v-else class="p-1 bg-slate-100 text-xs rounded-md text-gray-600">{{ $t('not set') }}</span>
            </div>
        </div>
    </div>
</div>
</template>