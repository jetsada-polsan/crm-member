<template>
  <div class="range-picker">
    <div class="range-heading"><div><strong>ช่วงวันที่</strong><small>เลือกช่วงที่ต้องการดูข้อมูล</small></div><span class="range-current">{{ displayRange }}</span></div>
    <div class="range-presets" role="group" aria-label="เลือกช่วงวันที่อย่างรวดเร็ว">
      <button v-for="option in options" :key="option.id" type="button" :class="{ selected: active === option.id }" :aria-pressed="active === option.id" @click="setPreset(option.id)">{{ option.label }}</button>
    </div>
    <div class="range-inputs">
      <label>ตั้งแต่วันที่<input type="date" :value="from" @input="change('from', $event.target.value)" /></label>
      <label>ถึงวันที่<input type="date" :value="to" :min="from || undefined" @input="change('to', $event.target.value)" /></label>
    </div>
    <p v-if="invalid" class="range-error" role="alert">กรุณาเลือกวันเริ่มต้นและสิ้นสุดให้ถูกต้อง</p>
  </div>
</template>
<script setup>
import { computed } from 'vue';
const props = defineProps({ from: { type: String, default: '' }, to: { type: String, default: '' }, allowYear: { type: Boolean, default: true } });
const emit = defineEmits(['update:from', 'update:to']);
const options = computed(() => [{ id: 'today', label: 'วันนี้' }, { id: 'month', label: 'เดือนนี้' }, { id: 'previous', label: 'เดือนก่อน' }, ...(props.allowYear ? [{ id: 'year', label: 'ปีนี้' }] : [])]);
const format = d => `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
function rangeFor(id) {
  const today = new Date();
  if (id === 'today') return [format(today), format(today)];
  if (id === 'month') return [format(new Date(today.getFullYear(), today.getMonth(), 1)), format(today)];
  if (id === 'previous') return [format(new Date(today.getFullYear(), today.getMonth()-1, 1)), format(new Date(today.getFullYear(), today.getMonth(), 0))];
  return [format(new Date(today.getFullYear(), 0, 1)), format(today)];
}
const active = computed(() => options.value.find(o => { const [a,b] = rangeFor(o.id); return a === props.from && b === props.to; })?.id || 'custom');
const invalid = computed(() => !props.from || !props.to || props.from > props.to);
const displayRange = computed(() => invalid.value ? 'เลือกวันที่' : [props.from, props.to].map(d => new Date(`${d}T00:00:00`).toLocaleDateString('th-TH', { day:'numeric', month:'short', year:'2-digit' })).join(' – '));
function setPreset(id) { const [a,b] = rangeFor(id); emit('update:from',a); emit('update:to',b); }
function change(which, value) { emit(`update:${which}`, value); }
</script>
<style scoped>
.range-picker{padding:18px;border:1px solid #dce7f8;border-radius:16px;background:linear-gradient(120deg,#f5f8ff,#fff)}.range-heading{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}.range-heading strong,.range-heading small{display:block}.range-heading strong{font-size:15px;color:#142c55}.range-heading small{font-size:12px;color:#718199;margin-top:2px}.range-current{font-size:13px;color:#315bd8;font-weight:700;background:#e9efff;padding:7px 11px;border-radius:9px}.range-presets{display:flex;gap:7px;flex-wrap:wrap;margin:14px 0}.range-presets button{border:1px solid #dce4f2;border-radius:10px;background:white;color:#52647c;font:inherit;font-size:13px;font-weight:700;padding:8px 13px;cursor:pointer}.range-presets button.selected{background:#315fe8;border-color:#315fe8;color:white;box-shadow:0 4px 12px #315fe832}.range-inputs{display:flex;gap:12px;flex-wrap:wrap}.range-inputs label{display:grid;gap:5px;color:#60728c;font-size:12px;font-weight:600;flex:1;min-width:145px}.range-inputs input{width:100%;min-height:40px}.range-error{color:#b42318;font-size:12px;margin:8px 0 0}@media(max-width:480px){.range-picker{padding:14px}.range-presets button{flex:1;padding:8px}.range-inputs{gap:8px}.range-inputs label{min-width:120px}}
</style>
