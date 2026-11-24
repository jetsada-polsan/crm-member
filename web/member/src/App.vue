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
    <div v-else class="app app-flat">
      <aside class="sidebar" aria-label="เมนูสมาชิก">
        <div class="logo">
          <div class="logo-mark">P</div>
          <div>
            <h1>POPSTAR <span>Connect</span></h1>
            <small>สมาชิก &amp; สะสมแต้ม</small>
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
        >
          <span class="ni" aria-hidden="true">{{ item.icon }}</span>
          <span>{{ item.label }}</span>
        </RouterLink>

        <div class="sidebar-bottom">
          <div class="avatar">{{ userInitial }}</div>
          <div class="sidebar-user-meta">
            <strong class="text-ellipsis">{{ me?.name }}</strong>
            <small>{{ me?.phone || me?.email }}</small>
          </div>
          <button type="button" class="logout-btn" aria-label="ออกจากระบบ" @click="handleLogout">⏻</button>
        </div>
      </aside>

      <div class="main">
        <header class="topbar">
          <h1 class="topbar-title">{{ route.meta?.label || 'สมาชิก POPSTAR' }}</h1>
          <div class="breadcrumb">
            POPSTAR Connect · สมาชิก / <strong>{{ route.meta?.label || 'หน้าหลัก' }}</strong>
          </div>
          <div class="grow"></div>
          <button type="button" class="topbtn mobile-only-sync" aria-label="ออกจากระบบ" @click="handleLogout">⏻</button>
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
          <footer class="footer">POPSTAR Connect · สะสมแต้ม ดูยอดซื้อ และแลกของรางวัลได้ในที่เดียว</footer>
        </main>
      </div>

      <nav class="mobile-nav mobile-nav-4" aria-label="เมนูสมาชิกมือถือ">
        <RouterLink
          v-for="item in NAV_ITEMS"
          :key="`mob-${item.name}`"
          :to="item.path"
          class="mobile-nav-item"
          active-class="active"
        >
          <span class="mni" aria-hidden="true">{{ item.icon }}</span>
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
import { onMounted, watch } from 'vue';
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router';
import { askConfirm } from '../../shared/composables/useUiStore.js';
import ConfirmDialog from '../../shared/components/ConfirmDialog.vue';
import ToastContainer from '../../shared/components/ToastContainer.vue';
import ErrorDetailDrawer from '../../shared/components/ErrorDetailDrawer.vue';
import PortalAuthScreen from '../../shared/components/PortalAuthScreen.vue';
import AppBootSplash from '../../shared/components/AppBootSplash.vue';
import { authenticateStaffPortal, getPortalUrl } from '../../shared/composables/usePortalRedirect.js';
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
    message: 'ต้องการออกจากระบบสมาชิก POPSTAR Connect ใช่หรือไม่?',
    confirmLabel: 'ออกจากระบบ',
  });
  if (!ok) return;
  await logoutMember();
}

watch(
  () => route.name,
  (n) => {
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
