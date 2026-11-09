<template>
    <AppLayout>
        <div class="cb-page-shell h-full md:overflow-y-auto">
            <div class="flex justify-between">
                <div>
                    <h2 class="text-xl mb-1">{{ $t('Team') }}</h2>
                    <p class="mb-6 flex items-center text-sm leading-6">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/></svg>
                        <span class="ml-1 mt-1">{{ $t('Add edit and delete accounts in your team') }}</span>
                    </p>
                </div>
                <div v-if="canInviteUsers">
                    <button @click="openModal()" class="cb-primary-button">{{ $t('Invite user') }}</button>
                </div>
            </div>
            <!-- Table Component-->
            <TeamTable :rows="props.rows" @edit="openModal"/>
        </div>

        <Modal :label="$t(label)" :isOpen="isOpenFormModal" :closeBtn="true" @close="closeFormModal">
            <div class="mt-5 grid grid-cols-1 gap-x-6 gap-y-4">
                <form @submit.prevent="submitForm()" class="grid gap-x-6 gap-y-4 sm:grid-cols-6">
                    <FormInput v-model="form.email" :error="form.errors.email" :name="$t('Email')" :type="'email'" :class="'sm:col-span-6'" :disabled="formMethod === 'put' ? true : false"/>
                    <FormPhoneInput v-model="form.phone" :error="form.errors.phone" :name="$t('Phone')" :class="'sm:col-span-6'" :required="true"/>
                    <FormSelect v-model="form.role" :error="form.errors.role" :options="roleOptions" :name="$t('Role')" :class="'sm:col-span-6'" :placeholder="$t('Select Role')" :disabled="formMethod === 'put' && !isOwner"/>
                    <label v-if="formMethod === 'post'" class="sm:col-span-6 flex cursor-pointer items-start gap-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-700">
                        <input
                            v-model="form.create_password"
                            type="checkbox"
                            class="mt-1 h-4 w-4 rounded border-slate-300 text-primary focus:ring-primary"
                        />
                        <span>
                            <span class="block font-medium text-slate-900">{{ $t('Create password for this user') }}</span>
                            <span class="block text-xs text-slate-500">{{ $t('The user will be added directly and no invitation email will be sent.') }}</span>
                        </span>
                    </label>
                    <template v-if="formMethod === 'post' && form.create_password">
                        <FormInput v-model="form.password" :error="form.errors.password" :name="$t('Password')" :type="'password'" :class="'sm:col-span-3'"/>
                        <FormInput v-model="form.password_confirmation" :error="form.errors.password_confirmation" :name="$t('Confirm password')" :type="'password'" :class="'sm:col-span-3'"/>
                        <div class="sm:col-span-6 flex flex-wrap items-center gap-3">
                            <button type="button" @click="generatePassword" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm hover:bg-slate-50">
                                {{ $t('Generate password') }}
                            </button>
                            <button v-if="generatedPassword" type="button" @click="copyGeneratedPassword" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm hover:bg-slate-50">
                                {{ $t('Copy password') }}
                            </button>
                        </div>
                        <div v-if="generatedPassword" class="sm:col-span-6 rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                            <span class="font-medium text-slate-900">{{ $t('Generated password') }}:</span>
                            <bdi dir="ltr" class="ms-2 font-mono">{{ generatedPassword }}</bdi>
                        </div>
                    </template>
                    <div class="mt-4 flex gap-3">
                        <button type="button" @click="closeFormModal" class="inline-flex justify-center rounded-md border border-transparent bg-slate-50 px-4 py-2 text-sm text-slate-500 hover:bg-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 mr-4">{{ $t('Cancel') }}</button>
                        <button :class="['inline-flex justify-center rounded-md border border-transparent bg-primary px-4 py-2 text-sm text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2', { 'opacity-50': form.processing }]" :disabled="form.processing">
                            <svg v-if="form.processing" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/><path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z"><animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/></path></svg>
                            <span v-else>{{ $t('Save') }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </AppLayout>
</template>
<script setup>
    import AppLayout from "./../Layout/App.vue";
    import { computed, ref } from 'vue';
    import { useForm, usePage } from "@inertiajs/vue3";
    import TeamTable from '@/Components/Tables/TeamTable.vue';
    import Modal from '@/Components/Modal.vue';
    import FormInput from '@/Components/FormInput.vue';
    import FormPhoneInput from '@/Components/FormPhoneInput.vue';
    import FormSelect from '@/Components/FormSelect.vue';
    import { trans } from 'laravel-vue-i18n';
    import { useI18n } from 'vue-i18n';
    const { t } = useI18n();

    const props = defineProps({ rows: Object, filters: Object });
    const user = computed(() => usePage().props.auth.user);
    const canInviteUsers = computed(() => ['owner', 'manager'].includes(user.value?.teams?.[0]?.role));
    const isOwner = computed(() => user.value?.teams?.[0]?.role === 'owner');
    const isOpenFormModal = ref(false);
    const formUrl = ref('/team/invite');
    const formMethod = ref('post');
    const label = ref('Invite user');
    const generatedPassword = ref(null);

    const form = useForm({
        email: null,
        phone: null,
        role: null,
        create_password: false,
        password: null,
        password_confirmation: null,
    });

    const roleOptions = computed(() => [
        { value: 'manager', label: t('Manager') },
        { value: 'agent', label: t('Agent') },
    ])

    const randomFrom = (characters) => {
        const values = new Uint32Array(1);

        if (window.crypto?.getRandomValues) {
            window.crypto.getRandomValues(values);
            return characters[values[0] % characters.length];
        }

        return characters[Math.floor(Math.random() * characters.length)];
    }

    const shufflePassword = (password) => {
        const characters = password.split('');

        for (let index = characters.length - 1; index > 0; index--) {
            const values = new Uint32Array(1);
            const randomIndex = window.crypto?.getRandomValues
                ? (window.crypto.getRandomValues(values), values[0] % (index + 1))
                : Math.floor(Math.random() * (index + 1));

            [characters[index], characters[randomIndex]] = [characters[randomIndex], characters[index]];
        }

        return characters.join('');
    }

    const generatePassword = () => {
        const lowercase = 'abcdefghijkmnopqrstuvwxyz';
        const uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        const numbers = '23456789';
        const symbols = '!@#$%^&*';
        const allCharacters = lowercase + uppercase + numbers + symbols;

        let password = [
            randomFrom(lowercase),
            randomFrom(uppercase),
            randomFrom(numbers),
            randomFrom(symbols),
        ].join('');

        while (password.length < 14) {
            password += randomFrom(allCharacters);
        }

        generatedPassword.value = shufflePassword(password);
        form.password = generatedPassword.value;
        form.password_confirmation = generatedPassword.value;
    }

    const copyGeneratedPassword = async () => {
        if (!generatedPassword.value || !navigator.clipboard) {
            return;
        }

        await navigator.clipboard.writeText(generatedPassword.value);
    }

    const openModal = (key, formData = {}) => {
        label.value = 'Invite user';
        formUrl.value = '/team/invite';
        formMethod.value = 'post';
        form.clearErrors();
        generatedPassword.value = null;

        if (key) {
            label.value = 'Update user';
            formUrl.value = '/team/' + key.id;
            formMethod.value = 'put';
            form.email = key.email;
            form.phone = key.phone;
            form.role = key.role
            form.create_password = false;
            form.password = null;
            form.password_confirmation = null;
            isOpenFormModal.value = true;
        } else {
            form.email = null;
            form.phone = null;
            form.role = null;
            form.create_password = false;
            form.password = null;
            form.password_confirmation = null;
            isOpenFormModal.value = true;
        }
    }

    const closeFormModal = () => {
        isOpenFormModal.value = false;
        form.clearErrors();
        generatedPassword.value = null;
    }

    const closeAfterSuccessfulSubmit = () => {
        form.reset();
        closeFormModal();
    }

    const submitForm = () => {
        const options = {
            preserveScroll: true,
            onStart: () => {
                isOpenFormModal.value = false;
            },
            onSuccess: closeAfterSuccessfulSubmit,
            onError: () => {
                isOpenFormModal.value = true;
            },
            onFinish: () => {
                if (form.recentlySuccessful) {
                    closeAfterSuccessfulSubmit();
                    return;
                }

                isOpenFormModal.value = true;
            },
        };

        if (formMethod.value === 'post') {
            form.post(formUrl.value, options);
            return;
        }

        form.put(formUrl.value, options);
    }
</script>
