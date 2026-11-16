<template>
  <div>
    <!-- Session check on refresh: neutral loader, never the login form -->
    <AppBootSplash v-if="!authChecked" message="กำลังตรวจสอบการเข้าสู่ระบบ…" />

    <!-- Futuristic Auth Shell (only when really signed out) -->
    <PortalAuthScreen
      v-else-if="!logged"
      default-role="staff"
      id-prefix="crm"
      :submit-login="handleAuthLogin"
      :submit-register="handleMemberRegister"
    />

    <!-- Modern Authenticated SPA Workspace -->
    <div v-else class="app">
      <div :class="['sidebar-shade', { open: sidebarOpen }]" @click="sidebarOpen = false"></div>

      <aside :class="['sidebar', { open: sidebarOpen }]" aria-label="เมนูหลัก CRM">
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
          @navigate="sidebarOpen = false"
        />

        <div class="sidebar-bottom">
          <div class="avatar">{{ userInitial }}</div>
          <div style="flex:1;min-width:0">
            <strong style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ me?.name }}</strong>
            <small>{{ me?.role }} {{ me?.salesperson_code ? `· ${me.salesperson_code}` : '' }}</small>
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
            class="mobile-trigger topbtn"
            aria-label="เปิดเมนู"
            @click="sidebarOpen = true"
          >
            ☰
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
            aria-label="เปิดค้นหาด่วน (Ctrl+K)"
            @click="uiState.commandPaletteOpen = true"
          >
            <span>⌘ เมนูด่วน &amp; ค้นหา</span>
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
              ☁️ ซิงค์ ({{ offlineDrafts.length }})
            </button>
            <button type="button" class="btn light" @click="openQuickAdd">+ เพิ่มร้าน</button>
            <button type="button" class="btn primary" @click="openVisitWizard()">📸 บันทึกเข้าพบ</button>
          </div>

          <button
            v-if="offlineDrafts.length"
            type="button"
            class="topbtn mobile-only-sync"
            :disabled="offlineSyncing"
            :aria-label="`ซิงค์ข้อมูลค้างส่ง ${offlineDrafts.length} รายการ`"
            @click="syncPendingOfflineDrafts"
          >
            ☁️
          </button>

          <div class="profile">
            <div class="avatar">{{ userInitial }}</div>
            <div>
              <strong>{{ me?.name }}</strong>
              <small>{{ me?.salesperson_code || me?.role }}</small>
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
          <span>หน้าหลัก</span>
        </RouterLink>
        <RouterLink to="/customers" class="mobile-nav-item" active-class="active">
          <span class="mni" aria-hidden="true">🏪</span>
          <span>ลูกค้า</span>
        </RouterLink>
        <button
          type="button"
          class="mobile-nav-item mobile-nav-main"
          aria-label="งานด่วน: เพิ่มร้าน บันทึกเข้าพบ"
          @click="mobileQuickOpen = true"
        >
          <span class="mni" aria-hidden="true">＋</span>
          <span>งานด่วน</span>
        </button>
        <RouterLink to="/visits" class="mobile-nav-item" active-class="active">
          <span class="mni" aria-hidden="true">📍</span>
          <span>ออกตลาด</span>
        </RouterLink>
        <button
          type="button"
          :class="['mobile-nav-item', { active: sidebarOpen }]"
          aria-label="เปิดเมนูทั้งหมด"
          @click="sidebarOpen = true"
        >
          <span class="mni" aria-hidden="true">☰</span>
          <span>เมนู</span>
        </button>
      </nav>

      <!-- Quick actions sheet (center button of the bottom bar) -->
      <MobileBottomSheet
        :open="mobileQuickOpen"
        title="งานด่วน"
        @close="mobileQuickOpen = false"
      >
        <div class="quick-grid">
          <button type="button" class="quick-tile primary" @click="runMobileAction(() => openVisitWizard())">
            <span class="quick-icon" aria-hidden="true">📸</span>
            <strong>บันทึกเข้าพบ</strong>
            <small>ถ่ายรูป + พิกัด GPS</small>
          </button>
          <button type="button" class="quick-tile" @click="runMobileAction(openQuickAdd)">
            <span class="quick-icon" aria-hidden="true">➕</span>
            <strong>เพิ่มร้านใหม่</strong>
            <small>ตรวจชื่อ/เบอร์ซ้ำให้</small>
          </button>
          <button type="button" class="quick-tile" @click="runMobileAction(() => router.push('/plans'))">
            <span class="quick-icon" aria-hidden="true">📅</span>
            <strong>แผนวันนี้</strong>
            <small>ร้านที่ต้องไปเยี่ยม</small>
          </button>
          <button type="button" class="quick-tile" @click="runMobileAction(() => router.push('/followups'))">
            <span class="quick-icon" aria-hidden="true">✅</span>
            <strong>งานติดตาม</strong>
            <small>งานค้าง / นัดหมาย</small>
          </button>
          <button type="button" class="quick-tile" @click="runMobileAction(() => router.push('/map'))">
            <span class="quick-icon" aria-hidden="true">🗺️</span>
            <strong>แผนที่ร้าน</strong>
            <small>ร้านใกล้ตัว</small>
          </button>
          <button type="button" class="quick-tile" @click="runMobileAction(() => router.push('/pipeline'))">
            <span class="quick-icon" aria-hidden="true">🎯</span>
            <strong>โอกาสการขาย</strong>
            <small>สถานะลูกค้าใหม่</small>
          </button>
          <button
            v-if="offlineDrafts.length"
            type="button"
            class="quick-tile wide"
            @click="runMobileAction(syncPendingOfflineDrafts)"
          >
            <span class="quick-icon" aria-hidden="true">☁️</span>
            <strong>ส่งข้อมูลค้างส่ง ({{ offlineDrafts.length }})</strong>
            <small>บันทึกตอนไม่มีเน็ต</small>
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

const sidebarOpen = ref(false);
const mobileQuickOpen = ref(false);

const visibleNavGroups = computed(() => filterNavGroups(CRM_NAV_GROUPS, isManager.value));

const activeNavMeta = computed(() =>
  findNavMetaByRouteName(visibleNavGroups.value, String(route.name || ''))
);

const pageTitle = computed(
  () => activeNavMeta.value?.item?.label || route.meta?.label || 'POPSTAR Connect'
);

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
      id: 'act:quick-add',
      icon: '➕',
      label: 'เพิ่มร้านค้าใหม่ (Quick Add)',
      hint: 'ตรวจชื่อ/เบอร์/พิกัดซ้ำก่อนบันทึก',
      section: 'คำสั่งด่วน',
      action: openQuickAdd,
    },
    {
      id: 'act:visit',
      icon: '📸',
      label: 'บันทึกเข้าพบร้านค้า (Visit Evidence)',
      hint: 'GPS + รูปถ่ายหน้างาน + แบบสอบถาม 6 ข้อ',
      section: 'คำสั่งด่วน',
      action: () => openVisitWizard(),
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
    sidebarOpen.value = false;
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
