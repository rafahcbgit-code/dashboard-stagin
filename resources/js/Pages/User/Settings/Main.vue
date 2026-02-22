<template>
    <AppLayout>
        <div class="px-6 py-8 bg-white">
            <div class="flex justify-between">
                <div>
                    <h2 class="text-xl mb-1">{{ $t('General settings') }}</h2>
                    <p class="mb-6 flex items-center text-sm leading-6">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24">
                            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z" />
                        </svg>
                        <span class="ml-1 mt-1">{{ $t('Update your organization settings') }}</span>
                    </p>
                </div>
            </div>

            <Link href="/settings" class="flex justify-between border-b py-3 mb-2">
                <div class="flex items-center gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                        <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="1.5">
                            <path d="M12 15a3 3 0 1 0 0-6a3 3 0 0 0 0 6" />
                            <path
                                d="m19.622 10.395l-1.097-2.65L20 6l-2-2l-1.735 1.483l-2.707-1.113L12.935 2h-1.954l-.632 2.401l-2.645 1.115L6 4L4 6l1.453 1.789l-1.08 2.657L2 11v2l2.401.656L5.516 16.3L4 18l2 2l1.791-1.46l2.606 1.072L11 22h2l.604-2.387l2.651-1.098C16.697 18.832 18 20 18 20l2-2l-1.484-1.75l1.098-2.652l2.386-.62V11z" />
                        </g>
                    </svg>
                    <span>{{ $t('General settings') }}</span>
                </div>
                <div>
                    <svg class="float-right" xmlns="http://www.w3.org/2000/svg" width="1.5em" height="1.5em"
                        viewBox="0 0 256 256">
                        <path fill="currentColor"
                            d="m180.24 132.24l-80 80a6 6 0 0 1-8.48-8.48L167.51 128L91.76 52.24a6 6 0 0 1 8.48-8.48l80 80a6 6 0 0 1 0 8.48" />
                    </svg>
                </div>
            </Link>
            <Link href="/settings/whatsapp" class="flex justify-between border-b py-3 mb-2">
                <div class="flex items-center gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                        <path fill="currentColor"
                            d="M19.05 4.91A9.816 9.816 0 0 0 12.04 2c-5.46 0-9.91 4.45-9.91 9.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21c5.46 0 9.91-4.45 9.91-9.91c0-2.65-1.03-5.14-2.9-7.01m-7.01 15.24c-1.48 0-2.93-.4-4.2-1.15l-.3-.18l-3.12.82l.83-3.04l-.2-.31a8.264 8.264 0 0 1-1.26-4.38c0-4.54 3.7-8.24 8.24-8.24c2.2 0 4.27.86 5.82 2.42a8.183 8.183 0 0 1 2.41 5.83c.02 4.54-3.68 8.23-8.22 8.23m4.52-6.16c-.25-.12-1.47-.72-1.69-.81c-.23-.08-.39-.12-.56.12c-.17.25-.64.81-.78.97c-.14.17-.29.19-.54.06c-.25-.12-1.05-.39-1.99-1.23c-.74-.66-1.23-1.47-1.38-1.72c-.14-.25-.02-.38.11-.51c.11-.11.25-.29.37-.43s.17-.25.25-.41c.08-.17.04-.31-.02-.43s-.56-1.34-.76-1.84c-.2-.48-.41-.42-.56-.43h-.48c-.17 0-.43.06-.66.31c-.22.25-.86.85-.86 2.07c0 1.22.89 2.4 1.01 2.56c.12.17 1.75 2.67 4.23 3.74c.59.26 1.05.41 1.41.52c.59.19 1.13.16 1.56.1c.48-.07 1.47-.6 1.67-1.18c.21-.58.21-1.07.14-1.18s-.22-.16-.47-.28" />
                    </svg>
                    <span>{{ $t('Whatsapp settings') }}</span>
                </div>
                <div>
                    <svg class="float-right" xmlns="http://www.w3.org/2000/svg" width="1.5em" height="1.5em"
                        viewBox="0 0 256 256">
                        <path fill="currentColor"
                            d="m180.24 132.24l-80 80a6 6 0 0 1-8.48-8.48L167.51 128L91.76 52.24a6 6 0 0 1 8.48-8.48l80 80a6 6 0 0 1 0 8.48" />
                    </svg>
                </div>
            </Link>

            <Link href="/settings/salla" class="flex justify-between border-b py-3 mb-2">
                <div class="flex items-center gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                        fill="currentColor">
                        <path
                            d="M21.9 8.89l-1.05-4.37c-.22-.9-1-1.52-1.91-1.52H5.05c-.9 0-1.69.63-1.9 1.52L2.1 8.89c-.24 1.02-.02 2.06.62 2.88.08.11.19.19.28.29V19c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2v-6.94c.09-.09.2-.18.28-.28.64-.82.87-1.87.62-2.89zm-2.99-3.9l1.05 4.37c.1.42.01.84-.25 1.17-.14.18-.44.47-.94.47-.61 0-1.14-.49-1.21-1.14L16.98 5l1.93-.01zM13 5h1.96l.54 4.52c.05.39-.07.78-.33 1.07-.22.26-.54.41-.95.41-.67 0-1.22-.59-1.22-1.31V5zM8.49 5h1.96v4.69c0 .72-.55 1.31-1.22 1.31-.41 0-.73-.15-.95-.41-.26-.29-.38-.68-.33-1.07L8.49 5h.02.02zm-4.45 0h1.93l-.58 4.86c-.07.65-.6 1.14-1.21 1.14-.49 0-.8-.29-.93-.47-.27-.32-.36-.75-.26-1.17l1.05-4.36zM5 19v-6.03c.08.01.15.03.23.03.87 0 1.66-.36 2.24-.95.6.6 1.4.95 2.31.95.87 0 1.65-.36 2.23-.93.59.57 1.39.93 2.29.93.84 0 1.64-.35 2.24-.95.58.59 1.37.95 2.24.95.08 0 .15-.02.23-.03V19H5z" />
                    </svg>
                    <span>{{ $t('Salla Integration') }}</span>
                </div>
                <div>
                    <svg class="float-right" xmlns="http://www.w3.org/2000/svg" width="1.5em" height="1.5em"
                        viewBox="0 0 256 256">
                        <path fill="currentColor"
                            d="m180.24 132.24l-80 80a6 6 0 0 1-8.48-8.48L167.51 128L91.76 52.24a6 6 0 0 1 8.48-8.48l80 80a6 6 0 0 1 0 8.48" />
                    </svg>
                </div>
            </Link>

            <Link href="/web-widget" class="flex justify-between border-b py-3 mb-2">
                <div class="flex items-center gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                        <g fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2">
                            <path d="M12 21a9 9 0 1 0 0-18a9 9 0 0 0 0 18" />
                            <path d="M3.6 9h16.8" />
                            <path d="M3.6 15h16.8" />
                            <path d="M12 3c2.5 2.7 2.5 15.3 0 18" />
                            <path d="M12 3c-2.5 2.7-2.5 15.3 0 18" />
                        </g>
                    </svg>
                    <span>{{ $t('Web Widget') }}</span>
                </div>
                <div>
                    <svg class="float-right" xmlns="http://www.w3.org/2000/svg" width="1.5em" height="1.5em"
                        viewBox="0 0 256 256">
                        <path fill="currentColor"
                            d="m180.24 132.24l-80 80a6 6 0 0 1-8.48-8.48L167.51 128L91.76 52.24a6 6 0 0 1 8.48-8.48l80 80a6 6 0 0 1 0 8.48" />
                    </svg>
                </div>
            </Link>

            
            <Link href="/settings/contacts" class="flex justify-between border-b py-3 mb-2">
                <div class="flex items-center gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                        <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2"
                            d="M21 20c0-1.742-1.67-3.223-4-3.773M15 20c0-2.21-2.686-4-6-4s-6 1.79-6 4m12-7a4 4 0 0 0 0-8m-6 8a4 4 0 1 1 0-8a4 4 0 0 1 0 8" />
                    </svg>
                    <span>{{ $t('Contact fields') }}</span>
                </div>
                <div>
                    <svg class="float-right" xmlns="http://www.w3.org/2000/svg" width="1.5em" height="1.5em"
                        viewBox="0 0 256 256">
                        <path fill="currentColor"
                            d="m180.24 132.24l-80 80a6 6 0 0 1-8.48-8.48L167.51 128L91.76 52.24a6 6 0 0 1 8.48-8.48l80 80a6 6 0 0 1 0 8.48" />
                    </svg>
                </div>
            </Link>
            <Link href="/settings/tickets" class="flex justify-between border-b py-3">
                <div class="flex items-center gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                        <path fill="currentColor"
                            d="M4 4a2 2 0 0 0-2 2v4a2 2 0 0 1 2 2a2 2 0 0 1-2 2v4a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-4a2 2 0 0 1-2-2a2 2 0 0 1 2-2V6a2 2 0 0 0-2-2zm0 2h16v2.54c-1.24.71-2 2.03-2 3.46s.76 2.75 2 3.46V18H4v-2.54c1.24-.71 2-2.03 2-3.46s-.76-2.75-2-3.46z" />
                    </svg>
                    <span>{{ $t('Ticket settings') }}</span>
                </div>
                <div>
                    <svg class="float-right" xmlns="http://www.w3.org/2000/svg" width="1.5em" height="1.5em"
                        viewBox="0 0 256 256">
                        <path fill="currentColor"
                            d="m180.24 132.24l-80 80a6 6 0 0 1-8.48-8.48L167.51 128L91.76 52.24a6 6 0 0 1 8.48-8.48l80 80a6 6 0 0 1 0 8.48" />
                    </svg>
                </div>
            </Link>
            
        </div>
    </AppLayout>
</template>
<script setup>
import { Link } from "@inertiajs/vue3";
import AppLayout from "../Layout/App.vue";

const props = defineProps('title');
</script>
