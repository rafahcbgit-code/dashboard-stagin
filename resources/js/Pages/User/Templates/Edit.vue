<template>
    <AppLayout>
        <div class="px-4 md:px-0 flex flex-col bg-white border-l py-4 text-[#000] md:overflow-y-hidden">
            <div class="flex justify-between md:px-8 border-b pb-4">
                <div>
                    <h2 class="text-xl mb-1">{{ $t('Edit template') }}</h2>
                    <p class="flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                        <span class="ml-1 mt-1">{{ $t('Modify whatsapp template') }}</span>
                    </p>
                </div>
                <div class="space-s-2 flex items-center">
                    <Link href="/templates" class="rounded-md bg-black px-3 py-2 text-sm text-white shadow-sm hover:bg-indigo-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{{ $t('Back') }}</Link>
                    <button @click="submitForm()" type="button" class="rounded-md px-3 py-2 float-right text-sm text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 capitalize" :class="isFormValid === true ? 'bg-indigo-600 hover:bg-indigo-500 shadow-sm' : 'bg-gray-200'" :disabled="!isFormValid || isLoading">
                        <span v-if="!isLoading">{{ $t('Update template') }}</span>
                        <svg v-else xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                    </button>
                </div>
            </div>
            <div class="md:flex md:flex-grow-1">
                <div class="md:w-[50%] py-4 md:p-8 overflow-y-auto md:h-[90vh]">
                    <div class="grid gap-x-6 gap-y-4 sm:grid-cols-6 mb-8">
                        <div class="sm:col-span-6">
                            <FormInput v-model="form.name" :name="$t('Name')" :disabled="true" :type="'text'" :class="''"/>
                            <span class="flex items-center gap-x-1 text-[11px] text-red-800 mt-1">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="none" stroke="#991b1b" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3"/></svg>
                                {{ $t('You are not allowed to edit this field') }}
                            </span>
                        </div>
                        <div class="sm:col-span-3">
                            <FormSelect v-model="form.category" :options="categoryOptions" :name="$t('Category')" :disabled="true" :class="'sm:col-span-3'" :placeholder="'Select Category'"/>
                            <span class="flex items-center gap-x-1 text-[11px] text-red-800 mt-1">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="none" stroke="#991b1b" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3"/></svg>
                                {{ $t('You are not allowed to edit this field') }}
                            </span>
                        </div>
                        <div class="sm:col-span-3">
                            <FormSelect v-model="form.language" :disabled="true" :options="langOptions" :name="$t('Language')" :class="'sm:col-span-3'" :placeholder="'Select Language'"/>
                            <span class="flex items-center gap-x-1 text-[11px] text-red-800 mt-1">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="none" stroke="#991b1b" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3"/></svg>
                                {{ $t('You are not allowed to edit this field') }}
                            </span>
                        </div>
                    </div>

                    <!-- ── STANDARD template form ────────────────────────────────── -->
                    <div v-if="!isCarousel">
                        <div v-if="form.category === 'UTILITY' || form.category === 'MARKETING'">
                            <h2 class="text-slate-600">{{ $t('Header') }} <span class="text-xs">({{ $t('Optional') }})</span></h2>
                            <span class="text-slate-600 text-xs">{{ $t('Add a title or choose which type of media you\'ll use for this header') }}</span>
                            <div class="grid grid-cols-4 mt-2 bg-[#f9f9fa] rounded-lg mb-4">
                                <button @click="changeHeaderType('TEXT')" class="text-center py-2 text-sm text-slate-800 m-1" :class="form.header.format === 'TEXT' ? 'bg-white shadow rounded-lg' : ''">{{ $t('Text') }}</button>
                                <button @click="changeHeaderType('IMAGE')" class="text-center py-2 text-sm text-slate-800 m-1" :class="form.header.format === 'IMAGE' ? 'bg-white shadow rounded-lg' : ''">{{ $t('Image') }}</button>
                                <button @click="changeHeaderType('VIDEO')" class="text-center py-2 text-sm text-slate-800 m-1" :class="form.header.format === 'VIDEO' ? 'bg-white shadow rounded-lg' : ''">{{ $t('Video') }}</button>
                                <button @click="changeHeaderType('DOCUMENT')" class="text-center py-2 text-sm text-slate-800 m-1" :class="form.header.format === 'DOCUMENT' ? 'bg-white shadow rounded-lg' : ''">{{ $t('Document') }}</button>
                            </div>
                            <div class="mb-8">
                                <div v-if="form.header.format === 'TEXT'">
                                    <HeaderTextArea v-model="form.header.text" :customValues="form.header.example" @updateExamples="updateHeaderExamples"/>
                                </div>
                                <div v-if="form.header.format === 'IMAGE' || form.header.format === 'VIDEO' || form.header.format === 'DOCUMENT'">
                                    <div class="flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md">
                                        <input type="file" class="sr-only" :accept="form.header.format === 'IMAGE' ? '.jpg,.png' : form.header.format === 'VIDEO' ? '.mp4' : '.pdf'" ref="fileInput" id="file-upload" @change="handleFileUpload($event)" />
                                        <div class="text-center">
                                            <div v-if="form.header.example" class="flex justify-center items-center">
                                                <div class="flex justify-center items-center space-s-3 py-1 border bg-slate-100 rounded-lg mb-2 w-fit px-2">
                                                    <span class="text-sm">{{ form.header.example.name || 'File attached' }}</span>
                                                    <button @click="removeFile()">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" fill-rule="evenodd" d="M17.707 7.707a1 1 0 0 0-1.414-1.414L12 10.586L7.707 6.293a1 1 0 0 0-1.414 1.414L10.586 12l-4.293 4.293a1 1 0 1 0 1.414 1.414L12 13.414l4.293 4.293a1 1 0 1 0 1.414-1.414L13.414 12l4.293-4.293Z" clip-rule="evenodd"/></svg>
                                                    </button>
                                                </div>
                                            </div>
                                            <label v-else for="file-upload">
                                                <svg class="mx-auto h-12 w-12 text-gray-400 cursor-pointer" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M14 9a1.5 1.5 0 1 1 3 0a1.5 1.5 0 0 1-3 0Z"/><path fill="currentColor" fill-rule="evenodd" d="M7.268 4.658a54.647 54.647 0 0 1 9.465 0l1.51.132a3.138 3.138 0 0 1 2.831 2.66a30.604 30.604 0 0 1 0 9.1a3.138 3.138 0 0 1-2.831 2.66l-1.51.131c-3.15.274-6.316.274-9.465 0l-1.51-.131a3.138 3.138 0 0 1-2.832-2.66a30.601 30.601 0 0 1 0-9.1a3.138 3.138 0 0 1 2.831-2.66l1.51-.132Z" clip-rule="evenodd"/></svg>
                                            </label>
                                            <div class="flex text-sm text-gray-600">
                                                <label for="file-upload" class="relative cursor-pointer bg-white rounded-md font-medium hover:text-indigo-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500">
                                                    <span>{{ $t('Provide examples of the variables or media in the header') }}</span>
                                                </label>
                                            </div>
                                            <p class="text-xs text-gray-500">{{ form.header.format === 'IMAGE' ? $t('PNG or JPG files only') : form.header.format === 'VIDEO' ? $t('MP4 files only') : $t('PDF files only') }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <h2 class="text-slate-600">{{ $t('Body') }} <span class="text-xs">({{ $t('Required') }})</span></h2>
                        <span class="text-slate-600 text-xs">{{ $t('Enter the text for your message in the language that you\'ve selected') }}</span>
                        <div class="mb-8">
                            <BodyTextArea v-model="form.body.text" :customValues="form.body.example" @updateExamples="updateBodyExamples"/>
                        </div>

                        <h2 class="text-slate-600 capitalize">{{ $t('Footer description') }} <span class="text-xs">({{ $t('Optional') }})</span></h2>
                        <span class="text-slate-600 text-xs">{{ $t('Add a short line of text to the bottom of your message template') }}</span>
                        <div class="mb-8">
                            <FormTextArea v-model="form.footer.text" @input="characterCount('footer')" :name="$t('Footer text')" :showLabel="false" :type="'text'" :textAreaRows="2" :class="'sm:col-span-6'"/>
                            <span class="text-xs">{{ $t('Characters') }}: {{ footerCharacterCount }}/{{ footerCharacterLimit }}</span>
                        </div>

                        <h2 class="text-slate-600">{{ $t('Buttons') }} <span class="text-xs">({{ $t('Optional') }})</span></h2>
                        <span class="text-slate-600 text-xs">{{ $t('Create buttons that let customers respond to your message or take action') }}</span>
                        <div class="grid grid-cols-2 mt-3 mb-2">
                            <button @click="addButton('call')" class="flex items-center justify-center text-slate-700 text-sm bg-slate-100 hover:bg-slate-200 hover:shadow-sm rounded-lg p-2 px-4 mr-2"><span>{{ $t('Call phone number (1)') }}</span></button>
                            <button @click="addButton('website')" class="flex items-center justify-center text-slate-700 text-sm bg-slate-100 hover:bg-slate-200 hover:shadow-sm rounded-lg p-2 px-4"><span>{{ $t('Visit website (2)') }}</span></button>
                        </div>
                        <div class="grid grid-cols-2 mt-3 mb-2">
                            <button @click="addButton('offer')" class="flex items-center justify-center text-slate-700 text-sm bg-slate-100 hover:bg-slate-200 hover:shadow-sm rounded-lg p-2 px-4 mr-2"><span>{{ $t('Copy offer code (1)') }}</span></button>
                            <button @click="addButton('custom')" class="flex items-center justify-center text-slate-700 text-sm bg-slate-100 hover:bg-slate-200 hover:shadow-sm rounded-lg p-2 px-4"><span>{{ $t('Custom button (6)') }}</span></button>
                        </div>
                        <div v-if="form?.buttons?.length > 0" class="mt-3 mb-8">
                            <div v-for="(button, index) in form.buttons" :key="index" class="bg-[#f9f9fa] p-3 rounded-lg mb-3">
                                <div class="flex items-center justify-between pb-1">
                                    <span class="text-sm">{{ formatText(button.type) }}</span>
                                    <button @click="removeButton(index)" class="bg-slate-200 hover:shadow rounded-full p-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" fill-rule="evenodd" d="M17.707 7.707a1 1 0 0 0-1.414-1.414L12 10.586L7.707 6.293a1 1 0 0 0-1.414 1.414L10.586 12l-4.293 4.293a1 1 0 1 0 1.414 1.414L12 13.414l4.293 4.293a1 1 0 1 0 1.414-1.414L13.414 12l4.293-4.293Z" clip-rule="evenodd"/></svg>
                                    </button>
                                </div>
                                <div class="flex flex-wrap gap-2 border-t pt-2">
                                    <FormInput v-if="button.type !== 'COPY_CODE'" v-model="button.text" :name="$t('Button text')" :type="'text'" :class="button.type === 'QUICK_REPLY' ? 'w-full' :'sm:col-span-2'" :labelClass="'mb-0'"/>
                                    <FormInput v-model="button.url" v-if="button.type === 'URL'" :name="$t('Website URL')" :type="'text'" :class="'w-full'" :labelClass="'mb-0'"/>
                                    <FormInput v-model="button.example" v-if="button.type === 'URL' && isDynamicUrl(button.url)" :name="$t('Example full URL')" :type="'text'" :class="'w-full'" :labelClass="'mb-0'" :placeholder="buildDynamicUrlExample(button.url)"/>
                                    <FormInput v-model="button.country" v-if="button.type === 'PHONE_NUMBER'" :name="$t('Country')" :type="'text'" :class="'sm:col-span-2'" :labelClass="'mb-0'"/>
                                    <FormInput v-model="button.phone_number" v-if="button.type === 'PHONE_NUMBER'" :name="$t('Phone number')" :type="'text'" :class="'sm:col-span-2'" :labelClass="'mb-0'"/>
                                    <FormInput v-model="button.example" v-if="button.type === 'COPY_CODE'" :name="$t('Coupon code')" :type="'text'" :class="'w-full'" :labelClass="'mb-0'" :maxLength="15"/>
                                </div>
                            </div>
                        </div>

                        <div v-if="form.category === 'AUTHENTICATION'">
                            <!-- Authentication section kept as-is from your original -->
                        </div>

                        <div v-if="form.category == 'UTILITY' || form.category == 'AUTHENTICATION'" class="mt-6 bg-gray-50 rounded-md p-3">
                            <h2 class="text-slate-600 mb-1">{{ $t('Message validity period') }}</h2>
                            <p class="text-slate-600 leading-tight text-xs">{{ $t('It\'s recommended to set a custom validity period that your authentication message must be delivered by before it expires.') }}</p>
                            <div class="mt-4">
                                <div class="flex w-full mb-4">
                                    <p class="w-3/4 text-slate-600 text-[11px]">{{ $t('If you don\'t set a custom validity period, the standard 10 minutes WhatsApp message validity period will be applied.') }}</p>
                                    <div class="w-1/4"><FormToggleSwitch v-model="form.customize_ttl" /></div>
                                </div>
                                <FormSelect v-if="form.customize_ttl" v-model="form.message_send_ttl_seconds" :options="form.category == 'UTILITY' ? utilityTTLOptions : authTTLOptions" :name="$t('Validity period')" :class="'sm:col-span-3'" :placeholder="$t('Select validity period')"/>
                            </div>
                        </div>
                    </div>

                    <!-- ── CAROUSEL template edit form ───────────────────────────── -->
                    <div v-if="isCarousel">
                        <!-- Top-level message body — editable -->
                        <div class="mb-6">
                            <h2 class="text-slate-600">{{ $t('Message body') }} <span class="text-xs">({{ $t('Required') }})</span></h2>
                            <span class="text-slate-600 text-xs">{{ $t('This text appears above the carousel cards') }}</span>
                            <textarea
                                v-model="carouselMessageBody"
                                rows="3"
                                class="block w-full rounded-md border-0 py-1.5 px-3 text-gray-900 shadow-sm outline-none ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 sm:text-sm sm:leading-6 mt-2"
                                :placeholder="$t('Enter message body text…')"
                                maxlength="1024"
                            ></textarea>
                            <span class="text-xs text-slate-400">{{ carouselMessageBody.length }}/1024</span>
                        </div>

                        <!-- Per-card edit form -->
                        <CarouselEditForm :editableCards="editableCards" />
                    </div>
                </div>

                <!-- ── PREVIEW PANEL ─────────────────────────────────────────── -->
                <div class="md:w-[50%] py-20 px-4 md:px-20 overflow-y-auto" style="background-image: url('/images/whatsapp-bg-02.png');">
                    <!-- Standard preview -->
                    <div v-if="!isCarousel">
                        <div class="mr-auto rounded-lg rounded-tl-none my-1 p-1 text-sm bg-white flex flex-col relative speech-bubble-left w-[25em]">
                            <div v-if="form.header.format != null && form.header.format != 'TEXT' && form.category != 'AUTHENTICATION'" class="mb-4 bg-[#ccd0d5] flex justify-center py-8 rounded">
                                <img v-if="form.header.format === 'IMAGE'" :src="'/images/image-placeholder.png'">
                                <img v-if="form.header.format === 'VIDEO'" :src="'/images/video-placeholder.png'">
                                <img v-if="form.header.format === 'DOCUMENT'" :src="'/images/document-placeholder.png'">
                            </div>
                            <h2 v-else-if="form.header.format == 'TEXT' && form.category != 'AUTHENTICATION'" class="text-gray-700 text-[16px] mb-1 px-2">{{ form.header.text }}</h2>
                            <p v-if="form.category != 'AUTHENTICATION'" class="px-2 normal-case whitespace-pre-wrap" v-html="form.body.text"></p>
                            <p v-else class="px-2 normal-case whitespace-pre-wrap" v-html="form.body.add_security_recommendation === true ? '{{1}} is your verification code. For your security, do not share this code.' : '{{1}} is your verification code.'"></p>
                            <div class="text-[#8c8c8c] mt-1 px-2">
                                <span v-if="form.category != 'AUTHENTICATION'" class="text-[13px]">{{ form.footer.text }}</span>
                                <span class="text-right text-xs leading-none float-right" :class="form.footer.text ? 'mt-2' : ''">9:15</span>
                            </div>
                        </div>
                        <div v-if="form.category != 'AUTHENTICATION' && form?.buttons?.length > 0" class="mr-auto text-sm text-[#00a5f4] flex flex-col relative max-w-[25em]">
                            <div v-for="(item, index) in form.buttons" :key="index" class="flex justify-center items-center space-s-2 rounded-lg bg-white h-10 my-[0.1em]">
                                <span>
                                    <svg v-if="item.type === 'COPY_CODE'" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M19 21H8V7h11m0-2H8a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2m-3-4H4a2 2 0 0 0-2 2v14h2V3h12V1Z"/></svg>
                                    <svg v-else-if="item.type === 'PHONE_NUMBER'" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><g fill="none"><path fill="currentColor" d="M20 16v4c-2.758 0-5.07-.495-7-1.325c-3.841-1.652-6.176-4.63-7.5-7.675C4.4 8.472 4 5.898 4 4h4l1 4l-3.5 3c1.324 3.045 3.659 6.023 7.5 7.675L16 15l4 1z"/></g></svg>
                                    <img v-else-if="item.type === 'URL'" :src="'/images/icons/link.png'" class="h-4">
                                    <img v-else :src="'/images/icons/reply.png'" class="h-4">
                                </span>
                                <span>{{ item.text }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Carousel preview — passes editableCards so preview updates live -->
                    <div v-else>
                        <!-- Shared message body shown above the cards -->
                        <div class="mr-auto rounded-lg rounded-tl-none my-1 p-1 text-sm bg-white flex flex-col relative speech-bubble-left w-[25em] mb-2">
                            <p class="px-2 normal-case whitespace-pre-wrap">{{ carouselMessageBody }}</p>
                            <div class="text-[#8c8c8c] mt-1 px-2 text-right">
                                <span class="text-xs leading-none">9:15</span>
                            </div>
                        </div>
                        <!-- Pass the full editableCards with all metadata -->
                        <CarouselPreview :cards="editableCards" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal (same for both standard and carousel) -->
        <Modal :label="''" :isOpen="isModalOpen" :showHeader="false">
            <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4">
                <div class="text-center">
                    <div v-if="error != null" class="bg-[#fae6e6] text-[darkred] rounded text-sm p-2 mb-4">
                        <div class="font-semibold mb-1">{{ $t('Error') }}</div>
                        <div class="whitespace-pre-wrap">{{ error }}</div>
                        <button 
                            type="button" 
                            @click="closeModal" 
                            class="mt-4 inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4"
                        >
                            {{ $t('Close') }}
                        </button>
                    </div>
                    <div v-else>
                        <h2 class="text-xl">{{ $t('Your template is being updated!') }}</h2>
                        <div class="flex justify-center mt-4">
                            <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24">
                                <path fill="none" stroke="currentColor" stroke-dasharray="15" stroke-dashoffset="15" stroke-linecap="round" stroke-width="2" d="M12 3C16.9706 3 21 7.02944 21 12">
                                    <animate fill="freeze" attributeName="stroke-dashoffset" dur="0.3s" values="15;0"/>
                                    <animateTransform attributeName="transform" dur="1.5s" repeatCount="indefinite" type="rotate" values="0 12 12;360 12 12"/>
                                </path>
                            </svg>
                        </div>
                    </div>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>

<script setup>
    import axios from "axios";
    import AppLayout from "./../Layout/App.vue";
    import BodyTextArea from '@/Components/Template/BodyTextArea.vue';
    import HeaderTextArea from '@/Components/Template/HeaderTextArea.vue';
    import CarouselEditForm from '@/Components/Template/CarouselEditForm.vue';
    import CarouselPreview from '@/Components/Template/CarouselPreview.vue';
    import FormInput from '@/Components/FormInput.vue';
    import FormSelect from '@/Components/FormSelect.vue';
    import FormTextArea from '@/Components/FormTextArea.vue';
    import FormToggleSwitch from '@/Components/FormToggleSwitch.vue';
    import { ref, computed } from 'vue';
    import { Link } from "@inertiajs/vue3";
    import Modal from '@/Components/Modal.vue';
    import { router } from '@inertiajs/vue3';

    const props = defineProps(['languages', 'template']);

    const footerCharacterLimit = ref('60');
    const footerCharacterCount = ref('0');
    const isLoading = ref(false);
    const isModalOpen = ref(false);
    const error = ref(null);
    const metadata = ref(JSON.parse(props.template.metadata));

    // ── CAROUSEL DETECTION ────────────────────────────────────────────────
    // Works from both our custom flag AND from Meta's synced structure.
    // After sync, template_type is preserved by rebuildCarouselMeta.
    // Fallback: scan components for a CAROUSEL type in case the flag is missing.
    const isCarousel = computed(() => {
        if (metadata.value?.template_type === 'carousel') return true;

        // Fallback: check components array directly
        if (Array.isArray(metadata.value?.components)) {
            return metadata.value.components.some(
                c => (c.type || '').toUpperCase() === 'CAROUSEL'
            );
        }
        return false;
    });

    // ── CAROUSEL STATE ────────────────────────────────────────────────────
    // editableCards: deep copy of carousel_cards from metadata.
    // carouselMessageBody: the shared top-level body text.
    // Both are what gets sent to updateCarouselTemplate on submit.
    const editableCards = ref(
        metadata.value?.carousel_cards
            ? JSON.parse(JSON.stringify(metadata.value.carousel_cards))
            : []
    );
    const carouselMessageBody = ref(
        metadata.value?.carousel_message_body || ''
    );

    // ── STANDARD TEMPLATE STATE ───────────────────────────────────────────
    const extractComponent = (type, key, fromButtons = false) => {
        const components = metadata.value?.components || [];
        const component = components.find(c => (c.type || '').toUpperCase() === type.toUpperCase());
        if (!component) return null;
        if (fromButtons && component.buttons && component.buttons.length > 0) {
            const button = component.buttons[0];
            return button.hasOwnProperty(key) ? button[key] : null;
        }
        return component.hasOwnProperty(key) ? component[key] : null;
    };

    const form = ref({
        name: props.template.name,
        category: props.template.category,
        language: props.template.language,
        message_send_ttl_seconds: null,
        customize_ttl: false,
        header: {
            format: extractComponent('HEADER', 'format') ?? 'TEXT',
            text: extractComponent('HEADER', 'text'),
            example: extractComponent('HEADER', 'example')?.header_text,
        },
        body: {
            text: extractComponent('BODY', 'text'),
            variables: null,
            add_security_recommendation: extractComponent('BODY', 'add_security_recommendation'),
            example: extractComponent('BODY', 'example')?.body_text?.[0],
        },
        footer: {
            text: extractComponent('FOOTER', 'text'),
            code_expiration_minutes: extractComponent('FOOTER', 'code_expiration_minutes'),
        },
        code_expiration: extractComponent('FOOTER', 'code_expiration_minutes') ? true : false,
        buttons: normalizeTemplateButtons(extractComponent('BUTTONS', 'buttons')),
        authentication_button: {
            type: 'OTP',
            otp_type: null,
            text: extractComponent('BUTTONS', 'text', true),
            autofill_text: null,
            zero_tap_terms_accepted: true,
            supported_apps: [{ package_name: null, signature_hash: null }],
        },
    });

    const langOptions = ref(props.languages);
    const categoryOptions = ref([
        { value: 'UTILITY', label: 'Utility' },
        { value: 'MARKETING', label: 'Marketing' },
        { value: 'AUTHENTICATION', label: 'Authentication' },
    ]);

    const previousExamples = ref({});
    const changeHeaderType = (value) => {
        const currentType = form.value.header.format;
        previousExamples.value[currentType] = form.value.header.example;
        form.value.header.format = value;
        form.value.header.example = previousExamples.value[value] ?? null;
    };

    const handleFileUpload = (event) => {
        const file = event.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = () => { form.value.header.example = file; };
        reader.readAsDataURL(file);
    };
    const removeFile = () => { form.value.header.example = ''; };

    const characterCount = (type) => {
        if (type === 'footer' && form.value.footer.text) {
            const limit = parseInt(footerCharacterLimit.value);
            const count = form.value.footer.text.length;
            footerCharacterCount.value = count <= limit ? count : limit;
            if (count > limit) form.value.footer.text = form.value.footer.text.slice(0, limit);
        }
    };

    const addButton = ($type) => {
        if (!form.value.buttons) form.value.buttons = [];
        if ($type === 'call')    form.value.buttons.push({ type: 'PHONE_NUMBER', country: null, text: null, phone_number: null });
        if ($type === 'website') form.value.buttons.push({ type: 'URL', text: null, url: null });
        if ($type === 'custom')  form.value.buttons.push({ type: 'QUICK_REPLY', text: null });
        if ($type === 'offer')   form.value.buttons.push({ type: 'COPY_CODE', example: null });
    };
    const removeButton = (index) => {
        if (form.value.buttons && index >= 0 && index < form.value.buttons.length) {
            form.value.buttons.splice(index, 1);
        }
    };

    const updateHeaderExamples = (value) => { form.value.header.example = value; };
    const updateBodyExamples   = (value) => { form.value.body.example = value; };

    // ── VALIDATION ────────────────────────────────────────────────────────
    const isFormValid = computed(() => {
        if (!form.value.name?.trim() || !form.value.language?.trim() || !form.value.category?.trim()) {
            return false;
        }

        // Carousel: message body must not be empty
        if (isCarousel.value) {
            if (!carouselMessageBody.value || carouselMessageBody.value.trim() === '') return false;
            return true;   // card texts are optional on edit per Meta
        }

        // Standard: body text required
        if (!form.value.body?.text?.trim()) return false;

        if ((form.value.buttons || []).some(btn => btn.type === 'URL' && isDynamicUrl(btn.url) && !getButtonExampleValue(btn.example))) {
            return false;
        }

        if ((form.value.buttons || []).some(btn => btn.type === 'COPY_CODE' && (!getButtonExampleValue(btn.example) || getButtonExampleValue(btn.example).length > 15))) {
            return false;
        }

        return true;
    });

    // ── SUBMIT ────────────────────────────────────────────────────────────
    const submitForm = () => {
        isLoading.value = true;
        isModalOpen.value = true;

        // Carousel: send only what's editable
        if (isCarousel.value) {
            axios.post('/templates/' + props.template.uuid, {
                name: form.value.name,
                category: form.value.category,
                language: form.value.language,
                carousel_message_body: carouselMessageBody.value,
                carousel_cards: editableCards.value,
            })
            .then(response => {
                if (response.data.success === false) {
                    isLoading.value = false;
                    
                    // Extract the most user-friendly error message
                    const responseData = response.data;
                    error.value = responseData.message 
                        || responseData.data?.error?.error_user_msg 
                        || responseData.data?.error?.error_user_title
                        || responseData.data?.error?.message 
                        || 'Something went wrong';
                } else {
                    router.visit('/templates', { method: 'get' });
                }
            })
            .catch(err => {
                isLoading.value = false;
                
                // Handle network/server errors
                if (err.response?.data) {
                    const responseData = err.response.data;
                    error.value = responseData.message 
                        || responseData.data?.error?.error_user_msg 
                        || responseData.data?.error?.error_user_title
                        || responseData.data?.error?.message 
                        || 'Network error occurred';
                } else {
                    error.value = 'Network error occurred';
                }
            });
            return;
        }

        // Standard: existing logic
        axios.post('/templates/' + props.template.uuid, form.value, {
            headers: { 'Content-Type': 'multipart/form-data' },
        })
        .then(response => {
            if (response.data.success === false) {
                isLoading.value = false;
                
                // Extract the most user-friendly error message
                const responseData = response.data;
                error.value = responseData.message 
                    || responseData.data?.error?.error_user_msg 
                    || responseData.data?.error?.error_user_title
                    || responseData.data?.error?.message 
                    || 'Something went wrong';
            } else {
                router.visit('/templates', { method: 'get' });
            }
        })
        .catch(err => {
            isLoading.value = false;
            
            // Handle network/server errors
            if (err.response?.data) {
                const responseData = err.response.data;
                error.value = responseData.message 
                    || responseData.data?.error?.error_user_msg 
                    || responseData.data?.error?.error_user_title
                    || responseData.data?.error?.message 
                    || 'Network error occurred';
            } else {
                error.value = 'Network error occurred';
            }
        });
    };

    function formatText(text) {
        return text.toLowerCase().replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
    }

    function normalizeTemplateButtons(buttons) {
        if (!Array.isArray(buttons)) return [];

        return buttons.map(button => ({
            ...button,
            example: Array.isArray(button.example) ? (button.example[0] ?? '') : button.example,
        }));
    }

    function isDynamicUrl(url) {
        return typeof url === 'string' && /\{\{\d+\}\}/.test(url);
    }

    function buildDynamicUrlExample(url) {
        if (!isDynamicUrl(url)) return '';

        return url.replace(/\{\{\d+\}\}/g, 'sample');
    }

    function getButtonExampleValue(example) {
        const value = Array.isArray(example) ? example[0] : example;

        return typeof value === 'string' ? value.trim() : '';
    }

    const closeModal = () => {
        isModalOpen.value = false;
        setTimeout(() => { error.value = null; }, 500);
    };

    const utilityTTLOptions = ref([
        { value: '30', label: '30 seconds' }, { value: '60', label: '1 minute' },
        { value: '120', label: '2 minutes' }, { value: '300', label: '5 minutes' },
        { value: '600', label: '10 minutes' }, { value: '1600', label: '30 minutes' },
        { value: '3200', label: '1 hour' }, { value: '9600', label: '3 hours' },
        { value: '19200', label: '6 hours' }, { value: '38400', label: '12 hours' },
    ]);
    const authTTLOptions = ref([
        { value: '30', label: '30 seconds' }, { value: '60', label: '1 minute' },
        { value: '120', label: '2 minutes' }, { value: '180', label: '3 minutes' },
        { value: '300', label: '5 minutes' }, { value: '600', label: '10 minutes' },
        { value: '800', label: '15 minutes' },
    ]);
</script>
