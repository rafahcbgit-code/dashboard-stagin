<template>
    <SettingLayout :modules="props.modules">
        <div class="md:h-[90vh]">
            <div class="flex justify-center items-center mb-8">
                <div class="md:w-[60em]">
                    <div class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-4 pb-2">
                        <div class="flex px-4 pt-2 pb-4">
                            <div>
                                <h2 class="text-[17px]">{{ $t('Plugins Section') }}</h2>
                                <span class="flex items-center mt-1">
                                    <svg class="mr-2" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                                    {{ $t('Enable or disable plugins to enhance your business operations.') }}
                                </span>
                            </div>
                        </div>
                        <div class="max-w-7xl mx-auto">
                            <div class="overflow-hidden sm:rounded-lg">
                                <div class="p-6">
                                    <Link href="/settings/salla" class="flex justify-between border rounded-lg p-4 mb-6 hover:shadow-md transition-shadow">
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
                                    <div v-if="plugins.length === 0" class="text-center py-12">
                                        <div class="mx-auto w-24 h-24 text-gray-300 mb-4">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z" />
                                            </svg>
                                        </div>
                                        <!-- <h3 class="text-lg font-medium text-gray-900 mb-2">{{ $t('No Plugins Available') }}</h3>
                                        <p class="text-gray-500 max-w-sm mx-auto">
                                            {{ $t('There are currently no plugins available for your account. Check back later for new integrations.') }}
                                        </p> -->
                                    </div>
                                    
                                    <div v-else class="space-y-6">
                                        <div v-for="plugin in plugins" :key="plugin.uuid" 
                                            class="border rounded-lg p-4 hover:shadow-md transition-shadow">
                                            <component 
                                                :is="getPluginComponent(plugin.name)"
                                                :plugin="plugin"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </SettingLayout>
</template>

<script setup>
import { ref, defineAsyncComponent } from 'vue';
import { Link } from '@inertiajs/vue3';
import SettingLayout from "./../Layout.vue";

const props = defineProps(['modules', 'plugins']);

const getPluginComponent = (pluginName) => {
    try {
        return defineAsyncComponent(() => 
            import(`../../../../../../modules/${pluginName}/Pages/User/Index.vue`)
        );
    } catch (error) {
        console.warn(`Plugin component not found for ${pluginName}`);
        return null;
    }
};
</script> 