<template>
  <Teleport to="body">
    <Transition name="pop-fade">
      <div
        v-if="open && currentItem"
        class="ui-lightbox"
        role="dialog"
        aria-modal="true"
        aria-label="ดูรูปภาพหลักฐาน"
        @click.self="$emit('close')"
      >
        <div class="ui-lightbox-bar">
          <div>
            <strong>{{ currentItem.title || currentItem.category || 'ภาพหลักฐาน' }}</strong>
            <span v-if="items.length > 1" class="small" style="margin-left: 8px; opacity: 0.8"
              >({{ index + 1 }} / {{ items.length }})</span
            >
          </div>
          <button class="close" type="button" aria-label="ปิดภาพขยาย" @click="$emit('close')">✕</button>
        </div>
        <div class="ui-lightbox-stage">
          <button
            v-if="items.length > 1"
            type="button"
            class="ui-lightbox-nav prev"
            aria-label="ก่อนหน้า"
            @click="step(-1)"
          >
            ‹
          </button>
          <img :src="currentItem.src" :alt="currentItem.title || 'หลักฐานภาพถ่าย'" class="ui-lightbox-img" />
          <button v-if="items.length > 1" type="button" class="ui-lightbox-nav next" aria-label="ถัดไป" @click="step(1)">
            ›
          </button>
        </div>
        <div class="ui-lightbox-meta">
          <span v-if="currentItem.category" class="tag blue">{{ currentItem.category }}</span>
          <span v-if="currentItem.capturedAt" class="tag gray">ถ่ายเมื่อ: {{ currentItem.capturedAt }}</span>
          <span v-if="currentItem.consentConfirmed" class="tag green">ลูกค้ายินยอมแล้ว</span>
          <span v-if="currentItem.compressedOnClient" class="tag blue">บีบอัดก่อนอัปโหลด</span>
          <span v-if="currentItem.latitude != null && currentItem.longitude != null" class="tag gray">
            GPS: {{ Number(currentItem.latitude).toFixed(5) }}, {{ Number(currentItem.longitude).toFixed(5) }}
          </span>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  items: { type: Array, default: () => [] },
  startIndex: { type: Number, default: 0 },
});

const emit = defineEmits(['close']);
const index = ref(0);

watch(
  () => [props.open, props.startIndex],
  ([open, idx]) => {
    if (open) index.value = Math.max(0, Math.min(idx || 0, Math.max(0, props.items.length - 1)));
  }
);

const currentItem = computed(() => props.items[index.value] || null);

function step(delta) {
  if (!props.items.length) return;
  index.value = (index.value + delta + props.items.length) % props.items.length;
}

function onKey(e) {
  if (!props.open) return;
  if (e.key === 'Escape') emit('close');
  else if (e.key === 'ArrowLeft') step(-1);
  else if (e.key === 'ArrowRight') step(1);
}

onMounted(() => window.addEventListener('keydown', onKey));
onUnmounted(() => window.removeEventListener('keydown', onKey));
</script>
