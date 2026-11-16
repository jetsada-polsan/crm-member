<template>
  <div class="ui-success-box">
    <div class="ui-check-ring" aria-hidden="true">
      <svg viewBox="0 0 52 52" class="ui-check-svg">
        <circle cx="26" cy="26" r="24" fill="none" class="ui-check-circle" />
        <path fill="none" d="M15 27l7 7 15-16" class="ui-check-tick" />
      </svg>
    </div>
    <h3>{{ title }}</h3>
    <p v-if="subtitle" class="small muted">{{ subtitle }}</p>
  </div>
</template>

<script setup>
defineProps({
  title: { type: String, default: 'บันทึกสำเร็จ' },
  subtitle: { type: String, default: '' },
});
</script>
