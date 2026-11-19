<template>
  <div class="range-picker">
    <div class="range-heading">
      <div class="range-title-wrap">
        <span class="range-icon" aria-hidden="true">📅</span>
        <div>
          <strong>ช่วงวันที่ดูข้อมูล</strong>
          <small>เลือกช่วงเวลาด่วน หรือกดปฏิทินเพื่อกำหนดวันเอง</small>
        </div>
      </div>
      <span class="range-current">{{ displayRange }}</span>
    </div>

    <div class="range-presets" role="group" aria-label="เลือกช่วงวันที่อย่างรวดเร็ว">
      <button
        v-for="option in options"
        :key="option.id"
        type="button"
        :class="{ selected: active === option.id }"
        :aria-pressed="active === option.id"
        @click="setPreset(option.id)"
      >
        {{ option.label }}
      </button>
    </div>

    <div class="range-inputs">
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
import { computed } from 'vue';
import NiceDateInput from '../../../shared/components/NiceDateInput.vue';

const props = defineProps({
  from: { type: String, default: '' },
  to: { type: String, default: '' },
  allowYear: { type: Boolean, default: true },
});
const emit = defineEmits(['update:from', 'update:to']);

const options = computed(() => [
  { id: 'today', label: 'วันนี้' },
  { id: '7d', label: '7 วันล่าสุด' },
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
}

function change(which, value) {
  emit(`update:${which}`, value);
}
</script>

<style scoped>
.range-picker {
  padding: 18px 20px;
  border: 1px solid #d8e6f4;
  border-radius: 20px;
  background: linear-gradient(135deg, #f0f9ff 0%, #ffffff 70%);
  box-shadow: 0 6px 20px rgba(2, 132, 199, 0.05);
}

.range-heading {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  align-items: center;
  flex-wrap: wrap;
}

.range-title-wrap {
  display: flex;
  align-items: center;
  gap: 10px;
}

.range-icon {
  width: 38px;
  height: 38px;
  border-radius: 12px;
  background: #e0f2fe;
  display: grid;
  place-items: center;
  font-size: 18px;
  flex-shrink: 0;
}

.range-heading strong,
.range-heading small {
  display: block;
}

.range-heading strong {
  font-size: 15px;
  color: #0f172a;
}

.range-heading small {
  font-size: 12.5px;
  color: #64748b;
  margin-top: 1px;
}

.range-current {
  font-size: 13px;
  color: #0369a1;
  font-weight: 700;
  background: #e0f2fe;
  border: 1px solid #bae6fd;
  padding: 6px 14px;
  border-radius: 999px;
}

.range-presets {
  display: flex;
  gap: 7px;
  flex-wrap: wrap;
  margin: 14px 0;
}

.range-presets button {
  border: 1px solid #d5e1ee;
  border-radius: 999px;
  background: #ffffff;
  color: #475569;
  font: inherit;
  font-size: 13px;
  font-weight: 600;
  padding: 7px 14px;
  cursor: pointer;
  transition: all 0.14s ease;
}

.range-presets button:hover {
  border-color: #7dd3fc;
  color: #0284c7;
  background: #f0f9ff;
}

.range-presets button.selected {
  background: linear-gradient(135deg, #0284c7, #0ea5e9);
  border-color: #0284c7;
  color: #ffffff;
  box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);
}

.range-inputs {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.range-field {
  display: flex;
  flex-direction: column;
  gap: 5px;
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

@media (max-width: 560px) {
  .range-picker {
    padding: 14px;
  }
  .range-inputs {
    grid-template-columns: 1fr;
  }
}
</style>
