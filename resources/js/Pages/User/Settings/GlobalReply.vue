<template>
  <SettingLayout :modules="props.modules">
    <div class="md:h-[90vh] flex justify-center">
      <div class="md:w-[60em]">
        <div class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-4">
          <div class="flex items-center justify-between px-4 pt-2 pb-4">
            <div>
              <h2 class="text-[17px]">{{ $t('Global Reply Settings') }}</h2>
              <span class="flex items-center mt-1">
                <svg class="mr-2" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24">
                  <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/>
                </svg>
                {{ $t('Enable a universal auto-reply with optional interactive buttons (max 3).') }}
              </span>
            </div>
          </div>

          <form @submit.prevent="submit" class="px-4 pb-4">
            <!-- Active toggle -->
            <div class="flex items-center space-x-3 mb-5">
              <div
                @click="form.active = form.active ? 0 : 1"
                class="w-12 h-6 flex items-center rounded-full p-1 cursor-pointer transition-colors duration-300"
                :class="form.active ? 'bg-green-500' : 'bg-gray-300'"
              >
                <div
                  class="bg-white w-4 h-4 rounded-full shadow-md transform duration-300 ease-in-out"
                  :class="form.active ? 'translate-x-6' : 'translate-x-0'"
                ></div>
              </div>
              <span class="text-sm">{{ $t('Active') }}</span>
              <div v-if="form.errors.active" class="text-xs text-red-600">{{ form.errors.active }}</div>
            </div>

            <!-- Message -->
            <div class="mb-6">
              <label class="block text-slate-600 mb-1">{{ $t('Reply message') }}</label>
              <FormTextArea v-model="form.message" :error="form.errors.message" :name="''" :type="'text'" :class="'sm:col-span-6'"/>
              <p class="text-xs text-slate-500 mt-1">{{ $t('Shown above your buttons in WhatsApp.') }}</p>
            </div>

            <!-- Buttons -->
            <div class="mb-3">
              <div class="flex items-center justify-between mb-2">
                <label class="text-slate-600">{{ $t('Interactive buttons') }}</label>
                <button
                  type="button"
                  class="bg-primary text-white text-xs px-3 py-1 rounded hover:shadow disabled:opacity-50"
                  @click="addButton"
                  :disabled="form.buttons.length >= 3"
                >
                  + {{ $t('Add button') }}
                </button>
              </div>

              <div v-if="form.errors.buttons" class="text-xs text-red-600 mb-2">{{ form.errors.buttons }}</div>

              <div v-for="(button, i) in form.buttons" :key="i" class="grid grid-cols-6 gap-3 mb-3 items-center">
                <div class="col-span-3">
                  <FormInput v-model="button.title" :error="form.errors[`buttons.${i}.title`]" :name="$t('Title')" :type="'text'"/>
                  <p class="text-[11px] text-slate-500 mt-1">{{ $t('Max 20 chars') }}</p>
                </div>
                <div class="col-span-3">
                  <FormInput v-model="button.payload" :error="form.errors[`buttons.${i}.payload`]" :name="$t('Payload')" :type="'text'"/>
                  <p class="text-[11px] text-slate-500 mt-1">{{ $t('Identifier you’ll receive on click') }}</p>
                </div>
                <div class="col-span-6 flex justify-end">
                  <button type="button" class="text-red-600 text-xs" @click="removeButton(i)">
                    {{ $t('Remove') }}
                  </button>
                </div>
              </div>

              <p class="text-xs text-slate-500">
                {{ $t('At least 1 and at most 3 buttons when active.') }}
              </p>
            </div>

            <!-- Save -->
            <div class="mt-6">
              <button
                type="submit"
                class="rounded-md bg-primary px-3 py-2 text-sm text-white shadow-sm hover:shadow-md"
                :disabled="form.processing"
              >
                <svg v-if="form.processing" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24">
                  <path fill="currentColor" d="M12 2A10 10 0 1 0 22 12A10 10 0 0 0 12 2Zm0 18a8 8 0 1 1 8-8A8 8 0 0 1 12 20Z" opacity=".5"/>
                  <path fill="currentColor" d="M20 12h2A10 10 0 0 0 12 2V4A8 8 0 0 1 20 12Z">
                    <animateTransform attributeName="transform" dur="1s" from="0 12 12" repeatCount="indefinite" to="360 12 12" type="rotate"/>
                  </path>
                </svg>
                <span v-else>{{ $t('Save') }}</span>
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </SettingLayout>
</template>

<script setup>
import SettingLayout from "./Layout.vue";
import FormInput from "@/Components/FormInput.vue";
import FormTextArea from "@/Components/FormTextArea.vue";
import { useForm } from "@inertiajs/vue3";
import { ref } from "vue";

const props = defineProps(['settings','modules']);
const raw = ref(props.settings.metadata);
const config = ref(raw.value ? JSON.parse(raw.value) : {});
const initial = config.value?.global_reply ?? { active: 0, message: '', buttons: [] };

const form = useForm({
  active: initial.active ? 1 : 0,
  message: initial.message || '',
  buttons: Array.isArray(initial.buttons) ? initial.buttons : [],
});

function addButton() {
  if (form.buttons.length < 3) form.buttons.push({ title: '', payload: '' });
}
function removeButton(i) {
  form.buttons.splice(i, 1);
}
function submit() {
  form.post('/settings/global-reply', { preserveScroll: true });
}
</script>
