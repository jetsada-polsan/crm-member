<template>
  <Teleport to="body">
    <Transition name="pop-fade">
      <div v-if="open" class="ui-backdrop ui-fs-wrap" @click.self="requestClose">
        <section
          ref="panelRef"
          class="ui-fs-sheet"
          role="dialog"
          aria-modal="true"
          :aria-label="title"
          tabindex="-1"
        >
          <header class="ui-fs-head">
            <button class="close" type="button" aria-label="ย้อนกลับหรือปิด" @click="requestClose">✕</button>
            <div class="ui-fs-title">
              <strong>{{ title }}</strong>
              <small v-if="stepLabel" class="muted">{{ stepLabel }}</small>
            </div>
            <div class="ui-fs-head-right">
              <slot name="header-extra" />
            </div>
          </header>
          <div class="ui-fs-body">
            <slot />
          </div>
          <footer v-if="$slots.footer" class="ui-fs-foot">
            <slot name="footer" :request-close="requestClose" />
          </footer>
        </section>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref } from 'vue';
import { useOverlayBehavior } from '../composables/useOverlayBehavior.js';

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
  stepLabel: { type: String, default: '' },
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
