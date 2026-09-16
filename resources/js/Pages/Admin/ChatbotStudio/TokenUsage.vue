<script setup>
import { computed } from 'vue';
import { ChevronDown } from 'lucide-vue-next';

const props = defineProps({ usage: { type: Object, default: null } });
const known = computed(() => (props.usage?.reported_requests ?? 0) > 0
    || (!!props.usage && props.usage.requests === 0 && !props.usage.historical_usage_unknown));
const missing = computed(() => Math.max(0, (props.usage?.requests ?? 0) - (props.usage?.reported_requests ?? 0)));
const format = value => new Intl.NumberFormat().format(value ?? 0);
const metrics = [
    ['input_tokens', 'Input tokens'], ['output_tokens', 'Output tokens'], ['thinking_tokens', 'Thinking tokens'],
    ['cached_input_tokens', 'Cached input tokens'], ['tool_input_tokens', 'Tool input tokens'],
];
</script>

<template>
    <details class="token-usage" open>
        <summary><span>{{ $t('Token usage') }}</span><strong>{{ known ? format(usage.total_tokens) : $t('Unavailable') }}</strong><ChevronDown :size="15" /></summary>
        <dl v-if="usage">
            <div v-for="[key, label] in metrics" :key="key"><dt>{{ $t(label) }}</dt><dd>{{ known ? format(usage[key]) : $t('Unavailable') }}</dd></div>
            <div><dt>{{ $t('API requests') }}</dt><dd>{{ format(usage.requests) }}</dd></div>
        </dl>
        <p v-if="missing" class="usage-warning">{{ $t('Requests without usage data') }}: {{ format(missing) }}</p>
        <p v-if="!usage || usage.historical_usage_unknown" class="usage-warning">{{ $t('Earlier usage unavailable') }}</p>
        <p v-if="usage" class="usage-since">{{ $t('Recorded since') }} {{ new Date(usage.tracked_since).toLocaleString() }}</p>
    </details>
</template>

<style scoped>
.token-usage{padding:12px 20px;border-bottom:1px solid #e2e7e4;font-size:12px;min-width:0;background:#f8fafb;color:#425260}
summary{display:flex;align-items:center;gap:10px;cursor:pointer;list-style:none;min-height:28px;overflow-wrap:anywhere}summary::-webkit-details-marker{display:none}summary span{flex:1;font-weight:600}summary strong{font-size:15px;font-variant-numeric:tabular-nums;overflow-wrap:anywhere;min-width:0}summary svg{flex-shrink:0}details:not([open]) summary svg{transform:rotate(-90deg)}
dl{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:12px 0}dl>div{min-width:0}dt{font-size:11px;overflow-wrap:anywhere;color:#657583}dd{margin:4px 0 0;font-variant-numeric:tabular-nums;overflow-wrap:anywhere;color:#27392e}.usage-warning{margin:6px 0;color:#925b15;font-size:11px;overflow-wrap:anywhere}.usage-since{margin:6px 0 0;color:#657583;font-size:11px;overflow-wrap:anywhere}
@media(max-width:700px){.token-usage{padding:12px 16px}dl{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
