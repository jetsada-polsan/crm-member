<template>
  <div class="more-page">
    <section class="card more-profile-card" aria-label="ข้อมูลผู้ใช้งาน">
      <div class="more-profile-row">
        <div class="avatar more-avatar">{{ userInitial }}</div>
        <div class="more-profile-info">
          <h2>{{ me?.name || 'ผู้ใช้งาน' }}</h2>
          <p>
            {{ isManager ? 'ผู้จัดการ / ผู้ดูแลระบบ' : 'พนักงานขาย' }}
            <template v-if="me?.salesperson_code"> · รหัส {{ me.salesperson_code }}</template>
          </p>
        </div>
        <button
          type="button"
          class="btn danger more-logout-btn"
          @click="confirmLogout"
        >
          ออกจากระบบ
        </button>
      </div>

      <div v-if="offlineDrafts.length" class="more-draft-bar">
        <span>มีข้อมูลเข้าพบค้างส่ง <strong>{{ offlineDrafts.length }}</strong> รายการ</span>
        <button
          type="button"
          class="btn light"
          :disabled="offlineSyncing"
          @click="syncPendingOfflineDrafts"
        >
          {{ offlineSyncing ? 'กำลังส่ง...' : 'ส่งข้อมูลทันที' }}
        </button>
      </div>
    </section>

    <section class="more-search-wrap" aria-label="ค้นหาเมนู">
      <label class="more-search-box" for="more-menu-search">
        <span class="more-search-icon" aria-hidden="true">🔍</span>
        <input
          id="more-menu-search"
          v-model="searchQuery"
          type="search"
          class="more-search-input"
          placeholder="ค้นหาเมนู เช่น แผนที่, ติดตามผล, รายงาน"
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
    </section>

    <div v-if="filteredGroups.length" class="more-groups">
      <section v-for="group in filteredGroups" :key="group.id" class="more-group">
        <h3 class="more-group-title">{{ group.title }}</h3>
        <div class="more-grid">
          <RouterLink
            v-for="item in group.items"
            :key="item.name"
            :to="item.path"
            class="card more-tile"
            @mouseenter="prefetchRouteView(item.name, { withData: true })"
          >
            <span class="more-tile-icon" aria-hidden="true">{{ item.icon }}</span>
            <span class="more-tile-label">{{ item.label }}</span>
            <span
              v-if="item.name === 'profile' && offlineDrafts.length > 0"
              class="tag orange more-tile-badge"
            >
              ค้าง {{ offlineDrafts.length }}
            </span>
          </RouterLink>
        </div>
      </section>
    </div>

    <div v-else class="card placeholder more-empty">
      <p>ไม่พบเมนูที่ตรงกับ "{{ searchQuery }}"</p>
      <button type="button" class="btn light" @click="searchQuery = ''">
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
  syncPendingOfflineDrafts,
  userInitial,
} from '../composables/useCrmSession.js';

const searchQuery = ref('');

const roleGroups = computed(() => getMoreNavGroups(CRM_NAV_GROUPS, isManager.value));

const filteredGroups = computed(() => {
  const q = searchQuery.value.trim().toLowerCase();
  if (!q) return roleGroups.value;

  return roleGroups.value
    .map((group) => {
      const groupMatch = group.title.toLowerCase().includes(q);
      const items = groupMatch
        ? group.items
        : group.items.filter(
            (item) =>
              item.label.toLowerCase().includes(q) ||
              (item.keywords || '').toLowerCase().includes(q) ||
              item.path.toLowerCase().includes(q)
          );
      return { ...group, items };
    })
    .filter((group) => group.items.length > 0);
});

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
