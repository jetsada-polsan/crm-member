<template>
  <Teleport to="body">
    <Transition name="pop-fade">
      <div v-if="open" class="ui-backdrop ui-backdrop-top" @click.self="$emit('close')">
        <div
          ref="boxRef"
          class="ui-cmd card"
          role="dialog"
          aria-modal="true"
          aria-label="ค้นหาและเปิดเมนูด่วน"
          tabindex="-1"
        >
          <div class="ui-cmd-input-row">
            <span>⌘</span>
            <input
              ref="inputRef"
              v-model="query"
              type="text"
              placeholder="พิมพ์ชื่อหน้า, เมนู, หรือคำสั่งด่วน... (ลูกศร ↑↓ + Enter)"
              @keydown.down.prevent="move(1)"
              @keydown.up.prevent="move(-1)"
              @keydown.enter.prevent="selectCurrent"
              @keydown.esc.prevent="$emit('close')"
            />
            <kbd>ESC</kbd>
          </div>
          <div class="ui-cmd-list">
            <button
              v-for="(item, idx) in filtered"
              :key="item.id"
              type="button"
              :class="['ui-cmd-item', { active: idx === cursor }]"
              @mouseenter="cursor = idx"
              @click="pick(item)"
            >
              <span class="ui-cmd-icon">{{ item.icon || '→' }}</span>
              <span class="ui-cmd-main">
                <strong>{{ item.label }}</strong>
                <small v-if="item.hint">{{ item.hint }}</small>
              </span>
              <span v-if="item.section" class="tag gray">{{ item.section }}</span>
            </button>
            <div v-if="!filtered.length" class="placeholder" style="padding:22px">
              ไม่พบคำสั่งที่ตรงกับ "{{ query }}"
            </div>
          </div>
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
});
const emit = defineEmits(['close', 'select']);

const query = ref('');
const cursor = ref(0);
const inputRef = ref(null);
const boxRef = ref(null);

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase();
  if (!q) return props.items;
  return props.items.filter(
    (x) =>
      (x.label || '').toLowerCase().includes(q) ||
      (x.hint || '').toLowerCase().includes(q) ||
      (x.section || '').toLowerCase().includes(q) ||
      (x.keywords || '').toLowerCase().includes(q)
  );
});

watch(
  () => props.open,
  (open) => {
    if (open) {
      query.value = '';
      cursor.value = 0;
      setTimeout(() => inputRef.value?.focus?.(), 25);
    }
  }
);

watch(filtered, () => {
  cursor.value = 0;
});

function move(delta) {
  if (!filtered.value.length) return;
  cursor.value = (cursor.value + delta + filtered.value.length) % filtered.value.length;
}

function pick(item) {
  emit('select', item);
  emit('close');
}

function selectCurrent() {
  const item = filtered.value[cursor.value];
  if (item) pick(item);
}

function onEsc(e) {
  if (props.open && e.key === 'Escape') {
    e.preventDefault();
    emit('close');
  }
}

onMounted(() => window.addEventListener('keydown', onEsc));
onUnmounted(() => window.removeEventListener('keydown', onEsc));
</script>
