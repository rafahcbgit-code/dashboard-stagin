<template>
    <AppLayout>
        <div class="bg-white md:bg-inherit pt-10 px-4 md:pt-8 md:p-8 rounded-[5px] text-[#000] h-full md:overflow-y-auto">
            <div class="flex justify-between mb-6">
                <div>
                    <h1 class="text-xl mb-1">{{ $t('Salla Automations') }}</h1>
                    <p class="flex items-center text-sm leading-6 text-gray-600">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24">
                            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                                stroke-width="2" d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z" />
                        </svg>
                        <span class="ltr:ml-1 rtl:mr-1 mt-1">{{ $t('Configure automatic WhatsApp messages from Salla webhooks') }}</span>
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="/settings/salla/message-logs" class="rounded-md bg-white px-3 py-2 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                        {{ $t('Message Logs') }}
                    </a>
                    <a href="/settings/salla" class="rounded-md bg-white px-3 py-2 text-sm text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50">
                        {{ $t('Back') }}
                    </a>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-lg p-6">
                <div class="flex items-start justify-between gap-6">
                    <div class="flex-1">
                        <h2 class="text-lg font-medium">{{ $t('New Order') }}</h2>
                        <p class="text-sm text-gray-600">{{ $t('Send a message automatically when a new order is created in Salla') }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-700">{{ form.enabled ? $t('Enabled') : $t('Disabled') }}</span>
                        <FormToggleSwitch v-model="form.enabled" />
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Template') }}</label>
                        <BaseListbox v-model="form.template_id" :options="templates" :placeholder="$t('Select template')" />
                        <div v-if="errors.template_id" class="form-error text-[#b91c1c] text-xs mt-1">{{ errors.template_id }}</div>
                    </div>

                    <!-- <div>
                        <FormInput
                            v-model="form.recipient_phone_payload_path"
                            :name="$t('Recipient phone payload path')"
                            :placeholder="'data.customer.mobile_code+data.customer.mobile'"
                            :error="errors.recipient_phone_payload_path"
                            :type="'text'"
                        />
                        <p class="text-xs text-gray-500 mt-1">{{ $t('Example: data.customer.mobile_code+data.customer.mobile') }}</p>
                    </div> -->
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <h3 class="text-sm font-medium text-gray-900">{{ $t('Variable Mapping') }}</h3>
                    <button type="button"
                        class="inline-flex items-center gap-2 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-sm rounded-md"
                        :disabled="loadingFields"
                        @click="refreshWebhookFields">
                        <span v-if="loadingFields">{{ $t('Refreshing...') }}</span>
                        <span v-else>{{ $t('Refresh webhook fields') }}</span>
                    </button>
                </div>

                <div v-if="templateVariablesLocal.length === 0" class="mt-4 text-sm text-gray-600">
                    {{ $t('Select a template to configure variable mapping.') }}
                </div>

                <div v-else class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600">
                                <th class="py-2 pr-4">{{ $t('Template variable') }}</th>
                                <th class="py-2 pr-4">{{ $t('Webhook field') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="variable in templateVariablesLocal" :key="variable.key" class="border-t">
                                <td class="py-3 pr-4">
                                    <div class="font-medium text-gray-900">{{ variable.key }}</div>
                                    <div class="text-xs text-gray-500">{{ variable.placeholder }}</div>
                                </td>
                                <td class="py-3 pr-4">
                                    <select v-model="mappingsLocal[variable.key]"
                                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                        <option value="">{{ $t('Select field') }}</option>
                                        <option v-for="field in webhookFieldsLocal" :key="field.path" :value="field.path">
                                            {{ fieldLabel(field) }}
                                        </option>
                                    </select>
                                    <div v-if="errors[`mappings.${variable.key}`]" class="form-error text-[#b91c1c] text-xs mt-1">
                                        {{ errors[`mappings.${variable.key}`] }}
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="webhookFieldsLocal.length === 0" class="mt-3 text-xs text-gray-500">
                        {{ $t('No webhook payload received yet. Trigger an order.created webhook to see available fields.') }}
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button type="button"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white text-sm rounded-md hover:shadow-md disabled:opacity-50"
                        :disabled="saving"
                        @click="save">
                        <span v-if="saving">{{ $t('Saving...') }}</span>
                        <span v-else>{{ $t('Save') }}</span>
                    </button>
                    <div v-if="successMessage" class="text-sm text-green-700">{{ successMessage }}</div>
                    <div v-if="errorMessage" class="text-sm text-red-700">{{ errorMessage }}</div>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-lg p-6 mt-6">
                <div class="flex items-start justify-between gap-6">
                    <div class="flex-1">
                        <h2 class="text-lg font-medium">{{ $t('Cash on Delivery') }}</h2>
                        <p class="text-sm text-gray-600">
                            {{ $t('The message will be sent only for orders that are not paid yet and have Cash on Delivery (COD) as the selected payment method.') }}
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-700">{{ codForm.enabled ? $t('Enabled') : $t('Disabled') }}</span>
                        <FormToggleSwitch v-model="codForm.enabled" />
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Template') }}</label>
                        <BaseListbox v-model="codForm.template_id" :options="templates" :placeholder="$t('Select template')" />
                        <div v-if="codErrors.template_id" class="form-error text-[#b91c1c] text-xs mt-1">{{ codErrors.template_id }}</div>
                    </div>

                    <!-- <div>
                        <FormInput
                            v-model="codForm.recipient_phone_payload_path"
                            :name="$t('Recipient phone payload path')"
                            :placeholder="'data.customer.mobile_code+data.customer.mobile'"
                            :error="codErrors.recipient_phone_payload_path"
                            :type="'text'"
                        />
                        <p class="text-xs text-gray-500 mt-1">{{ $t('Example: data.customer.mobile_code+data.customer.mobile') }}</p>
                    </div> -->
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <h3 class="text-sm font-medium text-gray-900">{{ $t('Variable Mapping') }}</h3>
                    <button type="button"
                        class="inline-flex items-center gap-2 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-sm rounded-md"
                        :disabled="loadingFields"
                        @click="refreshWebhookFields">
                        <span v-if="loadingFields">{{ $t('Refreshing...') }}</span>
                        <span v-else>{{ $t('Refresh webhook fields') }}</span>
                    </button>
                </div>

                <div v-if="codTemplateVariablesLocal.length === 0" class="mt-4 text-sm text-gray-600">
                    {{ $t('Select a template to configure variable mapping.') }}
                </div>

                <div v-else class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600">
                                <th class="py-2 pr-4">{{ $t('Template variable') }}</th>
                                <th class="py-2 pr-4">{{ $t('Webhook field') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="variable in codTemplateVariablesLocal" :key="variable.key" class="border-t">
                                <td class="py-3 pr-4">
                                    <div class="font-medium text-gray-900">{{ variable.key }}</div>
                                    <div class="text-xs text-gray-500">{{ variable.placeholder }}</div>
                                </td>
                                <td class="py-3 pr-4">
                                    <select v-model="codMappingsLocal[variable.key]"
                                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                        <option value="">{{ $t('Select field') }}</option>
                                        <option v-for="field in webhookFieldsLocal" :key="field.path" :value="field.path">
                                            {{ fieldLabel(field) }}
                                        </option>
                                    </select>
                                    <div v-if="codErrors[`mappings.${variable.key}`]" class="form-error text-[#b91c1c] text-xs mt-1">
                                        {{ codErrors[`mappings.${variable.key}`] }}
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="webhookFieldsLocal.length === 0" class="mt-3 text-xs text-gray-500">
                        {{ $t('No webhook payload received yet. Trigger an order.created webhook to see available fields.') }}
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button type="button"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white text-sm rounded-md hover:shadow-md disabled:opacity-50"
                        :disabled="codSaving"
                        @click="saveCod">
                        <span v-if="codSaving">{{ $t('Saving...') }}</span>
                        <span v-else>{{ $t('Save') }}</span>
                    </button>
                    <div v-if="codSuccessMessage" class="text-sm text-green-700">{{ codSuccessMessage }}</div>
                    <div v-if="codErrorMessage" class="text-sm text-red-700">{{ codErrorMessage }}</div>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-lg p-6 mt-6">
                <div class="flex items-start justify-between gap-6">
                    <div class="flex-1">
                        <h2 class="text-lg font-medium">{{ $t('Order Status Updated') }}</h2>
                        <p class="text-sm text-gray-600">
                            {{ $t('Send a message automatically when an order status changes in Salla') }}
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-700">{{ statusForm.enabled ? $t('Enabled') : $t('Disabled') }}</span>
                        <FormToggleSwitch v-model="statusForm.enabled" />
                    </div>
                </div>

                <!-- <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <FormInput
                            v-model="statusForm.recipient_phone_payload_path"
                            :name="$t('Recipient phone payload path')"
                            :placeholder="'data.order.customer.mobile'"
                            :error="statusErrors.recipient_phone_payload_path"
                            :type="'text'"
                        />
                        <p class="text-xs text-gray-500 mt-1">{{ $t('Example: data.order.customer.mobile') }}</p>
                    </div>
                </div> -->

                <div class="mt-6 flex items-center justify-between">
                    <h3 class="text-sm font-medium text-gray-900">{{ $t('Status Rules') }}</h3>
                    <div class="flex items-center gap-2">
                        <button type="button"
                            class="inline-flex items-center gap-2 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-sm rounded-md"
                            :disabled="statusLoadingFields"
                            @click="refreshStatusWebhookFields">
                            <span v-if="statusLoadingFields">{{ $t('Refreshing...') }}</span>
                            <span v-else>{{ $t('Refresh webhook fields') }}</span>
                        </button>
                        <button type="button"
                            class="inline-flex items-center gap-2 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-sm rounded-md"
                            @click="addStatusRule">
                            {{ $t('Add rule') }}
                        </button>
                    </div>
                </div>

                <div v-if="statusRulesLocal.length === 0" class="mt-4 text-sm text-gray-600">
                    {{ $t('No status rules configured yet. Add a rule for a status slug (e.g. under_review).') }}
                </div>

                <div v-else class="mt-4 space-y-4">
                    <div v-for="(rule, idx) in statusRulesLocal" :key="rule._key" class="border border-slate-200 rounded-md p-4">
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Status slug') }}</label>
                                    <div class="flex items-center gap-2">
                                        <input v-model="rule.status_slug"
                                            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                            :placeholder="'under_review'"
                                        />
                                        <select class="block w-44 rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                            @change="(e) => { const v = e?.target?.value; if (v) { rule.status_slug = v; const opt = (statusOptions || []).find(o => o.slug === v); if (opt && opt.name) rule.status_name = opt.name; e.target.value = ''; } }">
                                            <option value="">{{ $t('Quick pick') }}</option>
                                            <option v-for="opt in statusOptions" :key="opt.slug" :value="opt.slug">
                                                {{ opt.name ? `${opt.name} (${opt.slug})` : opt.slug }}
                                            </option>
                                        </select>
                                    </div>
                                    <!-- <p class="text-xs text-gray-500 mt-1">{{ $t('Use the status slug sent by Salla (example: under_review).') }}</p> -->

                                    <label class="block text-sm leading-6 text-gray-900 mb-2 mt-3">{{ $t('Status name (optional)') }}</label>
                                    <input v-model="rule.status_name"
                                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                                        :placeholder="$t('Under review')"
                                    />
                                </div>

                                <div>
                                    <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Template') }}</label>
                                    <BaseListbox v-model="rule.template_id" :options="templates" :placeholder="$t('Select template')" />
                                </div>

                                <div class="flex items-center justify-between md:justify-start md:gap-3">
                                    <div>
                                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Enabled') }}</label>
                                        <FormToggleSwitch v-model="rule.enabled" />
                                    </div>
                                    <button type="button"
                                        class="inline-flex items-center gap-2 px-3 py-2 bg-red-50 hover:bg-red-100 text-red-700 text-sm rounded-md md:mt-6"
                                        @click="removeStatusRule(idx)">
                                        {{ $t('Remove') }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div v-if="!rule.template_id" class="mt-4 text-sm text-gray-600">
                            {{ $t('Select a template to configure variable mapping for this status.') }}
                        </div>

                        <div v-else class="mt-4 overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="text-left text-gray-600">
                                        <th class="py-2 pr-4">{{ $t('Template variable') }}</th>
                                        <th class="py-2 pr-4">{{ $t('Webhook field') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="variable in (templateVarsCache[rule.template_id] ?? [])" :key="variable.key" class="border-t">
                                        <td class="py-3 pr-4">
                                            <div class="font-medium text-gray-900">{{ variable.key }}</div>
                                            <div class="text-xs text-gray-500">{{ variable.placeholder }}</div>
                                        </td>
                                        <td class="py-3 pr-4">
                                            <select v-model="rule.mappings[variable.key]"
                                                class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                                <option value="">{{ $t('Select field') }}</option>
                                                <option v-for="field in statusWebhookFieldsLocal" :key="field.path" :value="field.path">
                                                    {{ fieldLabel(field) }}
                                                </option>
                                            </select>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div v-if="!(rule.template_id in templateVarsCache)" class="mt-3 text-xs text-gray-500">
                                {{ $t('Loading template variables...') }}
                            </div>
                            <div v-if="statusWebhookFieldsLocal.length === 0" class="mt-3 text-xs text-gray-500">
                                {{ $t('No webhook payload received yet. Trigger an order.status.updated webhook to see available fields.') }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button type="button"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white text-sm rounded-md hover:shadow-md disabled:opacity-50"
                        :disabled="statusSaving"
                        @click="saveStatusUpdated">
                        <span v-if="statusSaving">{{ $t('Saving...') }}</span>
                        <span v-else>{{ $t('Save') }}</span>
                    </button>
                    <div v-if="statusSuccessMessage" class="text-sm text-green-700">{{ statusSuccessMessage }}</div>
                    <div v-if="statusErrorMessage" class="text-sm text-red-700">{{ statusErrorMessage }}</div>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-lg p-6 mt-6">
                <div class="flex items-start justify-between gap-6">
                    <div class="flex-1">
                        <h2 class="text-lg font-medium">{{ $t('Abandoned Cart') }}</h2>
                        <p class="text-sm text-gray-600">
                            {{ $t('Send a message automatically when a cart is abandoned in Salla') }}
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-700">{{ abandonedForm.enabled ? $t('Enabled') : $t('Disabled') }}</span>
                        <FormToggleSwitch v-model="abandonedForm.enabled" />
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Template') }}</label>
                        <BaseListbox v-model="abandonedForm.template_id" :options="templates" :placeholder="$t('Select template')" />
                        <div v-if="abandonedErrors.template_id" class="form-error text-[#b91c1c] text-xs mt-1">{{ abandonedErrors.template_id }}</div>
                    </div>

                    <!-- <div>
                        <FormInput
                            v-model="abandonedForm.recipient_phone_payload_path"
                            :name="$t('Recipient phone payload path')"
                            :placeholder="'data.customer.mobile'"
                            :error="abandonedErrors.recipient_phone_payload_path"
                            :type="'text'"
                        />
                        <p class="text-xs text-gray-500 mt-1">{{ $t('Example: data.customer.mobile') }}</p>
                    </div> -->
                </div>

                <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <FormInput
                            v-model="abandonedForm.send_delay_hours"
                            :name="$t('Send after (hours)')"
                            :placeholder="'0'"
                            :type="'number'"
                            :error="abandonedErrors.send_delay_hours"
                        />
                        <p class="text-xs text-gray-500 mt-1">
                            {{ $t('0 = send immediately. Otherwise, the message will be scheduled after N hours from cart creation time.') }}
                        </p>
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <h3 class="text-sm font-medium text-gray-900">{{ $t('Variable Mapping') }}</h3>
                    <button type="button"
                        class="inline-flex items-center gap-2 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-sm rounded-md"
                        :disabled="abandonedLoadingFields"
                        @click="refreshAbandonedWebhookFields">
                        <span v-if="abandonedLoadingFields">{{ $t('Refreshing...') }}</span>
                        <span v-else>{{ $t('Refresh webhook fields') }}</span>
                    </button>
                </div>

                <div v-if="abandonedTemplateVariablesLocal.length === 0" class="mt-4 text-sm text-gray-600">
                    {{ $t('Select a template to configure variable mapping.') }}
                </div>

                <div v-else class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600">
                                <th class="py-2 pr-4">{{ $t('Template variable') }}</th>
                                <th class="py-2 pr-4">{{ $t('Webhook field') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="variable in abandonedTemplateVariablesLocal" :key="variable.key" class="border-t">
                                <td class="py-3 pr-4">
                                    <div class="font-medium text-gray-900">{{ variable.key }}</div>
                                    <div class="text-xs text-gray-500">{{ variable.placeholder }}</div>
                                </td>
                                <td class="py-3 pr-4">
                                    <select v-model="abandonedMappingsLocal[variable.key]"
                                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                        <option value="">{{ $t('Select field') }}</option>
                                        <option v-for="field in abandonedWebhookFieldsLocal" :key="field.path" :value="field.path">
                                            {{ fieldLabel(field) }}
                                        </option>
                                    </select>
                                    <div v-if="abandonedErrors[`mappings.${variable.key}`]" class="form-error text-[#b91c1c] text-xs mt-1">
                                        {{ abandonedErrors[`mappings.${variable.key}`] }}
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="abandonedWebhookFieldsLocal.length === 0" class="mt-3 text-xs text-gray-500">
                        {{ $t('No webhook payload received yet. Trigger an abandoned.cart webhook to see available fields.') }}
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button type="button"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white text-sm rounded-md hover:shadow-md disabled:opacity-50"
                        :disabled="abandonedSaving"
                        @click="saveAbandonedCart">
                        <span v-if="abandonedSaving">{{ $t('Saving...') }}</span>
                        <span v-else>{{ $t('Save') }}</span>
                    </button>
                    <div v-if="abandonedSuccessMessage" class="text-sm text-green-700">{{ abandonedSuccessMessage }}</div>
                    <div v-if="abandonedErrorMessage" class="text-sm text-red-700">{{ abandonedErrorMessage }}</div>
                </div>
            </div>

            <div class="bg-white border border-slate-200 rounded-lg p-6 mt-6">
                <div class="flex items-start justify-between gap-6">
                    <div class="flex-1">
                        <h2 class="text-lg font-medium">{{ $t('Customer Login') }}</h2>
                        <p class="text-sm text-gray-600">
                            {{ $t('Send a message automatically when a customer logs in to Salla') }}
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-700">{{ customerLoginForm.enabled ? $t('Enabled') : $t('Disabled') }}</span>
                        <FormToggleSwitch v-model="customerLoginForm.enabled" />
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Template') }}</label>
                        <BaseListbox v-model="customerLoginForm.template_id" :options="templates" :placeholder="$t('Select template')" />
                        <div v-if="customerLoginErrors.template_id" class="form-error text-[#b91c1c] text-xs mt-1">{{ customerLoginErrors.template_id }}</div>
                    </div>

                    <!-- <div>
                        <FormInput
                            v-model="customerLoginForm.recipient_phone_payload_path"
                            :name="$t('Recipient phone payload path')"
                            :placeholder="'data.mobile_code+data.mobile'"
                            :error="customerLoginErrors.recipient_phone_payload_path"
                            :type="'text'"
                        />
                        <p class="text-xs text-gray-500 mt-1">{{ $t('Example: data.mobile_code+data.mobile') }}</p>
                    </div> -->
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <h3 class="text-sm font-medium text-gray-900">{{ $t('Variable Mapping') }}</h3>
                    <button type="button"
                        class="inline-flex items-center gap-2 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-sm rounded-md"
                        :disabled="customerLoginLoadingFields"
                        @click="refreshCustomerLoginWebhookFields">
                        <span v-if="customerLoginLoadingFields">{{ $t('Refreshing...') }}</span>
                        <span v-else>{{ $t('Refresh webhook fields') }}</span>
                    </button>
                </div>

                <div v-if="customerLoginTemplateVariablesLocal.length === 0" class="mt-4 text-sm text-gray-600">
                    {{ $t('Select a template to configure variable mapping.') }}
                </div>

                <div v-else class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600">
                                <th class="py-2 pr-4">{{ $t('Template variable') }}</th>
                                <th class="py-2 pr-4">{{ $t('Webhook field') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="variable in customerLoginTemplateVariablesLocal" :key="variable.key" class="border-t">
                                <td class="py-3 pr-4">
                                    <div class="font-medium text-gray-900">{{ variable.key }}</div>
                                    <div class="text-xs text-gray-500">{{ variable.placeholder }}</div>
                                </td>
                                <td class="py-3 pr-4">
                                    <select v-model="customerLoginMappingsLocal[variable.key]"
                                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                        <option value="">{{ $t('Select field') }}</option>
                                        <option v-for="field in customerLoginWebhookFieldsLocal" :key="field.path" :value="field.path">
                                            {{ fieldLabel(field) }}
                                        </option>
                                    </select>
                                    <div v-if="customerLoginErrors[`mappings.${variable.key}`]" class="form-error text-[#b91c1c] text-xs mt-1">
                                        {{ customerLoginErrors[`mappings.${variable.key}`] }}
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="customerLoginWebhookFieldsLocal.length === 0" class="mt-3 text-xs text-gray-500">
                        {{ $t('No webhook payload received yet. Trigger a customer.login webhook to see available fields.') }}
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button type="button"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white text-sm rounded-md hover:shadow-md disabled:opacity-50"
                        :disabled="customerLoginSaving"
                        @click="saveCustomerLogin">
                        <span v-if="customerLoginSaving">{{ $t('Saving...') }}</span>
                        <span v-else>{{ $t('Save') }}</span>
                    </button>
                    <div v-if="customerLoginSuccessMessage" class="text-sm text-green-700">{{ customerLoginSuccessMessage }}</div>
                    <div v-if="customerLoginErrorMessage" class="text-sm text-red-700">{{ customerLoginErrorMessage }}</div>
                </div>
            </div>
           <!-- <div class="bg-white border border-slate-200 rounded-lg p-6 mt-6">
                <div class="flex items-start justify-between gap-6">
                    <div class="flex-1">
                        <h2 class="text-lg font-medium">{{ $t('OTP Request') }}</h2>
                        <p class="text-sm text-gray-600">
                            {{ $t('Send a message automatically when Salla emits a verification code') }}
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-700">{{ customerOtpForm.enabled ? $t('Enabled') : $t('Disabled') }}</span>
                        <FormToggleSwitch v-model="customerOtpForm.enabled" />
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Template') }}</label>
                        <BaseListbox v-model="customerOtpForm.template_id" :options="templates" :placeholder="$t('Select template')" />
                        <div v-if="customerOtpErrors.template_id" class="form-error text-[#b91c1c] text-xs mt-1">{{ customerOtpErrors.template_id }}</div>
                    </div>

                     <div>
                        <FormInput
                            v-model="customerOtpForm.recipient_phone_payload_path"
                            :name="$t('Recipient phone payload path')"
                            :placeholder="'data.contact'"
                            :error="customerOtpErrors.recipient_phone_payload_path"
                            :type="'text'"
                        />
                        <p class="text-xs text-gray-500 mt-1">{{ $t('Example: data.contact') }}</p>
                    </div> 
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <h3 class="text-sm font-medium text-gray-900">{{ $t('Variable Mapping') }}</h3>
                    <button type="button"
                        class="inline-flex items-center gap-2 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-sm rounded-md"
                        :disabled="customerOtpLoadingFields"
                        @click="refreshCustomerOtpWebhookFields">
                        <span v-if="customerOtpLoadingFields">{{ $t('Refreshing...') }}</span>
                        <span v-else>{{ $t('Refresh webhook fields') }}</span>
                    </button>
                </div>

                <div v-if="customerOtpTemplateVariablesLocal.length === 0" class="mt-4 text-sm text-gray-600">
                    {{ $t('Select a template to configure variable mapping.') }}
                </div>

                <div v-else class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600">
                                <th class="py-2 pr-4">{{ $t('Template variable') }}</th>
                                <th class="py-2 pr-4">{{ $t('Webhook field') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="variable in customerOtpTemplateVariablesLocal" :key="variable.key" class="border-t">
                                <td class="py-3 pr-4">
                                    <div class="font-medium text-gray-900">{{ variable.key }}</div>
                                    <div class="text-xs text-gray-500">{{ variable.placeholder }}</div>
                                </td>
                                <td class="py-3 pr-4">
                                    <select v-model="customerOtpMappingsLocal[variable.key]"
                                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                        <option value="">{{ $t('Select field') }}</option>
                                        <option v-for="field in customerOtpWebhookFieldsLocal" :key="field.path" :value="field.path">
                                            {{ fieldLabel(field) }}
                                        </option>
                                    </select>
                                    <div v-if="customerOtpErrors[`mappings.${variable.key}`]" class="form-error text-[#b91c1c] text-xs mt-1">
                                        {{ customerOtpErrors[`mappings.${variable.key}`] }}
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="customerOtpWebhookFieldsLocal.length === 0" class="mt-3 text-xs text-gray-500">
                        {{ $t('No webhook payload received yet. Trigger a customer.otp.request webhook to see available fields.') }}
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button type="button"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white text-sm rounded-md hover:shadow-md disabled:opacity-50"
                        :disabled="customerOtpSaving"
                        @click="saveCustomerOtp">
                        <span v-if="customerOtpSaving">{{ $t('Saving...') }}</span>
                        <span v-else>{{ $t('Save') }}</span>
                    </button>
                    <div v-if="customerOtpSuccessMessage" class="text-sm text-green-700">{{ customerOtpSuccessMessage }}</div>
                    <div v-if="customerOtpErrorMessage" class="text-sm text-red-700">{{ customerOtpErrorMessage }}</div>
                </div>
            </div>-->
            <div class="bg-white border border-slate-200 rounded-lg p-6 mt-6">
                <div class="flex items-start justify-between gap-6">
                    <div class="flex-1">
                        <h2 class="text-lg font-medium">{{ $t('Communication WhatsApp Send') }}</h2>
                        <p class="text-sm text-gray-600">
                            {{ $t('Send a template message whenever Salla fires the communication.whatsapp.send webhook') }}
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-700">{{ communicationForm.enabled ? $t('Enabled') : $t('Disabled') }}</span>
                        <FormToggleSwitch v-model="communicationForm.enabled" />
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm leading-6 text-gray-900 mb-2">{{ $t('Template') }}</label>
                        <BaseListbox v-model="communicationForm.template_id" :options="templates" :placeholder="$t('Select template')" />
                        <div v-if="communicationErrors.template_id" class="form-error text-[#b91c1c] text-xs mt-1">{{ communicationErrors.template_id }}</div>
                    </div>
                    <div>
                        <FormInput
                            v-model="communicationForm.recipient_phone_payload_path"
                            :name="$t('Recipient phone payload path')"
                            :placeholder="'data.notifiable.0'"
                            :error="communicationErrors.recipient_phone_payload_path"
                            :type="'text'"
                        />
                        <p class="text-xs text-gray-500 mt-1">{{ $t('Example: data.notifiable.0') }}</p>
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-between">
                    <h3 class="text-sm font-medium text-gray-900">{{ $t('Variable Mapping') }}</h3>
                    <button type="button"
                        class="inline-flex items-center gap-2 px-3 py-2 bg-slate-100 hover:bg-slate-200 text-sm rounded-md"
                        :disabled="communicationLoadingFields"
                        @click="refreshCommunicationWebhookFields">
                        <span v-if="communicationLoadingFields">{{ $t('Refreshing...') }}</span>
                        <span v-else>{{ $t('Refresh webhook fields') }}</span>
                    </button>
                </div>

                <div v-if="communicationTemplateVariablesLocal.length === 0" class="mt-4 text-sm text-gray-600">
                    {{ $t('Select a template to configure variable mapping.') }}
                </div>

                <div v-else class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600">
                                <th class="py-2 pr-4">{{ $t('Template variable') }}</th>
                                <th class="py-2 pr-4">{{ $t('Webhook field') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="variable in communicationTemplateVariablesLocal" :key="variable.key" class="border-t">
                                <td class="py-3 pr-4">
                                    <div class="font-medium text-gray-900">{{ variable.key }}</div>
                                    <div class="text-xs text-gray-500">{{ variable.placeholder }}</div>
                                </td>
                                <td class="py-3 pr-4">
                                    <select v-model="communicationMappingsLocal[variable.key]"
                                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm">
                                        <option value="">{{ $t('Select field') }}</option>
                                        <option v-for="field in communicationWebhookFieldsLocal" :key="field.path" :value="field.path">
                                            {{ fieldLabel(field) }}
                                        </option>
                                    </select>
                                    <div v-if="communicationErrors[`mappings.${variable.key}`]" class="form-error text-[#b91c1c] text-xs mt-1">
                                        {{ communicationErrors[`mappings.${variable.key}`] }}
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="communicationWebhookFieldsLocal.length === 0" class="mt-3 text-xs text-gray-500">
                        {{ $t('No webhook payload received yet. Trigger a communication.whatsapp.send webhook to see available fields.') }}
                    </div>
                </div>

                <div class="mt-6 flex items-center gap-3">
                    <button type="button"
                        class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-white text-sm rounded-md hover:shadow-md disabled:opacity-50"
                        :disabled="communicationSaving"
                        @click="saveCommunicationWhatsappSend">
                        <span v-if="communicationSaving">{{ $t('Saving...') }}</span>
                        <span v-else>{{ $t('Save') }}</span>
                    </button>
                    <div v-if="communicationSuccessMessage" class="text-sm text-green-700">{{ communicationSuccessMessage }}</div>
                    <div v-if="communicationErrorMessage" class="text-sm text-red-700">{{ communicationErrorMessage }}</div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<script setup>
import { reactive, ref, watch } from 'vue';
import axios from 'axios';
import AppLayout from '../Layout/App.vue';
import BaseListbox from '@/Components/BaseListbox.vue';
import FormInput from '@/Components/FormInput.vue';
import FormToggleSwitch from '@/Components/FormToggleSwitch.vue';

const props = defineProps({
    title: String,
    templates: {
        type: Array,
        default: () => []
    },
    setting: {
        type: Object,
        default: () => ({ enabled: false, template_id: null, recipient_phone_payload_path: 'data.customer.mobile' })
    },
    templateVariables: {
        type: Array,
        default: () => []
    },
    webhookFields: {
        type: Array,
        default: () => []
    },
    mappings: {
        type: Object,
        default: () => ({})
    },
    codSetting: {
        type: Object,
        default: () => ({ enabled: false, template_id: null, recipient_phone_payload_path: 'data.customer.mobile_code+data.customer.mobile' })
    },
    codTemplateVariables: {
        type: Array,
        default: () => []
    },
    codMappings: {
        type: Object,
        default: () => ({})
    },
    statusSetting: {
        type: Object,
        default: () => ({ enabled: false, recipient_phone_payload_path: 'data.order.customer.mobile' })
    },
    statusWebhookFields: {
        type: Array,
        default: () => []
    },
    statusRules: {
        type: Array,
        default: () => []
    },
    statusOptions: {
        type: Array,
        default: () => []
    },
    abandonedSetting: {
        type: Object,
        default: () => ({ enabled: false, template_id: null, recipient_phone_payload_path: 'data.customer.mobile', send_delay_hours: 0 })
    },
    abandonedTemplateVariables: {
        type: Array,
        default: () => []
    },
    abandonedWebhookFields: {
        type: Array,
        default: () => []
    },
    abandonedMappings: {
        type: Object,
        default: () => ({})
    },
    customerLoginSetting: {
        type: Object,
        default: () => ({ enabled: false, template_id: null, recipient_phone_payload_path: 'data.mobile_code+data.mobile' })
    },
    customerLoginTemplateVariables: {
        type: Array,
        default: () => []
    },
    customerLoginWebhookFields: {
        type: Array,
        default: () => []
    },
    customerLoginMappings: {
        type: Object,
        default: () => ({})
    },
    customerOtpSetting: {
        type: Object,
        default: () => ({ enabled: false, template_id: null, recipient_phone_payload_path: 'data.contact' })
    },
    customerOtpTemplateVariables: {
        type: Array,
        default: () => []
    },
    customerOtpWebhookFields: {
        type: Array,
        default: () => []
    },
    customerOtpMappings: {
        type: Object,
        default: () => ({})
    },
    communicationSetting: {
        type: Object,
        default: () => ({ enabled: false, template_id: null, recipient_phone_payload_path: 'data.notifiable.0' })
    },
    communicationTemplateVariables: {
        type: Array,
        default: () => []
    },
    communicationWebhookFields: {
        type: Array,
        default: () => []
    },
    communicationMappings: {
        type: Object,
        default: () => ({})
    }
});

const form = reactive({
    enabled: !!props.setting.enabled,
    template_id: props.setting.template_id ?? null,
    recipient_phone_payload_path: props.setting.recipient_phone_payload_path ?? 'data.customer.mobile',
});

const templateVariablesLocal = ref(props.templateVariables ?? []);
const webhookFieldsLocal = ref(props.webhookFields ?? []);
const mappingsLocal = reactive({ ...(props.mappings ?? {}) });

const codForm = reactive({
    enabled: !!props.codSetting.enabled,
    template_id: props.codSetting.template_id ?? null,
    recipient_phone_payload_path: props.codSetting.recipient_phone_payload_path ?? 'data.customer.mobile_code+data.customer.mobile',
});

const codTemplateVariablesLocal = ref(props.codTemplateVariables ?? []);
const codMappingsLocal = reactive({ ...(props.codMappings ?? {}) });
for (const variable of codTemplateVariablesLocal.value ?? []) {
    if (!(variable.key in codMappingsLocal)) {
        codMappingsLocal[variable.key] = '';
    }
}

const statusForm = reactive({
    enabled: !!props.statusSetting.enabled,
    recipient_phone_payload_path: props.statusSetting.recipient_phone_payload_path ?? 'data.order.customer.mobile',
});

const statusWebhookFieldsLocal = ref(props.statusWebhookFields ?? []);
const statusRulesLocal = ref((props.statusRules ?? []).map((rule, idx) => ({
    _key: rule?.id ?? `rule-${idx}-${Date.now()}`,
    _loadedTemplateId: null,
    id: rule?.id ?? null,
    status_slug: rule?.status_slug ?? '',
    status_name: rule?.status_name ?? '',
    enabled: typeof rule?.enabled === 'boolean' ? rule.enabled : true,
    template_id: rule?.template_id ? Number(rule.template_id) : null,
    mappings: { ...(rule?.mappings ?? {}) },
})));

const abandonedForm = reactive({
    enabled: !!props.abandonedSetting.enabled,
    template_id: props.abandonedSetting.template_id ?? null,
    recipient_phone_payload_path: props.abandonedSetting.recipient_phone_payload_path ?? 'data.customer.mobile',
    send_delay_hours: props.abandonedSetting.send_delay_hours ?? 0,
});

const abandonedTemplateVariablesLocal = ref(props.abandonedTemplateVariables ?? []);
const abandonedWebhookFieldsLocal = ref(props.abandonedWebhookFields ?? []);
const abandonedMappingsLocal = reactive({ ...(props.abandonedMappings ?? {}) });

const customerLoginForm = reactive({
    enabled: !!props.customerLoginSetting.enabled,
    template_id: props.customerLoginSetting.template_id ?? null,
    recipient_phone_payload_path: props.customerLoginSetting.recipient_phone_payload_path ?? 'data.mobile_code+data.mobile',
});

const customerLoginTemplateVariablesLocal = ref(props.customerLoginTemplateVariables ?? []);
const customerLoginWebhookFieldsLocal = ref(props.customerLoginWebhookFields ?? []);
const customerLoginMappingsLocal = reactive({ ...(props.customerLoginMappings ?? {}) });

const customerOtpForm = reactive({
    enabled: !!props.customerOtpSetting.enabled,
    template_id: props.customerOtpSetting.template_id ?? null,
    recipient_phone_payload_path: props.customerOtpSetting.recipient_phone_payload_path ?? 'data.contact',
});

const customerOtpTemplateVariablesLocal = ref(props.customerOtpTemplateVariables ?? []);
const customerOtpWebhookFieldsLocal = ref(props.customerOtpWebhookFields ?? []);
const customerOtpMappingsLocal = reactive({ ...(props.customerOtpMappings ?? {}) });
for (const variable of customerOtpTemplateVariablesLocal.value ?? []) {
    if (!(variable.key in customerOtpMappingsLocal)) {
        customerOtpMappingsLocal[variable.key] = '';
    }
}

const communicationForm = reactive({
    enabled: !!props.communicationSetting.enabled,
    template_id: props.communicationSetting.template_id ?? null,
    recipient_phone_payload_path: props.communicationSetting.recipient_phone_payload_path ?? 'data.notifiable.0',
});

const communicationTemplateVariablesLocal = ref(props.communicationTemplateVariables ?? []);
const communicationWebhookFieldsLocal = ref(props.communicationWebhookFields ?? []);
const communicationMappingsLocal = reactive({ ...(props.communicationMappings ?? {}) });
for (const variable of communicationTemplateVariablesLocal.value ?? []) {
    if (!(variable.key in communicationMappingsLocal)) {
        communicationMappingsLocal[variable.key] = '';
    }
}

const customerOtpLoadingFields = ref(false);
const customerOtpSaving = ref(false);
const customerOtpErrors = reactive({});
const customerOtpSuccessMessage = ref('');
const customerOtpErrorMessage = ref('');

const communicationLoadingFields = ref(false);
const communicationSaving = ref(false);
const communicationErrors = reactive({});
const communicationSuccessMessage = ref('');
const communicationErrorMessage = ref('');

const saving = ref(false);
const loadingFields = ref(false);
const errors = reactive({});
const successMessage = ref('');
const errorMessage = ref('');

const codSaving = ref(false);
const codErrors = reactive({});
const codSuccessMessage = ref('');
const codErrorMessage = ref('');

const abandonedSaving = ref(false);
const abandonedLoadingFields = ref(false);
const abandonedErrors = reactive({});
const abandonedSuccessMessage = ref('');
const abandonedErrorMessage = ref('');

const customerLoginSaving = ref(false);
const customerLoginLoadingFields = ref(false);
const customerLoginErrors = reactive({});
const customerLoginSuccessMessage = ref('');
const customerLoginErrorMessage = ref('');

const statusSaving = ref(false);
const statusLoadingFields = ref(false);
const statusErrors = reactive({});
const statusSuccessMessage = ref('');
const statusErrorMessage = ref('');

const templateVarsCache = reactive({});

const stringifySample = (value) => {
    try {
        if (typeof value === 'string') {
            return value.length > 60 ? value.slice(0, 60) + '…' : value;
        }
        if (typeof value === 'number' || typeof value === 'boolean') {
            return String(value);
        }
        if (value === null || value === undefined) {
            return '';
        }
        const json = JSON.stringify(value);
        return json.length > 60 ? json.slice(0, 60) + '…' : json;
    } catch (e) {
        return '';
    }
};

const fieldLabel = (field) => {
    const path = field?.path ?? '';
    const label = field?.label ?? '';
    const sample = field?.sample ?? null;
    const sampleText = stringifySample(sample);

    const base = label;
    return sampleText ? `${base} — ${sampleText}` : base;
};

const refreshWebhookFields = async () => {
    loadingFields.value = true;
    try {
        const res = await axios.get('/settings/salla/automations/order-created/webhook-fields');
        webhookFieldsLocal.value = res?.data?.data?.fields ?? [];
    } finally {
        loadingFields.value = false;
    }
};

const refreshAbandonedWebhookFields = async () => {
    abandonedLoadingFields.value = true;
    try {
        const res = await axios.get('/settings/salla/automations/abandoned-cart/webhook-fields');
        abandonedWebhookFieldsLocal.value = res?.data?.data?.fields ?? [];
    } finally {
        abandonedLoadingFields.value = false;
    }
};

const refreshCustomerLoginWebhookFields = async () => {
    customerLoginLoadingFields.value = true;
    try {
        const res = await axios.get('/settings/salla/automations/customer-login/webhook-fields');
        customerLoginWebhookFieldsLocal.value = res?.data?.data?.fields ?? [];
    } finally {
        customerLoginLoadingFields.value = false;
    }
};

const refreshCustomerOtpWebhookFields = async () => {
    customerOtpLoadingFields.value = true;
    try {
        const res = await axios.get('/settings/salla/automations/customer-otp/webhook-fields');
        customerOtpWebhookFieldsLocal.value = res?.data?.data?.fields ?? [];
    } finally {
        customerOtpLoadingFields.value = false;
    }
};

const refreshCommunicationWebhookFields = async () => {
    communicationLoadingFields.value = true;
    try {
        const res = await axios.get('/settings/salla/automations/communication-whatsapp-send/webhook-fields');
        communicationWebhookFieldsLocal.value = res?.data?.data?.fields ?? [];
    } finally {
        communicationLoadingFields.value = false;
    }
};

const fetchTemplateVariables = async (templateId) => {
    if (!templateId) {
        return [];
    }

    if (templateId in templateVarsCache) {
        return templateVarsCache[templateId] ?? [];
    }

    const res = await axios.get(`/settings/salla/automations/template/${templateId}/variables`);
    templateVarsCache[templateId] = res?.data?.data?.variables ?? [];

    return templateVarsCache[templateId];
};

const loadTemplateVariables = async (templateId) => {
    if (!templateId) {
        templateVariablesLocal.value = [];
        return;
    }

    templateVariablesLocal.value = await fetchTemplateVariables(templateId);

    // Initialize missing mapping keys
    for (const variable of templateVariablesLocal.value) {
        if (!(variable.key in mappingsLocal)) {
            mappingsLocal[variable.key] = '';
        }
    }
};

const loadCodTemplateVariables = async (templateId) => {
    if (!templateId) {
        codTemplateVariablesLocal.value = [];
        return;
    }

    codTemplateVariablesLocal.value = await fetchTemplateVariables(templateId);

    for (const variable of codTemplateVariablesLocal.value) {
        if (!(variable.key in codMappingsLocal)) {
            codMappingsLocal[variable.key] = '';
        }
    }
};

watch(() => form.template_id, async (newId) => {
    await loadTemplateVariables(newId);
});

watch(() => codForm.template_id, async (newId) => {
    await loadCodTemplateVariables(newId);
});

const loadAbandonedTemplateVariables = async (templateId) => {
    if (!templateId) {
        abandonedTemplateVariablesLocal.value = [];
        return;
    }

    abandonedTemplateVariablesLocal.value = await fetchTemplateVariables(templateId);

    for (const variable of abandonedTemplateVariablesLocal.value) {
        if (!(variable.key in abandonedMappingsLocal)) {
            abandonedMappingsLocal[variable.key] = '';
        }
    }
};

watch(() => abandonedForm.template_id, async (newId) => {
    await loadAbandonedTemplateVariables(newId);
});

const loadCustomerLoginTemplateVariables = async (templateId) => {
    if (!templateId) {
        customerLoginTemplateVariablesLocal.value = [];
        return;
    }

    customerLoginTemplateVariablesLocal.value = await fetchTemplateVariables(templateId);

    for (const variable of customerLoginTemplateVariablesLocal.value) {
        if (!(variable.key in customerLoginMappingsLocal)) {
            customerLoginMappingsLocal[variable.key] = '';
        }
    }
};

watch(() => customerLoginForm.template_id, async (newId) => {
    await loadCustomerLoginTemplateVariables(newId);
});

const loadCustomerOtpTemplateVariables = async (templateId) => {
    if (!templateId) {
        customerOtpTemplateVariablesLocal.value = [];
        return;
    }

    customerOtpTemplateVariablesLocal.value = await fetchTemplateVariables(templateId);

    for (const variable of customerOtpTemplateVariablesLocal.value) {
        if (!(variable.key in customerOtpMappingsLocal)) {
            customerOtpMappingsLocal[variable.key] = '';
        }
    }
};

watch(() => customerOtpForm.template_id, async (newId) => {
    await loadCustomerOtpTemplateVariables(newId);
});

const loadCommunicationTemplateVariables = async (templateId) => {
    if (!templateId) {
        communicationTemplateVariablesLocal.value = [];
        return;
    }

    communicationTemplateVariablesLocal.value = await fetchTemplateVariables(templateId);

    for (const variable of communicationTemplateVariablesLocal.value) {
        if (!(variable.key in communicationMappingsLocal)) {
            communicationMappingsLocal[variable.key] = '';
        }
    }
};

watch(() => communicationForm.template_id, async (newId) => {
    await loadCommunicationTemplateVariables(newId);
});

watch(statusRulesLocal, async (rules) => {
    for (const rule of rules ?? []) {
        const templateId = rule?.template_id ?? null;

        if (!templateId) {
            rule._loadedTemplateId = null;
            continue;
        }

        if (rule._loadedTemplateId === templateId) {
            continue;
        }

        const vars = await fetchTemplateVariables(templateId);
        rule._loadedTemplateId = templateId;

        for (const v of vars ?? []) {
            if (!(v.key in rule.mappings)) {
                rule.mappings[v.key] = '';
            }
        }
    }
}, { deep: true, immediate: true });

const refreshStatusWebhookFields = async () => {
    statusLoadingFields.value = true;
    try {
        const res = await axios.get('/settings/salla/automations/order-status-updated/webhook-fields');
        statusWebhookFieldsLocal.value = res?.data?.data?.fields ?? [];
    } finally {
        statusLoadingFields.value = false;
    }
};

const addStatusRule = () => {
    statusRulesLocal.value.push({
        _key: `new-${Date.now()}-${Math.random().toString(16).slice(2)}`,
        _loadedTemplateId: null,
        id: null,
        status_slug: '',
        status_name: '',
        enabled: true,
        template_id: null,
        mappings: {},
    });
};

const removeStatusRule = (idx) => {
    statusRulesLocal.value.splice(idx, 1);
};

const save = async () => {
    saving.value = true;
    successMessage.value = '';
    errorMessage.value = '';
    Object.keys(errors).forEach((k) => delete errors[k]);

    const mappingsArray = (templateVariablesLocal.value ?? []).map(v => ({
        template_variable: v.key,
        payload_path: mappingsLocal[v.key] ?? '',
    }));

    try {
        await axios.post('/settings/salla/automations/order-created', {
            enabled: form.enabled,
            template_id: form.template_id,
            recipient_phone_payload_path: form.recipient_phone_payload_path,
            mappings: mappingsArray,
        });

        successMessage.value = 'Saved';
    } catch (e) {
        errorMessage.value = e?.response?.data?.message ?? 'Failed to save';

        const serverErrors = e?.response?.data?.errors ?? null;
        if (serverErrors && typeof serverErrors === 'object') {
            for (const [key, value] of Object.entries(serverErrors)) {
                // Remap index-based mapping errors to stable keys like `mappings.body.1`
                const match = String(key).match(/^mappings\.(\d+)\.(payload_path|template_variable)$/);
                if (match) {
                    const idx = parseInt(match[1], 10);
                    const varKey = mappingsArray[idx]?.template_variable;
                    if (varKey) {
                        errors[`mappings.${varKey}`] = Array.isArray(value) ? value[0] : value;
                        continue;
                    }
                }

                errors[key] = Array.isArray(value) ? value[0] : value;
            }
        }

        // Special case for missing required mappings (422 custom response)
        if (e?.response?.status === 422 && e?.response?.data?.message?.startsWith('Missing mapping for')) {
            errorMessage.value = e.response.data.message;
        }
    } finally {
        saving.value = false;
    }
};

const saveCod = async () => {
    codSaving.value = true;
    codSuccessMessage.value = '';
    codErrorMessage.value = '';
    Object.keys(codErrors).forEach((k) => delete codErrors[k]);

    const mappingsArray = (codTemplateVariablesLocal.value ?? []).map(v => ({
        template_variable: v.key,
        payload_path: codMappingsLocal[v.key] ?? '',
    }));

    try {
        await axios.post('/settings/salla/automations/order-created-cod', {
            enabled: codForm.enabled,
            template_id: codForm.template_id,
            recipient_phone_payload_path: codForm.recipient_phone_payload_path,
            mappings: mappingsArray,
        });

        codSuccessMessage.value = 'Saved';
    } catch (e) {
        codErrorMessage.value = e?.response?.data?.message ?? 'Failed to save';

        const serverErrors = e?.response?.data?.errors ?? null;
        if (serverErrors && typeof serverErrors === 'object') {
            for (const [key, value] of Object.entries(serverErrors)) {
                const match = String(key).match(/^mappings\.(\d+)\.(payload_path|template_variable)$/);
                if (match) {
                    const idx = parseInt(match[1], 10);
                    const varKey = mappingsArray[idx]?.template_variable;
                    if (varKey) {
                        codErrors[`mappings.${varKey}`] = Array.isArray(value) ? value[0] : value;
                        continue;
                    }
                }

                codErrors[key] = Array.isArray(value) ? value[0] : value;
            }
        }

        if (e?.response?.status === 422 && e?.response?.data?.message?.startsWith('Missing mapping for')) {
            codErrorMessage.value = e.response.data.message;
        }
    } finally {
        codSaving.value = false;
    }
};

const saveAbandonedCart = async () => {
    abandonedSaving.value = true;
    abandonedSuccessMessage.value = '';
    abandonedErrorMessage.value = '';
    Object.keys(abandonedErrors).forEach((k) => delete abandonedErrors[k]);

    const mappingsArray = (abandonedTemplateVariablesLocal.value ?? []).map(v => ({
        template_variable: v.key,
        payload_path: abandonedMappingsLocal[v.key] ?? '',
    }));

    try {
        await axios.post('/settings/salla/automations/abandoned-cart', {
            enabled: abandonedForm.enabled,
            template_id: abandonedForm.template_id,
            recipient_phone_payload_path: abandonedForm.recipient_phone_payload_path,
            send_delay_hours: abandonedForm.send_delay_hours,
            mappings: mappingsArray,
        });

        abandonedSuccessMessage.value = 'Saved';
    } catch (e) {
        abandonedErrorMessage.value = e?.response?.data?.message ?? 'Failed to save';

        const serverErrors = e?.response?.data?.errors ?? null;
        if (serverErrors && typeof serverErrors === 'object') {
            for (const [key, value] of Object.entries(serverErrors)) {
                const match = String(key).match(/^mappings\.(\d+)\.(payload_path|template_variable)$/);
                if (match) {
                    const idx = parseInt(match[1], 10);
                    const varKey = mappingsArray[idx]?.template_variable;
                    if (varKey) {
                        abandonedErrors[`mappings.${varKey}`] = Array.isArray(value) ? value[0] : value;
                        continue;
                    }
                }

                abandonedErrors[key] = Array.isArray(value) ? value[0] : value;
            }
        }

        if (e?.response?.status === 422 && e?.response?.data?.message?.startsWith('Missing mapping for')) {
            abandonedErrorMessage.value = e.response.data.message;
        }
    } finally {
        abandonedSaving.value = false;
    }
};

const saveCustomerLogin = async () => {
    customerLoginSaving.value = true;
    customerLoginSuccessMessage.value = '';
    customerLoginErrorMessage.value = '';
    Object.keys(customerLoginErrors).forEach((k) => delete customerLoginErrors[k]);

    const mappingsArray = (customerLoginTemplateVariablesLocal.value ?? []).map(v => ({
        template_variable: v.key,
        payload_path: customerLoginMappingsLocal[v.key] ?? '',
    }));

    try {
        await axios.post('/settings/salla/automations/customer-login', {
            enabled: customerLoginForm.enabled,
            template_id: customerLoginForm.template_id,
            recipient_phone_payload_path: customerLoginForm.recipient_phone_payload_path,
            mappings: mappingsArray,
        });

        customerLoginSuccessMessage.value = 'Saved';
    } catch (e) {
        customerLoginErrorMessage.value = e?.response?.data?.message ?? 'Failed to save';

        const serverErrors = e?.response?.data?.errors ?? null;
        if (serverErrors && typeof serverErrors === 'object') {
            for (const [key, value] of Object.entries(serverErrors)) {
                const match = String(key).match(/^mappings\.(\d+)\.(payload_path|template_variable)$/);
                if (match) {
                    const idx = parseInt(match[1], 10);
                    const varKey = mappingsArray[idx]?.template_variable;
                    if (varKey) {
                        customerLoginErrors[`mappings.${varKey}`] = Array.isArray(value) ? value[0] : value;
                        continue;
                    }
                }

                customerLoginErrors[key] = Array.isArray(value) ? value[0] : value;
            }
        }

        if (e?.response?.status === 422 && e?.response?.data?.message?.startsWith('Missing mapping for')) {
            customerLoginErrorMessage.value = e.response.data.message;
        }
    } finally {
        customerLoginSaving.value = false;
    }
};

const saveCustomerOtp = async () => {
    customerOtpSaving.value = true;
    customerOtpSuccessMessage.value = '';
    customerOtpErrorMessage.value = '';
    Object.keys(customerOtpErrors).forEach((k) => delete customerOtpErrors[k]);

    const mappingsArray = (customerOtpTemplateVariablesLocal.value ?? []).map(v => ({
        template_variable: v.key,
        payload_path: customerOtpMappingsLocal[v.key] ?? '',
    }));

    try {
        await axios.post('/settings/salla/automations/customer-otp', {
            enabled: customerOtpForm.enabled,
            template_id: customerOtpForm.template_id,
            recipient_phone_payload_path: customerOtpForm.recipient_phone_payload_path,
            mappings: mappingsArray,
        });

        customerOtpSuccessMessage.value = 'Saved';
    } catch (e) {
        customerOtpErrorMessage.value = e?.response?.data?.message ?? 'Failed to save';

        const serverErrors = e?.response?.data?.errors ?? null;
        if (serverErrors && typeof serverErrors === 'object') {
            for (const [key, value] of Object.entries(serverErrors)) {
                const match = String(key).match(/^mappings\.(\d+)\.(payload_path|template_variable)$/);
                if (match) {
                    const idx = parseInt(match[1], 10);
                    const varKey = mappingsArray[idx]?.template_variable;
                    if (varKey) {
                        customerOtpErrors[`mappings.${varKey}`] = Array.isArray(value) ? value[0] : value;
                        continue;
                    }
                }

                customerOtpErrors[key] = Array.isArray(value) ? value[0] : value;
            }
        }

        if (e?.response?.status === 422 && e?.response?.data?.message?.startsWith('Missing mapping for')) {
            customerOtpErrorMessage.value = e.response.data.message;
        }
    } finally {
        customerOtpSaving.value = false;
    }
};

const saveCommunicationWhatsappSend = async () => {
    communicationSaving.value = true;
    communicationSuccessMessage.value = '';
    communicationErrorMessage.value = '';
    Object.keys(communicationErrors).forEach((k) => delete communicationErrors[k]);

    const mappingsArray = (communicationTemplateVariablesLocal.value ?? []).map(v => ({
        template_variable: v.key,
        payload_path: communicationMappingsLocal[v.key] ?? '',
    }));

    try {
        await axios.post('/settings/salla/automations/communication-whatsapp-send', {
            enabled: communicationForm.enabled,
            template_id: communicationForm.template_id,
            recipient_phone_payload_path: communicationForm.recipient_phone_payload_path,
            mappings: mappingsArray,
        });

        communicationSuccessMessage.value = 'Saved';
    } catch (e) {
        communicationErrorMessage.value = e?.response?.data?.message ?? 'Failed to save';

        const serverErrors = e?.response?.data?.errors ?? null;
        if (serverErrors && typeof serverErrors === 'object') {
            for (const [key, value] of Object.entries(serverErrors)) {
                const match = String(key).match(/^mappings\.(\d+)\.(payload_path|template_variable)$/);
                if (match) {
                    const idx = parseInt(match[1], 10);
                    const varKey = mappingsArray[idx]?.template_variable;
                    if (varKey) {
                        communicationErrors[`mappings.${varKey}`] = Array.isArray(value) ? value[0] : value;
                        continue;
                    }
                }

                communicationErrors[key] = Array.isArray(value) ? value[0] : value;
            }
        }

        if (e?.response?.status === 422 && e?.response?.data?.message?.startsWith('Missing mapping for')) {
            communicationErrorMessage.value = e.response.data.message;
        }
    } finally {
        communicationSaving.value = false;
    }
};

const saveStatusUpdated = async () => {
    statusSaving.value = true;
    statusSuccessMessage.value = '';
    statusErrorMessage.value = '';
    Object.keys(statusErrors).forEach((k) => delete statusErrors[k]);

    try {
        const rulesPayload = [];

        for (const rule of statusRulesLocal.value ?? []) {
            const templateId = rule.template_id ? Number(rule.template_id) : null;
            const vars = templateId ? await fetchTemplateVariables(templateId) : [];

            const mappingsArray = (vars ?? []).map(v => ({
                template_variable: v.key,
                payload_path: rule.mappings?.[v.key] ?? '',
            }));

            rulesPayload.push({
                id: rule.id,
                status_slug: rule.status_slug,
                status_name: rule.status_name,
                enabled: !!rule.enabled,
                template_id: templateId,
                mappings: mappingsArray,
            });
        }

        await axios.post('/settings/salla/automations/order-status-updated', {
            enabled: statusForm.enabled,
            recipient_phone_payload_path: statusForm.recipient_phone_payload_path,
            rules: rulesPayload,
        });

        statusSuccessMessage.value = 'Saved';
    } catch (e) {
        statusErrorMessage.value = e?.response?.data?.message ?? 'Failed to save';

        const serverErrors = e?.response?.data?.errors ?? null;
        if (serverErrors && typeof serverErrors === 'object') {
            for (const [key, value] of Object.entries(serverErrors)) {
                statusErrors[key] = Array.isArray(value) ? value[0] : value;
            }
        }
    } finally {
        statusSaving.value = false;
    }
};
</script>
