<template>
  <div>
    <!-- Session check on refresh: neutral loader, never the login form -->
    <AppBootSplash v-if="!authChecked" message="กำลังตรวจสอบการเข้าสู่ระบบ…" />

    <!-- Auth Shell (only when really signed out) -->
    <PortalAuthScreen
      v-else-if="!logged"
      default-role="staff"
      id-prefix="crm"
      :submit-login="handleAuthLogin"
      :submit-register="handleMemberRegister"
    />

    <RouterView v-else-if="route.meta.presentation" />

    <!-- Authenticated SPA Workspace -->
    <div v-else class="app app-flat">
      <div
        :class="['sidebar-shade', { open: mobileSidebarOpen }]"
        @click="mobileSidebarOpen = false"
      ></div>
      <aside :class="['sidebar', { open: mobileSidebarOpen }]" aria-label="เมนูหลัก POPSTAR Connect">
        <div class="logo">
          <div class="logo-mark">P</div>
          <div>
            <h1>POPSTAR <span>Connect</span></h1>
            <small>ทีมขาย &amp; ออกตลาด</small>
          </div>
        </div>

        <CrmSidebarNav
          :groups="visibleNavGroups"
          :active-route-name="String(route.name || '')"
          :offline-draft-count="offlineDrafts.length"
          @prefetch="(name) => prefetchRouteView(name, { withData: true })"
          @navigate="mobileSidebarOpen = false"
          @open-visit="mobileSidebarOpen = false; openVisitWizard()"
          @open-quick-add="mobileSidebarOpen = false; openQuickAdd()"
        />

        <div class="sidebar-bottom">
          <div class="avatar">{{ userInitial }}</div>
          <div class="sidebar-user-meta">
            <strong class="text-ellipsis">{{ me?.name }}</strong>
            <small>
              {{ roleLabel }}
              <template v-if="me?.salesperson_code"> · รหัส {{ me.salesperson_code }}</template>
              <template v-if="Array.isArray(me?.reservation_doc_types) && me.reservation_doc_types.length">
                · ใบจอง {{ me.reservation_doc_types.join(',') }}
              </template>
            </small>
          </div>
          <button
            type="button"
            class="logout-btn"
            title="ออกจากระบบ"
            aria-label="ออกจากระบบ"
            @click="handleLogout"
          >
            ⏻
          </button>
        </div>
      </aside>

      <div class="main">
        <header class="topbar">
          <button
            type="button"
            class="topbtn mobile-menu-btn"
            aria-label="เปิดเมนูหลัก"
            @click="mobileSidebarOpen = !mobileSidebarOpen"
          >
            ☰
          </button>
          <button
            v-if="showMobileBack"
            type="button"
            class="topbtn mobile-back-btn"
            aria-label="กลับหน้าเมนู"
            @click="handleMobileBack"
          >
            ←
          </button>
          <h1 class="topbar-title">{{ pageTitle }}</h1>
          <div class="breadcrumb">
            POPSTAR Connect
            <template v-if="activeNavMeta?.group?.title">
              / <span class="muted">{{ activeNavMeta.group.title }}</span>
            </template>
            / <strong>{{ pageTitle }}</strong>
          </div>
          <div class="grow"></div>

          <button
            type="button"
            class="cmd-chip"
            aria-label="ค้นหาเมนูและหน้าต่างๆ (Ctrl+K)"
            @click="uiState.commandPaletteOpen = true"
          >
            <span>⌘ ค้นหาเมนู</span>
            <kbd>Ctrl K</kbd>
          </button>

          <div class="topbar-actions">
            <button
              v-if="offlineDrafts.length"
              type="button"
              class="btn light"
              :disabled="offlineSyncing"
              @click="syncPendingOfflineDrafts"
            >
              ☁️ ส่งข้อมูล ({{ offlineDrafts.length }})
            </button>
            <button type="button" class="btn light" @click="openQuickAdd">+ เพิ่มร้าน</button>
            <button type="button" class="btn primary" @click="openVisitWizard()">📸 บันทึกเข้าพบ</button>
          </div>

          <div class="mobile-top-quick">
            <button
              v-if="offlineDrafts.length"
              type="button"
              class="topbtn"
              :disabled="offlineSyncing"
              :aria-label="`ส่งข้อมูลค้างส่ง ${offlineDrafts.length} รายการ`"
              @click="syncPendingOfflineDrafts"
            >
              ☁️
            </button>
            <button
              type="button"
              class="topbtn"
              aria-label="ค้นหาเมนูทั้งหมด"
              @click="uiState.commandPaletteOpen = true"
            >
              🔍
            </button>
            <button
              type="button"
              class="topbtn mobile-top-visit"
              aria-label="บันทึกเข้าพบร้านค้า"
              @click="openVisitWizard()"
            >
              📸
            </button>
          </div>

          <div class="profile">
            <div class="avatar">{{ userInitial }}</div>
            <div>
              <strong>{{ me?.name }}</strong>
              <small>{{ me?.salesperson_code || roleLabel }}</small>
            </div>
          </div>
        </header>

        <main class="content">
          <RouterView v-slot="{ Component, route: r }">
            <Transition name="pop-fade" mode="out-in">
              <KeepAlive :include="['DashboardView', 'CustomersView']">
                <component :is="Component" :key="r.name" />
              </KeepAlive>
            </Transition>
          </RouterView>
          <footer class="footer">
            POPSTAR Connect · เชื่อมทีมขาย ร้านค้า และสมาชิก ไว้ในที่เดียว
          </footer>
        </main>
      </div>

      <!-- Bottom tab bar for phones & tablets (<=1024px) -->
      <nav class="mobile-nav" aria-label="เมนูหลักบนมือถือ">
        <RouterLink to="/dashboard" class="mobile-nav-item" active-class="active">
          <span class="mni" aria-hidden="true">🏠</span>
          <span>วันนี้</span>
        </RouterLink>
        <RouterLink to="/customers" class="mobile-nav-item" active-class="active">
          <span class="mni" aria-hidden="true">🏪</span>
          <span>ลูกค้า</span>
        </RouterLink>
        <button
          type="button"
          class="mobile-nav-item mobile-nav-main"
          aria-label="เพิ่มหรือบันทึกเข้าพบ"
          @click="mobileQuickOpen = true"
        >
          <span class="mni" aria-hidden="true">＋</span>
          <span>บันทึก</span>
        </button>
        <RouterLink to="/visits" class="mobile-nav-item" active-class="active">
          <span class="mni" aria-hidden="true">📍</span>
          <span>ออกตลาด</span>
        </RouterLink>
        <RouterLink to="/more" :class="['mobile-nav-item', { active: isMoreSectionActive }]">
          <span class="mni" aria-hidden="true">⋯</span>
          <span>เพิ่มเติม</span>
          <span v-if="offlineDrafts.length" class="mobile-nav-dot" aria-hidden="true"></span>
        </RouterLink>
      </nav>

      <!-- Center (+) Bottom Sheet: Primary actions + quick jumps -->
      <MobileBottomSheet
        :open="mobileQuickOpen"
        title="ทำรายการด่วน (มือเดียว)"
        @close="mobileQuickOpen = false"
      >
        <div class="sheet-two-actions">
          <button
            type="button"
            class="quick-tile primary sheet-big-btn"
            @click="runMobileAction(() => openVisitWizard())"
          >
            <span class="quick-icon" aria-hidden="true">📸</span>
            <div>
              <strong>บันทึกเข้าพบ</strong>
              <small>ถ่ายรูปและบันทึกพิกัดร้านค้า</small>
            </div>
          </button>
          <button
            type="button"
            class="quick-tile sheet-big-btn"
            @click="runMobileAction(openQuickAdd)"
          >
            <span class="quick-icon" aria-hidden="true">🏪</span>
            <div>
              <strong>เพิ่มร้านใหม่</strong>
              <small>บันทึกร้านค้าใหม่พร้อมตรวจเบอร์ซ้ำ</small>
            </div>
          </button>
        </div>

        <div class="sheet-quick-links">
          <button type="button" class="sheet-mini-link" @click="runMobileNav('/map')">
            <span>🗺️</span>
            <span>แผนที่ร้าน</span>
          </button>
          <button type="button" class="sheet-mini-link" @click="runMobileNav('/plans')">
            <span>📅</span>
            <span>แผนออกตลาด</span>
          </button>
          <button type="button" class="sheet-mini-link" @click="runMobileNav('/followups')">
            <span>✅</span>
            <span>งานติดตาม</span>
          </button>
          <button
            type="button"
            class="sheet-mini-link"
            @click="runMobileAction(() => { uiState.commandPaletteOpen = true; })"
          >
            <span>🔍</span>
            <span>ค้นหาเมนู</span>
          </button>
        </div>
      </MobileBottomSheet>

      <!-- Global Feature Modals & Drawers -->
      <QuickAddShopModal />
      <VisitEvidenceWizard />
      <CustomerDetailDrawer />

      <!-- Global Command Palette (Ctrl/Cmd+K) -->
      <CommandPalette
        :open="uiState.commandPaletteOpen"
        :items="commandItems"
        @close="uiState.commandPaletteOpen = false"
        @select="onCommandSelect"
      />

      <!-- Photo Evidence Lightbox -->
      <ImageLightbox
        :open="lightboxState.open"
        :items="lightboxState.items"
        :start-index="lightboxState.startIndex"
        @close="closeLightbox"
      />
    </div>

    <!-- Global Shared Confirmation, Toasts & Error Diagnostics -->
    <ConfirmDialog />
    <ErrorDetailDrawer />
    <ToastContainer />
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router';
import { askConfirm, uiState } from '../../shared/composables/useUiStore.js';
import ConfirmDialog from '../../shared/components/ConfirmDialog.vue';
import ToastContainer from '../../shared/components/ToastContainer.vue';
import ErrorDetailDrawer from '../../shared/components/ErrorDetailDrawer.vue';
import ImageLightbox from '../../shared/components/ImageLightbox.vue';
import CommandPalette from '../../shared/components/CommandPalette.vue';
import MobileBottomSheet from '../../shared/components/MobileBottomSheet.vue';
import PortalAuthScreen from '../../shared/components/PortalAuthScreen.vue';
import AppBootSplash from '../../shared/components/AppBootSplash.vue';
import {
  authenticateMemberPortal,
  getPortalUrl,
  registerMemberPortal,
} from '../../shared/composables/usePortalRedirect.js';
import QuickAddShopModal from './components/QuickAddShopModal.vue';
import VisitEvidenceWizard from './components/VisitEvidenceWizard.vue';
import CustomerDetailDrawer from './components/CustomerDetailDrawer.vue';
import CrmSidebarNav from './components/CrmSidebarNav.vue';
import {
  CRM_NAV_GROUPS,
  filterNavGroups,
  findNavMetaByRouteName,
} from './navigation.js';
import { CRM_ROUTES, prefetchRouteView, scheduleIdlePrefetch } from './router.js';
import {
  authChecked,
  closeLightbox,
  isManager,
  lightboxState,
  logged,
  loginStaff,
  logoutStaff,
  me,
  offlineDrafts,
  offlineSyncing,
  openQuickAdd,
  openVisitWizard,
  refreshSession,
  reloadOfflineDrafts,
  syncPendingOfflineDrafts,
  userInitial,
} from './composables/useCrmSession.js';

const route = useRoute();
const router = useRouter();

const mobileQuickOpen = ref(false);
const mobileSidebarOpen = ref(false);

const visibleNavGroups = computed(() => filterNavGroups(CRM_NAV_GROUPS, isManager.value));

const roleLabel = computed(() => {
  if (me.value?.role === 'admin') return 'แอดมินระบบ';
  if (me.value?.role === 'executive') return 'ผู้บริหาร (ดูทั้งหมด)';
  if (me.value?.role === 'manager') return 'ผู้จัดการ';
  return 'พนักงานขาย';
});

const activeNavMeta = computed(() =>
  findNavMetaByRouteName(visibleNavGroups.value, String(route.name || ''))
);

const pageTitle = computed(
  () => activeNavMeta.value?.item?.label || route.meta?.label || 'POPSTAR Connect'
);

const isMoreSectionActive = computed(() => {
  if (route.path === '/more') return true;
  return Boolean(activeNavMeta.value?.group?.showInMore);
});

const showMobileBack = computed(() => {
  return route.path !== '/dashboard' && route.path !== '/customers' && route.path !== '/visits' && route.path !== '/more';
});

function handleMobileBack() {
  if (activeNavMeta.value?.group?.showInMore) {
    router.push('/more');
    return;
  }
  router.push('/dashboard');
}

function runMobileNav(path) {
  mobileQuickOpen.value = false;
  router.push(path);
}

const commandItems = computed(() => {
  const routes = CRM_ROUTES.filter((r) => r.name && !r.meta?.hiddenInNav && (!r.meta?.adminOnly || isManager.value)).map((r) => {
    const meta = findNavMetaByRouteName(CRM_NAV_GROUPS, r.name);
    return {
      id: `route:${r.name}`,
      icon: meta?.item?.icon || '→',
      label: meta?.item?.label || r.meta?.label || r.name,
      hint: r.meta?.navPath || r.path,
      keywords: meta?.item?.keywords || '',
      section: meta?.group?.title || 'หน้าเว็บ',
      action: () => router.push(r.meta?.navPath || r.path),
    };
  });
  return [
    {
      id: 'act:visit',
      icon: '📸',
      label: 'บันทึกเข้าพบ',
      hint: 'ถ่ายรูป พิกัดร้าน และแบบสอบถามเข้าพบ',
      section: 'คำสั่งด่วน',
      action: () => openVisitWizard(),
    },
    {
      id: 'act:quick-add',
      icon: '🏪',
      label: 'เพิ่มร้านใหม่',
      hint: 'ตรวจชื่อและเบอร์โทรซ้ำก่อนบันทึก',
      section: 'คำสั่งด่วน',
      action: openQuickAdd,
    },
    ...routes,
  ];
});

function onCommandSelect(item) {
  item?.action?.();
}

function runMobileAction(fn) {
  mobileQuickOpen.value = false;
  fn?.();
}

async function handleAuthLogin({ roleMode, identifier, password }) {
  if (roleMode === 'member') {
    const res = await authenticateMemberPortal({ identifier, password });
    window.location.assign(getPortalUrl('member', res?.redirect_path || '/overview'));
    return { redirected: true };
  }
  await loginStaff(identifier, password);
  if (route.path === '/' || !route.name) {
    await router.replace('/dashboard');
  }
  scheduleIdlePrefetch(route.name || 'dashboard');
  return { redirected: false };
}

async function handleMemberRegister(payload) {
  const res = await registerMemberPortal(payload);
  window.location.assign(getPortalUrl('member', res?.redirect_path || '/overview'));
  return { redirected: true };
}

async function handleLogout() {
  const ok = await askConfirm({
    title: 'ยืนยันการออกจากระบบ',
    message: 'ต้องการออกจากระบบ POPSTAR Connect ใช่หรือไม่?',
    confirmLabel: 'ออกจากระบบ',
    cancelLabel: 'อยู่ต่อ',
  });
  if (!ok) return;
  await logoutStaff();
}

function onGlobalKeydown(e) {
  if (!logged.value) return;
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault();
    uiState.commandPaletteOpen = !uiState.commandPaletteOpen;
  }
}

function onWindowOnline() {
  reloadOfflineDrafts();
}

watch(
  () => route.name,
  (name) => {
    mobileQuickOpen.value = false;
    mobileSidebarOpen.value = false;
    if (logged.value && name) {
      scheduleIdlePrefetch(name);
    }
  }
);

onMounted(async () => {
  window.addEventListener('keydown', onGlobalKeydown);
  window.addEventListener('online', onWindowOnline);
  await refreshSession();
  if (logged.value) {
    scheduleIdlePrefetch(route.name || 'dashboard');
  }
});

onUnmounted(() => {
  window.removeEventListener('keydown', onGlobalKeydown);
  window.removeEventListener('online', onWindowOnline);
});
</script>

<style scoped>
.mobile-menu-btn,
.mobile-back-btn,
.mobile-top-quick {
  display: none;
}

.mobile-nav-dot {
  position: absolute;
  top: 7px;
  right: 22%;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--orange);
  border: 2px solid #ffffff;
}

.sheet-quick-links {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 8px;
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid var(--border);
}

.sheet-mini-link {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  padding: 10px 6px;
  border-radius: 13px;
  border: 1px solid var(--border);
  background: #f8fafc;
  color: var(--ink);
  font-size: 12.5px;
  font-weight: 600;
  min-height: 64px;
}

.sheet-mini-link span:first-child {
  font-size: 20px;
  line-height: 1.1;
}

.sheet-mini-link:active {
  transform: scale(0.97);
  background: var(--blue-soft);
}

@media (max-width: 1024px) {
  .mobile-menu-btn,
  .mobile-back-btn {
    display: grid;
    width: 40px;
    height: 40px;
    border-radius: 11px;
    font-size: 18px;
    font-weight: 700;
    background: #f0f9ff;
    border-color: #bae6fd;
    color: #0284c7;
  }

  .mobile-top-quick {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .mobile-top-visit {
    background: var(--blue-soft);
    border-color: #c7d9ff;
    color: var(--blue);
  }
}

@media (max-width: 420px) {
  .sheet-quick-links {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
