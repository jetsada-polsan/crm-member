<template>
  <div class="ui-progress-wrap" role="progressbar" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100">
    <div class="ui-progress-head">
      <span class="small">{{ label || 'กำลังอัปโหลดหลักฐาน...' }}</span>
      <div style="display: flex; align-items: center; gap: 8px">
        <strong class="small">{{ percent }}%</strong>
        <button
          v-if="cancellable"
          type="button"
          class="btn danger"
          style="padding: 6px 12px; font-size: 13.5px; min-height: 36px"
          @click="$emit('cancel')"
        >
          ยกเลิก
        </button>
      </div>
    </div>
    <div class="ui-progress-track">
      <div class="ui-progress-fill" :style="{ width: `${Math.max(4, Math.min(100, percent))}%` }" />
    </div>
  </div>
</template>

<script setup>
defineProps({
  percent: { type: Number, default: 0 },
  label: { type: String, default: '' },
  cancellable: { type: Boolean, default: false },
});
defineEmits(['cancel']);
</script>
