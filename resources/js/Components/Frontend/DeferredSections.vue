<template>
    <section id="section2" class="mt-[7em] px-5 md:px-10 lg:px-20 py-16 2xl:px-60">
        <div class="text-center">
            <h2 class="text-3xl md:text-5xl font-bold">{{ $t('Whatsapp messaging done differently') }}</h2>
            <p class="mt-4 text-gray-600">{{ $t('Powerful tools to help you manage WhatsApp communication faster.') }}</p>
        </div>

        <div class="mt-10 grid gap-4 md:grid-cols-3">
            <button type="button" @click="selectedFeature = 1" class="rounded-xl border p-4 text-left" :class="selectedFeature === 1 ? 'border-primary bg-primary/5' : 'border-gray-200'">
                <h3 class="font-semibold">{{ $t('Instant connectivity') }}</h3>
                <p class="mt-2 text-sm text-gray-600">{{ $t('Engage with your audience in real-time through the WhatsApp Cloud API, ensuring swift and effective communication') }}.</p>
            </button>
            <button type="button" @click="selectedFeature = 2" class="rounded-xl border p-4 text-left" :class="selectedFeature === 2 ? 'border-primary bg-primary/5' : 'border-gray-200'">
                <h3 class="font-semibold">{{ $t('Effortless integration') }}</h3>
                <p class="mt-2 text-sm text-gray-600">{{ $t('Seamlessly integrate our platform into your systems with user-friendly API documentation and expert support') }}.</p>
            </button>
            <button type="button" @click="selectedFeature = 3" class="rounded-xl border p-4 text-left" :class="selectedFeature === 3 ? 'border-primary bg-primary/5' : 'border-gray-200'">
                <h3 class="font-semibold">{{ $t('Multi-media support') }}</h3>
                <p class="mt-2 text-sm text-gray-600">{{ $t('Enrich conversations effortlessly by sharing images, documents, and multimedia content') }}.</p>
            </button>
        </div>
    </section>

    <section id="section4" class="bg-gray-50 px-5 md:px-10 lg:px-20 py-16 2xl:px-60">
        <div class="text-center">
            <h2 class="text-3xl md:text-5xl font-bold">{{ $t('Pricing') }}</h2>
            <p class="mt-4 text-gray-600">{{ $t('Simple plans built for teams of all sizes.') }}</p>
        </div>

        <div class="mt-10 grid gap-6 lg:grid-cols-3 md:grid-cols-2">
            <article v-for="plan in plans" :key="plan.id" class="rounded-2xl border border-gray-200 bg-white p-6">
                <h3 class="text-xl font-semibold">{{ plan.title }}</h3>
                <p class="mt-2 text-2xl font-bold">
                    {{ plan.amount }}
                    <span class="text-sm font-normal text-gray-500">/ {{ plan.duration }}</span>
                </p>
                <ul class="mt-4 space-y-2 text-sm text-gray-600">
                    <li>{{ $t('Campaigns') }}: {{ limitText(plan, 'campaign_limit') }}</li>
                    <li>{{ $t('Template messages') }}: {{ limitText(plan, 'template_messages_limit') }}</li>
                    <li>{{ $t('Conversations') }}: {{ limitText(plan, 'conversations_limit') }}</li>
                    <li>{{ $t('Contacts') }}: {{ limitText(plan, 'contacts_limit') }}</li>
                    <li>{{ $t('Users') }}: {{ limitText(plan, 'team_limit') }}</li>
                    <li>{{ $t('Organizations') }}: {{ limitText(plan, 'organizations_limit') }}</li>
                </ul>
                <Link href="/signup" class="mt-6 inline-flex rounded-lg bg-primary px-4 py-2 text-white">
                    {{ $t('Get started') }}
                </Link>
            </article>
        </div>
    </section>

    <section id="section5" class="px-5 md:px-10 lg:px-20 py-16 2xl:px-60">
        <div class="text-center">
            <h2 class="text-3xl md:text-5xl font-bold">{{ $t('Reviews') }}</h2>
        </div>

        <div class="mt-10 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            <article v-for="item in reviews" :key="item.id" class="rounded-2xl border border-gray-200 p-5">
                <p class="text-sm text-gray-700">{{ item.review }}</p>
                <p class="mt-3 font-semibold">{{ item.name }}</p>
                <p class="text-sm text-gray-500">{{ item.position }}</p>
            </article>
        </div>
    </section>

    <section id="section6" class="bg-gray-50 px-5 md:px-10 lg:px-20 py-16 2xl:px-60">
        <div class="text-center">
            <h2 class="text-3xl md:text-5xl font-bold">{{ $t('Frequently asked questions') }}</h2>
        </div>

        <div class="mt-10 max-w-4xl mx-auto space-y-3">
            <article v-for="(item, index) in faqs?.data || []" :key="item.id || index" class="rounded-xl border border-gray-200 bg-white">
                <button type="button" class="w-full p-4 text-left font-medium" @click="selectedFaq = selectedFaq === index ? -1 : index">
                    {{ item.question }}
                </button>
                <div v-if="selectedFaq === index" class="px-4 pb-4 text-sm text-gray-600" v-html="item.answer"></div>
            </article>
        </div>
    </section>

    <footer id="section7" class="px-5 md:px-10 lg:px-20 py-12 2xl:px-60 border-t">
        <div class="md:flex md:justify-between gap-8">
            <div>
                <img class="max-w-[150px]" v-if="companyConfig?.logo" :src="'/media/' + companyConfig.logo" :alt="companyConfig.company_name">
                <h4 v-else class="text-2xl">{{ companyConfig?.company_name }}</h4>
                <p class="mt-3 text-sm text-gray-600">{{ companyConfig?.address }}</p>
            </div>

            <div class="mt-8 md:mt-0 grid gap-6 sm:grid-cols-2">
                <div>
                    <h5 class="font-semibold">{{ $t('Links') }}</h5>
                    <div class="mt-2 flex flex-col gap-2 text-sm">
                        <a href="#section2">{{ $t('Features') }}</a>
                        <a href="#section4">{{ $t('Pricing') }}</a>
                        <a href="#section5">{{ $t('Reviews') }}</a>
                        <a href="#section6">{{ $t('FAQs') }}</a>
                    </div>
                </div>

                <div>
                    <h5 class="font-semibold">{{ $t('Contact us') }}</h5>
                    <div class="mt-2 text-sm">
                        <p>{{ $t('Email') }}: <a :href="'mailto:' + companyConfig?.email">{{ companyConfig?.email }}</a></p>
                        <p>{{ $t('Phone') }}: <a :href="'tel:' + companyConfig?.phone">{{ companyConfig?.phone }}</a></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-8 text-sm text-gray-500">
            {{ $t('Copyright') }} {{ currentYear }}. {{ $t('All rights reserved') }}.
        </div>
    </footer>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    plans: { type: Array, default: () => [] },
    faqs: { type: Object, default: () => ({ data: [] }) },
    reviews: { type: Array, default: () => [] },
    companyConfig: { type: Object, default: () => ({}) },
});

const selectedFeature = ref(1);
const selectedFaq = ref(0);
const currentYear = new Date().getFullYear();

const limitText = (plan, key) => {
    try {
        const metadata = plan?.metadata ? JSON.parse(plan.metadata) : {};
        const value = metadata?.[key];
        if (value === '-1') return 'Unlimited';
        return value ?? '-';
    } catch (error) {
        return '-';
    }
};
</script>
