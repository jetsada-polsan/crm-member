import { computed, ref } from 'vue';
import {
  apiGet,
  apiMutate,
  clearCsrfCache,
  createAppQueryClient,
  ensureCsrfToken,
} from '../../../shared/api/http.js';
import { toast } from '../../../shared/composables/useUiStore.js';
import { listOfflineDrafts, removeOfflineDraft } from '../../../shared/utils/offlineDrafts.js';

export const crmQueryClient = createAppQueryClient();

export const SHOP_TYPES = [
  'หมูกระทะ',
  'ร้านอาหาร',
  'ร้านค้าส่ง',
  'ร้านค้าปลีก',
  'ร้านขายของชำ',
  'มินิมาร์ท',
  'คาเฟ่/เบเกอรี่',
  'อื่นๆ',
];

export const PIPELINE_STAGES = [
  { id: 'visited', label: 'เข้าพบแล้ว', tag: 'blue' },
  { id: 'new_lead', label: 'ลูกค้าใหม่', tag: 'blue' },
  { id: 'contacted', label: 'ติดต่อแล้ว', tag: 'blue' },
  { id: 'interested', label: 'สนใจ', tag: 'orange' },
  { id: 'quoted', label: 'เสนอราคา', tag: 'orange' },
  { id: 'follow_up', label: 'ติดตาม', tag: 'orange' },
  { id: 'converted', label: 'ซื้อจริงแล้ว', tag: 'green' },
  { id: 'lost', label: 'ไม่สำเร็จ', tag: 'gray' },
];

export const APPROVAL_LABELS = {
  pending: 'รออนุมัติ',
  approved: 'อนุมัติแล้ว',
  rejected: 'ไม่อนุมัติ',
};

export const me = ref(null);
export const logged = ref(false);
export const authChecked = ref(false);
export const isManager = computed(() => ['admin', 'manager', 'executive'].includes(me.value?.role));
export const isExecutive = computed(() => me.value?.role === 'executive');
export const userInitial = computed(() => (me.value?.name || 'P').trim().charAt(0).toUpperCase());

export const quickAddOpen = ref(false);
export const visitWizardOpen = ref(false);
export const visitWizardTarget = ref(null);

export const detailModal = ref({ open: false, kind: 'master', id: null });

export const lightboxState = ref({ open: false, items: [], startIndex: 0 });
export const offlineDrafts = ref([]);
export const offlineSyncing = ref(false);

export const fmt = (n) => Number(n ?? 0).toLocaleString('th-TH', { maximumFractionDigits: 2 });
export const stageLabel = (s) => PIPELINE_STAGES.find((x) => x.id === s)?.label || s;
export const stageTagClass = (s) => PIPELINE_STAGES.find((x) => x.id === s)?.tag || 'blue';

export function crmGet(path, opts) {
  return apiGet('/api/crm' + path, opts);
}

export function crmMutate(path, method = 'POST', body = undefined) {
  return apiMutate('/api/crm' + path, method, body, { csrfEndpoint: '/api/crm/csrf' });
}

export async function refreshSession() {
  try {
    await ensureCsrfToken('/api/crm/csrf');
    me.value = await crmGet('/me');
    logged.value = true;
  } catch {
    me.value = null;
    logged.value = false;
  } finally {
    authChecked.value = true;
  }

  // Load offline drafts after the workspace is shown, so it does not delay the session check.
  if (logged.value) {
    try {
      await reloadOfflineDrafts();
    } catch {
      // Draft storage problems must not sign the user out.
    }
  }
}

export async function loginStaff(email, password) {
  clearCsrfCache();
  await ensureCsrfToken('/api/crm/csrf');
  await crmMutate('/login', 'POST', { email, password });
  clearCsrfCache();
  await ensureCsrfToken('/api/crm/csrf');
  me.value = await crmGet('/me');
  logged.value = true;
  authChecked.value = true;
  await reloadOfflineDrafts();
  toast.success('เข้าสู่ระบบสำเร็จ', `ยินดีต้อนรับ ${me.value?.name || ''}`);
}

export async function logoutStaff() {
  try {
    await crmMutate('/logout', 'POST');
  } finally {
    clearCsrfCache();
    crmQueryClient.clear();
    me.value = null;
    logged.value = false;
  }
}

export function invalidateCrmData(keys = ['dashboard', 'customers', 'leads', 'master', 'visits', 'pins', 'tasks', 'plans']) {
  for (const k of keys) {
    crmQueryClient.invalidateQueries({ queryKey: ['crm', k] });
  }
}

export function openQuickAdd() {
  quickAddOpen.value = true;
}
export function closeQuickAdd() {
  quickAddOpen.value = false;
}

export function openVisitWizard(target = null) {
  visitWizardTarget.value = target;
  visitWizardOpen.value = true;
}
export function closeVisitWizard() {
  visitWizardOpen.value = false;
  visitWizardTarget.value = null;
}

export function openDetailDrawer(kind, id) {
  detailModal.value = { open: true, kind, id };
}
export function closeDetailDrawer() {
  detailModal.value = { open: false, kind: 'master', id: null };
}

export function openLightbox(items, startIndex = 0) {
  lightboxState.value = { open: true, items, startIndex };
}
export function closeLightbox() {
  lightboxState.value = { open: false, items: [], startIndex: 0 };
}

export async function reloadOfflineDrafts() {
  offlineDrafts.value = await listOfflineDrafts();
}

export async function syncPendingOfflineDrafts() {
  if (offlineSyncing.value) return;
  await reloadOfflineDrafts();
  const pending = offlineDrafts.value.filter((d) => d.type === 'visit');
  if (!pending.length) {
    toast.info('ไม่มี Draft ค้างส่ง');
    return;
  }
  offlineSyncing.value = true;
  let synced = 0;
  try {
    for (const draft of pending) {
      const res = await crmMutate('/field-visits', 'POST', {
        ...draft.payload,
        client_draft_id: draft.client_draft_id,
      });
      const visitId = res.id;
      if (visitId && Array.isArray(draft.photos)) {
        for (const p of draft.photos) {
          if (!p?.blob) continue;
          const fd = new FormData();
          fd.set('photo', new File([p.blob], p.fileName || 'visit.jpg', { type: p.mimeType || 'image/jpeg' }));
          fd.set('category', p.category || 'storefront');
          fd.set('consent_confirmed', p.consentConfirmed ? '1' : '0');
          fd.set('compressed_on_client', '1');
          if (p.capturedAt) fd.set('captured_at', p.capturedAt);
          if (p.latitude != null && p.longitude != null) {
            fd.set('latitude', String(p.latitude));
            fd.set('longitude', String(p.longitude));
          }
          await crmMutate(`/field-visits/${visitId}/photos`, 'POST', fd);
        }
      }
      await removeOfflineDraft(draft.client_draft_id);
      synced++;
    }
    invalidateCrmData(['dashboard', 'visits', 'tasks']);
    toast.success(`ซิงค์ข้อมูล Draft สำเร็จ ${synced} รายการ`, 'ตรวจสอบข้อมูลซ้ำด้วย client_draft_id แล้ว');
  } catch (err) {
    toast.error('ซิงค์ Draft บางรายการไม่สำเร็จ', err);
  } finally {
    await reloadOfflineDrafts();
    offlineSyncing.value = false;
  }
}
