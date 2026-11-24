<template>
  <Teleport to="body">
    <div class="ui-toast-stack" role="status" aria-live="polite">
      <TransitionGroup name="pop-toast">
        <div v-for="t in uiState.toasts" :key="t.id" :class="['ui-toast-item', `tone-${t.tone}`]">
          <div class="ui-toast-body">
            <strong>{{ t.title }}</strong>
            <div v-if="t.message" class="ui-toast-msg">{{ t.message }}</div>
          </div>
          <div class="ui-toast-actions">
            <button v-if="t.error" type="button" class="ui-toast-link" @click="openErrorDetail(t.title, t.error)">
              รายละเอียด
            </button>
            <button type="button" class="ui-toast-close" aria-label="ปิดการแจ้งเตือน" @click="toast.dismiss(t.id)">
              ✕
            </button>
          </div>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<script setup>
import { openErrorDetail, toast, uiState } from '../composables/useUiStore.js';
</script>
