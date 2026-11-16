<template>
  <header class="pagehead">
    <div class="pagehead-main">
      <h2>{{ title }}</h2>
      <p v-if="subtitle">{{ subtitle }}</p>
    </div>
    <div v-if="$slots.primary || $slots.secondary" class="pagehead-actions">
      <div v-if="$slots.secondary" class="pagehead-secondary">
        <slot name="secondary" />
      </div>
      <div v-if="$slots.primary" class="pagehead-primary">
        <slot name="primary" />
      </div>
    </div>
  </header>
</template>

<script setup>
defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
});
</script>
