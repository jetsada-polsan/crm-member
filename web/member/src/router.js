import { createRouter, createWebHistory } from 'vue-router';
import { memberGet, memberQueryClient } from './composables/useMemberSession.js';

const viewLoaders = {
  overview: () => import('./views/OverviewView.vue'),
  invoices: () => import('./views/InvoicesView.vue'),
  rewards: () => import('./views/RewardsView.vue'),
  binding: () => import('./views/BindingView.vue'),
};

export const MEMBER_ROUTES = [
  { path: '/', redirect: '/overview' },
  {
    path: '/overview',
    name: 'overview',
    component: viewLoaders.overview,
    meta: { label: 'ภาพรวมสมาชิก', nextLikely: ['invoices', 'rewards'] },
  },
  {
    path: '/invoices',
    name: 'invoices',
    component: viewLoaders.invoices,
    meta: { label: 'ประวัติยอดซื้อ', nextLikely: ['rewards'] },
  },
  {
    path: '/rewards',
    name: 'rewards',
    component: viewLoaders.rewards,
    meta: { label: 'แลกของรางวัล', nextLikely: ['overview'] },
  },
  {
    path: '/binding',
    name: 'binding',
    component: viewLoaders.binding,
    meta: { label: 'ผูกบัญชี LINE & ร้านค้า ERP', nextLikely: ['overview'] },
  },
];

export const router = createRouter({
  history: createWebHistory(),
  routes: MEMBER_ROUTES,
  scrollBehavior: () => ({ top: 0 }),
});

const prefetched = new Set();

export function prefetchMemberView(name, { withData = false } = {}) {
  const loader = viewLoaders[name];
  if (loader && !prefetched.has(name)) {
    prefetched.add(name);
    loader().catch(() => prefetched.delete(name));
  }
  if (!withData) return;

  if (name === 'invoices') {
    memberQueryClient.prefetchQuery({
      queryKey: ['member', 'invoices'],
      queryFn: ({ signal }) => memberGet('/invoices', { signal }),
      staleTime: 45_000,
    });
  } else if (name === 'rewards') {
    memberQueryClient.prefetchQuery({
      queryKey: ['member', 'rewards'],
      queryFn: ({ signal }) => memberGet('/rewards', { signal }),
      staleTime: 45_000,
    });
    memberQueryClient.prefetchQuery({
      queryKey: ['member', 'points'],
      queryFn: ({ signal }) => memberGet('/points', { signal }),
      staleTime: 45_000,
    });
  }
}

export function scheduleMemberPrefetch(name) {
  const route = MEMBER_ROUTES.find((r) => r.name === name);
  const next = route?.meta?.nextLikely || [];
  if (!next.length) return;
  const run = () => next.forEach((n) => prefetchMemberView(n, { withData: true }));
  if (typeof window !== 'undefined' && typeof window.requestIdleCallback === 'function') {
    window.requestIdleCallback(run, { timeout: 1500 });
  } else {
    setTimeout(run, 350);
  }
}
