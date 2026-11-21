<template>
  <div class="range-bar card">
    <div class="range-bar-row">
      <div class="range-presets" role="group" aria-label="เลือกช่วงวันที่">
        <button
          v-for="option in options"
          :key="option.id"
          type="button"
          :class="['range-pill', { selected: active === option.id && !customOpen }]"
          :aria-pressed="active === option.id && !customOpen"
          @click="setPreset(option.id)"
        >
          {{ option.label }}
        </button>
        <button
          type="button"
          :class="['range-pill', { selected: customOpen || active === 'custom' }]"
          :aria-expanded="String(customOpen)"
          @click="customOpen = !customOpen"
        >
          📅 กำหนดเอง
        </button>
      </div>

      <button
        type="button"
        class="range-summary-chip"
        title="คลิกเพื่อเลือกช่วงวันที่เอง"
        @click="customOpen = !customOpen"
      >
        <span>{{ displayRange }}</span>
        <span class="range-caret" aria-hidden="true">{{ customOpen ? '▴' : '▾' }}</span>
      </button>
    </div>

    <div v-if="customOpen" class="range-inputs">
      <div class="range-field">
        <span class="range-field-label">ตั้งแต่วันที่</span>
        <NiceDateInput
          :model-value="from"
          :clearable="false"
          placeholder="เลือกวันเริ่มต้น"
          @update:model-value="change('from', $event)"
        />
      </div>
      <div class="range-field">
        <span class="range-field-label">ถึงวันที่</span>
        <NiceDateInput
          :model-value="to"
          :min="from || ''"
          :clearable="false"
          placeholder="เลือกวันสิ้นสุด"
          @update:model-value="change('to', $event)"
        />
      </div>
    </div>

    <p v-if="invalid" class="range-error" role="alert">กรุณาเลือกวันเริ่มต้นและวันสิ้นสุดให้ถูกต้อง</p>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import NiceDateInput from '../../../shared/components/NiceDateInput.vue';

const props = defineProps({
  from: { type: String, default: '' },
  to: { type: String, default: '' },
  allowYear: { type: Boolean, default: true },
});
const emit = defineEmits(['update:from', 'update:to']);

const customOpen = ref(false);

const options = computed(() => [
  { id: 'today', label: 'วันนี้' },
  { id: '7d', label: '7 วัน' },
  { id: 'month', label: 'เดือนนี้' },
  { id: 'previous', label: 'เดือนก่อน' },
  ...(props.allowYear ? [{ id: 'year', label: 'ปีนี้' }] : []),
]);

const format = (d) =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

function rangeFor(id) {
  const today = new Date();
  if (id === 'today') return [format(today), format(today)];
  if (id === '7d') {
    const start = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 6);
    return [format(start), format(today)];
  }
  if (id === 'month') return [format(new Date(today.getFullYear(), today.getMonth(), 1)), format(today)];
  if (id === 'previous') {
    return [
      format(new Date(today.getFullYear(), today.getMonth() - 1, 1)),
      format(new Date(today.getFullYear(), today.getMonth(), 0)),
    ];
  }
  return [format(new Date(today.getFullYear(), 0, 1)), format(today)];
}

const active = computed(
  () =>
    options.value.find((o) => {
      const [a, b] = rangeFor(o.id);
      return a === props.from && b === props.to;
    })?.id || 'custom'
);

const invalid = computed(() => !props.from || !props.to || props.from > props.to);

const displayRange = computed(() =>
  invalid.value
    ? 'เลือกช่วงวันที่'
    : [props.from, props.to]
        .map((d) => new Date(`${d}T00:00:00`).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: '2-digit' }))
        .join(' – ')
);

function setPreset(id) {
  const [a, b] = rangeFor(id);
  emit('update:from', a);
  emit('update:to', b);
  customOpen.value = false;
}

function change(which, value) {
  emit(`update:${which}`, value);
}
</script>

<style scoped>
.range-bar {
  padding: 10px 14px;
}

.range-bar-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
}

.range-presets {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  align-items: center;
}

.range-pill {
  border: 1px solid transparent;
  border-radius: 999px;
  background: #f1f5f9;
  color: #475569;
  font: inherit;
  font-size: 13px;
  font-weight: 600;
  padding: 6px 13px;
  min-height: 36px;
  cursor: pointer;
  transition: background-color 0.14s ease, color 0.14s ease, border-color 0.14s ease;
}

.range-pill:hover {
  background: #e0f2fe;
  color: #0369a1;
}

.range-pill.selected {
  background: var(--blue);
  color: #ffffff;
}

.range-summary-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  border: 1px solid #bae6fd;
  background: #f0f9ff;
  color: #0369a1;
  border-radius: 999px;
  padding: 6px 13px;
  min-height: 36px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}

.range-summary-chip:hover {
  background: #e0f2fe;
}

.range-caret {
  font-size: 11px;
  opacity: 0.8;
}

.range-inputs {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid var(--border);
}

.range-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.range-field-label {
  color: #475569;
  font-size: 12.5px;
  font-weight: 600;
}

.range-error {
  color: #e11d48;
  font-size: 12.5px;
  margin: 8px 0 0;
}

@media (max-width: 600px) {
  .range-bar {
    padding: 10px 12px;
  }
  .range-presets {
    width: 100%;
  }
  .range-pill {
    flex: 1 1 auto;
    padding: 6px 10px;
    font-size: 12.5px;
  }
  .range-summary-chip {
    width: 100%;
    justify-content: center;
  }
  .range-inputs {
    grid-template-columns: 1fr;
  }
}
</style>
