<template>
  <div>
    <div class="pagehead">
      <div>
        <div class="eyebrow">MEMBER REGISTRY &amp; VERIFICATION</div>
        <h2>ทะเบียนสมาชิก</h2>
        <p>ดูบัญชีสมาชิก ตรวจคำขอผูกร้าน และอนุญาตให้ดูบิลและแต้ม</p>
      </div>
      <div class="head-actions">
        <button type="button" class="btn" @click="refetchAll">⟳ รีเฟรช</button>
        <RouterLink to="/loyalty" class="btn light">💎 กติกา &amp; บัญชีแต้ม</RouterLink>
      </div>
    </div>

    <div class="card panel-pad" style="margin-bottom:16px">
      <div class="head-filter" style="margin-bottom:0">
        <input
          v-model="search"
          style="min-width:220px;flex:1"
          placeholder="ค้นหาชื่อสมาชิก, เบอร์โทร, อีเมล, รหัสลูกค้า ERP..."
        />
        <select v-model="verifyFilter">
          <option value="pending">คำขอเชื่อมรอตรวจสอบ (Pending)</option>
          <option value="approved">อนุมัติแล้ว (Approved)</option>
          <option value="rejected">ปฏิเสธแล้ว (Rejected)</option>
          <option value="all">คำขอทั้งหมด</option>
        </select>
      </div>
    </div>

    <!-- Verification Requests Queue -->
    <div class="card" style="margin-bottom:16px">
      <div class="section-head" style="padding:16px 18px 0">
        <div>
          <h3>คำขอเชื่อมบัญชี Member → ERP ({{ (verifications || []).length }})</h3>
          <small>เบอร์โทรตรงอย่างเดียวจะไม่อนุมัติอัตโนมัติ — ต้องตรวจสอบและยืนยันโดยทีมงาน</small>
        </div>
      </div>

      <div class="table-wrap desktop-table-only">
        <table class="table">
          <thead>
            <tr>
              <th>สมาชิก</th>
              <th>รหัสที่ขอเชื่อม</th>
              <th>เบอร์โทรประกอบ</th>
              <th>บันทึกหลักฐาน</th>
              <th>สถานะ</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="v in verifications || []" :key="v.id">
              <td>
                <b>{{ v.member_name }}</b>
                <div class="small muted">{{ v.account_phone || v.member_email || `Member #${v.member_account_id}` }}</div>
              </td>
              <td>
                <span class="tag blue">{{ v.requested_customer_code || '—' }}</span>
                <span v-if="v.requested_member_code" class="tag gray" style="margin-left:4px">{{ v.requested_member_code }}</span>
              </td>
              <td>{{ v.requested_phone || '—' }}</td>
              <td>{{ v.evidence_note || '—' }}</td>
              <td>
                <span class="tag" :class="v.status === 'approved' ? 'green' : v.status === 'rejected' ? 'red' : 'orange'">
                  {{ v.status }}
                </span>
              </td>
              <td>
                <div v-if="v.status === 'pending'" style="display:flex;gap:6px;flex-wrap:wrap">
                  <button type="button" class="btn primary" @click="openReviewModal(v)">ตรวจสอบ &amp; อนุมัติ</button>
                  <button type="button" class="btn danger" @click="quickRejectVerification(v)">ปฏิเสธ</button>
                </div>
              </td>
            </tr>
            <tr v-if="!(verifications || []).length">
              <td colspan="6" class="placeholder">ไม่มีคำขอในสถานะ "{{ verifyFilter }}"</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="mob-cards" style="padding:12px">
        <div v-for="v in verifications || []" :key="`vm-${v.id}`" class="card mob-card">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
            <strong>{{ v.member_name }}</strong>
            <span class="tag" :class="v.status === 'approved' ? 'green' : v.status === 'rejected' ? 'red' : 'orange'">{{ v.status }}</span>
          </div>
          <div class="small muted" style="margin:6px 0">
            รหัส ERP ที่ขอ: {{ v.requested_customer_code || '—' }} · โทร: {{ v.requested_phone || v.account_phone || '—' }}
          </div>
          <div v-if="v.status === 'pending'" style="display:flex;gap:8px;flex-wrap:wrap">
            <button type="button" class="btn primary" @click="openReviewModal(v)">ตรวจสอบ &amp; อนุมัติ</button>
            <button type="button" class="btn danger" @click="quickRejectVerification(v)">ปฏิเสธ</button>
          </div>
        </div>
      </div>
    </div>

    <!-- All Registered Member Accounts -->
    <div class="card">
      <div class="section-head" style="padding:16px 18px 0">
        <div>
          <h3>ทะเบียนบัญชีสมาชิกทั้งหมด ({{ (members || []).length }})</h3>
          <small>แสดงสถานะการยืนยัน ERP, การเชื่อม LINE และคะแนนสะสม</small>
        </div>
      </div>

      <SkeletonBlock v-if="membersLoading && !(members || []).length" variant="table" :rows="5" />
      <div v-else class="table-wrap desktop-table-only">
        <table class="table">
          <thead>
            <tr>
              <th>ชื่อสมาชิก</th>
              <th>เบอร์โทร / อีเมล</th>
              <th>สถานะยืนยันตัวตน</th>
              <th>ERP / Master Link</th>
              <th>LINE</th>
              <th>แต้มใน CRM</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in members || []" :key="m.id">
              <td>
                <b>{{ m.name }}</b>
                <div class="small muted">ID #{{ m.id }} · {{ m.status }}</div>
              </td>
              <td>{{ m.phone || '—' }}<div class="small muted">{{ m.email || '—' }}</div></td>
              <td>
                <span class="tag" :class="m.verification_status === 'verified' ? 'green' : 'orange'">
                  {{ m.verification_status === 'verified' ? 'member_verified' : (m.verification_status || 'unverified') }}
                </span>
              </td>
              <td>
                <span v-if="m.erp_customer_code" class="tag gray">{{ m.erp_customer_code }}</span>
                <span v-if="m.internal_customer_code" class="tag blue" style="margin-left:4px">{{ m.internal_customer_code }}</span>
                <div v-if="m.erp_customer_name" class="small muted">{{ m.erp_customer_name }}</div>
                <span v-if="!m.erp_customer_code && !m.internal_customer_code" class="small muted">ยังไม่ผูกบัญชี</span>
              </td>
              <td>
                <span class="tag" :class="m.line_binding_status === 'bound' ? 'teal' : 'gray'">
                  {{ m.line_binding_status || 'unbound' }}
                </span>
              </td>
              <td><b>{{ fmt(m.points_balance) }} แต้ม</b></td>
              <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                  <RouterLink
                    v-if="m.crm_customer_master_id"
                    :to="`/customer-360/${m.crm_customer_master_id}`"
                    class="btn light"
                  >
                    360°
                  </RouterLink>
                  <button
                    v-if="m.link_id && m.verification_status !== 'verified'"
                    type="button"
                    class="btn primary"
                    @click="verifyExistingLink(m.link_id)"
                  >
                    ✓ Verify Link
                  </button>
                  <button
                    v-else-if="!m.link_id"
                    type="button"
                    class="btn light"
                    @click="openBindModal(m)"
                  >
                    🔗 ผูก ERP / Master
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!(members || []).length">
              <td colspan="7" class="placeholder">ยังไม่มีสมาชิกในระบบ</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="mob-cards" style="padding:12px">
        <div v-for="m in members || []" :key="`mm-${m.id}`" class="card mob-card">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
            <strong>{{ m.name }}</strong>
            <span class="tag" :class="m.verification_status === 'verified' ? 'green' : 'orange'">
              {{ m.verification_status || 'unverified' }}
            </span>
          </div>
          <div class="small muted" style="margin:6px 0">
            {{ m.phone || m.email || '—' }} · ERP: {{ m.erp_customer_code || 'ยังไม่ผูก' }} · {{ fmt(m.points_balance) }} แต้ม
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <RouterLink v-if="m.crm_customer_master_id" :to="`/customer-360/${m.crm_customer_master_id}`" class="btn light">360°</RouterLink>
            <button v-if="!m.link_id" type="button" class="btn light" @click="openBindModal(m)">🔗 ผูก ERP / Master</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Verification Review Modal -->
    <BaseModal :open="Boolean(reviewTarget)" title="อนุมัติคำขอเชื่อมบัญชี Member" @close="reviewTarget = null">
      <div v-if="reviewTarget" style="display:grid;gap:10px">
        <div class="notice" style="margin-bottom:0">
          สมาชิก <b>{{ reviewTarget.member_name }}</b> ขอเชื่อมรหัส <b>{{ reviewTarget.requested_customer_code || 'ไม่ระบุ' }}</b>
        </div>
        <div class="field">
          <label>ค้นหาลูกค้า ERP ที่ต้องการเชื่อม</label>
          <input v-model="erpSearch" placeholder="พิมพ์ชื่อร้านหรือรหัส AR..." />
        </div>
        <div class="field">
          <label>เลือกลูกค้า ERP (ไม่บังคับหากรหัสที่ขอตรงกับ Bplus อยู่แล้ว)</label>
          <select v-model="reviewForm.erp_customer_id">
            <option value="">-- ใช้รหัสที่สมาชิกขอมา ({{ reviewTarget.requested_customer_code || 'อัตโนมัติ' }}) --</option>
            <option v-for="c in erpOptions" :key="c.id" :value="c.id">
              {{ c.erp_customer_code }} · {{ c.customer_name }} ({{ c.phone || '—' }})
            </option>
          </select>
        </div>
        <div class="field">
          <label>รหัสสมาชิก ERP (ถ้ามี)</label>
          <input v-model="reviewForm.erp_member_code" placeholder="เช่น MEM001" />
        </div>
        <div class="field">
          <label>หมายเหตุการพิจารณา</label>
          <input v-model="reviewForm.review_note" />
        </div>
      </div>
      <template #footer="{ requestClose }">
        <button type="button" class="btn" @click="requestClose">ยกเลิก</button>
        <button type="button" class="btn primary" :disabled="busy" @click="confirmApproveVerification">
          {{ busy ? 'กำลังบันทึก...' : 'อนุมัติ & ยืนยันสิทธิ์สมาชิก' }}
        </button>
      </template>
    </BaseModal>

    <!-- Manual Bind Member Link Modal -->
    <BaseModal :open="Boolean(bindTarget)" title="ผูกสมาชิกเข้ากับลูกค้า ERP / Customer Master" @close="bindTarget = null">
      <div v-if="bindTarget" style="display:grid;gap:10px">
        <div class="notice" style="margin-bottom:0">
          บัญชีสมาชิก: <b>{{ bindTarget.name }}</b> ({{ bindTarget.phone || bindTarget.email }})
        </div>
        <div class="field">
          <label>ค้นหาลูกค้า ERP</label>
          <input v-model="erpSearch" placeholder="พิมพ์ชื่อร้านหรือรหัส AR..." />
        </div>
        <div class="field">
          <label>เลือกลูกค้า ERP *</label>
          <select v-model="bindForm.erp_customer_id">
            <option value="">-- เลือกลูกค้า ERP --</option>
            <option v-for="c in erpOptions" :key="c.id" :value="c.id">
              {{ c.erp_customer_code }} · {{ c.customer_name }} ({{ c.phone || '—' }})
            </option>
          </select>
        </div>
        <div class="field">
          <label>หมายเหตุ</label>
          <input v-model="bindForm.verification_note" placeholder="ตรวจสอบตัวตนเรียบร้อย" />
        </div>
      </div>
      <template #footer="{ requestClose }">
        <button type="button" class="btn" @click="requestClose">ยกเลิก</button>
        <button type="button" class="btn primary" :disabled="!bindForm.erp_customer_id || busy" @click="submitBindMember">
          {{ busy ? 'กำลังผูก...' : 'ผูกและเปิดรอตรวจ' }}
        </button>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useQuery } from '@tanstack/vue-query';
import BaseModal from '../../../shared/components/BaseModal.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { askConfirm, toast } from '../../../shared/composables/useUiStore.js';
import { crmGet, crmMutate, fmt } from '../composables/useCrmSession.js';

const search = ref('');
const verifyFilter = ref('pending');
const busy = ref(false);
const erpSearch = ref('');

const reviewTarget = ref(null);
const reviewForm = ref({ erp_customer_id: '', erp_member_code: '', review_note: '' });

const bindTarget = ref(null);
const bindForm = ref({ erp_customer_id: '', verification_note: '' });

const { data: members, isLoading: membersLoading, refetch: refetchMembers } = useQuery({
  queryKey: computed(() => ['crm', 'members-registry', search.value]),
  queryFn: ({ signal }) => {
    const p = new URLSearchParams();
    if (search.value.trim()) p.set('q', search.value.trim());
    return crmGet(`/members?${p.toString()}`, { signal });
  },
});

const { data: verifications, refetch: refetchVerifs } = useQuery({
  queryKey: computed(() => ['crm', 'loyalty-verifications', verifyFilter.value]),
  queryFn: ({ signal }) => crmGet(`/loyalty/verifications?status=${encodeURIComponent(verifyFilter.value)}`, { signal }),
});

const { data: erpData } = useQuery({
  queryKey: computed(() => ['crm', 'member-erp-options', erpSearch.value]),
  queryFn: ({ signal }) => {
    const p = new URLSearchParams({ paginate: '1', per_page: '20' });
    if (erpSearch.value.trim()) p.set('q', erpSearch.value.trim());
    return crmGet(`/customers?${p.toString()}`, { signal });
  },
  enabled: computed(() => Boolean(reviewTarget.value || bindTarget.value)),
});
const erpOptions = computed(() => erpData.value?.data || []);

function refetchAll() {
  refetchMembers();
  refetchVerifs();
}

function openReviewModal(v) {
  reviewTarget.value = v;
  erpSearch.value = v.requested_customer_code || '';
  reviewForm.value = {
    erp_customer_id: '',
    erp_member_code: v.requested_member_code || '',
    review_note: 'ตรวจสอบหลักฐานสมาชิกและยืนยันกับ Bplus ERP แล้ว',
  };
}

async function confirmApproveVerification() {
  if (!reviewTarget.value) return;
  busy.value = true;
  try {
    const payload = {
      decision: 'approved',
      review_note: reviewForm.value.review_note || 'อนุมัติโดยผู้ดูแลระบบ',
    };
    if (reviewForm.value.erp_customer_id) payload.erp_customer_id = Number(reviewForm.value.erp_customer_id);
    if (reviewForm.value.erp_member_code) payload.erp_member_code = reviewForm.value.erp_member_code;
    await crmMutate(`/loyalty/verifications/${reviewTarget.value.id}/review`, 'POST', payload);
    reviewTarget.value = null;
    toast.success('อนุมัติและยืนยันตัวตนสมาชิกแล้ว');
    refetchAll();
  } catch (err) {
    toast.error('อนุมัติคำขอไม่สำเร็จ', err);
  } finally {
    busy.value = false;
  }
}

async function quickRejectVerification(v) {
  const ok = await askConfirm({
    title: 'ปฏิเสธคำขอเชื่อมบัญชี ERP',
    message: `ปฏิเสธคำขอของ ${v.member_name} (${v.requested_customer_code || 'ไม่ระบุรหัส'})?`,
    confirmLabel: 'ปฏิเสธคำขอ',
    tone: 'danger',
  });
  if (!ok) return;
  try {
    await crmMutate(`/loyalty/verifications/${v.id}/review`, 'POST', {
      decision: 'rejected',
      review_note: 'ข้อมูลไม่ตรงกับทะเบียนลูกค้า Bplus ERP',
    });
    toast.success('ปฏิเสธคำขอแล้ว');
    refetchAll();
  } catch (err) {
    toast.error('ปฏิเสธไม่สำเร็จ', err);
  }
}

function openBindModal(member) {
  bindTarget.value = member;
  erpSearch.value = member.phone || member.name || '';
  bindForm.value = { erp_customer_id: '', verification_note: 'ผูกบัญชีโดยผู้ดูแลระบบ' };
}

async function submitBindMember() {
  if (!bindTarget.value || !bindForm.value.erp_customer_id) return;
  busy.value = true;
  try {
    const created = await crmMutate('/member-links', 'POST', {
      member_account_id: bindTarget.value.id,
      erp_customer_id: Number(bindForm.value.erp_customer_id),
      verification_note: bindForm.value.verification_note,
    });
    if (created?.id) {
      await crmMutate(`/member-links/${created.id}/verify`, 'POST', {
        verification_note: bindForm.value.verification_note,
      });
    }
    bindTarget.value = null;
    toast.success('ผูกและยืนยันบัญชีสมาชิกแล้ว');
    refetchAll();
  } catch (err) {
    toast.error('ผูกบัญชีสมาชิกไม่สำเร็จ', err);
  } finally {
    busy.value = false;
  }
}

async function verifyExistingLink(linkId) {
  const ok = await askConfirm({
    title: 'ยืนยันสิทธิ์บัญชีสมาชิก',
    message: 'อนุญาตให้สมาชิกบัญชีนี้เข้าถึงบิลขายและแต้มสะสมใน Member Portal?',
    confirmLabel: 'ยืนยันสิทธิ์',
  });
  if (!ok) return;
  try {
    await crmMutate(`/member-links/${linkId}/verify`, 'POST', { verification_note: 'ยืนยันจาก Member Registry' });
    toast.success('ยืนยันบัญชีสมาชิกแล้ว');
    refetchAll();
  } catch (err) {
    toast.error('ยืนยันไม่สำเร็จ', err);
  }
}
</script>
