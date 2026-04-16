<template>
    <SettingLayout :modules="props.modules">
        <div class="md:h-[90vh]">
            <div class="flex justify-center items-center mb-8">
                <div class="md:w-[60em] space-y-6">
                    <div class="bg-white border border-slate-200 rounded-lg p-6">
                        <div class="flex items-start justify-between gap-6">
                            <div class="max-w-[32rem] space-y-2">
                                <h1 class="text-xl font-semibold text-gray-900">{{ $t('WooCommerce Integration') }}</h1>
                                <p class="text-sm text-slate-500">
                                    {{ $t('Manage store credentials, subscriptions, and WooCommerce automations from one page.') }}
                                </p>
                            </div>
                            <Link
                                href="/settings/plugins"
                                class="inline-flex items-center gap-2 text-sm font-semibold text-blue-600 hover:text-blue-800"
                            >
                                {{ $t('Back to Plugins') }}
                            </Link>
                        </div>
                    </div>

                    <section class="bg-white border border-slate-200 rounded-lg p-6">
                        <WooCommerceIntegrationCard :expanded-by-default="true" :collapsible="false" />
                    </section>

                    
                </div>
            </div>
        </div>
    </SettingLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import SettingLayout from './Layout.vue';
import WooCommerceIntegrationCard from './Plugins/WooCommerceIntegrationCard.vue';

const props = defineProps({
    modules: {
        type: Array,
        default: () => [],
    },
});
</script>
