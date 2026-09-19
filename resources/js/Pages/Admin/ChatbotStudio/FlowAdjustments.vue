<script setup>
import { ChevronDown } from 'lucide-vue-next';

defineProps({ adjustments: { type: Array, default: () => [] } });
const reasons = {
    distinct_trigger: 'Distinct trigger', short_label: 'Shortened label',
    list_title: 'List title', split_cta: 'Separated website action',
};
</script>

<template>
    <details v-if="adjustments.length" class="adjustments" open>
        <summary><strong>{{ $t('Flow adjustments') }} ({{ adjustments.length }})</strong><ChevronDown :size="16" /></summary>
        <ol>
            <li v-for="(entry, index) in adjustments" :key="index">
                <div class="adjustment-heading"><code>{{ entry.screen }} · {{ entry.field }}</code><span>{{ $t(reasons[entry.reason] ?? entry.reason) }}</span></div>
                <dl><div><dt>{{ $t('Original value') }}</dt><dd dir="auto">{{ entry.before || $t('Not set') }}</dd></div><div><dt>{{ $t('Adjusted value') }}</dt><dd dir="auto">{{ entry.after }}</dd></div></dl>
            </li>
        </ol>
    </details>
</template>

<style scoped>
.adjustments{margin-bottom:18px;border-block:1px solid #dfe5e2;color:#344750;font-size:12px;min-width:0}
summary{display:flex;align-items:center;justify-content:space-between;gap:8px;cursor:pointer;list-style:none;min-height:40px;overflow-wrap:anywhere}summary::-webkit-details-marker{display:none}summary svg{flex-shrink:0}details:not([open]) summary svg{transform:rotate(-90deg)}
ol{max-height:300px;overflow:auto;list-style:none;padding:0;margin:0}.adjustments li{padding:12px 0;border-top:1px solid #edf0ee}.adjustment-heading{display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;color:#667780;font-size:11px}.adjustment-heading code{overflow-wrap:anywhere;min-width:0}dl{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:8px 0 0}dt{font-size:11px;color:#718077}dd{margin:5px 0 0;white-space:pre-wrap;overflow-wrap:anywhere}
@media(max-width:700px){dl{grid-template-columns:minmax(0,1fr)}}
</style>
