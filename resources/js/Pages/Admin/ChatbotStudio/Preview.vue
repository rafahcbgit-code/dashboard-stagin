<script setup>
import { computed, ref, watch } from 'vue';
import { RotateCcw, Send, ExternalLink } from 'lucide-vue-next';

const props = defineProps({ blueprint: Object });
const entries = ref([]);
const current = ref(null);
const questionIndex = ref(0);
const answer = ref('');
const variables = ref({});
const error = ref('');
const screens = computed(() => props.blueprint?.screens ?? []);
const questions = computed(() => current.value?.questions ?? []);
const question = computed(() => questions.value[questionIndex.value]);
const inputType = computed(() => ({ phone: 'tel', number: 'number', date: 'date', email: 'email' }[question.value?.input_type] ?? 'text'));

function append(content, role = 'assistant', extra = {}) {
    entries.value.push({ content, role, ...extra });
}
function enter(key, depth = 0) {
    if (depth > 200) { error.value = 'Automatic cycle detected.'; return; }
    const screen = screens.value.find(s => s.key === key);
    if (!screen) { error.value = `Missing flow: ${key}`; return; }
    current.value = screen;
    questionIndex.value = 0;
    const interactive = screen.choices?.length || screen.cta;
    const messages = [...(screen.messages ?? [])];
    if (interactive) messages.pop();
    messages.forEach(message => append(message));
    if (screen.questions?.length) append(screen.questions[0].text);
    else finish(depth);
}
function finish(depth = 0) {
    const screen = current.value;
    if (screen.choices?.length || screen.cta) {
        append(screen.messages?.at(-1) ?? '', 'assistant', { cta: screen.cta });
    }
    if (screen.next) enter(screen.next, depth + 1);
}
function choose(choice) {
    append(choice.label, 'user');
    error.value = '';
    enter(choice.target);
}
function submit() {
    if (!answer.value.trim() || !question.value) return;
    variables.value[question.value.variable] = answer.value;
    append(answer.value, 'user');
    answer.value = '';
    questionIndex.value++;
    if (question.value) append(question.value.text);
    else finish();
}
function reset() {
    entries.value = [];
    current.value = null;
    questionIndex.value = 0;
    variables.value = {};
    answer.value = '';
    error.value = '';
    if (props.blueprint?.start) enter(props.blueprint.start);
}
function safeUrl(url) {
    try { return new URL(url).protocol === 'https:' ? url : null; } catch { return null; }
}
watch(() => props.blueprint, reset, { immediate: true });
</script>

<template>
    <section class="preview" :aria-label="$t('Chatbot preview')">
        <header><span>{{ current?.name ?? $t('Preview') }}</span><button type="button" :title="$t('Restart preview')" :aria-label="$t('Restart preview')" @click="reset"><RotateCcw :size="17" /></button></header>
        <div class="transcript" aria-live="polite">
            <div v-for="(entry, index) in entries" :key="index" class="bubble" :class="entry.role" dir="auto">
                {{ entry.content }}
                <a v-if="entry.cta && safeUrl(entry.cta.url)" :href="safeUrl(entry.cta.url)" target="_blank" rel="noopener noreferrer"><ExternalLink :size="15" />{{ entry.cta.label }}</a>
            </div>
            <p v-if="error" class="error" role="alert">{{ error }}</p>
            <div v-if="!question && current?.choices?.length" class="choices" :class="{ list: current.choices.length > 3 }">
                <span v-if="current.choices.length > 3" class="list-label">{{ current.list_label }}</span>
                <button v-for="(choice, index) in current.choices" :key="index" type="button" dir="auto" @click="choose(choice)">{{ choice.label }}</button>
            </div>
        </div>
        <form v-if="question" class="reply" @submit.prevent="submit">
            <input v-model="answer" :type="inputType" :aria-label="question.text" dir="auto" required />
            <button type="submit" :title="$t('Send answer')" :aria-label="$t('Send answer')"><Send :size="18" /></button>
        </form>
        <details v-if="Object.keys(variables).length" class="variables"><summary>{{ $t('Answers') }}</summary><dl><template v-for="(value, key) in variables" :key="key"><dt>{{ key }}</dt><dd dir="auto">{{ value }}</dd></template></dl></details>
    </section>
</template>

<style scoped>
.preview{display:flex;flex-direction:column;min-height:420px;max-height:720px;border:1px solid #dfe4e7;border-radius:6px;background:#f5f8f7;overflow:hidden;font-size:14px;letter-spacing:0}
header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;background:white;border-bottom:1px solid #dfe4e7;font-weight:600;overflow-wrap:anywhere}
button{display:inline-flex;align-items:center;justify-content:center;min-width:36px;min-height:36px;border-radius:4px;flex-shrink:0}button:hover{background:#e3ece7}button:focus-visible,a:focus-visible{outline:2px solid #087f5b;outline-offset:2px}
.transcript{display:flex;flex-direction:column;gap:12px;padding:16px;overflow:auto;flex:1;min-height:0}.bubble{max-width:92%;padding:10px 12px;white-space:pre-wrap;overflow-wrap:anywhere;border-radius:6px;background:white;border:1px solid #e0e7e3;align-self:flex-start;flex-shrink:0}.bubble.user{align-self:flex-end;background:#dff3e9;border-color:#bde2d0}.bubble a{display:flex;align-items:center;gap:8px;color:#087f5b;margin-top:10px;text-decoration:underline}.choices{display:flex;flex-wrap:wrap;gap:6px;flex-shrink:0}.choices button{background:white;color:#087f5b;border:1px solid #b7d7ca;max-width:100%;padding:8px 12px;overflow-wrap:anywhere;white-space:normal}.choices.list{flex-direction:column}.list-label{font-size:12px;color:#52665c}.reply{display:flex;gap:8px;padding:12px;background:white;border-top:1px solid #dfe4e7}.reply input{flex:1;width:0;min-width:0;border:1px solid #bdc9c3;border-radius:4px;padding:8px}.reply button{color:#087f5b}.variables{padding:12px;font-size:12px;border-top:1px solid #dfe4e7}.variables dl{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,2fr);gap:4px;margin-top:8px;overflow-wrap:anywhere}.error{color:#ae263b}
</style>
