<template>
  <div class="peak-nav-root">
    <!-- Compact Executive Quick Action Row -->
    <div class="peak-create-wrap">
      <button type="button" class="peak-create-btn" @click="$emit('open-visit')">
        <span aria-hidden="true">＋</span>
        <span>บันทึกเข้าพบ</span>
      </button>
      <button
        type="button"
        class="peak-subcreate-btn"
        title="เพิ่มร้านค้าใหม่"
        aria-label="เพิ่มร้านค้าใหม่"
        @click="$emit('open-quick-add')"
      >
        🏪
      </button>
    </div>

    <!-- PEAK-style Clean Text Dropdown Menu -->
    <nav class="peak-menu" aria-label="เมนูหลัก POPSTAR Connect">
      <div
        v-for="group in groups"
        :key="group.id"
        :class="['peak-group', { 'is-open': isGroupOpen(group), 'has-active': isGroupActive(group) }]"
      >
        <!-- Single direct link (e.g. ภาพรวมวันนี้) -->
        <RouterLink
          v-if="group.directPath || group.items.length === 1"
          :to="group.directPath || group.items[0].path"
          :class="['peak-group-head', 'is-direct', { active: isGroupActive(group) }]"
          @mouseenter="$emit('prefetch', group.directRouteName || group.items[0].name)"
          @click="$emit('navigate')"
        >
          <span class="peak-group-icon" aria-hidden="true">{{ group.icon }}</span>
          <span class="peak-group-label">{{ group.items.length === 1 ? group.items[0].label : group.title }}</span>
        </RouterLink>

        <!-- Expandable PEAK-style text dropdown category -->
        <template v-else>
          <button
            type="button"
            :class="['peak-group-head', { active: isGroupActive(group) }]"
            :aria-expanded="String(isGroupOpen(group))"
            @click="toggleGroup(group.id)"
          >
            <span class="peak-group-icon" aria-hidden="true">{{ group.icon }}</span>
            <span class="peak-group-label">{{ group.title }}</span>
            <svg
              class="peak-chevron"
              viewBox="0 0 20 20"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              aria-hidden="true"
            >
              <path d="M6 8l4 4 4-4" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </button>

          <div v-show="isGroupOpen(group)" class="peak-sublist">
            <RouterLink
              v-for="item in group.items"
              :key="item.name"
              :to="item.path"
              :class="['peak-subitem', { active: activeRouteName === item.name }]"
              @mouseenter="$emit('prefetch', item.name)"
              @focus="$emit('prefetch', item.name)"
              @click="$emit('navigate')"
            >
              <span class="peak-subdot" aria-hidden="true"></span>
              <span class="peak-sublabel">{{ item.label }}</span>
              <span v-if="item.name === 'profile' && offlineDraftCount > 0" class="tag orange peak-subbadge">
                {{ offlineDraftCount }}
              </span>
            </RouterLink>
          </div>
        </template>
      </div>
    </nav>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import { RouterLink } from 'vue-router';

const props = defineProps({
  groups: { type: Array, required: true },
  activeRouteName: { type: String, default: '' },
  offlineDraftCount: { type: Number, default: 0 },
});

defineEmits(['prefetch', 'navigate', 'open-visit', 'open-quick-add']);

const openGroupId = ref(null);

function isGroupActive(group) {
  return group.items.some((item) => item.name === props.activeRouteName);
}

function isGroupOpen(group) {
  return openGroupId.value === group.id;
}

function toggleGroup(groupId) {
  openGroupId.value = openGroupId.value === groupId ? null : groupId;
}

watch(
  () => props.activeRouteName,
  (routeName) => {
    const group = props.groups.find((g) => g.items.some((i) => i.name === routeName));
    openGroupId.value = group && !group.directPath && group.items.length > 1 ? group.id : null;
  },
  { immediate: true }
);
</script>

<style scoped>
.peak-nav-root {
  display: flex;
  flex-direction: column;
  gap: 14px;
  margin-bottom: 16px;
}

.peak-create-wrap {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 0 2px 12px;
  border-bottom: 1px solid var(--border);
}

.peak-create-btn {
  flex: 1;
  min-height: 38px;
  border-radius: 10px;
  border: 0;
  background: #0284c7;
  color: #ffffff;
  font-size: 13.5px;
  font-weight: 600;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  transition:
    background-color 0.14s ease,
    transform 0.12s ease;
}

.peak-create-btn:hover {
  background: #0369a1;
}

.peak-create-btn:active {
  transform: scale(0.98);
}

.peak-subcreate-btn {
  width: 38px;
  height: 38px;
  flex-shrink: 0;
  border-radius: 10px;
  border: 1px solid var(--border);
  background: #ffffff;
  color: #334155;
  font-size: 16px;
  display: grid;
  place-items: center;
  transition:
    background-color 0.14s ease,
    border-color 0.14s ease;
}

.peak-subcreate-btn:hover {
  background: #f0f9ff;
  border-color: #7dd3fc;
}

.peak-menu {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.peak-group {
  display: flex;
  flex-direction: column;
}

.peak-group-head {
  width: 100%;
  min-height: 38px;
  padding: 7px 10px;
  border: 0;
  border-radius: 9px;
  background: transparent;
  color: #334155;
  font-size: 13.5px;
  font-weight: 500;
  display: flex;
  align-items: center;
  gap: 9px;
  text-align: left;
  text-decoration: none;
  transition:
    background-color 0.13s ease,
    color 0.13s ease;
}

.peak-group-head:hover {
  background: #f3f4f6;
  color: #0f172a;
}

.peak-group-head.active {
  color: #0284c7;
  background: #f0f9ff;
  font-weight: 600;
}

.peak-group-head.is-direct.active {
  background: #eff6ff;
  color: #0284c7;
  font-weight: 600;
}

.peak-group-icon {
  width: 24px;
  height: 24px;
  border-radius: 6px;
  background: transparent;
  display: inline-grid;
  place-items: center;
  font-size: 15px;
  flex-shrink: 0;
}

.peak-group-head.active .peak-group-icon {
  background: transparent;
}

.peak-group-label {
  flex: 1;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.peak-chevron {
  width: 16px;
  height: 16px;
  color: #64748b;
  flex-shrink: 0;
  transition: transform 0.18s ease;
}

.peak-group.is-open .peak-chevron {
  transform: rotate(180deg);
  color: #0284c7;
}

/* PEAK-style indented text dropdown tree */
.peak-sublist {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin: 3px 0 6px 24px;
  padding-left: 10px;
  border-left: 2px solid #e2e8f0;
}

.peak-subitem {
  position: relative;
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 8px 11px;
  min-height: 38px;
  border-radius: 8px;
  color: #475569;
  font-size: 14px;
  font-weight: 500;
  text-decoration: none;
  transition:
    background-color 0.13s ease,
    color 0.13s ease;
}

.peak-subitem:hover {
  background: #f8fafc;
  color: #0f172a;
}

.peak-subdot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #cbd5e1;
  flex-shrink: 0;
  transition:
    background-color 0.14s ease,
    transform 0.14s ease;
}

.peak-subitem.active {
  background: #e0f2fe;
  color: #0284c7;
  font-weight: 600;
}

.peak-subitem.active .peak-subdot {
  background: #0284c7;
  transform: scale(1.3);
}

.peak-sublabel {
  flex: 1;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.peak-subbadge {
  flex-shrink: 0;
}
</style>
