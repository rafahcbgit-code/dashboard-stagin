<template>
    <SettingLayout :modules="props.modules">
        <div class="md:h-[90vh]">
            <div class="flex justify-center items-center mb-8">
                <div class="md:w-[60em]">
                    <div v-if="!settings?.whatsapp" class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-4">
                        <div class="flex items-center px-4 pt-2 pb-4">
                            <div class="min-w-0 flex-1">
                                <h2 class="text-[17px]">{{ $t('Setup Whatsapp Account') }}</h2>
                                <span class="flex items-center mt-1">
                                    {{ $t('Setup your integration to be able to receive and send messages via Whatsapp.') }}
                                </span>
                            </div>
                            <div class="ms-auto shrink-0">
                                <EmbeddedSignupBtn v-if="showEmbeddedSignupBtn" :appId="props.appId" :configId="props.configId" :graphAPIVersion="props.graphAPIVersion"/>
                                <CoExistacneSignupBtn v-if="showCoexistenceSignupBtn" :appId="props.appId" :configId="props.configId" :graphAPIVersion="props.graphAPIVersion"/>
                                <button v-if="showManualSetupBtn"  @click="openModal()" class="bg-primary text-white p-2 rounded-lg text-sm mt-5 flex px-3 w-fit">
                                    {{ $t('Setup whatsapp') }}
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20"><g fill="currentColor" fill-rule="evenodd" clip-rule="evenodd"><g opacity=".2"><path d="M12.206 5.848a1.5 1.5 0 0 1 2.113.192l3.333 4a1.5 1.5 0 1 1-2.304 1.92l-3.334-4a1.5 1.5 0 0 1 .192-2.112Z"/><path d="M12.206 16.152a1.5 1.5 0 0 1-.192-2.112l3.334-4a1.5 1.5 0 0 1 2.304 1.92l-3.333 4a1.5 1.5 0 0 1-2.113.192Z"/><path d="M16 11a1.5 1.5 0 0 1-1.5 1.5h-8a1.5 1.5 0 0 1 0-3h8A1.5 1.5 0 0 1 16 11Z"/></g><path d="M11.347 5.616a.5.5 0 0 1 .704.064l3.333 4a.5.5 0 0 1-.768.64l-3.333-4a.5.5 0 0 1 .064-.704Z"/><path d="M11.347 14.384a.5.5 0 0 1-.064-.704l3.333-4a.5.5 0 0 1 .768.64l-3.333 4a.5.5 0 0 1-.704.064Z"/><path d="M15.5 10a.5.5 0 0 1-.5.5H5a.5.5 0 0 1 0-1h20a.5.5 0 0 1 .5.5Z"/></g></svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <section v-if="settings?.whatsapp" class="mb-4 overflow-hidden rounded-xl border border-slate-200 bg-white text-sm shadow-sm">
                        <div class="flex flex-col gap-4 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                            <div class="min-w-0 text-start">
                                <h2 class="font-semibold text-slate-900">{{ settings.whatsapp?.verified_name || $t('WhatsApp business account') }}</h2>
                                <p class="mt-1 truncate text-slate-500" dir="ltr">{{ settings.whatsapp?.display_phone_number || $t('Phone number unavailable') }}</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold" :class="statusBadgeClass('number', settings.whatsapp?.number_status)" role="status">
                                    <span class="h-2 w-2 rounded-full bg-current" aria-hidden="true"></span>
                                    {{ numberStatusLabel }}
                                </span>
                                <button type="button" @click="refreshData()" :disabled="refreshLoading" class="inline-flex min-w-[4.25rem] items-center justify-center rounded-lg border border-slate-300 px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary disabled:cursor-wait disabled:opacity-60" :aria-busy="refreshLoading" :aria-label="refreshLoading ? $t('Checking connection') : $t('Sync')">
                                    <svg v-if="refreshLoading" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z"/></svg>
                                    <span v-else>{{ $t('Sync') }}</span>
                                </button>
                            </div>
                        </div>

                        <div v-if="isNumberDisconnected" class="flex flex-col gap-3 border-b border-red-200 bg-red-50 p-4 text-red-800 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-start leading-6">{{ $t('WhatsApp disconnected notice') }}</p>
                            <button type="button" @click="refreshData()" :disabled="refreshLoading" class="inline-flex min-w-[8.5rem] shrink-0 items-center justify-center rounded-lg bg-red-700 px-4 py-2 text-xs font-semibold text-white hover:bg-red-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-600 disabled:cursor-wait disabled:opacity-60" :aria-busy="refreshLoading" :aria-label="refreshLoading ? $t('Checking connection') : $t('Check connection again')">
                                <svg v-if="refreshLoading" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z"/></svg>
                                <span v-else>{{ $t('Check connection again') }}</span>
                            </button>
                        </div>

                        <div v-if="templateSyncStatusMessage" class="flex items-center gap-2 border-b px-4 py-3 text-start text-xs" :class="templateSyncStatusClass" role="status" aria-live="polite">
                            <svg v-if="templateSyncStatus === 'queued' || templateSyncStatus === 'running' || templateSyncStatus === 'timeout'" class="h-4 w-4 shrink-0 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z"/></svg>
                            <span>{{ templateSyncStatusMessage }}</span>
                        </div>

                        <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4 sm:p-5">
                            <div class="rounded-lg bg-slate-50 p-3 text-start">
                                <p class="text-xs text-slate-500">{{ $t('Quality rating') }}</p>
                                <span class="mt-2 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusBadgeClass('quality', settings.whatsapp?.quality_rating)">{{ metaStatusLabel(settings.whatsapp?.quality_rating, 'quality') }}</span>
                            </div>
                            <div class="rounded-lg bg-slate-50 p-3 text-start">
                                <p class="text-xs text-slate-500">{{ $t('Account status') }}</p>
                                <span class="mt-2 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusBadgeClass('account', settings.whatsapp?.account_review_status)">{{ metaStatusLabel(settings.whatsapp?.account_review_status, 'account') }}</span>
                            </div>
                            <div class="rounded-lg bg-slate-50 p-3 text-start">
                                <p class="text-xs text-slate-500">{{ $t('Phone verification status') }}</p>
                                <span class="mt-2 inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusBadgeClass('verification', settings.whatsapp?.code_verification_status)">{{ metaStatusLabel(settings.whatsapp?.code_verification_status, 'verification') }}</span>
                            </div>
                            <div class="rounded-lg bg-slate-50 p-3 text-start">
                                <p class="text-xs text-slate-500">{{ $t('Daily messaging limit') }}</p>
                                <p class="mt-2 font-semibold text-blue-700">{{ formattedMessagingLimit }}</p>
                            </div>
                        </div>

                        <details class="border-t border-slate-100 px-4 py-3 sm:px-5">
                            <summary class="cursor-pointer text-start text-xs font-medium text-slate-600 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">{{ $t('Show WhatsApp account details') }}</summary>
                            <div class="mt-3 grid grid-cols-1 gap-3 text-start sm:grid-cols-2">
                                <div><p class="text-xs text-slate-500">{{ $t('Display name') }}</p><p class="mt-1 text-slate-800">{{ settings.whatsapp?.verified_name || $t('Not available') }}</p></div>
                                <div><p class="text-xs text-slate-500">{{ $t('Whatsapp business ac ID') }}</p><p class="mt-1 break-all text-slate-800" dir="ltr">{{ settings.whatsapp?.waba_id || $t('Not available') }}</p></div>
                            </div>
                        </details>
                    </section>
                    <form @submit.prevent="submitForm2()" v-if="settings?.whatsapp" class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-4 pb-4">
                        <div class="flex items-center justify-between px-4 pt-2 pb-4">
                            <div>
                                <h2 class="text-[17px]">{{ $t('Business profile settings') }}</h2>
                                <span class="flex items-center mt-1">
                                    <svg class="me-2 shrink-0" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                                    {{ $t('Setup the Whatsapp business profile for your number') }}
                                </span>
                            </div>
                            <div>
                                <button v-if="settings?.whatsapp && settings?.whatsapp?.is_embedded_signup === 0" type="button" @click="openModal2()" class="bg-primary text-white p-2 rounded-lg text-sm mt-5 flex px-3 w-fit">
                                    {{ $t('Update token') }}
                                </button>
                            </div>
                        </div>
                        <div class="flex w-full flex-col gap-4 border-b px-4 py-6 sm:flex-row sm:space-s-10">
                            <div class="w-full sm:w-2/5">
                                <span class="text-slate-600">{{ $t('Whatsapp profile picture') }}</span>
                                <div class="text-xs text-slate-700 flex items-center">
                                    <svg class="me-1 shrink-0" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                                    <span>{{ $t('Add/update your profile picture') }}</span>
                                </div>
                            </div>
                            <div class="w-full min-w-0 sm:w-3/5">
                                <FormImageLogo v-model="form2.profile_picture_url" :name="''" :error="form2.errors.profile_picture_url" :label="$t('Upload logo')" :imageUrl="form2.profile_picture_url" :class="'col-span-4 ms-6'"/>
                                <div class="ms-6">{{ $t('Accepted formats: JPG/PNG') }}</div>
                                <div class="ms-6">{{ $t('Minimum dimensions: 192x192 pixels.') }}</div>
                            </div>
                        </div>
                        <div class="flex w-full flex-col gap-4 border-b px-4 py-6 sm:flex-row sm:space-s-10">
                            <div class="w-full sm:w-2/5">
                                <span class="text-slate-600">{{ $t('Business address') }}</span>
                                <div class="text-xs text-slate-700 flex items-center">
                                    <svg class="me-1 shrink-0" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                                    <span>{{ $t('Specify your physical business address') }}</span>
                                </div>
                            </div>
                            <div class="w-full min-w-0 sm:w-3/5">
                                <FormInput v-model="form2.address" :error="form2.errors.address" :name="''" :type="'text'" :class="'col-span-4'"/>
                            </div>
                        </div>
                        <div class="flex w-full flex-col gap-4 border-b px-4 py-6 sm:flex-row sm:space-s-10">
                            <div class="w-full sm:w-2/5">
                                <span class="text-slate-600">{{ $t('Business email') }}</span>
                                <div class="text-xs text-slate-700 flex items-center">
                                    <svg class="me-1 shrink-0" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                                    <span>{{ $t('Add your business email address') }}</span>
                                </div>
                            </div>
                            <div class="w-full min-w-0 sm:w-3/5">
                                <FormInput v-model="form2.email" :error="form2.errors.email" :name="''" :type="'email'" :class="'col-span-4'"/>
                            </div>
                        </div>
                        <div class="flex w-full flex-col gap-4 border-b px-4 py-6 sm:flex-row sm:space-s-10">
                            <div class="w-full sm:w-2/5">
                                <span class="text-slate-600">{{ $t('Business description') }}</span>
                                <div class="text-xs text-slate-700 flex items-center">
                                    <svg class="me-1 shrink-0" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                                    <span>{{ $t('Edit your whatsapp business account description') }}</span>
                                </div>
                            </div>
                            <div class="w-full min-w-0 sm:w-3/5">
                                <FormTextArea v-model="form2.description" :error="form2.errors.description" :name="''" :type="'text'" :class="'col-span-4'"/>
                            </div>
                        </div>
                        <div class="flex w-full flex-col gap-4 px-4 py-6 sm:flex-row sm:space-s-10">
                            <div class="w-full sm:w-2/5">
                                <span class="text-slate-600">{{ $t('Business industry') }}</span>
                                <div class="text-xs text-slate-700 flex items-center">
                                    <svg class="me-1 shrink-0" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                                    <span>{{ $t('Specify your business vertical') }}</span>
                                </div>
                            </div>
                            <div class="w-full min-w-0 sm:w-3/5">
                                <FormSelect v-model="form2.industry" :name="''" :type="'text'" :placeholder="$t('Select option')" :options="options" :error="form2.errors.industry" :class="'col-span-4'"/>
                            </div>
                        </div>
                        <div class="flex justify-end px-4 pb-2 pt-1">
                            <div>
                                <button type="submit" class="rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm hover:shadow-md hover:bg-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600" :disabled="form2.processing">
                                    <svg v-if="form2.processing" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                                    <span v-else>{{ $t('Save') }}</span>
                                </button>
                            </div>
                        </div>
                    </form>

                    <div v-if="settings?.whatsapp && settings?.whatsapp?.is_embedded_signup === 0" class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-4">
                        <div class="px-4 pt-2 pb-4">
                            <h2 class="text-[17px]">{{ $t('Meta webhook settings') }}</h2>
                            <span class="flex items-center mt-1">
                                <svg class="me-2 shrink-0" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                                {{ $t('Add these webhook settings to your facebook developer account') }}
                            </span>
                        </div>
                        <div class="flex w-full flex-col gap-2 border-b px-4 py-4 sm:flex-row sm:space-s-10">
                            <div class="w-full sm:w-2/5">
                                <span class="text-slate-600">{{ $t('Webhook url') }}</span>
                            </div>
                            <div class="w-full min-w-0 text-start sm:w-3/5" dir="ltr">
                                <span class="break-all" dir="ltr">{{ currentURL + '/webhook/whatsapp/' +props.settings.identifier }}</span>
                            </div>
                        </div>
                        <div class="flex w-full flex-col gap-2 px-4 py-4 sm:flex-row sm:space-s-10">
                            <div class="w-full sm:w-2/5">
                                <span class="text-slate-600">{{ $t('Verify token') }}</span>
                            </div>
                            <div class="w-full min-w-0 text-start sm:w-3/5" dir="ltr">
                                <div dir="ltr">{{ props.settings.identifier }}</div>
                            </div>
                        </div>
                    </div>

                    <div v-if="settings?.whatsapp && settings?.whatsapp?.is_coexistence === 1" 
                        class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-4"
                    >
                    <div class="flex flex-col gap-4 px-4 pb-4 pt-2 sm:flex-row sm:items-center sm:justify-end">
                        <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-2">
                            <!-- Sync Contacts -->
                            <span class="text-sm">
                            {{ $t('Sync Contacts') }}
                            </span>
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="sync_contacts === 1"
                                :aria-label="$t('Sync Contacts')"
                                @click="updateCoExistenceSync({ 'sync_contacts': sync_contacts === 1 ? 0 : 1 })"
                                class="flex h-6 w-12 items-center rounded-full p-1 transition-colors duration-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                :class="sync_contacts === 1 ? 'bg-green-500' : 'bg-gray-300'"
                            >
                                <div
                                    class="bg-white w-4 h-4 rounded-full shadow-md transform duration-300 ease-in-out"
                                    :class="sync_contacts === 1 ? 'ltr:translate-x-6 rtl:translate-x-[-24px]' : 'translate-x-0'"
                                ></div>
                            </button>

                            <!-- Sync History -->
                            <span class="text-sm">
                            {{ $t('Sync History') }}
                            </span>
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="sync_history === 1"
                                :aria-label="$t('Sync History')"
                                @click="updateCoExistenceSync({ 'sync_history': sync_history === 1 ? 0 : 1 })"
                                class="flex h-6 w-12 items-center rounded-full p-1 transition-colors duration-300 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"
                                :class="sync_history === 1 ? 'bg-green-500' : 'bg-gray-300'"
                            >
                            <div
                                class="bg-white w-4 h-4 rounded-full shadow-md transform duration-300 ease-in-out"
                                :class="sync_history === 1 ? 'ltr:translate-x-6 rtl:translate-x-[-24px]' : 'translate-x-0'"
                            ></div>
                            </button>
                        </div>
                    </div>
                    </div>

                    <div v-if="settings?.whatsapp" class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-20">
                        <div class="flex flex-col gap-4 px-4 pt-2 pb-4 sm:flex-row sm:items-center">
                            <div class="min-w-0 flex-1">
                                <h2 class="text-[17px]">{{ $t('Remove Whatsapp account') }}</h2>
                                <span class="flex items-center mt-1">
                                    {{ $t('This will completely delete your whatsapp integration. Your contacts & messages will be unaffected.') }}
                                </span>
                            </div>
                            <div class="ms-auto">
                                <button @click="deleteIntegration()" class="rounded-md bg-red-700 px-3 py-2 text-sm text-white shadow-sm hover:bg-red-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Delete integration')}}</button>
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </div>

        <Modal :label="$t('Whatsapp API config')" :isOpen=isOpenFormModal>
            <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4">
                <form @submit.prevent="submitForm()" class="grid gap-x-6 gap-y-4 sm:grid-cols-6">

                    <FormInput v-model="form.app_id" :error="form.errors.app_id" :name="$t('App ID')" :type="'text'" :class="'sm:col-span-6'"/>
                    <FormInput v-model="form.access_token" :error="form.errors.access_token" :name="$t('Access token')" :type="'text'" :class="'sm:col-span-6'"/>
                    <FormInput v-model="form.phone_number_id" :error="form.errors.phone_number_id" :name="$t('Phone number ID')" :type="'text'" :class="'sm:col-span-6'"/>
                    <FormInput v-model="form.waba_id" :error="form.errors.waba_id" :name="$t('Whatsapp business account ID')" :type="'text'" :class="'sm:col-span-6'"/>

                    <div class="mt-4 flex">
                        <button type="button" @click.self="isOpenFormModal = false" class="me-4 inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">{{ $t('Cancel') }}</button>
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

        <Modal v-if="settings?.whatsapp && settings?.whatsapp?.is_embedded_signup === 0" :label="$t('Whatsapp API config')" :isOpen=isOpenForm2Modal>
            <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4">
                <form @submit.prevent="submitForm3()" class="grid gap-x-6 gap-y-4 sm:grid-cols-6">
                    <FormInput v-model="form3.access_token" :error="form3.errors.access_token" :name="$t('Access token')" :type="'text'" :class="'sm:col-span-6'"/>

                    <div class="mt-4 flex">
                        <button type="button" @click.self="isOpenForm2Modal = false" class="me-4 inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">{{ $t('Cancel') }}</button>
                        <button 
                            :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': form.processing }]"
                            :disabled="form3.processing"
                        >
                            <svg v-if="form3.processing" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                            <span v-else>{{ $t('Save') }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </SettingLayout>
</template>
<script setup>
    import SettingLayout from "./Layout.vue";
    import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
    import EmbeddedSignupBtn from '@/Components/EmbeddedSignupBtn.vue';
    import CoExistacneSignupBtn from "../../../Components/CoExistacneSignupBtn.vue";
    import FormImageLogo from '@/Components/FormImageLogo.vue';
    import FormInput from '@/Components/FormInput.vue';
    import FormSelect from '@/Components/FormSelect.vue';
    import FormTextArea from '@/Components/FormTextArea.vue';
    import Modal from '@/Components/Modal.vue';
    import { trans } from 'laravel-vue-i18n';
    import { router, useForm, usePage } from "@inertiajs/vue3";
    import { useI18n } from 'vue-i18n';
    import axios from 'axios';

    const props = defineProps(['settings', 'embeddedSignupActive', 'appId', 'configId', 'graphAPIVersion', 'modules', 'templateSyncStatus', 'templateSyncEnabled']);
    const { t, locale } = useI18n();
    const isTrialAccount = Number(usePage().props.is_trial) === 1;
    const showEmbeddedSignupBtn = isTrialAccount || props.embeddedSignupActive == 1;
    const showCoexistenceSignupBtn = isTrialAccount || props.embeddedSignupActive == 1;
    const showManualSetupBtn = !isTrialAccount && props.embeddedSignupActive != 1;
    const currentURL = ref(window.location.origin);
    const isOpenFormModal = ref(false);
    const isOpenForm2Modal = ref(false);
    const settings = computed(() => {
        try {
            return props.settings?.metadata ? JSON.parse(props.settings.metadata) : null;
        } catch (error) {
            return null;
        }
    });
    const refreshLoading = ref(false);
    const templateSyncStatus = ref(props.templateSyncStatus?.status || 'idle');
    let templateSyncPollTimer = null;
    let templateSyncPollStartedAt = 0;
    const form = useForm({
        app_id: settings.value && settings.value.whatsapp ? settings.value.whatsapp.app_id : null,
        access_token: settings.value && settings.value.whatsapp ? settings.value.whatsapp.access_token : null,
        phone_number_id: settings.value && settings.value.whatsapp ? settings.value.whatsapp.phone_number_id : null,
        waba_id: settings.value && settings.value.whatsapp ? settings.value.whatsapp.waba_id : null,
    });

    const form2 = useForm({
        profile_picture_url: settings.value && settings.value.whatsapp ? settings.value?.whatsapp?.business_profile?.profile_picture_url : null,
        description: settings.value && settings.value.whatsapp ? settings.value?.whatsapp?.business_profile?.description : null,
        address: settings.value && settings.value.whatsapp ? settings.value?.whatsapp?.business_profile?.address : null,
        email: settings.value && settings.value.whatsapp ? settings.value?.whatsapp?.business_profile?.email : null,
        industry: settings.value && settings.value.whatsapp ? settings.value?.whatsapp?.business_profile?.industry : null,
        websites: []
    });

    watch(() => settings.value?.whatsapp?.business_profile, (profile) => {
        if (!profile || form2.isDirty) return;
        form2.profile_picture_url = profile.profile_picture_url ?? null;
        form2.description = profile.description ?? null;
        form2.address = profile.address ?? null;
        form2.email = profile.email ?? null;
        form2.industry = profile.industry ?? null;
    });

    const form3 = useForm({
        access_token: settings.value && settings.value.whatsapp ? settings.value.whatsapp.access_token : null,
    });

    const options = [
        { label: trans('Automotive'), value: 'AUTO' },
        { label: trans('Beauty, spa and salon'), value: 'BEAUTY' },
        { label: trans('Clothing'), value: 'APPAREL' },
        { label: trans('Education'), value: 'EDU' },
        { label: trans('Entertainment'), value: 'ENTERTAIN' },
        { label: trans('Event planning and service'), value: 'EVENT_PLAN' },
        { label: trans('Finance and banking'), value: 'FINANCE' },
        { label: trans('Food and groceries'), value: 'GROCERY' },
        { label: trans('Public service'), value: 'GOVT' },
        { label: trans('Hotel and lodging'), value: 'HOTEL' },
        { label: trans('Medical and health'), value: 'HEALTH' },
        { label: trans('Charity'), value: 'NONPROFIT' },
        { label: trans('Professional services'), value: 'PROF_SERVICES' },
        { label: trans('Shopping and retail'), value: 'RETAIL' },
        { label: trans('Travel and transportation'), value: 'TRAVEL' },
        { label: trans('Restaurant'), value: 'RESTAURANT' },
        { label: trans('Not a business'), value: 'NOT_A_BIZ' },
        { label: trans('Undefined'), value: 'UNDEFINED' },
        { label: trans('Other'), value: 'OTHER' },
    ]

    const metaStatusTranslations = {
        number: {
            CONNECTED: 'Connected', DISCONNECTED: 'Disconnected', PENDING: 'Pending',
            MIGRATED: 'Migrated', BANNED: 'Banned', RESTRICTED: 'Restricted', RATE_LIMITED: 'Rate limited',
        },
        verification: { VERIFIED: 'Verified', NOT_VERIFIED: 'Not verified', PENDING: 'Pending' },
        quality: { GREEN: 'Good', YELLOW: 'Average', RED: 'Low', UNKNOWN: 'Unknown' },
        account: { APPROVED: 'Approved', PENDING: 'Pending', REJECTED: 'Rejected', EXPIRED: 'Expired' },
    };

    const isNumberDisconnected = computed(() => {
        const status = String(settings.value?.whatsapp?.number_status || '').trim().toUpperCase();
        return status === 'DISCONNECTED' || status === 'BANNED';
    });

    const numberStatusLabel = computed(() => {
        const status = String(settings.value?.whatsapp?.number_status || '').trim().toUpperCase();
        if (status === 'CONNECTED') return t('WhatsApp number connected');
        if (status === 'DISCONNECTED') return t('WhatsApp number disconnected');
        return metaStatusLabel(settings.value?.whatsapp?.number_status, 'number');
    });

    const formattedMessagingLimit = computed(() => {
        const rawLimit = String(
            settings.value?.whatsapp?.whatsapp_business_manager_messaging_limit
            || settings.value?.whatsapp?.messaging_limit_tier
            || ''
        ).trim().toUpperCase();

        if (!rawLimit) return t('Not available');
        if (rawLimit.includes('UNLIMITED')) return t('Unlimited messaging');

        const match = rawLimit.match(/(?:TIER_)?(\d+(?:K|M)?)/);
        if (!match) return t('Not available');

        const amount = match[1];
        const multiplier = amount.endsWith('K') ? 1000 : amount.endsWith('M') ? 1000000 : 1;
        const count = Number.parseInt(amount, 10) * multiplier;
        if (!Number.isFinite(count)) return t('Not available');

        return `${new Intl.NumberFormat(locale.value || 'en').format(count)} ${t('messages per day')}`;
    });

    const statusBadgeClass = (category, value) => {
        const normalizedValue = String(value || '').trim().toUpperCase();
        const toneByCategory = {
            number: {
                CONNECTED: 'bg-emerald-100 text-emerald-800',
                MIGRATED: 'bg-emerald-100 text-emerald-800',
                DISCONNECTED: 'bg-red-100 text-red-800',
                BANNED: 'bg-red-100 text-red-800',
                PENDING: 'bg-amber-100 text-amber-800',
                RESTRICTED: 'bg-amber-100 text-amber-800',
                RATE_LIMITED: 'bg-amber-100 text-amber-800',
            },
            verification: {
                VERIFIED: 'bg-emerald-100 text-emerald-800',
                NOT_VERIFIED: 'bg-red-100 text-red-800',
                PENDING: 'bg-amber-100 text-amber-800',
            },
            quality: {
                GREEN: 'bg-emerald-100 text-emerald-800',
                YELLOW: 'bg-amber-100 text-amber-800',
                RED: 'bg-red-100 text-red-800',
            },
            account: {
                APPROVED: 'bg-emerald-100 text-emerald-800',
                PENDING: 'bg-amber-100 text-amber-800',
                REJECTED: 'bg-red-100 text-red-800',
                EXPIRED: 'bg-red-100 text-red-800',
            },
        };

        return toneByCategory[category]?.[normalizedValue] || 'bg-slate-100 text-slate-700';
    };

    const metaStatusLabel = (value, category) => {
        if (!value) return t('Unknown');

        const normalizedValue = String(value).trim().toUpperCase();
        const translationKey = metaStatusTranslations[category]?.[normalizedValue];

        return translationKey ? t(translationKey) : t('Unknown');
    };

    const templateSyncStatusMessage = computed(() => ({
        queued: t('WhatsApp templates queued'),
        running: t('WhatsApp templates syncing'),
        complete: t('WhatsApp templates synced'),
        failed: t('WhatsApp templates sync failed'),
        timeout: t('WhatsApp templates still syncing'),
    }[templateSyncStatus.value] || ''));

    const templateSyncStatusClass = computed(() => ({
        complete: 'border-emerald-100 bg-emerald-50 text-emerald-800',
        failed: 'border-red-100 bg-red-50 text-red-800',
    }[templateSyncStatus.value] || 'border-blue-100 bg-blue-50 text-blue-800'));

    const sync_contacts = ref(settings?.value?.whatsapp?.sync_contacts ? 1 : 0);
    const sync_history = ref(settings?.value?.whatsapp?.sync_history ? 1 : 0);

    function openModal() {
        isOpenFormModal.value = true;
    }

    function openModal2() {
        isOpenForm2Modal.value = true;
    }

    const submitForm = () => {
        form.post('/settings/whatsapp', {
            preserveScroll: true,
            preserveState: false,
            onSuccess: () => {
                isOpenFormModal.value = false
            }
        })
    }

    const submitForm2 = () => {
        form2.post('/settings/whatsapp/business-profile', {
            preserveScroll: true,
        })
    }

    const submitForm3 = () => {
        form3.post('/settings/whatsapp/token', {
            preserveScroll: true,
            onSuccess: () => {
                isOpenForm2Modal.value = false
            }
        })
    }

    const pollTemplateSyncStatus = async () => {
        try {
            const { data } = await axios.get('/settings/whatsapp/template-sync-status');
            templateSyncStatus.value = data.status || 'idle';

            if (['queued', 'running'].includes(templateSyncStatus.value)) {
                if (Date.now() - templateSyncPollStartedAt >= 180000) {
                    templateSyncStatus.value = 'timeout';
                    templateSyncPollTimer = setTimeout(pollTemplateSyncStatus, 10000);
                    return;
                }
                templateSyncPollTimer = setTimeout(pollTemplateSyncStatus, 1800);
                return;
            }

            if (templateSyncStatus.value === 'complete') {
                templateSyncPollTimer = setTimeout(() => { templateSyncStatus.value = 'idle'; }, 8000);
            }
        } catch (error) {
            templateSyncStatus.value = 'failed';
        }
    };

    onMounted(() => {
        if (['queued', 'running'].includes(templateSyncStatus.value)) {
            templateSyncPollStartedAt = Date.now();
            pollTemplateSyncStatus();
        }
    });

    const refreshData = () => {
        if (refreshLoading.value) return;
        refreshLoading.value = true;
        templateSyncStatus.value = props.templateSyncEnabled ? 'queued' : 'idle';
        templateSyncPollStartedAt = Date.now();
        clearTimeout(templateSyncPollTimer);

        router.post('/settings/whatsapp/refresh', {}, {
            preserveScroll: true,
            onSuccess: () => {
                if (props.templateSyncEnabled) {
                    pollTemplateSyncStatus();
                } else {
                    templateSyncStatus.value = 'idle';
                }
            },
            onError: () => {
                templateSyncStatus.value = 'idle';
            },
            onFinish: () => {
                refreshLoading.value = false;
            },
        });
    }

    onBeforeUnmount(() => clearTimeout(templateSyncPollTimer));

    const deleteIntegration = () => {
        router.delete(`/settings/whatsapp/business-profile`, {
            onBefore: () => confirm(trans('Are you sure you want to delete your integration?')),
            preserveState: true,
            onSuccess: () => {
                router.visit('/settings/whatsapp', {
                    preserveState: false,
                });
            },
        })
    }

    function updateCoExistenceSync(payload) {
        if(payload.hasOwnProperty('sync_contacts')) {
            console.log(payload, sync_contacts.value);
            sync_contacts.value = sync_contacts.value ? 0 : 1;
            console.log(payload, sync_contacts.value);
        }
        if(payload.hasOwnProperty('sync_history')) {
            sync_history.value = sync_history.value ? 0 : 1;
        }
        router.post(`/settings/whatsapp/update-coexistence`, payload, {
            preserveScroll: true,
            onSuccess: () => {
            console.log('Sync settings updated:', payload)
            },
            onError: (errors) => {
            console.error('Failed to update:', errors)
            }
        })

        
    }
</script>
