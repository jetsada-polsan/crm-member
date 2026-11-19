<template>
  <div ref="rootRef" class="nd-wrap">
    <button
      ref="triggerRef"
      type="button"
      :class="['nd-trigger', { 'is-open': open, 'has-value': Boolean(modelValue) }]"
      :disabled="disabled"
      @click="toggle"
    >
      <span class="nd-icon" aria-hidden="true">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="4" width="18" height="18" rx="4" />
          <line x1="16" y1="2" x2="16" y2="6" />
          <line x1="8" y1="2" x2="8" y2="6" />
          <line x1="3" y1="10" x2="21" y2="10" />
        </svg>
      </span>
      <span class="nd-text">{{ displayLabel }}</span>
      <span
        v-if="modelValue && clearable && !disabled"
        class="nd-clear"
        role="button"
        tabindex="0"
        title="ล้างวันที่"
        @click.stop="clearValue"
        @keydown.enter.stop.prevent="clearValue"
      >
        ✕
      </span>
      <span v-else class="nd-caret" aria-hidden="true">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="6 9 12 15 18 9" />
        </svg>
      </span>
    </button>

    <Teleport to="body">
      <div v-if="open" class="nd-backdrop" @click="close"></div>
      <div v-if="open" ref="popRef" class="nd-popover" :style="popoverStyle" @click.stop>
        <div class="nd-head">
          <button type="button" class="nd-nav-btn" aria-label="เดือนก่อนหน้า" @click="shiftMonth(-1)">‹</button>
          <div class="nd-month-year">
            <select v-model.number="viewMonth" class="nd-mini-select" aria-label="เลือกเดือน">
              <option v-for="(mName, idx) in THAI_MONTHS" :key="mName" :value="idx">{{ mName }}</option>
            </select>
            <select v-model.number="viewYear" class="nd-mini-select" aria-label="เลือกปี พ.ศ.">
              <option v-for="yr in yearOptions" :key="yr" :value="yr">พ.ศ. {{ yr + 543 }}</option>
            </select>
          </div>
          <button type="button" class="nd-nav-btn" aria-label="เดือนถัดไป" @click="shiftMonth(1)">›</button>
        </div>

        <div class="nd-weekdays">
          <span v-for="d in THAI_DAYS" :key="d">{{ d }}</span>
        </div>

        <div class="nd-days">
          <button
            v-for="cell in calendarCells"
            :key="cell.iso"
            type="button"
            :disabled="cell.disabled"
            :class="[
              'nd-day',
              {
                'is-other': !cell.inMonth,
                'is-today': cell.iso === todayIso,
                'is-selected': cell.iso === modelValue,
              },
            ]"
            @click="pick(cell.iso)"
          >
            {{ cell.day }}
          </button>
        </div>

        <div class="nd-foot">
          <button type="button" class="nd-foot-pill" @click="pick(todayIso)">วันนี้</button>
          <button type="button" class="nd-foot-pill" @click="pick(tomorrowIso)">พรุ่งนี้</button>
          <button v-if="clearable" type="button" class="nd-foot-pill ghost" @click="clearValue">ล้างค่า</button>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
  modelValue: { type: String, default: '' },
  min: { type: String, default: '' },
  max: { type: String, default: '' },
  placeholder: { type: String, default: 'เลือกวันที่...' },
  clearable: { type: Boolean, default: true },
  disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'change']);

const THAI_DAYS = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];
const THAI_MONTHS = [
  'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
  'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม',
];
const THAI_SHORT_MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

const rootRef = ref(null);
const triggerRef = ref(null);
const popRef = ref(null);
const open = ref(false);
const popoverStyle = ref({});

function toIso(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function parseIso(str) {
  if (!str || !/^\d{4}-\d{2}-\d{2}$/.test(str)) return null;
  const [y, m, d] = str.split('-').map(Number);
  return new Date(y, m - 1, d);
}

const now = new Date();
const todayIso = toIso(now);
const tomorrowIso = toIso(new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1));

const initDate = parseIso(props.modelValue) || now;
const viewYear = ref(initDate.getFullYear());
const viewMonth = ref(initDate.getMonth());

watch(
  () => props.modelValue,
  (val) => {
    const d = parseIso(val);
    if (d) {
      viewYear.value = d.getFullYear();
      viewMonth.value = d.getMonth();
    }
  }
);

const yearOptions = computed(() => {
  const cur = now.getFullYear();
  const list = [];
  for (let y = cur - 4; y <= cur + 3; y++) list.push(y);
  return list;
});

const displayLabel = computed(() => {
  const d = parseIso(props.modelValue);
  if (!d) return props.placeholder;
  return `${d.getDate()} ${THAI_SHORT_MONTHS[d.getMonth()]} ${d.getFullYear() + 543}`;
});

const calendarCells = computed(() => {
  const y = viewYear.value;
  const m = viewMonth.value;
  const firstDow = new Date(y, m, 1).getDay();
  const daysInMonth = new Date(y, m + 1, 0).getDate();
  const prevDays = new Date(y, m, 0).getDate();
  const cells = [];

  for (let i = firstDow - 1; i >= 0; i--) {
    const dt = new Date(y, m - 1, prevDays - i);
    const iso = toIso(dt);
    cells.push({ day: dt.getDate(), iso, inMonth: false, disabled: isOutOfRange(iso) });
  }
  for (let day = 1; day <= daysInMonth; day++) {
    const dt = new Date(y, m, day);
    const iso = toIso(dt);
    cells.push({ day, iso, inMonth: true, disabled: isOutOfRange(iso) });
  }
  const rem = (7 - (cells.length % 7)) % 7;
  for (let day = 1; day <= rem; day++) {
    const dt = new Date(y, m + 1, day);
    const iso = toIso(dt);
    cells.push({ day, iso, inMonth: false, disabled: isOutOfRange(iso) });
  }
  return cells;
});

function isOutOfRange(iso) {
  if (props.min && iso < props.min) return true;
  if (props.max && iso > props.max) return true;
  return false;
}

function shiftMonth(delta) {
  const d = new Date(viewYear.value, viewMonth.value + delta, 1);
  viewYear.value = d.getFullYear();
  viewMonth.value = d.getMonth();
}

function positionPopover() {
  if (!triggerRef.value) return;
  const rect = triggerRef.value.getBoundingClientRect();
  const popWidth = 310;
  const popHeight = 345;
  let left = rect.left;
  if (left + popWidth > window.innerWidth - 12) {
    left = Math.max(12, window.innerWidth - popWidth - 12);
  }
  let top = rect.bottom + 8;
  if (top + popHeight > window.innerHeight - 12 && rect.top - popHeight - 8 > 12) {
    top = rect.top - popHeight - 8;
  }
  popoverStyle.value = {
    top: `${Math.round(top)}px`,
    left: `${Math.round(left)}px`,
  };
}

function toggle() {
  if (props.disabled) return;
  open.value = !open.value;
  if (open.value) {
    const d = parseIso(props.modelValue) || now;
    viewYear.value = d.getFullYear();
    viewMonth.value = d.getMonth();
    nextTick(positionPopover);
  }
}

function close() {
  open.value = false;
}

function pick(iso) {
  if (isOutOfRange(iso)) return;
  emit('update:modelValue', iso);
  emit('change', iso);
  open.value = false;
}

function clearValue() {
  emit('update:modelValue', '');
  emit('change', '');
  open.value = false;
}

function onKeyDown(e) {
  if (e.key === 'Escape' && open.value) open.value = false;
}

onMounted(() => window.addEventListener('keydown', onKeyDown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeyDown));
</script>

<style scoped>
.nd-wrap {
  position: relative;
  width: 100%;
}

.nd-trigger {
  width: 100%;
  min-height: 44px;
  border: 1px solid #d5e0ec;
  border-radius: 14px;
  background: linear-gradient(180deg, #ffffff 0%, #f9fbfd 100%);
  padding: 9px 13px;
  display: flex;
  align-items: center;
  gap: 9px;
  color: #64748b;
  font-size: 14.5px;
  font-weight: 500;
  text-align: left;
  transition: all 0.16s ease;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
}

.nd-trigger.has-value {
  color: #0f172a;
  font-weight: 600;
}

.nd-trigger:hover:not(:disabled) {
  border-color: #7dd3fc;
  background: #ffffff;
}

.nd-trigger.is-open {
  border-color: #0284c7;
  background: #ffffff;
  box-shadow: 0 0 0 4px rgba(2, 132, 199, 0.14);
}

.nd-icon {
  color: #0284c7;
  display: inline-flex;
  align-items: center;
  flex-shrink: 0;
}

.nd-text {
  flex: 1;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.nd-caret {
  color: #64748b;
  display: inline-flex;
  align-items: center;
  transition: transform 0.16s ease;
}

.nd-trigger.is-open .nd-caret {
  transform: rotate(180deg);
  color: #0284c7;
}

.nd-clear {
  width: 22px;
  height: 22px;
  border-radius: 999px;
  background: #eef2f7;
  color: #475569;
  font-size: 11px;
  display: inline-grid;
  place-items: center;
  flex-shrink: 0;
}

.nd-clear:hover {
  background: #ffe4e6;
  color: #e11d48;
}

.nd-backdrop {
  position: fixed;
  inset: 0;
  z-index: 199;
}

.nd-popover {
  position: fixed;
  z-index: 200;
  width: 310px;
  background: #ffffff;
  border: 1px solid #dbe5f0;
  border-radius: 20px;
  padding: 14px;
  box-shadow: 0 20px 48px rgba(15, 23, 42, 0.18), 0 4px 12px rgba(2, 132, 199, 0.08);
  animation: ndPop 0.14s ease-out;
}

@keyframes ndPop {
  from {
    opacity: 0;
    transform: translateY(-6px) scale(0.98);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

.nd-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  margin-bottom: 10px;
}

.nd-nav-btn {
  width: 32px;
  height: 32px;
  border-radius: 999px;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  color: #334155;
  font-size: 18px;
  line-height: 1;
  display: grid;
  place-items: center;
  flex-shrink: 0;
}

.nd-nav-btn:hover {
  background: #e0f2fe;
  border-color: #7dd3fc;
  color: #0284c7;
}

.nd-month-year {
  display: flex;
  gap: 5px;
  flex: 1;
  justify-content: center;
}

.nd-mini-select {
  border: 1px solid #e2e8f0 !important;
  border-radius: 10px !important;
  background-color: #f8fafc !important;
  padding: 4px 24px 4px 8px !important;
  font-size: 12.5px !important;
  font-weight: 700 !important;
  min-height: 32px !important;
  color: #0f172a !important;
}

.nd-weekdays {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  text-align: center;
  font-size: 11.5px;
  font-weight: 700;
  color: #64748b;
  margin-bottom: 6px;
}

.nd-days {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 3px;
}

.nd-day {
  border: 0;
  background: transparent;
  height: 34px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 600;
  color: #0f172a;
  transition: all 0.12s ease;
}

.nd-day:hover:not(:disabled) {
  background: #e0f2fe;
  color: #0284c7;
}

.nd-day.is-other {
  color: #cbd5e1;
  font-weight: 500;
}

.nd-day.is-today {
  border: 1.5px solid #0284c7;
  color: #0284c7;
}

.nd-day.is-selected {
  background: linear-gradient(135deg, #0284c7, #0ea5e9) !important;
  color: #ffffff !important;
  box-shadow: 0 4px 10px rgba(2, 132, 199, 0.3);
}

.nd-day:disabled {
  opacity: 0.3;
  cursor: not-allowed;
}

.nd-foot {
  display: flex;
  gap: 6px;
  margin-top: 12px;
  padding-top: 10px;
  border-top: 1px solid #edf2f7;
}

.nd-foot-pill {
  flex: 1;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  color: #0284c7;
  border-radius: 999px;
  padding: 5px 8px;
  font-size: 12px;
  font-weight: 700;
}

.nd-foot-pill:hover {
  background: #e0f2fe;
  border-color: #7dd3fc;
}

.nd-foot-pill.ghost {
  color: #64748b;
}
</style>
