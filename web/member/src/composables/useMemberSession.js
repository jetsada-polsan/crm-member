import { computed, ref } from 'vue';
import {
  apiGet,
  apiMutate,
  clearCsrfCache,
  createAppQueryClient,
  ensureCsrfToken,
} from '../../../shared/api/http.js';
import { toast } from '../../../shared/composables/useUiStore.js';

export const memberQueryClient = createAppQueryClient();

export const me = ref(null);
export const logged = ref(false);
export const authChecked = ref(false);
export const userInitial = computed(() => (me.value?.name || 'M').trim().charAt(0).toUpperCase());
export const isMemberVerified = computed(() => me.value?.identity_status === 'member_verified');

export const fmt = (n) =>
  Number(n ?? 0).toLocaleString('th-TH', { minimumFractionDigits: 0, maximumFractionDigits: 2 });

export function memberGet(path, opts) {
  return apiGet('/api/member' + path, opts);
}

export function memberMutate(path, method = 'POST', body = undefined) {
  return apiMutate('/api/member' + path, method, body, { csrfEndpoint: '/api/member/csrf' });
}

export async function refreshMemberSession() {
  try {
    await ensureCsrfToken('/api/member/csrf');
    const [basic, profile] = await Promise.all([memberGet('/me'), memberGet('/profile')]);
    me.value = {
      ...basic,
      ...(profile?.member || {}),
      erp_link: profile?.erp_link || null,
      line_binding: profile?.line_binding || null,
      identity_status: profile?.identity_status || 'member_verification_pending',
      latest_verification_request: profile?.verification_request || null,
    };
    logged.value = true;
  } catch {
    me.value = null;
    logged.value = false;
  } finally {
    authChecked.value = true;
  }
}

export async function loginMember({ identifier, password }) {
  clearCsrfCache();
  await ensureCsrfToken('/api/member/csrf');
  const trimmed = String(identifier || '').trim();
  const payload = trimmed.includes('@')
    ? { email: trimmed, password }
    : { phone: trimmed, password };
  await memberMutate('/login', 'POST', payload);
  clearCsrfCache();
  await refreshMemberSession();
  toast.success('เข้าสู่ระบบสมาชิกสำเร็จ', `สวัสดีคุณ ${me.value?.name || ''}`);
}

export async function registerMember(payload) {
  clearCsrfCache();
  await ensureCsrfToken('/api/member/csrf');
  await memberMutate('/register', 'POST', payload);
  clearCsrfCache();
  await refreshMemberSession();
  toast.success('สมัครสมาชิกสำเร็จ', 'บัญชีของคุณพร้อมส่งคำขอยืนยันความเป็นเจ้าของร้านค้าแล้ว');
}

export async function logoutMember() {
  try {
    await memberMutate('/logout', 'POST');
  } finally {
    clearCsrfCache();
    memberQueryClient.clear();
    me.value = null;
    logged.value = false;
  }
}

export function invalidateMemberData(keys = ['me', 'invoices', 'points', 'rewards', 'redemptions']) {
  for (const k of keys) {
    memberQueryClient.invalidateQueries({ queryKey: ['member', k] });
  }
}
