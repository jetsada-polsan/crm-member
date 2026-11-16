<template>
  <div>
    <div class="pagehead">
      <div>
        <div class="eyebrow">EXPLICIT MATCHING REVIEW</div>
        <h2>จับคู่ลูกค้ากับ ERP</h2>
        <p>ตรวจคู่ที่ระบบแนะนำ แล้วยืนยันเองว่าร้านใหม่คือลูกค้ารายไหนใน ERP</p>
      </div>
      <div class="head-actions">
        <button type="button" class="btn" @click="refetch">⟳ โหลดใหม่</button>
        <button type="button" class="btn primary" :disabled="refreshing" @click="refreshCandidates">
          {{ refreshing ? 'กำลังสแกนคู่เทียบ...' : '✨ สแกนหาคู่เทียบใหม่ (Refresh Candidates)' }}
        </button>
      </div>
    </div>

    <div class="card panel-pad" style="margin-bottom:16px">
      <div class="head-filter" style="margin-bottom:0">
        <button
          v-for="st in STATUSES"
          :key="st.id"
          type="button"
          class="btn"
          :class="{ primary: statusFilter === st.id }"
          @click="statusFilter = st.id"
        >
          {{ st.label }}
        </button>
      </div>
    </div>

    <SkeletonBlock v-if="isLoading && !candidates.length" variant="cards" :rows="4" />
    <div v-else-if="isError" class="error">{{ error?.message || 'โหลดรายการจับคู่ไม่สำเร็จ' }}</div>

    <div v-else style="display:grid;gap:12px">
      <div v-for="c in candidates" :key="c.id" class="card panel-pad">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px">
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <span class="tag blue">Score: {{ c.score }}</span>
            <span class="tag" :class="statusTag(c.status)">{{ c.status }}</span>
            <span v-for="reason in parseReasons(c.match_reasons)" :key="reason" class="tag purple">{{ reason }}</span>
          </div>
          <div class="small muted">Candidate #{{ c.id }}</div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;align-items:stretch">
          <div style="background:var(--blue-soft);border:1px solid var(--border);border-radius:12px;padding:12px">
            <div class="eyebrow" style="margin-bottom:4px">CRM LEAD</div>
            <b style="font-size:15px">{{ c.crm_shop_name }}</b>
            <div class="small muted" style="margin-top:4px">โทร: {{ c.crm_phone || '—' }}</div>
            <div class="small muted">ที่อยู่: {{ c.crm_address || '—' }}</div>
          </div>

          <div style="background:#f8fafc;border:1px solid var(--border);border-radius:12px;padding:12px">
            <div class="eyebrow" style="margin-bottom:4px">BPLUS ERP ({{ c.source_system || 'BPLUS' }})</div>
            <b style="font-size:15px">{{ c.erp_customer_code }} · {{ c.erp_customer_name }}</b>
            <div class="small muted" style="margin-top:4px">โทร: {{ c.erp_phone || '—' }}</div>
            <div class="small muted">ที่อยู่: {{ c.erp_address || '—' }}</div>
          </div>
        </div>

        <div v-if="c.review_note" class="small muted" style="margin-top:8px">
          หมายเหตุล่าสุด ({{ c.reviewed_by_name || 'ผู้ดูแล' }}): {{ c.review_note }}
        </div>

        <div
          v-if="c.status === 'pending' || c.status === 'stale'"
          style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px"
        >
          <button type="button" class="btn primary" :disabled="busyId === c.id" @click="confirmMatch(c)">
            ✓ ยืนยันเป็นร้านเดียวกัน (เชื่อม ERP)
          </button>
          <button type="button" class="btn danger" :disabled="busyId === c.id" @click="dismissMatch(c)">
            ✕ ปฏิเสธคู่เทียบนี้
          </button>
        </div>
      </div>

      <div v-if="!candidates.length" class="card panel-pad placeholder">
        ไม่มีคู่เทียบในสถานะ "{{ statusFilter }}" — กด "สแกนหาคู่เทียบใหม่" เพื่อคำนวณคู่ที่เบอร์หรือชื่อตรงกับ Bplus ERP
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { askConfirm, toast } from '../../../shared/composables/useUiStore.js';
import { crmGet, crmMutate, invalidateCrmData } from '../composables/useCrmSession.js';

const STATUSES = [
  { id: 'pending', label: 'รอตรวจสอบ (Pending)' },
  { id: 'accepted', label: 'ยืนยันแล้ว (Accepted)' },
  { id: 'dismissed', label: 'ปฏิเสธแล้ว (Dismissed)' },
  { id: 'stale', label: 'ข้อมูลเปลี่ยน (Stale)' },
  { id: 'superseded', label: 'แทนที่แล้ว (Superseded)' },
];

const statusFilter = ref('pending');
const refreshing = ref(false);
const busyId = ref(null);

const { data, isLoading, isError, error, refetch } = useQuery({
  queryKey: computed(() => ['crm', 'match-candidates', statusFilter.value]),
  queryFn: ({ signal }) => crmGet(`/customer-master/match-candidates?status=${encodeURIComponent(statusFilter.value)}`, { signal }),
});

const candidates = computed(() => data.value || []);

function parseReasons(val) {
  if (Array.isArray(val)) return val;
  if (typeof val === 'string') {
    try {
      const parsed = JSON.parse(val);
      return Array.isArray(parsed) ? parsed : [val];
    } catch {
      return val ? [val] : [];
    }
  }
  return [];
}

function statusTag(s) {
  if (s === 'accepted') return 'green';
  if (s === 'dismissed') return 'red';
  if (s === 'pending' || s === 'stale') return 'orange';
  return 'gray';
}

async function refreshCandidates() {
  refreshing.value = true;
  try {
    const res = await crmMutate('/customer-master/match-candidates/refresh', 'POST', {});
    toast.success(`สแกนคู่เทียบแล้ว (สร้าง/อัปเดต ${res?.created_or_updated ?? 0} รายการ)`);
    await refetch();
  } catch (err) {
    toast.error('สแกนคู่เทียบไม่สำเร็จ', err);
  } finally {
    refreshing.value = false;
  }
}

async function confirmMatch(c) {
  const ok = await askConfirm({
    title: 'ยืนยันจับคู่ CRM Lead กับ ERP',
    message: `ยืนยันว่า "${c.crm_shop_name}" คือลูกค้า ERP ${c.erp_customer_code} (${c.erp_customer_name})? ประวัติเข้าพบและรูปภาพทั้งหมดจะยังคงอยู่ครบ`,
    confirmLabel: 'ยืนยันเชื่อมตัวตน',
  });
  if (!ok) return;
  busyId.value = c.id;
  try {
    await crmMutate(`/customer-master/match-candidates/${c.id}/confirm`, 'POST', {
      note: 'ผู้ดูแลระบบยืนยันคู่เทียบจากการตรวจสอบด้วยตนเอง',
    });
    toast.success(`เชื่อม ${c.erp_customer_code} เรียบร้อยแล้ว`);
    invalidateCrmData(['leads', 'master', 'dashboard']);
    await refetch();
  } catch (err) {
    toast.error('ยืนยันการเชื่อมไม่สำเร็จ', err);
  } finally {
    busyId.value = null;
  }
}

async function dismissMatch(c) {
  const ok = await askConfirm({
    title: 'ปฏิเสธคู่เทียบนี้',
    message: `ระบุว่า "${c.crm_shop_name}" ไม่ใช่ลูกค้า ERP ${c.erp_customer_code}?`,
    confirmLabel: 'ปฏิเสธคู่เทียบ',
    tone: 'danger',
  });
  if (!ok) return;
  busyId.value = c.id;
  try {
    await crmMutate(`/customer-master/match-candidates/${c.id}/dismiss`, 'POST', {
      note: 'ผู้ดูแลระบบปฏิเสธคู่เทียบ (ร้านค้าคนละราย)',
    });
    toast.success('ปฏิเสธคู่เทียบเรียบร้อย');
    await refetch();
  } catch (err) {
    toast.error('ดำเนินการไม่สำเร็จ', err);
  } finally {
    busyId.value = null;
  }
}
</script>
