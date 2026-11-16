import { createRouter, createWebHistory } from 'vue-router';
import { crmGet, crmQueryClient } from './composables/useCrmSession.js';

const viewLoaders = {
  dashboard: () => import('./views/DashboardView.vue'),
  customers: () => import('./views/CustomersView.vue'),
  pipeline: () => import('./views/PipelineView.vue'),
  'customer-360': () => import('./views/Customer360View.vue'),
  map: () => import('./views/MapView.vue'),
  profile: () => import('./views/MobileProfileView.vue'),
  'quick-add': () => import('./views/QuickAddView.vue'),
  visits: () => import('./views/VisitsView.vue'),
  plans: () => import('./views/PlansView.vue'),
  followups: () => import('./views/FollowupsView.vue'),
  'erp-requests': () => import('./views/ErpRequestsView.vue'),
  matching: () => import('./views/MatchingView.vue'),
  'erp-customers': () => import('./views/ErpCustomersView.vue'),
  sync: () => import('./views/SyncMonitorView.vue'),
  members: () => import('./views/MembersView.vue'),
  'member-registration': () => import('./views/MemberPortalHandoffView.vue'),
  'member-portal': () => import('./views/MemberPortalHandoffView.vue'),
  loyalty: () => import('./views/LoyaltyView.vue'),
  rewards: () => import('./views/CrmRewardsView.vue'),
  member: () => import('./views/MemberLoyaltyView.vue'),
  reports: () => import('./views/ReportsView.vue'),
  settings: () => import('./views/SettingsView.vue'),
};

export const CRM_ROUTES = [
  { path: '/', redirect: '/dashboard' },
  { path: '/dashboard', name: 'dashboard', component: viewLoaders.dashboard, meta: { label: 'ภาพรวมธุรกิจ' } },
  { path: '/customers', name: 'customers', component: viewLoaders.customers, meta: { label: 'ทะเบียนลูกค้า' } },
  { path: '/pipeline', name: 'pipeline', component: viewLoaders.pipeline, meta: { label: 'Lead Pipeline' } },
  {
    path: '/customer-360/:id?',
    name: 'customer-360',
    component: viewLoaders['customer-360'],
    meta: { label: 'Customer 360°', navPath: '/customer-360' },
  },
  { path: '/map', name: 'map', component: viewLoaders.map, meta: { label: 'แผนที่ร้านค้า' } },
  { path: '/profile', name: 'profile', component: viewLoaders.profile, meta: { label: 'Mobile / iPad Field App' } },
  { path: '/quick-add', name: 'quick-add', component: viewLoaders['quick-add'], meta: { label: 'เพิ่มร้านใหม่ (Quick Add)' } },
  { path: '/visits', name: 'visits', component: viewLoaders.visits, meta: { label: 'บันทึกเข้าพบร้านค้า' } },
  { path: '/plans', name: 'plans', component: viewLoaders.plans, meta: { label: 'แผนออกตลาด' } },
  { path: '/followups', name: 'followups', component: viewLoaders.followups, meta: { label: 'งานติดตามผล' } },
  { path: '/erp-requests', name: 'erp-requests', component: viewLoaders['erp-requests'], meta: { label: 'คำขอเปิด ERP', adminOnly: true } },
  { path: '/matching', name: 'matching', component: viewLoaders.matching, meta: { label: 'จับคู่ลูกค้า ERP Matching', adminOnly: true } },
  { path: '/erp-customers', name: 'erp-customers', component: viewLoaders['erp-customers'], meta: { label: 'ลูกค้า ERP Mirror' } },
  { path: '/sync', name: 'sync', component: viewLoaders.sync, meta: { label: 'ERP Sync Monitor', adminOnly: true } },
  { path: '/members', name: 'members', component: viewLoaders.members, meta: { label: 'ทะเบียนสมาชิก', adminOnly: true } },
  {
    path: '/member-registration',
    name: 'member-registration',
    component: viewLoaders['member-registration'],
    meta: { label: 'การสมัครสมาชิก', mode: 'register' },
  },
  {
    path: '/member-portal',
    name: 'member-portal',
    component: viewLoaders['member-portal'],
    meta: { label: 'Member Portal', mode: 'portal' },
  },
  { path: '/loyalty', name: 'loyalty', component: viewLoaders.loyalty, meta: { label: 'กติกา & Ledger แต้ม', adminOnly: true } },
  { path: '/rewards', name: 'rewards', component: viewLoaders.rewards, meta: { label: 'ของรางวัล & แลกแต้ม', adminOnly: true } },
  { path: '/member', name: 'member', component: viewLoaders.member, meta: { label: 'สมาชิก & คะแนน (รวม)', adminOnly: true, hiddenInNav: true } },
  { path: '/reports', name: 'reports', component: viewLoaders.reports, meta: { label: 'รายงานและวิเคราะห์' } },
  { path: '/settings', name: 'settings', component: viewLoaders.settings, meta: { label: 'ตั้งค่า & Access Control', adminOnly: true } },
  { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
];

export const router = createRouter({
  history: createWebHistory(),
  routes: CRM_ROUTES,
  scrollBehavior(_to, _from, saved) {
    if (saved) return saved;
    return { top: 0 };
  },
});

const prefetchedViews = new Set();

export function prefetchRouteView(name, { withData = false } = {}) {
  const loader = viewLoaders[name];
  if (loader && !prefetchedViews.has(name)) {
    prefetchedViews.add(name);
    loader().catch(() => {
      prefetchedViews.delete(name);
    });
  }
  if (!withData) return;

  if (name === 'dashboard') {
    crmQueryClient.prefetchQuery({
      queryKey: ['crm', 'dashboard'],
      queryFn: ({ signal }) => crmGet('/dashboard', { signal }),
    });
  } else if (name === 'customers') {
    crmQueryClient.prefetchQuery({
      queryKey: ['crm', 'master', { page: 1, perPage: 25, q: '', masterFilter: 'all', erpStatus: 'all', leadStage: '' }],
      queryFn: ({ signal }) => crmGet('/customer-master?paginate=1&page=1&per_page=25&filter=all', { signal }),
    });
  } else if (name === 'pipeline') {
    crmQueryClient.prefetchQuery({
      queryKey: ['crm', 'pipeline-leads'],
      queryFn: ({ signal }) => crmGet('/leads', { signal }),
    });
  } else if (name === 'erp-requests') {
    crmQueryClient.prefetchQuery({
      queryKey: ['crm', 'erp-creation-requests'],
      queryFn: ({ signal }) => crmGet('/customer-master/erp-creation-requests', { signal }),
    });
  } else if (name === 'matching') {
    crmQueryClient.prefetchQuery({
      queryKey: ['crm', 'match-candidates', 'pending'],
      queryFn: ({ signal }) => crmGet('/customer-master/match-candidates?status=pending', { signal }),
    });
  } else if (name === 'members') {
    crmQueryClient.prefetchQuery({
      queryKey: ['crm', 'members-registry', ''],
      queryFn: ({ signal }) => crmGet('/members', { signal }),
    });
  } else if (name === 'settings') {
    crmQueryClient.prefetchQuery({
      queryKey: ['crm', 'settings-overview'],
      queryFn: ({ signal }) => crmGet('/settings/overview', { signal }),
    });
  }
}

const NEXT_LIKELY = {
  dashboard: ['customers', 'pipeline', 'visits', 'customer-360'],
  customers: ['customer-360', 'visits', 'map'],
  pipeline: ['customers', 'erp-requests', 'matching'],
  'erp-requests': ['matching', 'customer-360', 'erp-customers'],
  matching: ['customer-360', 'erp-requests', 'erp-customers'],
  members: ['loyalty', 'rewards', 'member-portal'],
  visits: ['followups', 'plans', 'map'],
};

let idleTimer = null;
export function scheduleIdlePrefetch(currentName) {
  if (typeof window === 'undefined') return;
  if (idleTimer) clearTimeout(idleTimer);
  const nextNames = NEXT_LIKELY[currentName] || ['customers', 'visits'];
  idleTimer = setTimeout(() => {
    nextNames.forEach((n) => prefetchRouteView(n, { withData: false }));
  }, 500);
}
