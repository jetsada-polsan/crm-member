<template>
  <div>
    <!-- ===================================================== -->
    <!-- MOBILE NATIVE APP MENU SCREEN (<= 800px — Matches Mockup) -->
    <!-- ===================================================== -->
    <div class="mob-only mob-more-shell">
      <!-- Clean White Profile Card -->
      <div class="card mob-profile-card">
        <div class="mob-profile-avatar">{{ userInitial }}</div>
        <div class="app-list-body">
          <strong style="display: block; font-size: 15.5px; line-height: 1.25">{{ me?.name || 'ผู้ใช้งาน' }}</strong>
          <span class="app-list-sub">{{ profileSubtitle }}</span>
        </div>
        <span class="tag green">ออนไลน์</span>
        <button type="button" class="mob-logout-icon" aria-label="ออกจากระบบ" title="ออกจากระบบ" @click="confirmLogout">
          ⏻
        </button>
      </div>

      <div v-if="offlineDrafts.length" class="notice row-between" style="margin-bottom: 0">
        <span
          >ค้างส่ง <b>{{ offlineDrafts.length }}</b> รายการ</span
        >
        <button
          type="button"
          class="btn primary"
          style="min-height: 36px; padding: 6px 12px; font-size: 12.5px"
          :disabled="offlineSyncing"
          @click="syncPendingOfflineDrafts"
        >
          {{ offlineSyncing ? 'กำลังส่ง...' : 'ส่งทันที' }}
        </button>
      </div>

      <!-- Clean iOS Grouped List Sections -->
      <section v-for="group in roleGroups" :key="`mg-${group.id}`">
        <div class="mob-group-label">{{ group.title }}</div>
        <div class="app-group-list">
          <RouterLink
            v-for="item in group.items"
            :key="`mi-${item.name}`"
            :to="item.path"
            class="app-list-row"
            @mouseenter="prefetchRouteView(item.name, { withData: true })"
          >
            <span :class="['app-icon-box', iconToneClass(item.tone || group.tone)]">{{ item.icon }}</span>
            <div class="app-list-body">
              <strong class="app-list-title">{{ item.label }}</strong>
              <span v-if="item.desc" class="app-list-sub">{{ item.desc }}</span>
            </div>
            <span v-if="item.name === 'profile' && offlineDrafts.length > 0" class="tag orange">{{
              offlineDrafts.length
            }}</span>
            <span v-else class="app-chevron" aria-hidden="true">›</span>
          </RouterLink>
        </div>
      </section>
    </div>

    <!-- ===================================================== -->
    <!-- DESKTOP LAUNCHER (> 800px)                            -->
    <!-- ===================================================== -->
    <div class="more-page desktop-only">
      <section class="card more-profile-card" aria-label="ข้อมูลผู้ใช้งาน">
        <div class="more-profile-row">
          <div class="avatar more-avatar">{{ userInitial }}</div>
          <div class="more-profile-info">
            <div class="more-role-pill">
              <span>{{ roleTitle }}</span>
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
            <RouterLink to="/profile" class="btn more-profile-btn">📱 โปรไฟล์</RouterLink>
            <button type="button" class="btn danger more-logout-btn" @click="confirmLogout">⏻ ออกจากระบบ</button>
          </div>
        </div>
      </section>

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
          <button v-if="searchQuery" type="button" class="btn light more-search-clear" @click="searchQuery = ''">
            ล้าง
          </button>
        </label>
      </section>

      <div v-if="filteredGroups.length" class="more-groups">
        <section v-for="group in filteredGroups" :key="group.id" class="more-group">
          <div class="more-group-head">
            <div class="more-group-badge" aria-hidden="true">{{ group.icon || '📁' }}</div>
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
              @mouseenter="prefetchRouteView(item.name, { withData: true })"
            >
              <div class="more-tile-top">
                <span :class="['app-icon-box', iconToneClass(item.tone || group.tone)]">{{ item.icon }}</span>
                <span class="app-chevron" aria-hidden="true">›</span>
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
        <button type="button" class="btn light" @click="searchQuery = ''">แสดงเมนูทั้งหมด</button>
      </div>
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
  syncPendingOfflineDrafts,
  userInitial,
} from '../composables/useCrmSession.js';

const searchQuery = ref('');

const roleGroups = computed(() => getMoreNavGroups(CRM_NAV_GROUPS, isManager.value));

const roleTitle = computed(() => {
  if (me.value?.role === 'admin') return '👑 แอดมินระบบ';
  if (me.value?.role === 'executive') return '👔 ผู้บริหาร';
  if (isManager.value) return '👑 ผู้จัดการ';
  return '🚀 พนักงานขาย';
});

const profileSubtitle = computed(() => {
  const docs = Array.isArray(me.value?.reservation_doc_types) ? me.value.reservation_doc_types : [];
  if (docs.length)
    return `สาย ${docs.join(', ')}${me.value?.salesperson_code ? ` · รหัส ${me.value.salesperson_code}` : ''}`;
  if (me.value?.salesperson_code) return `รหัสเซลล์ ${me.value.salesperson_code}`;
  return `${roleTitle.value} · POPSTAR Connect`;
});

const filteredGroups = computed(() => {
  const q = searchQuery.value.trim().toLowerCase();
  if (!q) return roleGroups.value;
  return roleGroups.value
    .map((group) => ({
      ...group,
      items: group.title.toLowerCase().includes(q)
        ? group.items
        : group.items.filter((item) =>
            [item.label, item.desc, item.keywords, item.path].filter(Boolean).join(' ').toLowerCase().includes(q)
          ),
    }))
    .filter((group) => group.items.length > 0);
});

function iconToneClass(tone) {
  if (tone === 'amber') return 'amber';
  if (tone === 'emerald') return 'green';
  if (tone === 'purple') return 'purple';
  if (tone === 'slate') return 'slate';
  return '';
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
.mob-more-shell {
  display: none;
}

@media (max-width: 800px) {
  .mob-more-shell {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }
}

.mob-profile-card {
  padding: 14px;
  display: flex;
  align-items: center;
  gap: 12px;
}

.mob-profile-avatar {
  width: 48px;
  height: 48px;
  border-radius: 16px;
  background: linear-gradient(135deg, #0284c7, #0ea5e9);
  color: #ffffff;
  font-size: 19px;
  font-weight: 700;
  display: grid;
  place-items: center;
  flex-shrink: 0;
}

.mob-logout-icon {
  width: 36px;
  height: 36px;
  border-radius: 999px;
  border: 1px solid #fecdd3;
  background: #fff1f2;
  color: #be123c;
  display: grid;
  place-items: center;
  font-size: 15px;
  flex-shrink: 0;
}

.mob-group-label {
  font-size: 12.5px;
  font-weight: 700;
  color: var(--muted);
  padding: 0 6px 6px;
}

.more-page {
  display: flex;
  flex-direction: column;
  gap: 18px;
  max-width: 960px;
  margin: 0 auto;
}

@media (max-width: 800px) {
  .more-page {
    display: none;
  }
}

.more-profile-card {
  background: linear-gradient(135deg, #0f1a33 0%, #1d356e 58%, #312e81 100%);
  border: 0;
  color: #ffffff;
  padding: 20px;
  border-radius: 20px;
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
  color: #ffffff;
}
.more-profile-info p {
  margin: 2px 0 0;
  font-size: 13px;
  color: #cbd5e1;
}
.more-profile-actions {
  display: flex;
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
  gap: 10px;
  padding: 14px 15px;
  min-height: 104px;
  border-radius: 16px;
  color: var(--ink);
}
.more-tile-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
.more-tile-body {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.more-tile-label {
  font-size: 14.5px;
  font-weight: 700;
  color: var(--ink);
}
.more-tile-desc {
  font-size: 12px;
  color: var(--muted);
}
</style>
