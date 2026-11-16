<template>
  <div class="navroot">
    <!-- Quick Menu Search & Group Toggle -->
    <div class="navtools">
      <div class="navsearch">
        <span class="navsearch-icon" aria-hidden="true">🔍</span>
        <input
          v-model="searchQuery"
          type="search"
          class="navsearch-input"
          placeholder="ค้นหาเมนู... (เช่น ERP, แต้ม)"
          aria-label="ค้นหาเมนูในระบบ"
          autocomplete="off"
        />
        <button
          v-if="searchQuery"
          type="button"
          class="navsearch-clear"
          aria-label="ล้างคำค้นเมนู"
          @click="searchQuery = ''"
        >
          ✕
        </button>
      </div>

      <div v-if="!normalizedQuery" class="navtools-meta">
        <span>{{ groups.length }} หมวดงาน</span>
        <button
          type="button"
          class="nav-expand-btn"
          @click="toggleExpandAll"
        >
          {{ allExpanded ? 'ย่อหมวดรอง' : 'ขยายทั้งหมด' }}
        </button>
      </div>
    </div>

    <!-- Categorized Collapsible Navigation -->
    <nav class="navgroups" aria-label="หมวดหมู่เมนู CRM">
      <section
        v-for="group in displayedGroups"
        :key="group.id"
        :class="['navgroup', { open: isGroupExpanded(group.id), hasActive: isGroupActive(group) }]"
      >
        <button
          type="button"
          class="navgroup-toggle"
          :aria-expanded="String(isGroupExpanded(group.id))"
          :aria-controls="`nav-panel-${group.id}`"
          @click="toggleGroup(group.id)"
        >
          <span class="navgroup-icon" aria-hidden="true">{{ group.icon }}</span>
          <span class="navgroup-title">{{ group.title }}</span>
          <span
            v-if="isGroupActive(group) && !isGroupExpanded(group.id)"
            class="navgroup-active-dot"
            title="กำลังใช้งานหมวดนี้"
            aria-hidden="true"
          ></span>
          <span class="navgroup-count">{{ group.items.length }}</span>
          <span :class="['navgroup-chevron', { open: isGroupExpanded(group.id) }]" aria-hidden="true">
            ▾
          </span>
        </button>

        <div
          v-show="isGroupExpanded(group.id)"
          :id="`nav-panel-${group.id}`"
          class="navgroup-items"
        >
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
            <span class="ni">{{ item.icon }}</span>
            <span class="navitem-label">{{ item.label }}</span>
            <span
              v-if="item.name === 'profile' && offlineDraftCount > 0"
              class="tag orange navbadge"
            >
              {{ offlineDraftCount }}
            </span>
          </RouterLink>
        </div>
      </section>

      <div v-if="!displayedGroups.length" class="navempty">
        <p>ไม่พบเมนูที่ตรงกับ "{{ searchQuery }}"</p>
        <button type="button" class="nav-expand-btn" @click="searchQuery = ''">
          แสดงเมนูทั้งหมด
        </button>
      </div>
    </nav>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';

const STORAGE_KEY = 'popstar_crm_nav_groups_v1';

const props = defineProps({
  groups: { type: Array, required: true },
  activeRouteName: { type: String, default: '' },
  offlineDraftCount: { type: Number, default: 0 },
});

defineEmits(['prefetch', 'navigate']);

const searchQuery = ref('');
const openMap = ref(loadInitialOpenMap(props.groups));

function buildDefaultMap(groups) {
  const map = {};
  for (const g of groups) {
    map[g.id] = Boolean(g.defaultOpen);
  }
  return map;
}

function loadInitialOpenMap(groups) {
  const defaults = buildDefaultMap(groups);
  if (typeof window === 'undefined') return defaults;
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY);
    if (!raw) return defaults;
    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== 'object') return defaults;
    const merged = { ...defaults };
    for (const g of groups) {
      if (typeof parsed[g.id] === 'boolean') {
        merged[g.id] = parsed[g.id];
      }
    }
    return merged;
  } catch {
    return defaults;
  }
}

function saveOpenMap(map) {
  if (typeof window === 'undefined') return;
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(map));
  } catch {
    // Ignore storage quota errors
  }
}

function ensureActiveGroupExpanded(routeName) {
  if (!routeName) return;
  const activeGroup = props.groups.find((g) => g.items.some((item) => item.name === routeName));
  if (activeGroup && !openMap.value[activeGroup.id]) {
    openMap.value = { ...openMap.value, [activeGroup.id]: true };
    saveOpenMap(openMap.value);
  }
}

watch(
  () => props.activeRouteName,
  (nextName) => {
    ensureActiveGroupExpanded(nextName);
  },
  { immediate: true }
);

watch(
  () => props.groups,
  (nextGroups) => {
    const next = { ...openMap.value };
    for (const g of nextGroups) {
      if (typeof next[g.id] !== 'boolean') {
        next[g.id] = Boolean(g.defaultOpen);
      }
    }
    openMap.value = next;
    ensureActiveGroupExpanded(props.activeRouteName);
  }
);

const normalizedQuery = computed(() => searchQuery.value.trim().toLowerCase());

const displayedGroups = computed(() => {
  const q = normalizedQuery.value;
  if (!q) return props.groups;
  return props.groups
    .map((group) => {
      const groupMatch = group.title.toLowerCase().includes(q);
      const matchingItems = groupMatch
        ? group.items
        : group.items.filter(
            (item) =>
              item.label.toLowerCase().includes(q) ||
              (item.keywords || '').toLowerCase().includes(q) ||
              item.path.toLowerCase().includes(q)
          );
      return { ...group, items: matchingItems };
    })
    .filter((group) => group.items.length > 0);
});

const allExpanded = computed(() =>
  props.groups.length > 0 && props.groups.every((g) => Boolean(openMap.value[g.id]))
);

function isGroupExpanded(groupId) {
  if (normalizedQuery.value) return true;
  return Boolean(openMap.value[groupId]);
}

function isGroupActive(group) {
  return group.items.some((item) => item.name === props.activeRouteName);
}

function toggleGroup(groupId) {
  const next = { ...openMap.value, [groupId]: !openMap.value[groupId] };
  openMap.value = next;
  saveOpenMap(next);
}

function toggleExpandAll() {
  if (allExpanded.value) {
    const next = buildDefaultMap(props.groups);
    const activeGroup = props.groups.find((g) => g.items.some((item) => item.name === props.activeRouteName));
    if (activeGroup) next[activeGroup.id] = true;
    openMap.value = next;
    saveOpenMap(next);
    return;
  }
  const next = {};
  for (const g of props.groups) next[g.id] = true;
  openMap.value = next;
  saveOpenMap(next);
}
</script>

<style scoped>
.navroot {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-bottom: 14px;
}

.navtools {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.navsearch {
  position: relative;
  display: flex;
  align-items: center;
}

.navsearch-icon {
  position: absolute;
  left: 10px;
  font-size: 12px;
  opacity: 0.72;
  pointer-events: none;
}

.navsearch-input {
  width: 100%;
  min-height: 36px;
  padding: 7px 30px 7px 30px;
  border-radius: 10px;
  border: 1px solid rgba(148, 173, 219, 0.22);
  background: rgba(255, 255, 255, 0.06);
  color: #f8fbff;
  font-size: 12px;
  outline: none;
  transition: border-color 0.16s ease, background 0.16s ease;
}

.navsearch-input::placeholder {
  color: #879bc0;
}

.navsearch-input:focus {
  border-color: #5c8dff;
  background: rgba(255, 255, 255, 0.1);
}

.navsearch-clear {
  position: absolute;
  right: 5px;
  width: 26px;
  height: 26px;
  min-height: 26px;
  padding: 0;
  border: 0;
  border-radius: 7px;
  background: rgba(255, 255, 255, 0.12);
  color: #d7e3f7;
  font-size: 11px;
  display: grid;
  place-items: center;
}

.navtools-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 4px;
  font-size: 10.5px;
  color: #7c92ba;
}

.nav-expand-btn {
  border: 0;
  background: transparent;
  color: #8eb3ff;
  font-size: 11px;
  font-weight: 500;
  padding: 2px 6px;
  min-height: 26px;
  border-radius: 6px;
}

.nav-expand-btn:hover {
  background: rgba(255, 255, 255, 0.08);
  color: #fff;
}

.navgroups {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.navgroup {
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.03);
  border: 1px solid rgba(255, 255, 255, 0.06);
  padding: 4px;
}

.navgroup.hasActive {
  border-color: rgba(84, 138, 255, 0.34);
}

.navgroup-toggle {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 8px 9px;
  min-height: 36px;
  border: 0;
  border-radius: 9px;
  background: transparent;
  color: #d5e0f4;
  text-align: left;
  transition: background 0.15s ease, color 0.15s ease;
}

.navgroup-toggle:hover {
  background: rgba(255, 255, 255, 0.07);
  color: #fff;
}

.navgroup-icon {
  font-size: 13px;
  width: 18px;
  text-align: center;
  flex-shrink: 0;
}

.navgroup-title {
  flex: 1;
  min-width: 0;
  font-size: 11.5px;
  font-weight: 600;
  letter-spacing: 0.2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.navgroup-active-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #4f8bff;
  box-shadow: 0 0 8px rgba(79, 139, 255, 0.8);
  flex-shrink: 0;
}

.navgroup-count {
  font-size: 10px;
  font-weight: 600;
  color: #93a7cb;
  background: rgba(255, 255, 255, 0.08);
  border-radius: 99px;
  padding: 1px 7px;
  flex-shrink: 0;
}

.navgroup-chevron {
  font-size: 11px;
  color: #8ea3c9;
  transition: transform 0.18s ease;
  transform: rotate(-90deg);
  flex-shrink: 0;
}

.navgroup-chevron.open {
  transform: rotate(0deg);
}

.navgroup-items {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 3px 2px 2px;
}

.navgroup-items :deep(.navitem) {
  padding: 8px 10px;
  font-size: 12.5px;
  border-radius: 9px;
  gap: 9px;
}

.navgroup-items :deep(.ni) {
  font-size: 15px;
  width: 20px;
}

.navitem-label {
  flex: 1;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.navbadge {
  padding: 2px 7px;
  font-size: 10px;
  flex-shrink: 0;
}

.navempty {
  text-align: center;
  padding: 18px 10px;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.03);
  color: #8fa2c5;
  font-size: 12px;
}

.navempty p {
  margin: 0 0 8px;
}

@media (max-width: 1024px) {
  .navgroup-toggle,
  .navgroup-items :deep(.navitem) {
    min-height: 40px;
  }
}
</style>
