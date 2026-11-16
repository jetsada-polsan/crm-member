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
            <h1>POPSTAR</h1>
            <small>CRM &amp; Field Sales v4</small>
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
            class="close"
            style="background:#223250;color:#fff;width:32px;height:32px;font-size:13px"
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
          <div class="breadcrumb">
            Popstar CRM
            <template v-if="activeNavMeta?.group?.title">
              / <span class="muted">{{ activeNavMeta.group.title }}</span>
            </template>
            / <strong>{{ route.meta?.label || activeNavMeta?.item?.label || 'Workspace' }}</strong>
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
          <button type="button" class="btn primary" @click="openVisitWizard()">📸 เข้าพบ</button>

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
            Popstar Business Platform · Vue Router + TanStack Query · Bplus ERP Read-Only Mirror Isolation
          </footer>
        </main>
      </div>

      <!-- Mobile 5-Tab Bottom Navigation (<800px) -->
      <nav class="mobile-nav" aria-label="เมนูด่วนมือถือ">
        <RouterLink to="/dashboard" class="mobile-nav-item" active-class="active">
          <span class="mni">🏠</span>
          <span>หน้าหลัก</span>
        </RouterLink>
        <RouterLink to="/customers" class="mobile-nav-item" active-class="active">
          <span class="mni">🏪</span>
          <span>ลูกค้า</span>
        </RouterLink>
        <button type="button" class="mobile-nav-item" @click="openQuickAdd">
          <span class="mni" style="color:var(--blue);font-size:21px">➕</span>
          <span style="color:var(--blue);font-weight:600">เพิ่มร้าน</span>
        </button>
        <RouterLink to="/visits" class="mobile-nav-item" active-class="active">
          <span class="mni">📸</span>
          <span>งานออกตลาด</span>
        </RouterLink>
        <RouterLink to="/profile" class="mobile-nav-item" active-class="active">
          <span class="mni">👤</span>
          <span>โปรไฟล์</span>
        </RouterLink>
      </nav>

      <!-- Mobile Floating Field Action Shortcut -->
      <button
        type="button"
        class="fab-add"
        aria-label="ทางลัดงานออกตลาด"
        @click="mobileQuickOpen = true"
      >
        +
      </button>

      <!-- Mobile Quick Action Sheet -->
      <MobileBottomSheet
        :open="mobileQuickOpen"
        title="ทางลัดออกตลาดภาคสนาม"
        @close="mobileQuickOpen = false"
      >
        <div style="display:grid;gap:10px">
          <button
            type="button"
            class="btn primary"
            style="min-height:48px;justify-content:flex-start"
            @click="runMobileAction(openQuickAdd)"
          >
            ➕ เพิ่มร้านค้าใหม่ (Quick Add)
          </button>
          <button
            type="button"
            class="btn light"
            style="min-height:48px;justify-content:flex-start"
            @click="runMobileAction(() => openVisitWizard())"
          >
            📸 บันทึกหลักฐานการเข้าพบ (Visit Evidence)
          </button>
          <button
            type="button"
            class="btn"
            style="min-height:48px;justify-content:flex-start"
            @click="runMobileAction(() => router.push('/map'))"
          >
            🗺️ แผนที่ร้านค้า &amp; ปักหมุด GPS
          </button>
          <button
            type="button"
            class="btn"
            style="min-height:48px;justify-content:flex-start"
            @click="runMobileAction(() => router.push('/plans'))"
          >
            📅 แผนออกตลาดวันนี้
          </button>
          <button
            type="button"
            class="btn"
            style="min-height:48px;justify-content:flex-start"
            @click="runMobileAction(() => router.push('/followups'))"
          >
            ✅ งานติดตามผล (Follow-ups)
          </button>
          <button
            v-if="offlineDrafts.length"
            type="button"
            class="btn light"
            style="min-height:48px;justify-content:flex-start"
            @click="runMobileAction(syncPendingOfflineDrafts)"
          >
            ☁️ ซิงค์ Draft ค้างส่ง ({{ offlineDrafts.length }} รายการ)
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
    message: 'ต้องการออกจากระบบ Popstar CRM ในเซสชันนี้ใช่หรือไม่?',
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
