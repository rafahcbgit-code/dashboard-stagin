<template>
  <!-- <SettingLayout :modules="props.modules"> -->
    <!-- Card -->
    <div class="bg-white border border-slate-200 rounded-lg py-2 text-sm mb-6 px-4">
      <div class="pt-2 pb-4">
        <h2 class="text-[17px]">{{ $t('Campaign Settings') }}</h2>
        <span class="flex items-center mt-1 text-slate-500">
          <svg class="mr-2" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24">
            <path fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"
                  stroke-width="2"
                  d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/>
          </svg>
          {{ $t('Manage campaign unsubscribe settings') }}
        </span>
      </div>

      <div class="flex space-x-10 w-full py-4">
        <!-- Left side text -->
        <div class="w-[40%]">
          <span class="text-slate-600">{{ $t('Unsubscribe trigger keyword') }}</span>
          <div class="text-xs text-slate-700 flex items-center mt-1">
            <svg class="mr-1" xmlns="http://www.w3.org/2000/svg" width="15" height="15"
                 viewBox="0 0 24 24">
              <path fill="none" stroke="currentColor" stroke-linecap="round"
                    stroke-linejoin="round" stroke-width="2"
                    d="M12 11v5m0 5a9 9 0 1 1 0-18a9 9 0 0 1 0 18Zm.05-13v.1h-.1V8h.1Z"/>
            </svg>
            <span>{{ $t('Enter keyword to unsubscribe users from campaigns') }}</span>
          </div>
        </div>

        <!-- Right side input -->
        <div class="w-[60%]">
          <FormInput v-model="form.unsubscribe_trigger"
                     :error="form.errors.unsubscribe_trigger"
                     :name="$t('Unsubscribe Trigger')"
                     type="text"
                     class="col-span-4 w-full"/>
        </div>
      </div>

      <!-- Save button -->
      <div class="flex justify-end border-t border-slate-200 pt-4">
        <button @click="submitForm"
                class="bg-primary text-white px-4 py-2 rounded-lg shadow-md hover:bg-primary/90 transition">
          {{ $t('Save') }}
        </button>
      </div>
    </div>
  <!-- </SettingLayout> -->
</template>

<script setup>
import SettingLayout from "./Layout.vue";
import FormInput from '@/Components/FormInput.vue';
import { useForm } from "@inertiajs/vue3";

const props = defineProps(['settings', 'modules']);

const settings = props.settings?.metadata ? JSON.parse(props.settings.metadata) : {};

const form = useForm({
  unsubscribe_trigger: settings?.campaign_settings?.unsubscribe_trigger ?? 'stop'
});

const moduleActive = (moduleName) => {
    if (!Array.isArray(props.modules)) return false;

    const module = props.modules.find((mod) => mod.name === moduleName);
    return module && module.status === 1;
};

const submitForm = () => {
  form.post('/settings/campaign', {
    preserveScroll: true,
  });
};
</script>
