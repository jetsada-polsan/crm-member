<template>
  <div class="more-page">
    <!-- Hero Profile Card -->
    <section class="card more-profile-card" aria-label="ข้อมูลผู้ใช้งาน">
      <div class="more-profile-row">
        <div class="avatar more-avatar">{{ userInitial }}</div>
        <div class="more-profile-info">
          <div class="more-role-pill">
            <span>
              {{
                me?.role === 'admin'
                  ? '👑 แอดมินระบบ'
                  : me?.role === 'executive'
                    ? '👔 ผู้บริหาร (ดูข้อมูลทั้งระบบ · แก้ไขไม่ได้)'
                    : isManager
                      ? '👑 ผู้จัดการ'
                      : '🚀 พนักงานขายออกตลาด'
              }}
            </span>
            <span v-if="me?.salesperson_code" class="more-code-pill">รหัส {{ me.salesperson_code }}</span>
            <span
              v-if="Array.isArray(me?.reservation_doc_types) && me.reservation_doc_types.length"
              class="more-code-pill"
            >
              ใบจอง {{ me.reservation_doc_types.join(', ') }}
            </span>
          </div>
          <h2>{{ me?.name || 'ผู้ใช้งาน' }}</h2>
          <p>{{ me?.email || 'พร้อมใช้งานระบบภาคสนาม' }}</p>
        </div>
        <div class="more-profile-actions">
          <RouterLink to="/profile" class="btn more-profile-btn">
            📱 โปรไฟล์
          </RouterLink>
          <button
            type="button"
            class="btn danger more-logout-btn"
            @click="confirmLogout"
          >
            ⏻ ออกจากระบบ
          </button>
        </div>
      </div>

      <div v-if="offlineDrafts.length" class="more-draft-bar">
        <span>☁️ มีข้อมูลเข้าพบค้างส่ง <strong>{{ offlineDrafts.length }}</strong> รายการ</span>
        <button
          type="button"
          class="btn primary"
          :disabled="offlineSyncing"
          @click="syncPendingOfflineDrafts"
        >
          {{ offlineSyncing ? 'กำลังส่ง...' : 'ส่งข้อมูลทันที' }}
        </button>
      </div>
    </section>

    <!-- One-Hand Quick Actions (Mobile-first Hero Shortcuts) -->
    <section class="more-quick-dock" aria-label="ทางลัดงานด่วน">
      <div class="more-quick-primary">
        <button type="button" class="more-cta-card primary" @click="openVisitWizard()">
          <span class="more-cta-icon" aria-hidden="true">📸</span>
          <div class="more-cta-text">
            <strong>บันทึกเข้าพบร้าน</strong>
            <small>เช็คอิน GPS + ถ่ายรูปหน้างาน</small>
          </div>
        </button>

        <button type="button" class="more-cta-card secondary" @click="openQuickAdd()">
          <span class="more-cta-icon" aria-hidden="true">🏪</span>
          <div class="more-cta-text">
            <strong>เพิ่มร้านค้าใหม่</strong>
            <small>ลงทะเบียนด่วน + ตรวจเบอร์ซ้ำ</small>
          </div>
        </button>
      </div>

      <div class="more-daily-strip">
        <RouterLink
          v-for="shortcut in QUICK_SHORTCUTS"
          :key="shortcut.path"
          :to="shortcut.path"
          class="more-strip-pill"
          @mouseenter="prefetchRouteView(shortcut.name, { withData: true })"
        >
          <span class="more-strip-icon" aria-hidden="true">{{ shortcut.icon }}</span>
          <span>{{ shortcut.label }}</span>
        </RouterLink>
      </div>
    </section>

    <!-- Search & Category Filter Bar -->
    <section class="more-search-wrap" aria-label="ค้นหาและกรองหมวดเมนู">
      <label class="more-search-box" for="more-menu-search">
        <span class="more-search-icon" aria-hidden="true">🔍</span>
        <input
          id="more-menu-search"
          v-model="searchQuery"
          type="search"
          class="more-search-input"
          placeholder="ค้นหาเมนู เช่น แผนที่, ติดตามผล, รายงาน, สมาชิก..."
          autocomplete="off"
        />
        <button
          v-if="searchQuery"
          type="button"
          class="btn light more-search-clear"
          aria-label="ล้างคำค้น"
          @click="searchQuery = ''"
        >
          ล้าง
        </button>
      </label>

      <div class="more-cat-pills" role="tablist" aria-label="หมวดหมู่เมนู">
        <button
          type="button"
          role="tab"
          :aria-selected="activeCategory === 'all'"
          :class="['more-cat-chip', { active: activeCategory === 'all' }]"
          @click="activeCategory = 'all'"
        >
          <span>✨ ทั้งหมด</span>
          <span class="more-cat-count">{{ totalMenuCount }}</span>
        </button>
        <button
          v-for="group in roleGroups"
          :key="`tab-${group.id}`"
          type="button"
          role="tab"
          :aria-selected="activeCategory === group.id"
          :class="['more-cat-chip', { active: activeCategory === group.id }]"
          @click="activeCategory = group.id"
        >
          <span>{{ group.icon }} {{ group.title }}</span>
          <span class="more-cat-count">{{ group.items.length }}</span>
        </button>
      </div>
    </section>

    <!-- Categorized App Launcher Grid -->
    <div v-if="filteredGroups.length" class="more-groups">
      <section v-for="group in filteredGroups" :key="group.id" class="more-group">
        <div class="more-group-head">
          <div class="more-group-badge" :data-tone="group.tone || 'blue'" aria-hidden="true">
            {{ group.icon || '📁' }}
          </div>
          <div>
            <h3 class="more-group-title">{{ group.title }}</h3>
            <p v-if="group.subtitle" class="more-group-sub">{{ group.subtitle }}</p>
          </div>
        </div>

        <div class="more-grid">
          <RouterLink
            v-for="item in group.items"
            :key="item.name"
            :to="item.path"
            class="card more-tile"
            :data-tone="item.tone || group.tone || 'blue'"
            @mouseenter="prefetchRouteView(item.name, { withData: true })"
          >
            <div class="more-tile-top">
              <span :class="['more-tile-icon', `tone-${item.tone || group.tone || 'blue'}`]" aria-hidden="true">
                {{ item.icon }}
              </span>
              <span
                v-if="item.name === 'profile' && offlineDrafts.length > 0"
                class="tag orange more-tile-badge"
              >
                ค้าง {{ offlineDrafts.length }}
              </span>
              <span v-else class="more-tile-arrow" aria-hidden="true">›</span>
            </div>
            <div class="more-tile-body">
              <span class="more-tile-label">{{ item.label }}</span>
              <small v-if="item.desc" class="more-tile-desc">{{ item.desc }}</small>
            </div>
          </RouterLink>
        </div>
      </section>
    </div>

    <div v-else class="card placeholder more-empty">
      <p>ไม่พบเมนูที่ตรงกับ "{{ searchQuery }}"</p>
      <button type="button" class="btn light" @click="resetFilter">
        แสดงเมนูทั้งหมด
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { askConfirm } from '../../../shared/composables/useUiStore.js';
import { CRM_NAV_GROUPS, getMoreNavGroups } from '../navigation.js';
import { prefetchRouteView } from '../router.js';
import {
  isManager,
  logoutStaff,
  me,
  offlineDrafts,
  offlineSyncing,
  openQuickAdd,
  openVisitWizard,
  syncPendingOfflineDrafts,
  userInitial,
} from '../composables/useCrmSession.js';

const QUICK_SHORTCUTS = [
  { name: 'dashboard', path: '/dashboard', icon: '🏠', label: 'งานวันนี้' },
  { name: 'map', path: '/map', icon: '🗺️', label: 'แผนที่ร้าน' },
  { name: 'plans', path: '/plans', icon: '📅', label: 'แผนออกตลาด' },
  { name: 'followups', path: '/followups', icon: '✅', label: 'งานติดตาม' },
];

const searchQuery = ref('');
const activeCategory = ref('all');

const roleGroups = computed(() => getMoreNavGroups(CRM_NAV_GROUPS, isManager.value));
const totalMenuCount = computed(() =>
  roleGroups.value.reduce((sum, group) => sum + group.items.length, 0)
);

const filteredGroups = computed(() => {
  const q = searchQuery.value.trim().toLowerCase();
  const baseGroups =
    activeCategory.value === 'all'
      ? roleGroups.value
      : roleGroups.value.filter((group) => group.id === activeCategory.value);

  if (!q) return baseGroups;

  return baseGroups
    .map((group) => {
      const groupMatch = group.title.toLowerCase().includes(q);
      const items = groupMatch
        ? group.items
        : group.items.filter(
            (item) =>
              item.label.toLowerCase().includes(q) ||
              (item.desc || '').toLowerCase().includes(q) ||
              (item.keywords || '').toLowerCase().includes(q) ||
              item.path.toLowerCase().includes(q)
          );
      return { ...group, items };
    })
    .filter((group) => group.items.length > 0);
});

function resetFilter() {
  searchQuery.value = '';
  activeCategory.value = 'all';
}

async function confirmLogout() {
  const ok = await askConfirm({
    title: 'ยืนยันการออกจากระบบ',
    message: 'ต้องการออกจากระบบ POPSTAR Connect ใช่หรือไม่?',
    confirmLabel: 'ออกจากระบบ',
    cancelLabel: 'อยู่ต่อ',
    tone: 'danger',
  });
  if (ok) {
    await logoutStaff();
  }
}
</script>

<style scoped>
.more-page {
  display: flex;
  flex-direction: column;
  gap: 18px;
  max-width: 960px;
  margin: 0 auto;
}

.more-profile-card {
  background: linear-gradient(135deg, #0f1a33 0%, #1d356e 58%, #312e81 100%);
  border: 0;
  color: #ffffff;
  padding: 20px;
  border-radius: 20px;
  box-shadow: 0 14px 34px rgba(15, 26, 51, 0.22);
}

.more-profile-row {
  display: flex;
  align-items: center;
  gap: 14px;
  flex-wrap: wrap;
}

.more-avatar {
  width: 56px;
  height: 56px;
  border-radius: 16px;
  font-size: 22px;
  background: linear-gradient(135deg, #60a5fa, #818cf8);
  color: #ffffff;
  border: 2px solid rgba(255, 255, 255, 0.28);
  box-shadow: 0 8px 18px rgba(15, 23, 42, 0.28);
}

.more-profile-info {
  flex: 1;
  min-width: 180px;
}

.more-role-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  font-size: 12px;
  font-weight: 600;
  color: #dbeafe;
  background: rgba(255, 255, 255, 0.12);
  padding: 3px 10px;
  border-radius: 99px;
  margin-bottom: 6px;
}

.more-code-pill {
  background: rgba(15, 23, 42, 0.35);
  padding: 1px 7px;
  border-radius: 99px;
  color: #93c5fd;
}

.more-profile-info h2 {
  margin: 0;
  font-size: 20px;
  line-height: 1.3;
  color: #ffffff;
}

.more-profile-info p {
  margin: 2px 0 0;
  font-size: 13px;
  color: #cbd5e1;
}

.more-profile-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.more-profile-btn {
  background: rgba(255, 255, 255, 0.14);
  border-color: rgba(255, 255, 255, 0.22);
  color: #ffffff;
}

.more-logout-btn {
  background: rgba(244, 63, 94, 0.18);
  border-color: rgba(251, 113, 133, 0.35);
  color: #ffe4e6;
}

.more-draft-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px solid rgba(255, 255, 255, 0.14);
  font-size: 14px;
  flex-wrap: wrap;
}

/* One-Hand Quick Dock */
.more-quick-dock {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.more-quick-primary {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.more-cta-card {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 15px 16px;
  border-radius: 18px;
  border: 1px solid var(--border);
  text-align: left;
  min-height: 78px;
  transition: transform 0.14s ease, box-shadow 0.14s ease;
}

.more-cta-card:active {
  transform: scale(0.98);
}

.more-cta-card.primary {
  background: linear-gradient(135deg, #2563eb 0%, #4f46e5 100%);
  border-color: transparent;
  color: #ffffff;
  box-shadow: 0 10px 24px rgba(37, 99, 235, 0.25);
}

.more-cta-card.secondary {
  background: #ffffff;
  color: var(--ink);
  box-shadow: var(--shadow);
}

.more-cta-icon {
  width: 46px;
  height: 46px;
  border-radius: 14px;
  display: grid;
  place-items: center;
  font-size: 24px;
  background: rgba(255, 255, 255, 0.18);
  flex-shrink: 0;
}

.more-cta-card.secondary .more-cta-icon {
  background: #eff6ff;
}

.more-cta-text {
  min-width: 0;
}

.more-cta-text strong {
  display: block;
  font-size: 15.5px;
  line-height: 1.3;
}

.more-cta-text small {
  display: block;
  font-size: 12.5px;
  margin-top: 2px;
  opacity: 0.88;
}

.more-cta-card.secondary .more-cta-text small {
  color: var(--muted);
}

.more-daily-strip {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 8px;
}

.more-strip-pill {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  background: #ffffff;
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 10px 8px;
  min-height: 46px;
  color: var(--ink);
  font-size: 13.5px;
  font-weight: 600;
  box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
  transition: border-color 0.14s ease, transform 0.12s ease;
}

.more-strip-pill:hover {
  border-color: var(--blue);
  color: var(--blue);
}

.more-strip-pill:active {
  transform: scale(0.97);
}

.more-strip-icon {
  font-size: 17px;
}

/* Search & Category Chips */
.more-search-wrap {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.more-cat-pills {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  padding-bottom: 4px;
  -webkit-overflow-scrolling: touch;
}

.more-cat-chip {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 8px 13px;
  border-radius: 99px;
  border: 1px solid var(--border);
  background: #ffffff;
  color: var(--muted);
  font-size: 13.5px;
  font-weight: 600;
  white-space: nowrap;
  min-height: 40px;
  flex-shrink: 0;
  transition: all 0.14s ease;
}

.more-cat-chip.active {
  background: var(--ink);
  border-color: var(--ink);
  color: #ffffff;
  box-shadow: 0 4px 12px rgba(20, 33, 59, 0.18);
}

.more-cat-count {
  font-size: 12px;
  padding: 1px 7px;
  border-radius: 99px;
  background: rgba(148, 163, 184, 0.18);
}

.more-cat-chip.active .more-cat-count {
  background: rgba(255, 255, 255, 0.2);
  color: #ffffff;
}

/* Groups & App Tiles */
.more-groups {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.more-group-head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 11px;
}

.more-group-badge {
  width: 36px;
  height: 36px;
  border-radius: 11px;
  background: #e8efff;
  display: grid;
  place-items: center;
  font-size: 18px;
  flex-shrink: 0;
}

.more-group-title {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
  color: var(--ink);
}

.more-group-sub {
  margin: 1px 0 0;
  font-size: 12.5px;
  color: var(--muted);
}

.more-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.more-tile {
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  align-items: stretch;
  text-align: left;
  gap: 10px;
  padding: 14px 15px;
  min-height: 104px;
  border-radius: 16px;
  color: var(--ink);
  transition: transform 0.14s ease, border-color 0.16s ease, box-shadow 0.16s ease;
}

.more-tile:hover {
  border-color: #93b4ff;
  transform: translateY(-2px);
  box-shadow: 0 10px 24px rgba(37, 99, 235, 0.1);
}

.more-tile:active {
  transform: scale(0.98);
}

.more-tile-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.more-tile-icon {
  width: 44px;
  height: 44px;
  border-radius: 13px;
  display: grid;
  place-items: center;
  font-size: 23px;
  background: #eff6ff;
  flex-shrink: 0;
}

.more-tile-icon.tone-blue {
  background: #e8f0ff;
}

.more-tile-icon.tone-indigo {
  background: #e0e7ff;
}

.more-tile-icon.tone-emerald {
  background: #dcfce7;
}

.more-tile-icon.tone-amber {
  background: #fef3c7;
}

.more-tile-icon.tone-purple {
  background: #f3e8ff;
}

.more-tile-icon.tone-slate {
  background: #f1f5f9;
}

.more-tile-arrow {
  font-size: 20px;
  color: #94a3b8;
  font-weight: 600;
  line-height: 1;
}

.more-tile-body {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.more-tile-label {
  font-size: 14.5px;
  font-weight: 700;
  line-height: 1.35;
  color: var(--ink);
}

.more-tile-desc {
  font-size: 12px;
  color: var(--muted);
  line-height: 1.4;
}

@media (max-width: 720px) {
  .more-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }
  .more-daily-strip {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 520px) {
  .more-profile-card {
    padding: 16px;
  }
  .more-profile-actions {
    width: 100%;
    display: grid;
    grid-template-columns: 1fr 1fr;
  }
  .more-quick-primary {
    grid-template-columns: 1fr;
    gap: 10px;
  }
}
</style>
