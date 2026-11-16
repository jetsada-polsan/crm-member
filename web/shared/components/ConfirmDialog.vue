<template>
  <Teleport to="body">
    <Transition name="pop-fade">
      <div v-if="uiState.confirm.open" class="ui-backdrop ui-backdrop-top" @click.self="resolveConfirm(false)">
        <div
          ref="dialogRef"
          class="ui-modal card"
          style="max-width:440px"
          role="alertdialog"
          aria-modal="true"
          :aria-label="uiState.confirm.title"
          tabindex="-1"
        >
          <div class="ui-modal-head">
            <h3>{{ uiState.confirm.title }}</h3>
            <button class="close" type="button" aria-label="ยกเลิก" @click="resolveConfirm(false)">✕</button>
          </div>
          <p class="small" style="margin:0 0 18px;line-height:1.6;color:#475569">
            {{ uiState.confirm.message }}
          </p>
          <div class="ui-modal-foot">
            <button class="btn" type="button" @click="resolveConfirm(false)">
              {{ uiState.confirm.cancelLabel }}
            </button>
            <button
              :class="['btn', uiState.confirm.tone === 'danger' ? 'danger' : 'primary']"
              type="button"
              @click="resolveConfirm(true)"
            >
              {{ uiState.confirm.confirmLabel }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { resolveConfirm, uiState } from '../composables/useUiStore.js';

const dialogRef = ref(null);

function onKey(e) {
  if (!uiState.confirm.open) return;
  if (e.key === 'Escape') {
    e.preventDefault();
    resolveConfirm(false);
  }
}

watch(
  () => uiState.confirm.open,
  (open) => {
    if (open) setTimeout(() => dialogRef.value?.focus?.(), 20);
  }
);

onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => window.removeEventListener('keydown', onKey));
</script>
