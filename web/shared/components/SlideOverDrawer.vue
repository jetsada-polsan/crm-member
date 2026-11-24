<template>
  <Teleport to="body">
    <Transition name="pop-fade">
      <div v-if="open" class="ui-backdrop" @click.self="requestClose" />
    </Transition>
    <Transition name="pop-slide-right">
      <aside
        v-if="open"
        ref="panelRef"
        class="ui-drawer"
        :style="{ width }"
        role="dialog"
        aria-modal="true"
        :aria-label="title"
        tabindex="-1"
      >
        <div class="drawer-top">
          <div>
            <h3>{{ title }}</h3>
            <p v-if="subtitle" class="small muted" style="margin: 2px 0 0">{{ subtitle }}</p>
          </div>
          <button class="close" type="button" aria-label="ปิดลิ้นชักรายละเอียด" @click="requestClose">✕</button>
        </div>
        <div class="ui-drawer-body">
          <slot :request-close="requestClose" />
        </div>
      </aside>
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
  width: { type: String, default: 'min(520px, 100vw)' },
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
