<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { Plus, Search, Send, Paperclip, X, Save, Upload, RefreshCw, LoaderCircle, Trash2, ArrowRight, GitBranch, MessageSquare, Code, Check, Square } from 'lucide-vue-next';
import AppLayout from '../Layout/App.vue';
import Preview from './Preview.vue';
import TokenUsage from './TokenUsage.vue';
import FlowAdjustments from './FlowAdjustments.vue';
import { useAdminPermissions } from '@/Composables/useAdminPermissions';

const props = defineProps({ drafts: { type: Array, default: () => [] }, ai: { type: Object, default: () => ({}) } });
const { can } = useAdminPermissions();
const base = '/admin/chatbot-studio';
const drafts = ref(props.drafts);
const organizations = ref([]);
const search = ref('');
const selectedOrg = ref(null);
const draft = ref(null);
const message = ref('');
const url = ref('');
const file = ref(null);
const fileInput = ref(null);
const busy = ref(false);
const errors = ref([]);
const json = ref('');
const tab = ref('flows');
const selectedKey = ref('');
const confirmPublish = ref(false);
const orgLoading = ref(false);
let pollTimer;
let searchTimer;
let searchAbort;
let disposed = false;
const generating = computed(() => draft.value?.status === 'generating');
const published = computed(() => draft.value?.status === 'published');
const editable = computed(() => can('organizations', 'edit') && !generating.value && !published.value && !busy.value);
const blueprint = computed(() => { try { return JSON.parse(json.value); } catch { return null; } });
const screens = computed(() => Array.isArray(blueprint.value?.screens) ? blueprint.value.screens : []);
const screen = computed(() => screens.value.find(item => item.key === selectedKey.value));
const dirty = computed(() => !!draft.value?.blueprint && JSON.stringify(blueprint.value) !== JSON.stringify(draft.value.blueprint));
const triggerCount = computed(() => new Set([blueprint.value?.trigger, ...screens.value.flatMap(s => (s.choices ?? []).map(c => c.label))].filter(Boolean).map(s => s.trim().toLowerCase())).size);
const generationErrors = computed(() => Object.values(draft.value?.errors ?? {}).flat());
const canPublish = computed(() => editable.value && draft.value?.status === 'draft' && !!blueprint.value && !dirty.value && !blueprint.value.issues?.length);

function errorMessages(error) {
    if (error.response?.status === 419) return ['Session expired. Sign in again before continuing.'];
    return Object.values(error.response?.data?.errors ?? {}).flat().length
        ? Object.values(error.response.data.errors).flat()
        : [error.response?.data?.message ?? error.message ?? 'Request failed.'];
}
async function run(action) {
    busy.value = true;
    errors.value = [];
    try { await action(); } catch (error) { errors.value = errorMessages(error); }
    finally { busy.value = false; }
}
function acceptDraft(value) {
    draft.value = value;
    json.value = value.blueprint ? JSON.stringify(value.blueprint, null, 2) : '';
    if (!value.blueprint?.screens?.some(s => s.key === selectedKey.value)) selectedKey.value = value.blueprint?.start ?? '';
    window.history.replaceState({}, '', `${base}?draft=${value.id}`);
    schedulePoll();
}
async function refreshDrafts() {
    const response = await axios.get(`${base}/drafts`);
    drafts.value = response.data;
}
function schedulePoll() {
    clearTimeout(pollTimer);
    if (!disposed && generating.value) pollTimer = setTimeout(poll, 2000);
}
async function poll() {
    const id = draft.value?.id;
    if (!id || !generating.value || disposed) return;
    try {
        const { data } = await axios.get(`${base}/drafts/${id}`);
        if (draft.value?.id !== id || disposed) return;
        acceptDraft(data);
        if (data.status !== 'generating') await refreshDrafts();
    } catch (error) {
        errors.value = errorMessages(error);
        if (!error.response || error.response.status >= 500) schedulePoll();
    }
}
async function findOrganizations() {
    searchAbort?.abort();
    const controller = new AbortController();
    searchAbort = controller;
    orgLoading.value = true;
    try {
        const { data } = await axios.get(`${base}/organizations`, { params: { search: search.value }, signal: controller.signal });
        organizations.value = data;
    } catch (error) { if (!axios.isCancel(error)) errors.value = errorMessages(error); }
    finally { if (searchAbort === controller) orgLoading.value = false; }
}
function queueSearch() { clearTimeout(searchTimer); searchTimer = setTimeout(findOrganizations, 250); }
function discardAllowed() { return !dirty.value || window.confirm('Discard unsaved changes?'); }
function newDraft() {
    if (!discardAllowed()) return;
    clearTimeout(pollTimer);
    draft.value = null; json.value = ''; selectedKey.value = ''; errors.value = []; message.value = ''; url.value = ''; clearFile();
    window.history.replaceState({}, '', base);
}
async function loadDraft(item) {
    if (!discardAllowed()) return;
    await run(async () => {
        const { data } = await axios.get(`${base}/drafts/${item.id}`);
        selectedOrg.value = { id: data.organization_id, name: item.organization_name ?? `#${data.organization_id}` };
        acceptDraft(data);
    });
}
function clearFile() { file.value = null; if (fileInput.value) fileInput.value.value = ''; }
async function send() {
    if (!props.ai.configured || !selectedOrg.value || !editable.value || dirty.value || (!message.value.trim() && !url.value.trim() && !file.value)) return;
    await run(async () => {
        const form = new FormData();
        form.append('organization_id', selectedOrg.value.id);
        form.append('message', message.value);
        if (url.value.trim()) form.append('url', url.value.trim());
        if (file.value) form.append('file', file.value);
        if (draft.value) { form.append('draft_id', draft.value.id); form.append('version', draft.value.version); }
        const { data } = await axios.post(`${base}/generate`, form);
        acceptDraft(data);
        message.value = ''; url.value = ''; clearFile();
        await refreshDrafts();
    });
}
async function save() {
    if (!blueprint.value) { errors.value = ['Invalid JSON.']; return; }
    await run(async () => {
        const { data } = await axios.put(`${base}/drafts/${draft.value.id}`, { version: draft.value.version, blueprint: blueprint.value });
        acceptDraft(data);
    });
}
async function retryGeneration() {
    if (!editable.value || dirty.value || !props.ai.configured || draft.value?.status !== 'failed' || message.value.trim() || url.value.trim() || file.value) return;
    await run(async () => {
        acceptDraft((await axios.post(`${base}/drafts/${draft.value.id}/retry`, { version: draft.value.version })).data);
        await refreshDrafts();
    });
}
async function publish() {
    if (!canPublish.value) return;
    confirmPublish.value = false;
    await run(async () => {
        await axios.post(`${base}/drafts/${draft.value.id}/publish`, { version: draft.value.version });
        acceptDraft((await axios.get(`${base}/drafts/${draft.value.id}`)).data);
        await refreshDrafts();
    });
}
async function cancel() {
    await run(async () => acceptDraft((await axios.post(`${base}/drafts/${draft.value.id}/cancel`, { version: draft.value.version })).data));
}
function mutate(callback) {
    if (!editable.value || !blueprint.value) return;
    const value = structuredClone(blueprint.value);
    callback(value);
    json.value = JSON.stringify(value, null, 2);
}
function editScreen(callback) { mutate(value => callback(value.screens.find(s => s.key === selectedKey.value))); }
function addScreen() {
    let key = `flow_${screens.value.length + 1}`;
    while (screens.value.some(s => s.key === key)) key += '_new';
    mutate(value => value.screens.push({ key, name: 'New flow', messages: [''], questions: [], choices: [], next: null, cta: null, list_label: null }));
    selectedKey.value = key;
}
function removeScreen() {
    const key = selectedKey.value;
    mutate(value => { value.screens = value.screens.filter(s => s.key !== key); });
    selectedKey.value = screens.value[0]?.key ?? '';
}
function preventLeave(event) { if (dirty.value) { event.preventDefault(); event.returnValue = ''; } }
onMounted(async () => {
    await findOrganizations();
    const id = new URLSearchParams(window.location.search).get('draft');
    if (id) await loadDraft(drafts.value.find(d => d.id === id) ?? { id });
    window.addEventListener('beforeunload', preventLeave);
});
onBeforeUnmount(() => { disposed = true; clearTimeout(pollTimer); clearTimeout(searchTimer); searchAbort?.abort(); window.removeEventListener('beforeunload', preventLeave); });
</script>

<template>
    <Head :title="$t('Chatbot Studio')" />
    <AppLayout>
        <main class="studio">
            <header class="studio-header">
                <div><h1>{{ $t('Chatbot Studio') }}</h1><div class="subtitle">{{ selectedOrg?.name ?? $t('Select organization') }}<span v-if="selectedOrg"> #{{ selectedOrg.id }}</span></div></div>
                <div class="commands">
                    <span v-if="draft" class="status" :class="draft.status">{{ $t(draft.status) }}</span>
                    <span v-if="dirty" class="unsaved">{{ $t('Unsaved changes') }}</span>
                    <button v-if="draft?.blueprint" class="command" :disabled="!editable || !dirty" @click="save"><Save :size="16" />{{ $t('Save') }}</button>
                    <button class="command primary" :disabled="!canPublish" @click="confirmPublish = true"><Upload :size="16" />{{ $t('Publish') }}</button>
                </div>
            </header>
            <div v-if="!ai.configured" class="setup-notice" role="status"><span>{{ $t(ai.provider_id === 'deepseek' ? 'DeepSeek API key and paid usage confirmation required' : 'Gemini API key required') }}</span><a :href="ai.provider_id === 'deepseek' ? 'https://platform.deepseek.com/' : 'https://aistudio.google.com/api-keys'" target="_blank" rel="noopener noreferrer">{{ ai.provider_id === 'deepseek' ? 'DeepSeek' : $t('Google AI Studio') }}</a></div>
            <div v-if="errors.length" class="error-banner" role="alert"><ul><li v-for="(error, index) in errors" :key="index">{{ error }}</li></ul><button :aria-label="$t('Dismiss')" :title="$t('Dismiss')" @click="errors = []"><X :size="18" /></button></div>
            <div v-if="published" class="success-banner" role="status"><Check :size="18" /><span>{{ draft.result.organization_name }} · {{ draft.result.flows }} {{ $t('Flows') }} · {{ draft.result.triggers }} {{ $t('Triggers') }} · {{ $t('Start flow') }} #{{ draft.result.start_flow_id }}</span></div>
            <div class="workspace">
                <aside class="library">
                    <section><h2>{{ $t('Organization') }}</h2>
                        <div class="search"><Search :size="16" /><input v-model="search" :disabled="!!draft || busy" :placeholder="$t('Name or ID')" :aria-label="$t('Search organizations')" @input="queueSearch" /><LoaderCircle v-if="orgLoading" :size="16" class="spin" /></div>
                        <select :value="selectedOrg?.id ?? ''" :disabled="!!draft || busy" :aria-label="$t('Organization')" @change="selectedOrg = organizations.find(o => o.id === Number($event.target.value)) ?? null"><option value="">{{ $t('Select organization') }}</option><option v-if="selectedOrg && !organizations.some(o => o.id === selectedOrg.id)" :value="selectedOrg.id">{{ selectedOrg.name }} #{{ selectedOrg.id }}</option><option v-for="org in organizations" :key="org.id" :value="org.id">{{ org.name }} #{{ org.id }}</option></select>
                    </section>
                    <div class="section-heading"><h2>{{ $t('Drafts') }}</h2><button :disabled="busy" :title="$t('New draft')" :aria-label="$t('New draft')" @click="newDraft"><Plus :size="18" /></button></div>
                    <nav class="draft-list" :aria-label="$t('Drafts')"><button v-for="item in drafts" :key="item.id" :disabled="busy" :class="{ selected: item.id === draft?.id }" @click="loadDraft(item)"><strong>{{ item.organization_name ?? `#${item.organization_id}` }}</strong><span>{{ $t(item.status) }} · {{ new Date(item.updated_at).toLocaleDateString() }}</span></button><p v-if="!drafts.length" class="muted">{{ $t('No drafts') }}</p></nav>
                </aside>
                <section class="conversation">
                    <div class="section-heading"><h2>{{ $t('Conversation') }}</h2><button v-if="draft" :disabled="busy || dirty" :title="$t('Reload draft')" :aria-label="$t('Reload draft')" @click="loadDraft({ id: draft.id, organization_name: selectedOrg?.name })"><RefreshCw :size="16" /></button></div>
                    <div class="messages" aria-live="polite">
                        <div v-for="(entry, index) in draft?.conversation ?? []" :key="index" class="chat-entry" :class="entry.role"><span class="role-label">{{ $t(entry.role === 'user' ? 'You' : 'Chatbot Studio') }}</span><p dir="auto">{{ entry.content }}</p><span v-if="entry.flows" class="muted">{{ entry.flows }} {{ $t('Flows') }} · {{ entry.issues }} {{ $t('Open issues') }}</span></div>
                        <div v-if="!draft" class="empty-state"><MessageSquare :size="28" /><h3>{{ $t('New chatbot') }}</h3></div>
                        <button v-if="draft?.status === 'failed'" type="button" class="command" :disabled="!editable || dirty || !ai.configured || !!message.trim() || !!url.trim() || !!file" @click="retryGeneration"><RefreshCw :size="16" />{{ $t('Retry generation') }}</button>
                        <div v-if="generating" class="working" role="status"><LoaderCircle :size="18" class="spin" /><span>{{ $t('Generating draft') }}</span><button v-if="can('organizations', 'edit')" :disabled="busy" :title="$t('Cancel generation')" :aria-label="$t('Cancel generation')" @click="cancel"><Square :size="14" /></button></div>
                        <ul v-if="generationErrors.length" class="generation-errors" role="alert"><li v-for="(error, index) in generationErrors" :key="index">{{ error }}</li></ul>
                    </div>
                    <div v-if="draft?.sources?.length" class="sources"><span v-for="(source, index) in draft.sources" :key="index" :title="source.name"><Paperclip :size="13" />{{ source.name }}</span></div>
                    <form class="composer" @submit.prevent="send">
                        <label for="studio-message">{{ $t(draft ? 'Revision' : 'Chatbot content') }}</label><textarea id="studio-message" v-model="message" :disabled="!editable || dirty" :placeholder="$t('Message')" rows="5" maxlength="20000" dir="auto" />
                        <label for="studio-url">{{ $t('Simulator URL') }}</label><input id="studio-url" v-model="url" :disabled="!editable || dirty" type="url" placeholder="https://chatbot.chatberry.click/..." dir="ltr" />
                        <div v-if="file" class="file-name"><span>{{ file.name }}</span><button type="button" :disabled="busy" :title="$t('Remove file')" :aria-label="$t('Remove file')" @click="clearFile"><X :size="15" /></button></div>
                        <div class="composer-actions"><input ref="fileInput" class="sr-only" type="file" accept=".txt,.md,.json,.html,.htm,.pdf,.docx" :disabled="!editable || dirty" @change="file = $event.target.files[0] ?? null" /><button type="button" class="command" :disabled="!editable || dirty" :title="$t('Attach file')" @click="fileInput.click()"><Paperclip :size="16" />{{ $t('Attach file') }}</button><button type="submit" class="command primary" :disabled="!ai.configured || !editable || dirty || !selectedOrg || (!message.trim() && !url.trim() && !file)"><LoaderCircle v-if="busy" :size="16" class="spin" /><Send v-else :size="16" />{{ $t('Generate') }}</button></div>
                    </form>
                </section>
                <section class="review">
                    <TokenUsage v-if="draft" :usage="draft.token_usage" />
                    <div class="review-heading"><div class="tabs" role="tablist" :aria-label="$t('Draft review')"><button v-for="item in [{ key: 'flows', label: 'Flows', icon: GitBranch }, { key: 'preview', label: 'Preview', icon: MessageSquare }, { key: 'json', label: 'JSON', icon: Code }]" :key="item.key" role="tab" :aria-selected="tab === item.key" :class="{ active: tab === item.key }" @click="tab = item.key"><component :is="item.icon" :size="15" />{{ $t(item.label) }}</button></div><span v-if="blueprint" class="counts">{{ screens.length }} {{ $t('Flows') }} · {{ triggerCount }} {{ $t('Triggers') }}</span></div>
                    <div v-if="!draft?.blueprint" class="empty-state"><GitBranch :size="32" /><h3>{{ $t('No generated flows') }}</h3></div>
                    <div v-else class="review-content" role="tabpanel">
                        <FlowAdjustments :adjustments="blueprint?.adjustments ?? []" />
                        <div v-if="blueprint?.issues?.length" class="issues"><h3>{{ $t('Open issues') }} ({{ blueprint.issues.length }})</h3><label v-for="(issue, index) in blueprint.issues" :key="`${index}-${issue}`"><input type="checkbox" :disabled="!editable" :aria-label="$t('Mark resolved')" @change="mutate(value => value.issues.splice(index, 1))" /><span>{{ $t(issue) }}</span></label></div>
                        <template v-if="tab === 'flows' && blueprint">
                            <div class="bot-settings"><label>{{ $t('Chatbot name') }}<input :value="blueprint.name" :disabled="!editable" maxlength="150" dir="auto" @input="mutate(value => value.name = $event.target.value)" /></label><label>{{ $t('Start trigger') }}<input :value="blueprint.trigger" :disabled="!editable" maxlength="20" dir="auto" @input="mutate(value => value.trigger = $event.target.value)" /></label></div>
                            <label>{{ $t('Start flow') }}<select :value="blueprint.start" :disabled="!editable" @change="mutate(value => value.start = $event.target.value)"><option v-for="flow in screens" :key="flow.key" :value="flow.key">{{ flow.name }}</option></select></label>
                            <div class="flow-picker"><select v-model="selectedKey" :aria-label="$t('Selected flow')"><option v-for="flow in screens" :key="flow.key" :value="flow.key">{{ flow.name }}</option></select><button :disabled="!editable" :title="$t('Add flow')" :aria-label="$t('Add flow')" @click="addScreen"><Plus :size="18" /></button><button :disabled="!editable || screens.length < 2" :title="$t('Delete flow')" :aria-label="$t('Delete flow')" @click="removeScreen"><Trash2 :size="16" /></button></div>
                            <template v-if="screen">
                                <label>{{ $t('Flow name') }}<input :value="screen.name" :disabled="!editable" dir="auto" @input="editScreen(s => s.name = $event.target.value)" /></label>
                                <div class="section-heading"><h3>{{ $t('Messages') }}</h3><button :disabled="!editable" :title="$t('Add message')" :aria-label="$t('Add message')" @click="editScreen(s => s.messages.push(''))"><Plus :size="17" /></button></div>
                                <div v-for="(body, index) in screen.messages" :key="index" class="field-row"><textarea :value="body" :disabled="!editable" :aria-label="`${$t('Message')} ${index + 1}`" rows="3" dir="auto" @input="editScreen(s => s.messages[index] = $event.target.value)" /><button :disabled="!editable" :title="$t('Delete message')" :aria-label="$t('Delete message')" @click="editScreen(s => s.messages.splice(index, 1))"><Trash2 :size="15" /></button></div>
                                <div class="section-heading"><h3>{{ $t('Questions') }}</h3><button :disabled="!editable" :title="$t('Add question')" :aria-label="$t('Add question')" @click="editScreen(s => s.questions.push({ text: '', variable: `answer_${s.questions.length + 1}`, input_type: 'text', retry_message: 'Please try again.' }))"><Plus :size="17" /></button></div>
                                <fieldset v-for="(question, index) in screen.questions" :key="index" class="question"><legend>{{ $t('Question') }} {{ index + 1 }}</legend><label>{{ $t('Text') }}<textarea :value="question.text" :disabled="!editable" rows="2" dir="auto" @input="editScreen(s => s.questions[index].text = $event.target.value)" /></label><div class="field-row"><label>{{ $t('Variable') }}<input :value="question.variable" :disabled="!editable" @input="editScreen(s => s.questions[index].variable = $event.target.value)" /></label><label>{{ $t('Input type') }}<select :value="question.input_type" :disabled="!editable" @change="editScreen(s => s.questions[index].input_type = $event.target.value)"><option v-for="type in ['text','phone','email','number','date']" :key="type">{{ type }}</option></select></label></div><label>{{ $t('Retry message') }}<input :value="question.retry_message" :disabled="!editable" dir="auto" @input="editScreen(s => s.questions[index].retry_message = $event.target.value)" /></label><button :disabled="!editable" :title="$t('Delete question')" :aria-label="$t('Delete question')" @click="editScreen(s => s.questions.splice(index, 1))"><Trash2 :size="15" /></button></fieldset>
                                <div class="section-heading"><h3>{{ $t('Choices') }} <span class="muted">{{ screen.choices?.length > 3 ? $t('Interactive list') : $t('Interactive buttons') }}</span></h3><button :disabled="!editable || screen.choices?.length >= 10" :title="$t('Add choice')" :aria-label="$t('Add choice')" @click="editScreen(s => s.choices.push({ label: '', target: '' }))"><Plus :size="17" /></button></div>
                                <div v-for="(choice, index) in screen.choices" :key="index" class="choice-row"><label>{{ $t('Button label') }}<input :value="choice.label" :disabled="!editable" maxlength="20" dir="auto" @input="editScreen(s => s.choices[index].label = $event.target.value)" /><span class="length">{{ choice.label.length }}/20</span></label><ArrowRight :size="14" /><label>{{ $t('Portal target') }}<select :value="choice.target" :disabled="!editable" @change="editScreen(s => s.choices[index].target = $event.target.value)"><option value="">{{ $t('Select flow') }}</option><option v-for="flow in screens" :key="flow.key" :value="flow.key">{{ flow.name }}</option></select></label><button :disabled="!editable" :title="$t('Delete choice')" :aria-label="$t('Delete choice')" @click="editScreen(s => s.choices.splice(index, 1))"><Trash2 :size="15" /></button></div>
                                <label v-if="screen.choices?.length > 3">{{ $t('List button label') }}<input :value="screen.list_label" :disabled="!editable" maxlength="20" dir="auto" @input="editScreen(s => s.list_label = $event.target.value)" /></label>
                                <template v-if="screen.cta"><label>{{ $t('Website button') }}<input :value="screen.cta.label" :disabled="!editable" maxlength="20" @input="editScreen(s => s.cta.label = $event.target.value)" /></label><label>{{ $t('Website URL') }}<input :value="screen.cta.url" :disabled="!editable" type="url" dir="ltr" @input="editScreen(s => s.cta.url = $event.target.value)" /></label></template>
                                <label v-if="!screen.choices?.length">{{ $t('Next flow') }}<select :value="screen.next ?? ''" :disabled="!editable" @change="editScreen(s => s.next = $event.target.value || null)"><option value="">{{ $t('End') }}</option><option v-for="flow in screens" :key="flow.key" :value="flow.key">{{ flow.name }}</option></select></label>
                            </template>
                        </template>
                        <Preview v-if="tab === 'preview' && blueprint" :blueprint="blueprint" />
                        <template v-if="tab === 'json'"><label for="studio-json">{{ $t('Blueprint JSON') }}</label><textarea id="studio-json" v-model="json" :disabled="!editable" class="json-editor" spellcheck="false" dir="ltr" /><p v-if="!blueprint" class="generation-errors">{{ $t('Invalid JSON') }}</p></template>
                    </div>
                </section>
            </div>
            <div v-if="confirmPublish" class="modal-backdrop" @click.self="confirmPublish = false" @keydown.esc="confirmPublish = false"><section class="publish-dialog" role="dialog" aria-modal="true" aria-labelledby="publish-title"><h2 id="publish-title">{{ $t('Publish chatbot') }}</h2><dl><dt>{{ $t('Organization') }}</dt><dd>{{ selectedOrg?.name }} #{{ selectedOrg?.id }}</dd><dt>{{ $t('Flows') }}</dt><dd>{{ screens.length }}</dd><dt>{{ $t('Triggers') }}</dt><dd>{{ triggerCount }}</dd><dt>{{ $t('Start trigger') }}</dt><dd dir="auto">{{ blueprint?.trigger }}</dd></dl><div class="commands"><button class="command" @click="confirmPublish = false">{{ $t('Cancel') }}</button><button class="command primary" @click="publish"><Upload :size="16" />{{ $t('Confirm publish') }}</button></div></section></div>
        </main>
    </AppLayout>
</template>

<style scoped>
.setup-notice{display:flex;align-items:center;gap:16px;padding:12px 24px;background:#fff6dc;color:#796221;font-size:13px;flex-wrap:wrap}.setup-notice a{text-decoration:underline}
.studio{color:#202b29;background:#fff;min-height:100%;overflow:auto;letter-spacing:0;font-size:14px}.studio *{box-sizing:border-box}.studio-header{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:22px 24px;border-bottom:1px solid #dfe5e2;flex-wrap:wrap}h1{font-size:22px;font-weight:650;line-height:1.4}h2,h3{font-size:14px;font-weight:600;line-height:1.5}.subtitle{font-size:13px;color:#63736b;margin-top:4px;overflow-wrap:anywhere}.subtitle span{color:#89948e}.commands{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.studio button{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-width:32px;min-height:34px;border-radius:4px}.studio button svg{flex-shrink:0}.studio button:hover:not(:disabled){background:#edf3f0}.studio button:disabled{opacity:.45;cursor:not-allowed}.studio button:focus-visible,.studio select:focus-visible,.studio input:focus-visible,.studio textarea:focus-visible{outline:2px solid #0b8461;outline-offset:2px}.command{padding:7px 12px;border:1px solid #ccd6d0;background:#fff;white-space:normal}.command.primary{background:#087f5b;color:white;border-color:#087f5b}.command.primary:hover:not(:disabled){background:#086749}.status{font-size:12px;background:#edf0f1;color:#4b5964;padding:4px 8px;border-radius:4px}.status.published{background:#dcf3e8;color:#08774e}.status.failed{background:#fce5e8;color:#a72f44}.status.generating{background:#e4edf8;color:#285798}.unsaved{font-size:12px;color:#975c05}.workspace{display:grid;grid-template-columns:220px minmax(280px,.95fr) minmax(340px,1.25fr);min-height:calc(100vh - 94px)}.library{background:#f8faf9;border-inline-end:1px solid #dfe5e2;padding:20px 16px;min-width:0}.library section{margin-bottom:22px}.library h2{margin-bottom:10px}.search{position:relative;display:flex;align-items:center;gap:8px;border:1px solid #cfd8d3;border-radius:4px;padding:0 8px;background:white;margin-bottom:8px}.search input{border:0!important;padding-inline:0!important}.search svg{flex-shrink:0}.studio input:not([type=checkbox]),.studio textarea,.studio select{width:100%;min-width:0;font-size:13px;border:1px solid #cdd6d1;border-radius:4px;background:white;color:#27392e;padding:8px 10px;line-height:1.5}.studio input:disabled,.studio textarea:disabled,.studio select:disabled{background:#f5f7f6;color:#69786f}.studio textarea{resize:vertical}.studio select{text-overflow:ellipsis;padding-inline-end:26px}.section-heading{display:flex;align-items:center;justify-content:space-between;gap:8px;margin:12px 0 6px}.section-heading h2{margin:0}.draft-list{display:flex;flex-direction:column;gap:3px;max-height:65vh;overflow:auto}.draft-list button{display:block;text-align:start;padding:10px;border:1px solid transparent;width:100%;min-height:62px;overflow-wrap:anywhere}.draft-list button.selected{background:#e7f2ec;border-color:#c3dccf}.draft-list strong{font-weight:500;display:block;font-size:13px}.draft-list span{font-size:11px;color:#758178;display:block;margin-top:4px}.conversation{display:flex;flex-direction:column;padding:16px 20px;border-inline-end:1px solid #dfe5e2;min-width:0}.conversation>.section-heading{margin-top:0}.messages{min-height:170px;max-height:46vh;overflow:auto;flex:1;padding:8px 0 16px}.chat-entry{padding:12px 0;border-bottom:1px solid #edf0ee}.role-label{font-size:11px;font-weight:600;color:#758078}.chat-entry.assistant .role-label{color:#087f5b}.chat-entry p{white-space:pre-wrap;overflow-wrap:anywhere;margin:6px 0;font-size:13px;line-height:1.7}.composer{border-top:1px solid #e2e7e4;padding-top:14px;margin-top:auto}.studio label{display:block;font-size:12px;color:#53655a;margin:9px 0}.studio label>input,.studio label>textarea,.studio label>select{display:block;margin-top:5px}.composer>input{margin-bottom:4px}.composer-actions{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:12px;flex-wrap:wrap}.file-name{display:flex;align-items:center;justify-content:space-between;gap:8px;font-size:12px;color:#285b46;padding:6px}.file-name span{overflow-wrap:anywhere;min-width:0}.sources{display:flex;flex-direction:column;gap:5px;font-size:11px;color:#67746b;padding-bottom:12px}.sources span{display:flex;gap:5px;align-items:center;overflow-wrap:anywhere;min-width:0}.sources svg{flex-shrink:0}.working{display:flex;align-items:center;gap:8px;padding:12px 0;color:#1f6a53;font-size:13px}.working button{margin-inline-start:auto}.spin{animation:spin 1s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}.review{min-width:0;background:#fff}.review-heading{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;padding:16px 18px 12px;border-bottom:1px solid #e2e7e4}.tabs{display:flex;gap:2px}.tabs button{font-size:12px;padding:6px 9px;color:#67746c;border-bottom:2px solid transparent;border-radius:0}.tabs button.active{color:#087f5b;border-bottom-color:#087f5b}.counts{font-size:11px;color:#76837b}.review-content{padding:16px 20px;max-height:calc(100vh - 172px);overflow:auto}.empty-state{display:flex;flex-direction:column;justify-content:center;align-items:center;gap:12px;min-height:260px;color:#829088}.empty-state h3{font-size:14px;color:#65756a;font-weight:400}.bot-settings{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:12px}.flow-picker{display:flex;gap:5px;align-items:center;border-top:1px solid #dfe6e1;padding-top:16px;margin-top:16px}.flow-picker select{flex:1}.field-row{display:flex;gap:7px;align-items:flex-start;margin:8px 0}.field-row label{flex:1;min-width:0}.field-row button{flex-shrink:0}.question{padding:8px 0 12px;margin-top:12px;border-top:1px solid #e1e7e3}.question legend{font-size:11px;color:#758579}.choice-row{display:grid;grid-template-columns:minmax(0,1fr) 14px minmax(0,1fr) 30px;gap:6px;align-items:center}.choice-row label{min-width:0}.length{display:block;font-size:10px;text-align:end;color:#7e8a82;margin-top:3px}.muted{color:#849087;font-size:11px;font-weight:400}.json-editor{font-family:Consolas,monospace;min-height:520px;font-size:12px!important;line-height:1.6!important;tab-size:2}.issues{padding:12px;background:#fff6dc;border-inline-start:3px solid #d9ae31;margin-bottom:18px;color:#6b571d}.issues h3{font-size:13px}.issues label{display:flex;align-items:flex-start;gap:9px;font-size:12px;line-height:1.6;color:#766223}.issues input{margin-top:4px;flex-shrink:0}.issues span{overflow-wrap:anywhere}.error-banner,.success-banner{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:12px 24px;font-size:13px;overflow-wrap:anywhere}.error-banner{background:#fff0f2;color:#a52d43}.success-banner{background:#e4f7ed;color:#146b45;justify-content:flex-start}.error-banner button{flex-shrink:0}.generation-errors{color:#a52d43;font-size:12px;line-height:1.6;overflow-wrap:anywhere;padding:10px 0}.modal-backdrop{position:fixed;inset:0;background:#142b2659;display:flex;align-items:center;justify-content:center;padding:20px;z-index:100}.publish-dialog{background:white;border-radius:6px;width:100%;max-width:420px;padding:24px;box-shadow:0 16px 60px #142b2633}.publish-dialog h2{font-size:18px}.publish-dialog dl{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,2fr);gap:12px;padding:22px 0;font-size:13px;overflow-wrap:anywhere}.publish-dialog dt{color:#718077}.publish-dialog .commands{justify-content:flex-end}
@media(min-width:1600px){.workspace{grid-template-columns:240px minmax(320px,1fr) minmax(400px,1.2fr)}}
@media(max-width:1200px){.workspace{grid-template-columns:190px minmax(0,1fr)}.library{grid-row:span 2}.conversation{border-inline-end:0;border-bottom:1px solid #dfe5e2}.messages{max-height:360px;min-height:120px}.review-content{max-height:none}.review{grid-column:2}.review-heading{padding-inline:20px}.composer textarea{min-height:80px}.empty-state{min-height:160px}}
@media(max-width:700px){.studio-header{padding:16px;align-items:flex-start}h1{font-size:20px}.workspace{display:flex;flex-direction:column}.library{padding:14px 16px;border-inline-end:0;border-bottom:1px solid #dfe5e2}.library section{margin-bottom:10px}.draft-list{max-height:150px}.conversation,.review-content{padding:16px}.review-heading{padding:12px 16px}.messages{min-height:100px;max-height:300px}.empty-state{min-height:120px}.choice-row{grid-template-columns:minmax(0,1fr) 12px minmax(0,1fr) 28px;gap:4px}.error-banner,.success-banner{padding:12px 16px}.command{font-size:12px}.json-editor{min-height:380px}}
</style>
