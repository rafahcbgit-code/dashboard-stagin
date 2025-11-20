<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-10 px-4 md:pt-8 md:py-8 md:pl-8 md:pr-8 rounded-[5px] text-[#000] overflow-y-hidden">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h2 class="text-xl mb-1">{{ $t('Edit Reply') }}</h2>
                    <p class="text-sm text-gray-600">{{ $t('Update your pre-defined reply template.') }}</p>
                </div>
                <div>
                    <Link href="/pre-created-replies" class="rounded-md bg-white px-3 py-2 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50">{{ $t('Back') }}</Link>
                </div>
            </div>

            <div class="md:h-[80vh] overflow-y-auto">
                <div class="flex justify-center items-center mb-8">
                    <form @submit.prevent="submit" class="md:w-[60em] w-full">
                        <div class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-4 pb-2">
                            <div class="flex px-4 pt-2 pb-4 justify-between items-center border-b">
                                <div>
                                    <h2 class="text-[17px]">{{ $t('Edit Reply') }}</h2>
                                    <span class="flex items-center mt-1 text-slate-500">
                                        {{ $t('Update your pre-defined reply template.') }}
                                    </span>
                                </div>
                            </div>
                            
                            <div class="flex space-s-10 border-b w-full px-4 py-6">
                                <div class="w-[40%]">
                                    <span class="text-slate-600">{{ $t('Reply Details') }}</span>
                                    <div class="text-xs text-slate-700 flex items-center">
                                        <span>{{ $t('Basic information about the reply.') }}</span>
                                    </div>
                                </div>
                                <div class="w-[60%] grid grid-cols-1 gap-y-4">
                                    <FormInput v-model="form.title" :error="form.errors.title" :name="$t('Title')" :type="'text'" />
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">{{ $t('Type') }}</label>
                                        <select v-model="form.type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                            <option value="text">{{ $t('Simple Text') }}</option>
                                            <option value="quick_reply">{{ $t('Text with Buttons (Quick Replies)') }}</option>
                                            <option value="header_footer">{{ $t('Header, Text, Footer & Buttons') }}</option>
                                            <option value="link">{{ $t('Link Button') }}</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
    
                            <!-- Simple Text -->
                            <div v-if="form.type === 'text' || form.type === 'quick_reply' || form.type === 'header_footer'" class="flex space-s-10 border-b w-full px-4 py-6">
                                <div class="w-[40%]">
                                    <span class="text-slate-600">{{ $t('Message Content') }}</span>
                                    <div class="text-xs text-slate-700 flex items-center">
                                        <span>{{ $t('The main body of your message.') }}</span>
                                    </div>
                                </div>
                                <div class="w-[60%]">
                                    <FormTextArea v-model="form.content.body" :error="form.errors['content.body']" :name="$t('Message Body')" :rows="4" />
                                </div>
                            </div>
    
                            <!-- Header & Footer -->
                            <div v-if="form.type === 'header_footer'" class="flex space-s-10 border-b w-full px-4 py-6">
                                <div class="w-[40%]">
                                    <span class="text-slate-600">{{ $t('Header & Footer') }}</span>
                                    <div class="text-xs text-slate-700 flex items-center">
                                        <span>{{ $t('Optional header and footer text.') }}</span>
                                    </div>
                                </div>
                                <div class="w-[60%] grid grid-cols-1 gap-y-4">
                                    <FormInput v-model="form.content.header" :error="form.errors['content.header']" :name="$t('Header Text')" :type="'text'" />
                                    <FormInput v-model="form.content.footer" :error="form.errors['content.footer']" :name="$t('Footer Text')" :type="'text'" />
                                </div>
                            </div>
    
                            <!-- Buttons (Quick Reply & Header/Footer) -->
                            <div v-if="form.type === 'quick_reply' || form.type === 'header_footer'" class="flex space-s-10 border-b w-full px-4 py-6">
                                <div class="w-[40%]">
                                    <span class="text-slate-600">{{ $t('Buttons') }}</span>
                                    <div class="text-xs text-slate-700 flex items-center">
                                        <span>{{ $t('Add up to 3 buttons.') }}</span>
                                    </div>
                                </div>
                                <div class="w-[60%]">
                                    <div v-for="(btn, index) in form.content.buttons" :key="index" class="flex gap-2 mb-2 items-end">
                                        <div class="w-full">
                                            <FormInput v-model="btn.text" :error="form.errors[`content.buttons.${index}.text`]" :name="$t('Button Text')" :type="'text'" />
                                        </div>
                                        <button type="button" @click="removeButton(index)" class="text-red-600 hover:text-red-800 mb-2">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                                        </button>
                                    </div>
                                    <button v-if="form.content.buttons.length < 3" type="button" @click="addButton" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium flex items-center mt-2">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" class="mr-1"><path fill="currentColor" d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                                        {{ $t('Add Button') }}
                                    </button>
                                </div>
                            </div>
    
                            <!-- Link Button -->
                            <div v-if="form.type === 'link'" class="flex space-s-10 border-b w-full px-4 py-6">
                                <div class="w-[40%]">
                                    <span class="text-slate-600">{{ $t('Link Details') }}</span>
                                    <div class="text-xs text-slate-700 flex items-center">
                                        <span>{{ $t('Message body and link configuration.') }}</span>
                                    </div>
                                </div>
                                <div class="w-[60%] grid grid-cols-1 gap-y-4">
                                    <FormTextArea v-model="form.content.body" :error="form.errors['content.body']" :name="$t('Message Body')" :rows="4" />
                                    <FormInput v-model="form.content.link_text" :error="form.errors['content.link_text']" :name="$t('Link Button Text')" :type="'text'" />
                                    <FormInput v-model="form.content.link_url" :error="form.errors['content.link_url']" :name="$t('Link URL')" :type="'url'" />
                                </div>
                            </div>
                        </div>
    
                        <div class="bg-white border border-slate-200 rounded-lg py-2 text-sm pb-4 mb-20">
                            <div class="flex px-4 pt-1 pb-2">
                                <div class="ml-auto">
                                    <button type="submit" class="float-right rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm hover:shadow-md hover:bg-primary focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600" :disabled="form.processing">
                                        <svg v-if="form.processing" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                                        <span v-else>{{ $t('Update Reply') }}</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "../Layout/App.vue";
    import { Link, useForm } from "@inertiajs/vue3";
    import { watch } from "vue";
    import FormInput from '@/Components/FormInput.vue';
    import FormTextArea from '@/Components/FormTextArea.vue';

    const props = defineProps(['reply']);

    const form = useForm({
        title: props.reply.title,
        type: props.reply.type,
        content: {
            body: props.reply.content.body || '',
            header: props.reply.content.header || '',
            footer: props.reply.content.footer || '',
            buttons: props.reply.content.buttons || [],
            link_text: props.reply.content.link_text || '',
            link_url: props.reply.content.link_url || ''
        }
    });

    const addButton = () => {
        if (form.content.buttons.length < 3) {
            form.content.buttons.push({ text: '' });
        }
    };

    const removeButton = (index) => {
        form.content.buttons.splice(index, 1);
    };

    // Reset content fields when type changes to avoid clutter
    watch(() => form.type, (newType) => {
        if (newType !== props.reply.type) {
             form.content.header = '';
             form.content.footer = '';
             form.content.buttons = [];
             form.content.link_text = '';
             form.content.link_url = '';
        }
    });

    const submit = () => {
        form.clearErrors();
        let hasError = false;

        if (!form.title) {
            form.setError('title', 'Title is required');
            hasError = true;
        }

        if (form.type === 'text' || form.type === 'quick_reply' || form.type === 'header_footer' || form.type === 'link') {
            if (!form.content.body) {
                form.setError('content.body', 'Message body is required');
                hasError = true;
            }
        }

        if (form.type === 'quick_reply' || form.type === 'header_footer') {
            form.content.buttons.forEach((btn, index) => {
                if (!btn.text) {
                    form.setError(`content.buttons.${index}.text`, 'Button text is required');
                    hasError = true;
                }
            });
        }

        if (form.type === 'link') {
            if (!form.content.link_text) {
                form.setError('content.link_text', 'Link button text is required');
                hasError = true;
            }
            if (!form.content.link_url) {
                form.setError('content.link_url', 'Link URL is required');
                hasError = true;
            }
        }

        if (!hasError) {
            form.put(`/pre-created-replies/${props.reply.uuid}`);
        }
    };
</script>
