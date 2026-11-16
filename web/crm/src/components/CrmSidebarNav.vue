<template>
  <div class="navroot-flat">
    <div class="sidebar-cta-group">
      <button
        type="button"
        class="btn primary sidebar-visit-cta"
        @click="$emit('open-visit')"
      >
        <span aria-hidden="true">📸</span>
        <span>บันทึกเข้าพบ</span>
      </button>
      <button
        type="button"
        class="sidebar-quick-secondary"
        title="เพิ่มร้านค้าใหม่"
        @click="$emit('open-quick-add')"
      >
        <span>＋ เพิ่มร้านใหม่</span>
      </button>
    </div>

    <nav class="navflat" aria-label="เมนูหลัก POPSTAR Connect">
      <div v-for="group in groups" :key="group.id" class="navflat-group">
        <div class="navheading">
          <span class="navheading-icon" aria-hidden="true">{{ group.icon || '•' }}</span>
          <span>{{ group.title }}</span>
        </div>
        <RouterLink
          v-for="item in group.items"
          :key="item.name"
          :to="item.path"
          class="navitem"
          active-class="active"
          @mouseenter="$emit('prefetch', item.name)"
          @focus="$emit('prefetch', item.name)"
          @click="$emit('navigate')"
        >
          <span :class="['ni', `ni-${item.tone || group.tone || 'blue'}`]" aria-hidden="true">{{ item.icon }}</span>
          <span class="navitem-label">{{ item.label }}</span>
          <span
            v-if="item.name === 'profile' && offlineDraftCount > 0"
            class="tag orange navbadge"
          >
            {{ offlineDraftCount }}
          </span>
        </RouterLink>
      </div>
    </nav>
  </div>
</template>

<script setup>
import { RouterLink } from 'vue-router';

defineProps({
  groups: { type: Array, required: true },
  activeRouteName: { type: String, default: '' },
  offlineDraftCount: { type: Number, default: 0 },
});

defineEmits(['prefetch', 'navigate', 'open-visit', 'open-quick-add']);
</script>

<style scoped>
.navroot-flat {
  display: flex;
  flex-direction: column;
  gap: 14px;
  margin-bottom: 16px;
}

.sidebar-cta-group {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 0 4px;
}

.sidebar-visit-cta {
  width: 100%;
  min-height: 48px;
  font-size: 15px;
  font-weight: 700;
  border-radius: 13px;
  background: linear-gradient(135deg, #3b82f6 0%, #2563eb 55%, #4f46e5 100%);
  border: 0;
  box-shadow: 0 8px 20px rgba(37, 99, 235, 0.35);
}

.sidebar-quick-secondary {
  width: 100%;
  min-height: 40px;
  border-radius: 11px;
  border: 1px solid rgba(157, 184, 255, 0.24);
  background: rgba(255, 255, 255, 0.06);
  color: #dbeafe;
  font-size: 13.5px;
  font-weight: 600;
  transition: background-color 0.15s ease, border-color 0.15s ease;
}

.sidebar-quick-secondary:hover {
  background: rgba(255, 255, 255, 0.12);
  border-color: rgba(157, 184, 255, 0.45);
  color: #ffffff;
}

.navflat {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.navflat-group + .navflat-group {
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px solid rgba(255, 255, 255, 0.07);
}

.navheading {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: #93a6c8;
  padding: 6px 10px 6px;
  letter-spacing: 0.3px;
  font-weight: 700;
}

.navheading-icon {
  font-size: 13px;
}

.navitem {
  padding: 9px 10px;
  border-radius: 12px;
  gap: 10px;
  transition: background-color 0.14s ease, transform 0.12s ease;
}

.ni {
  width: 30px;
  height: 30px;
  border-radius: 9px;
  background: rgba(255, 255, 255, 0.08);
  font-size: 16px;
  display: inline-grid;
  place-items: center;
  flex-shrink: 0;
}

.navitem.active .ni {
  background: rgba(255, 255, 255, 0.22);
}

.navitem-label {
  flex: 1;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.navbadge {
  flex-shrink: 0;
}
</style>
