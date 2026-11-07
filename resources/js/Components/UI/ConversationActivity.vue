<template>
  <section class="cb-thread-activity" :aria-label="localized('نشاط المحادثات', 'Conversation activity')">
    <div class="cb-thread-activity-header">
      <div>
        <h2>{{ localized('نشاط المحادثات', 'Conversation activity') }}</h2>
        <p>
          {{ localized('الواردة', 'Incoming') }} <b class="n">{{ formatNumber(totalIncoming) }}</b>
          <span aria-hidden="true"> · </span>
          {{ localized('الصادرة', 'Outgoing') }} <b class="n">{{ formatNumber(totalOutgoing) }}</b>
        </p>
      </div>
      <div class="cb-thread-activity-legend" :aria-label="localized('مفتاح الرسم', 'Chart legend')">
        <span><i class="cb-thread-activity-swatch incoming" />{{ localized('الواردة', 'Incoming') }}</span>
        <span><i class="cb-thread-activity-swatch outgoing" />{{ localized('الصادرة', 'Outgoing') }}</span>
      </div>
    </div>

    <nav class="cb-thread-activity-period" :aria-label="localized('فترة الرسم البياني', 'Chart period')">
      <Link v-for="option in [7, 30, 90]" :key="option" :href="`/dashboard?days=${option}`" preserve-scroll :aria-current="days === option ? 'page' : undefined">
        {{ option }} {{ localized('أيام', 'days') }}
      </Link>
    </nav>

    <div v-if="empty" class="cb-thread-activity-empty">
      <p>{{ localized('لا توجد محادثات في هذه الفترة', 'No conversations in this period') }}</p>
      <Link v-if="days < 90" :href="`/dashboard?days=${days < 30 ? 30 : 90}`" preserve-scroll class="cb-secondary-button">
        {{ localized('عرض فترة أطول', 'View a longer period') }}
      </Link>
    </div>
    <div v-else class="cb-thread-activity-scroll">
      <div class="cb-thread-activity-chart" :style="{ minWidth: buckets.length > 7 ? `${buckets.length * 54 + 48}px` : undefined }">
        <div class="cb-thread-activity-yaxis n">
          <span v-for="tick in ticks" :key="tick">{{ formatNumber(tick) }}</span>
        </div>
        <div class="cb-thread-activity-content">
          <div class="cb-thread-activity-plot">
            <span class="cb-thread-activity-grid" style="top: 0" />
            <span class="cb-thread-activity-grid" style="top: 33.3%" />
            <span class="cb-thread-activity-grid" style="top: 66.6%" />
            <div v-for="bucket in buckets" :key="bucket.key" class="cb-thread-activity-day" tabindex="0" :title="tooltip(bucket)" :aria-label="tooltip(bucket)">
              <span class="incoming" :style="{ height: `${barHeight(bucket.incoming)}%` }" />
              <span class="outgoing" :style="{ height: `${barHeight(bucket.outgoing)}%` }" />
            </div>
          </div>
          <div class="cb-thread-activity-xaxis">
            <span v-for="(bucket, index) in buckets" :key="bucket.key">{{ axisLabel(bucket, index) }}</span>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const props = defineProps({
  period: { type: Array, default: () => [] },
  inbound: { type: Array, default: () => [] },
  outbound: { type: Array, default: () => [] },
  days: { type: Number, default: 7 },
});
const { locale } = useI18n();
const localized = (ar, en) => locale.value === 'ar' ? ar : en;
const formatNumber = value => new Intl.NumberFormat('en-US').format(value);
const countAt = (values, index) => Math.max(0, Number(values[index]) || 0);
const totalIncoming = computed(() => props.inbound.reduce((sum, _, index) => sum + countAt(props.inbound, index), 0));
const totalOutgoing = computed(() => props.outbound.reduce((sum, _, index) => sum + countAt(props.outbound, index), 0));
const empty = computed(() => totalIncoming.value + totalOutgoing.value === 0);

const buckets = computed(() => {
  const length = Math.max(props.period.length, props.inbound.length, props.outbound.length);
  const bucketSize = Math.max(1, Math.ceil(length / 12));
  const result = [];
  for (let start = 0; start < length; start += bucketSize) {
    const end = Math.min(length, start + bucketSize);
    let incoming = 0;
    let outgoing = 0;
    for (let index = start; index < end; index += 1) {
      incoming += countAt(props.inbound, index);
      outgoing += countAt(props.outbound, index);
    }
    result.push({ key: start, first: props.period[start], last: props.period[end - 1], incoming, outgoing });
  }
  return result;
});
const axisMaximum = computed(() => {
  const maximum = Math.max(0, ...buckets.value.flatMap(bucket => [bucket.incoming, bucket.outgoing]));
  if (maximum <= 4) return 4;
  const magnitude = 10 ** Math.floor(Math.log10(maximum));
  const step = magnitude / 2;
  return Math.ceil(maximum / step) * step;
});
const ticks = computed(() => [axisMaximum.value, Math.round(axisMaximum.value * 2 / 3), Math.round(axisMaximum.value / 3), 0]);
const barHeight = value => value ? Math.max(2, value / axisMaximum.value * 100) : 0;
const dateLabel = value => {
  if (!value) return '';
  const date = new Date(`${String(value).slice(0, 10)}T12:00:00Z`);
  if (Number.isNaN(date.getTime())) return String(value).slice(0, 10);
  return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-SA' : 'en-US', {
    timeZone: 'UTC', day: 'numeric', month: 'short', ...(props.days <= 7 ? { weekday: 'short' } : {}),
  }).format(date);
};
const axisLabel = (bucket, index) => {
  if (props.days <= 7 && index === buckets.value.length - 1) return localized('اليوم', 'Today');
  if (bucket.first === bucket.last) return dateLabel(bucket.first);
  return `${dateLabel(bucket.first)}–${dateLabel(bucket.last)}`;
};
const tooltip = bucket => `${dateLabel(bucket.first)}${bucket.first === bucket.last ? '' : ` – ${dateLabel(bucket.last)}`}: ${localized('الواردة', 'Incoming')} ${formatNumber(bucket.incoming)}, ${localized('الصادرة', 'Outgoing')} ${formatNumber(bucket.outgoing)}`;
</script>
