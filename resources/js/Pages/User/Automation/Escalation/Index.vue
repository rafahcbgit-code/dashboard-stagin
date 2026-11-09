<template>
    <SettingLayout :aimodule="aimodule" :fbmodule="fbmodule">
        <div dir="rtl" class="md:h-[90vh] text-start">
            <div class="flex justify-center items-center">
                <div class="md:w-[68em] w-full">
                    <div class="bg-white border border-slate-200 rounded-xl text-sm pb-6 px-6 mb-20">
                        <!-- Header -->
                        <div class="flex items-center justify-between pt-5 pb-4 border-b border-slate-100">
                            <div>
                                <h4 class="text-lg font-semibold text-slate-800">{{ $t('تعليمات التصعيد') }}</h4>
                                <p class="text-sm text-slate-500 mt-0.5">{{ $t('قواعد تحوّل المحادثات إلى الموظفين قبل إجابة الذكاء الاصطناعي. اسحب لتغيير ترتيب الأولوية.') }}</p>
                            </div>
                            <button
                                @click="showCreateModal = true"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-[#045a47] transition-colors"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14m7-7H5"/></svg>
                                {{ $t('إضافة قاعدة') }}
                            </button>
                        </div>

                        <!-- Info Banner -->
                        <div class="mt-4 flex items-start gap-2 rounded-lg bg-amber-50 border border-amber-100 px-3.5 py-2.5 text-xs text-amber-800">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 mt-0.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            <span>{{ $t('عندما تتطابق الرسالة مع قاعدة تصعيد، تُحوّل مباشرةً إلى موظف دون إجابة الذكاء الاصطناعي.') }}</span>
                        </div>

                        <!-- Empty State -->
                        <div v-if="instructions.length === 0" class="flex flex-col items-center justify-center py-16 text-slate-400">
                            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="mb-3 opacity-40"><path d="M16 21v3H8v-3"/><path d="M12 3v10"/><path d="m4.5 9.5 3-3"/><path d="M12 13a4 4 0 0 0 0-8"/></svg>
                            <p class="text-sm font-medium text-slate-500">{{ $t('لا توجد قواعد تصعيد بعد') }}</p>
                            <p class="text-xs text-slate-400 mt-1">{{ $t('أضف قواعد لتحويل رسائل محددة تلقائيًا إلى الموظفين.') }}</p>
                        </div>

                        <!-- Instructions List -->
                        <draggable
                            v-else
                            v-model="localInstructions"
                            item-key="uuid"
                            handle=".drag-handle"
                            ghost-class="opacity-40"
                            class="mt-4 space-y-2"
                            @end="onDragEnd"
                        >
                            <template #item="{ element: instruction }">
                                <div
                                    class="group relative flex items-center gap-3 rounded-lg border px-4 py-3 transition-all duration-150"
                                    :class="instruction.is_enabled
                                        ? 'bg-white border-slate-200 hover:border-slate-300 hover:shadow-sm'
                                        : 'bg-slate-50/60 border-slate-100 opacity-70'"
                                >
                                    <!-- Drag Handle -->
                                    <div class="drag-handle cursor-grab active:cursor-grabbing text-slate-300 hover:text-slate-500 transition-colors py-1">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="9" cy="5" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="19" r="1"/></svg>
                                    </div>

                                    <!-- Info -->
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="font-medium text-sm text-slate-800 truncate">{{ instruction.name }}</span>
                                            <span
                                                class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wider"
                                                :class="targetBadgeClass(instruction.target_type)"
                                            >
                                                {{ formatTargetType(instruction.target_type) }}
                                            </span>
                                        </div>
                                        <p class="text-xs text-slate-500 mt-0.5 truncate">{{ instruction.description }}</p>
                                    </div>

                                    <!-- Actions -->
                                    <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity" :class="instruction.is_enabled ? '' : 'opacity-100'">
                                        <button
                                            @click="toggleInstruction(instruction)"
                                            class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-1"
                                            :class="instruction.is_enabled ? 'bg-primary' : 'bg-slate-300'"
                                        >
                                            <span
                                                class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow-sm transition-transform duration-200"
                                                :class="instruction.is_enabled ? 'translate-x-[18px]' : 'translate-x-[3px]'"
                                            />
                                        </button>
                                        <button
                                            @click="openEditModal(instruction)"
                                            class="p-1 rounded text-slate-400 hover:text-primary hover:bg-slate-50 transition-colors"
                                            :title="$t('Edit')"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        </button>
                                        <button
                                            @click="deleteInstruction(instruction)"
                                            class="p-1 rounded text-slate-400 hover:text-red-500 hover:bg-red-50 transition-colors"
                                            :title="$t('Delete')"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </draggable>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create/Edit Modal -->
        <Modal :isOpen="showCreateModal || showEditModal" @close="closeModal" :label="editingInstruction ? $t('تعديل القاعدة') : $t('إضافة قاعدة تصعيد')" :closeBtn="true">
            <form @submit.prevent="submitForm" class="space-y-5 mt-1">
                <!-- Name -->
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">{{ $t('اسم القاعدة') }}</label>
                    <input
                        v-model="form.name"
                        type="text"
                        class="w-full rounded-lg border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-800 placeholder:text-slate-400 focus:border-primary focus:ring-1 focus:ring-primary/30 focus:outline-none transition-colors"
                        :placeholder="$t('مثل: الاستفسار عن الأسعار')"
                        required
                    />
                </div>

                <!-- Description -->
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">{{ $t('متى يجب التصعيد؟') }}</label>
                    <p class="text-xs text-slate-500 mb-1.5">{{ $t('صف متى يجب التحويل إلى موظف. سيطابق الذكاء الاصطناعي الرسائل مع هذا الوصف.') }}</p>
                    <textarea
                        v-model="form.description"
                        rows="3"
                        class="w-full rounded-lg border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-800 placeholder:text-slate-400 focus:border-primary focus:ring-1 focus:ring-primary/30 focus:outline-none transition-colors resize-none"
                        :placeholder="$t('مثل: عندما يسأل العميل عن الأسعار أو يعترض على التكلفة أو يطلب عرض سعر')"
                        required
                    />
                </div>

                <!-- Target Type -->
                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">{{ $t('التحويل إلى') }}</label>
                    <div class="grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            @click="form.target_type = 'auto_assignment'"
                            class="rounded-lg border px-3 py-3 text-center text-sm transition-all duration-150"
                            :class="form.target_type === 'auto_assignment'
                                ? 'border-primary bg-primary/5 text-primary font-semibold ring-1 ring-primary/20'
                                : 'border-slate-200 text-slate-600 hover:border-slate-300'"
                        >
                            <div class="font-medium text-xs">{{ $t('قائمة الانتظار التلقائية') }}</div>
                            <div class="text-[10px] mt-0.5 opacity-70">{{ $t('التوزيع بالتناوب') }}</div>
                        </button>
                        <button
                            type="button"
                            @click="form.target_type = 'specific_agent'"
                            class="rounded-lg border px-3 py-3 text-center text-sm transition-all duration-150"
                            :class="form.target_type === 'specific_agent'
                                ? 'border-primary bg-primary/5 text-primary font-semibold ring-1 ring-primary/20'
                                : 'border-slate-200 text-slate-600 hover:border-slate-300'"
                        >
                            <div class="font-medium text-xs">{{ $t('Specific Agent') }}</div>
                            <div class="text-[10px] mt-0.5 opacity-70">{{ $t('اختر موظفًا') }}</div>
                        </button>
                    </div>
                </div>

                <!-- Agent selector (only for specific_agent) -->
                <div v-if="form.target_type === 'specific_agent'">
                    <label class="block text-sm font-semibold text-slate-700 mb-1.5">{{ $t('Select Agent') }}</label>
                    <select
                        v-model="form.target_agent_id"
                        class="w-full rounded-lg border border-slate-200 bg-white px-3.5 py-2.5 text-sm text-slate-800 focus:border-primary focus:ring-1 focus:ring-primary/30 focus:outline-none transition-colors"
                    >
                        <option :value="null">{{ $t("— اختر موظفًا —") }}</option>
                        <option v-for="agent in agents" :key="agent.id" :value="agent.id">{{ agent.name }}</option>
                    </select>
                </div>

                <!-- Actions -->
                <div class="flex justify-end gap-2.5 pt-3 border-t border-slate-100">
                    <button type="button" @click="closeModal" class="rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-50 transition-colors">
                        {{ $t('Cancel') }}
                    </button>
                    <button type="submit" class="rounded-lg bg-primary px-5 py-2.5 text-sm font-medium text-white shadow-sm hover:bg-[#045a47] transition-colors">
                        {{ editingInstruction ? $t('Save Changes') : $t('إضافة قاعدة') }}
                    </button>
                </div>
            </form>
        </Modal>
    </SettingLayout>
</template>

<script setup>
import SettingLayout from './../Layout.vue';
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import draggable from 'vuedraggable';
import Modal from '@/Components/Modal.vue';
import { trans } from 'laravel-vue-i18n';

const props = defineProps(['instructions', 'agents', 'aimodule', 'fbmodule']);

const localInstructions = ref([...props.instructions]);

watch(() => props.instructions, (newList) => {
    localInstructions.value = [...newList];
}, { deep: true });

const showCreateModal = ref(false);
const showEditModal = ref(false);
const editingInstruction = ref(null);

const defaultForm = () => ({
    name: '',
    description: '',
    target_type: 'auto_assignment',
    target_agent_id: null,
    is_enabled: true,
});

const form = ref(defaultForm());

function targetBadgeClass(type) {
    const classes = {
        auto_assignment: 'bg-blue-50 text-blue-600 border border-blue-100',
        specific_agent: 'bg-emerald-50 text-emerald-600 border border-emerald-100',
    };
    return classes[type] || classes.auto_assignment;
}

function formatTargetType(type) {
    const types = {
        auto_assignment: 'قائمة الانتظار التلقائية',
        specific_agent: 'Agent',
    };
    return types[type] || type;
}

function openEditModal(instruction) {
    editingInstruction.value = instruction;
    form.value = {
        name: instruction.name,
        description: instruction.description,
        target_type: instruction.target_type,
        target_agent_id: instruction.target_agent_id,
        is_enabled: instruction.is_enabled,
    };
    showEditModal.value = true;
}

function closeModal() {
    showCreateModal.value = false;
    showEditModal.value = false;
    editingInstruction.value = null;
    form.value = defaultForm();
}

function submitForm() {
    if (editingInstruction.value) {
        router.put(`/automation/escalation/${editingInstruction.value.uuid}`, form.value, {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    } else {
        router.post('/automation/escalation', form.value, {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        });
    }
}

function toggleInstruction(instruction) {
    router.post(`/automation/escalation/${instruction.uuid}/toggle`, {
        is_enabled: !instruction.is_enabled,
    }, { preserveScroll: true });
}

function deleteInstruction(instruction) {
    if (!confirm('هل أنت متأكد من حذف هذه القاعدة؟')) return;
    router.delete(`/automation/escalation/${instruction.uuid}`, { preserveScroll: true });
}

function onDragEnd() {
    const orderedUuids = localInstructions.value.map(i => i.uuid);
    router.post('/automation/escalation/reorder', {
        ordered_uuids: orderedUuids,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            localInstructions.value.forEach((i, idx) => {
                i.priority = idx;
            });
        },
    });
}
</script>
