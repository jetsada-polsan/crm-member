<template>
  <Teleport to="body">
    <Transition name="pop-fade">
      <div v-if="open" class="ui-backdrop" @click.self="requestClose" />
    </Transition>
    <Transition name="pop-sheet">
      <section
        v-if="open"
        ref="panelRef"
        class="ui-sheet"
        role="dialog"
        aria-modal="true"
        :aria-label="title"
        tabindex="-1"
      >
        <div class="ui-sheet-handle" aria-hidden="true" />
        <div class="ui-modal-head">
          <h3>{{ title }}</h3>
          <button class="close" type="button" aria-label="ปิด" @click="requestClose">✕</button>
        </div>
        <div class="ui-sheet-body">
          <slot :request-close="requestClose" />
        </div>
      </section>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref } from 'vue';
import { useOverlayBehavior } from '../composables/useOverlayBehavior.js';

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
  dirty: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);
const panelRef = ref(null);

const { requestClose } = useOverlayBehavior({
  isOpen: () => props.open,
  isDirty: () => props.dirty,
  onClose: () => emit('close'),
  panelRef,
});
</script>
