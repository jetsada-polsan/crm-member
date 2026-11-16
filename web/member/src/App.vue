<template>
  <div>
    <AppBootSplash v-if="!authChecked" message="กำลังตรวจสอบการเข้าสู่ระบบ…" />

    <PortalAuthScreen
      v-else-if="!logged"
      default-role="member"
      id-prefix="member"
      :submit-login="handleAuthLogin"
      :submit-register="handleMemberRegister"
    />

    <!-- Authenticated Member Workspace -->
    <div v-else class="app">
      <div :class="['sidebar-shade', { open: sidebarOpen }]" @click="sidebarOpen = false"></div>

      <aside :class="['sidebar', { open: sidebarOpen }]" aria-label="เมนูสมาชิก">
        <div class="logo">
          <div class="logo-mark">P</div>
          <div>
            <h1>POPSTAR</h1>
            <small>Member Portal v4</small>
          </div>
        </div>

        <div class="navheading">บริการสมาชิก</div>
        <RouterLink
          v-for="item in NAV_ITEMS"
          :key="item.name"
          :to="item.path"
          class="navitem"
          active-class="active"
          @mouseenter="prefetchMemberView(item.name, { withData: true })"
          @focus="prefetchMemberView(item.name, { withData: true })"
          @click="sidebarOpen = false"
        >
          <span class="ni">{{ item.icon }}</span>
          <span>{{ item.label }}</span>
        </RouterLink>

        <div class="sidebar-bottom">
          <div class="avatar">{{ userInitial }}</div>
          <div style="flex:1;min-width:0">
            <strong style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">{{ me?.name }}</strong>
            <small>{{ me?.phone || me?.email }}</small>
          </div>
          <button
            type="button"
            class="close"
            style="background:#223250;color:#fff;width:32px;height:32px;font-size:13px"
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
            Popstar Member / <strong>{{ route.meta?.label || 'พอร์ทัลสมาชิก' }}</strong>
          </div>
          <div class="grow"></div>
          <div class="profile">
            <div class="avatar">{{ userInitial }}</div>
            <div>
              <strong>{{ me?.name }}</strong>
              <small>{{ me?.phone || me?.email }}</small>
            </div>
          </div>
        </header>

        <main class="content">
          <RouterView v-slot="{ Component, route: r }">
            <Transition name="pop-fade" mode="out-in">
              <KeepAlive :include="['OverviewView', 'RewardsView']">
                <component :is="Component" :key="r.name" />
              </KeepAlive>
            </Transition>
          </RouterView>
          <footer class="footer">
            Popstar Member Portal · แยกบัญชีสมาชิกออกจาก CRM Staff และต้องยืนยันสิทธิ์ก่อนเปิดข้อมูล ERP
          </footer>
        </main>
      </div>

      <nav class="mobile-nav" style="grid-template-columns:repeat(4,1fr)" aria-label="เมนูสมาชิกมือถือ">
        <RouterLink
          v-for="item in NAV_ITEMS"
          :key="`mob-${item.name}`"
          :to="item.path"
          class="mobile-nav-item"
          active-class="active"
        >
          <span class="mni">{{ item.icon }}</span>
          <span>{{ item.shortLabel }}</span>
        </RouterLink>
      </nav>
    </div>

    <ConfirmDialog />
    <ErrorDetailDrawer />
    <ToastContainer />
  </div>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue';
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router';
import { askConfirm } from '../../shared/composables/useUiStore.js';
import ConfirmDialog from '../../shared/components/ConfirmDialog.vue';
import ToastContainer from '../../shared/components/ToastContainer.vue';
import ErrorDetailDrawer from '../../shared/components/ErrorDetailDrawer.vue';
import PortalAuthScreen from '../../shared/components/PortalAuthScreen.vue';
import AppBootSplash from '../../shared/components/AppBootSplash.vue';
import {
  authenticateStaffPortal,
  getPortalUrl,
} from '../../shared/composables/usePortalRedirect.js';
import { prefetchMemberView, scheduleMemberPrefetch } from './router.js';
import {
  authChecked,
  logged,
  loginMember,
  logoutMember,
  me,
  refreshMemberSession,
  registerMember,
  userInitial,
} from './composables/useMemberSession.js';

const route = useRoute();
const router = useRouter();

const sidebarOpen = ref(false);

const NAV_ITEMS = [
  { name: 'overview', path: '/overview', icon: '🏠', label: 'ภาพรวมสมาชิก', shortLabel: 'ภาพรวม' },
  { name: 'invoices', path: '/invoices', icon: '🧾', label: 'ประวัติยอดซื้อ', shortLabel: 'ยอดซื้อ' },
  { name: 'rewards', path: '/rewards', icon: '🎁', label: 'แลกของรางวัล', shortLabel: 'รางวัล' },
  { name: 'binding', path: '/binding', icon: '🔗', label: 'ผูกบัญชี LINE & ร้านค้า', shortLabel: 'ผูกบัญชี' },
];

async function handleAuthLogin({ roleMode, identifier, password }) {
  if (roleMode === 'staff') {
    const res = await authenticateStaffPortal({ email: identifier, password });
    window.location.assign(getPortalUrl('crm', res?.redirect_path || '/dashboard'));
    return { redirected: true };
  }
  await loginMember({ identifier, password });
  if (route.path === '/' || !route.name) await router.replace('/overview');
  scheduleMemberPrefetch(route.name || 'overview');
  return { redirected: false };
}

async function handleMemberRegister(payload) {
  await registerMember(payload);
  if (route.path === '/' || !route.name) await router.replace('/overview');
  scheduleMemberPrefetch(route.name || 'overview');
  return { redirected: false };
}

async function handleLogout() {
  const ok = await askConfirm({
    title: 'ยืนยันการออกจากระบบ',
    message: 'ต้องการออกจากระบบ Popstar Member Portal ใช่หรือไม่?',
    confirmLabel: 'ออกจากระบบ',
  });
  if (!ok) return;
  await logoutMember();
}

watch(
  () => route.name,
  (n) => {
    sidebarOpen.value = false;
    if (logged.value && n) scheduleMemberPrefetch(n);
  }
);

onMounted(async () => {
  await refreshMemberSession();
  if (logged.value) {
    scheduleMemberPrefetch(route.name || 'overview');
  }
});
</script>
