<template>
  <Teleport to="body">
    <Transition name="pop-fade">
      <div v-if="open" class="ui-backdrop" @click.self="requestClose">
        <div
          ref="panelRef"
          class="ui-modal card"
          :style="{ maxWidth }"
          role="dialog"
          aria-modal="true"
          :aria-label="title"
          tabindex="-1"
        >
          <div class="ui-modal-head">
            <div>
              <h3>{{ title }}</h3>
              <p v-if="subtitle" class="small muted" style="margin: 2px 0 0">{{ subtitle }}</p>
            </div>
            <button v-if="!persistent" class="close" type="button" aria-label="ปิดหน้าต่าง" @click="requestClose">
              ✕
            </button>
          </div>
          <div class="ui-modal-body">
            <slot />
          </div>
          <div v-if="$slots.footer" class="ui-modal-foot">
            <slot name="footer" :request-close="requestClose" />
          </div>
        </div>
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
  subtitle: { type: String, default: '' },
  maxWidth: { type: String, default: '560px' },
  persistent: { type: Boolean, default: false },
  dirty: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);
const panelRef = ref(null);

const { requestClose } = useOverlayBehavior({
  isOpen: () => props.open,
  persistent: () => props.persistent,
  isDirty: () => props.dirty,
  onClose: () => emit('close'),
  panelRef,
});
</script>
