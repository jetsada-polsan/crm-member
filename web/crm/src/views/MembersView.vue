<template>
  <div>
    <PageHeader title="ทะเบียนสมาชิก" subtitle="จัดการสมาชิก ตรวจคำขอเชื่อม ERP และยืนยันสิทธิ์">
      <template #secondary>
        <RouterLink to="/loyalty" class="btn light">💎 กติกาแต้ม</RouterLink>
        <button type="button" class="btn light" @click="refetchAll">⟳ รีเฟรช</button>
      </template>
      <template #primary>
        <button type="button" class="btn primary" @click="openCreateMemberModal">➕ เพิ่มสมาชิก</button>
      </template>
    </PageHeader>

    <div class="card panel-pad mb-md">
      <div class="head-filter segmented-tabs mb-sm">
        <button
          type="button"
          class="btn"
          :class="memberTab === 'members' ? 'primary' : 'light'"
          @click="memberTab = 'members'"
        >
          👥 สมาชิกทั้งหมด ({{ (members || []).length }})
        </button>
        <button
          type="button"
          class="btn"
          :class="memberTab === 'verifications' ? 'primary' : 'light'"
          @click="memberTab = 'verifications'"
        >
          📩 คำขอเชื่อม ({{ (verifications || []).length }})
        </button>
      </div>
      <div class="head-filter" style="margin-bottom: 0">
        <input
          v-if="memberTab === 'members'"
          v-model="search"
          class="filter-search-input"
          placeholder="ค้นหาชื่อ, เบอร์โทร, รหัส ERP..."
        />
        <select v-else v-model="verifyFilter">
          <option value="pending">รอตรวจสอบ</option>
          <option value="approved">อนุมัติแล้ว</option>
          <option value="rejected">ปฏิเสธแล้ว</option>
          <option value="all">ทั้งหมด</option>
        </select>
      </div>
    </div>

    <!-- Verification Requests Queue -->
    <div v-if="memberTab === 'verifications'" class="card mb-md">
      <div class="section-head" style="padding: 16px 18px 0">
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
                <div class="small muted">
                  {{ v.account_phone || v.member_email || `Member #${v.member_account_id}` }}
                </div>
              </td>
              <td>
                <span class="tag blue">{{ v.requested_customer_code || '—' }}</span>
                <span v-if="v.requested_member_code" class="tag gray" style="margin-left: 4px">{{
                  v.requested_member_code
                }}</span>
              </td>
              <td>{{ v.requested_phone || '—' }}</td>
              <td>{{ v.evidence_note || '—' }}</td>
              <td>
                <span
                  class="tag"
                  :class="v.status === 'approved' ? 'green' : v.status === 'rejected' ? 'red' : 'orange'"
                >
                  {{ v.status }}
                </span>
              </td>
              <td>
                <div v-if="v.status === 'pending'" style="display: flex; gap: 6px; flex-wrap: wrap">
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

      <div class="mob-cards panel-pad">
        <div v-for="v in verifications || []" :key="`vm-${v.id}`" class="card mob-card">
          <div class="row-between-start">
            <strong>{{ v.member_name }}</strong>
            <span class="tag" :class="v.status === 'approved' ? 'green' : v.status === 'rejected' ? 'red' : 'orange'">{{
              v.status
            }}</span>
          </div>
          <div class="small muted mt-sm">
            รหัส ERP ที่ขอ: {{ v.requested_customer_code || '—' }} · โทร: {{ v.requested_phone || v.account_phone || '—' }}
          </div>
          <div v-if="v.status === 'pending'" class="mob-card-actions">
            <button type="button" class="btn primary" @click="openReviewModal(v)">ตรวจสอบ &amp; อนุมัติ</button>
            <button type="button" class="btn danger" @click="quickRejectVerification(v)">ปฏิเสธ</button>
          </div>
        </div>
        <div v-if="!(verifications || []).length" class="placeholder">ไม่มีคำขอในสถานะ "{{ verifyFilter }}"</div>
      </div>
    </div>

    <!-- All Registered Member Accounts -->
    <div v-if="memberTab === 'members'" class="card">
      <div class="section-head" style="padding: 16px 18px 0">
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
              <td>
                {{ m.phone || '—' }}
                <div class="small muted">{{ m.email || '—' }}</div>
              </td>
              <td>
                <span class="tag" :class="m.verification_status === 'verified' ? 'green' : 'orange'">
                  {{ m.verification_status === 'verified' ? 'member_verified' : m.verification_status || 'unverified' }}
                </span>
              </td>
              <td>
                <span v-if="m.erp_customer_code" class="tag gray">{{ m.erp_customer_code }}</span>
                <span v-if="m.internal_customer_code" class="tag blue" style="margin-left: 4px">{{
                  m.internal_customer_code
                }}</span>
                <div v-if="m.erp_customer_name" class="small muted">{{ m.erp_customer_name }}</div>
                <span v-if="!m.erp_customer_code && !m.internal_customer_code" class="small muted">ยังไม่ผูกบัญชี</span>
              </td>
              <td>
                <span class="tag" :class="m.line_binding_status === 'bound' ? 'teal' : 'gray'">
                  {{ m.line_binding_status || 'unbound' }}
                </span>
              </td>
              <td>
                <b>{{ fmt(m.points_balance) }} แต้ม</b>
              </td>
              <td>
                <div style="display: flex; gap: 6px; flex-wrap: wrap">
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
                  <button v-else-if="!m.link_id" type="button" class="btn light" @click="openBindModal(m)">
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

      <div class="mob-cards panel-pad">
        <div v-for="m in members || []" :key="`mm-${m.id}`" class="card mob-card">
          <div class="row-between-start">
            <strong>{{ m.name }}</strong>
            <span class="tag" :class="m.verification_status === 'verified' ? 'green' : 'orange'">
              {{ m.verification_status || 'unverified' }}
            </span>
          </div>
          <div class="small muted mt-sm">
            {{ m.phone || m.email || '—' }} · ERP: {{ m.erp_customer_code || 'ยังไม่ผูก' }} ·
            {{ fmt(m.points_balance) }} แต้ม
          </div>
          <div class="mob-card-actions">
            <RouterLink
              v-if="m.crm_customer_master_id"
              :to="`/customer-360/${m.crm_customer_master_id}`"
              class="btn light"
              >360°</RouterLink
            >
            <button
              v-if="m.link_id && m.verification_status !== 'verified'"
              type="button"
              class="btn primary"
              @click="verifyExistingLink(m.link_id)"
            >
              ✓ Verify Link
            </button>
            <button v-else-if="!m.link_id" type="button" class="btn light" @click="openBindModal(m)">
              🔗 ผูก ERP / Master
            </button>
          </div>
        </div>
        <div v-if="!(members || []).length" class="placeholder">ยังไม่มีสมาชิกในระบบ</div>
      </div>
    </div>

    <!-- Verification Review Modal -->
    <BaseModal :open="Boolean(reviewTarget)" title="อนุมัติคำขอเชื่อมบัญชี Member" @close="reviewTarget = null">
      <div v-if="reviewTarget" style="display: grid; gap: 10px">
        <div class="notice" style="margin-bottom: 0">
          สมาชิก <b>{{ reviewTarget.member_name }}</b> ขอเชื่อมรหัส
          <b>{{ reviewTarget.requested_customer_code || 'ไม่ระบุ' }}</b>
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
      <div v-if="bindTarget" style="display: grid; gap: 10px">
        <div class="notice" style="margin-bottom: 0">
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
        <button
          type="button"
          class="btn primary"
          :disabled="!bindForm.erp_customer_id || busy"
          @click="submitBindMember"
        >
          {{ busy ? 'กำลังผูก...' : 'ผูกและเปิดรอตรวจ' }}
        </button>
      </template>
    </BaseModal>

    <!-- Back-office Create Member Modal (คีย์สมาชิกที่หลังบ้าน) -->
    <BaseModal :open="createModalOpen" title="➕ เพิ่มสมาชิกใหม่ (คีย์จากหลังบ้าน)" @close="closeCreateMemberModal">
      <div v-if="createdCredentials" style="display: grid; gap: 12px">
        <div class="notice" style="margin-bottom: 0; border-color: #86efac; background: #f0fdf4; color: #166534">
          <b>สร้างบัญชีสมาชิกสำเร็จแล้ว!</b> สามารถคัดลอกข้อมูลด้านล่างส่งให้เจ้าของร้านเข้าใช้งาน Member Portal ได้ทันที (ไม่ต้องใช้ LINE OA)
        </div>
        <div class="minirow">
          <span>ลิงก์เข้าใช้งาน</span><b>{{ memberPortalUrl }}</b>
        </div>
        <div class="minirow">
          <span>ชื่อสมาชิก / ร้านค้า</span><b>{{ createdCredentials.name }}</b>
        </div>
        <div class="minirow">
          <span>ชื่อผู้ใช้ (เข้าสู่ระบบ)</span><b>{{ createdCredentials.phone || createdCredentials.email }}</b>
        </div>
        <div class="minirow">
          <span>รหัสผ่านชั่วคราว</span
          ><b style="font-family: monospace; font-size: 15px">{{ createdCredentials.password }}</b>
        </div>
        <div class="minirow">
          <span>สถานะสิทธิ์</span>
          <span class="tag" :class="createdCredentials.verification_status === 'verified' ? 'green' : 'orange'">
            {{
              createdCredentials.verification_status === 'verified'
                ? 'ยืนยันแล้ว (ดูบิลและแต้มได้ทันที)'
                : createdCredentials.verification_status
            }}
          </span>
        </div>
        <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 4px">
          <button type="button" class="btn primary" @click="copyCreatedCredentials">📋 คัดลอกข้อความส่งให้ร้านค้า</button>
          <a :href="memberPortalUrl" target="_blank" rel="noopener noreferrer" class="btn light"
            >↗ เปิดหน้า Member Portal</a
          >
        </div>
      </div>

      <div v-else style="display: grid; gap: 10px">
        <div class="notice" style="margin-bottom: 0">
          ทีมงานหลังบ้านสามารถคีย์สมัครสมาชิกและผูกกับลูกค้า Bplus ERP ได้ในขั้นตอนเดียว โดยไม่ต้องให้ร้านค้าสมัครเองหรือเชื่อม LINE OA ก่อน
        </div>
        <div class="field">
          <label>1. ค้นหาลูกค้าจาก Bplus ERP (เพื่อดึงชื่อ-เบอร์โทร และผูกสิทธิ์ดูบิลอัตโนมัติ)</label>
          <input v-model="erpSearch" placeholder="พิมพ์รหัสลูกค้า AR หรือชื่อร้านค้า..." />
        </div>
        <div class="field">
          <label>เลือกร้านค้าจาก ERP (ไม่บังคับ หากยังไม่ผูก)</label>
          <select v-model="createForm.erp_customer_id" @change="onSelectCreateErpCustomer">
            <option value="">-- ยังไม่ผูก ERP (สร้างเฉพาะบัญชีสมาชิก) --</option>
            <option v-for="c in erpOptions" :key="c.id" :value="c.id">
              {{ c.erp_customer_code }} · {{ c.customer_name }} ({{ c.phone || 'ไม่มีเบอร์' }})
            </option>
          </select>
        </div>
        <div class="field">
          <label>2. ชื่อสมาชิก / ชื่อร้านค้า *</label>
          <input v-model="createForm.name" placeholder="เช่น ร้านสมชายการค้า" />
        </div>
        <div class="form-grid-2">
          <div class="field">
            <label>เบอร์โทร (ใช้ล็อกอิน) *</label>
            <input v-model="createForm.phone" placeholder="08xxxxxxxx" />
          </div>
          <div class="field">
            <label>อีเมล (ไม่บังคับหากใส่เบอร์)</label>
            <input v-model="createForm.email" type="email" placeholder="shop@example.com" />
          </div>
        </div>
        <div class="field">
          <label>3. รหัสผ่านเข้า Member Portal (ขั้นต่ำ 12 ตัวอักษร) *</label>
          <div class="input-action-row">
            <input
              v-model="createForm.password"
              :type="showCreatePassword ? 'text' : 'password'"
              placeholder="อย่างน้อย 12 ตัวอักษร"
            />
            <button type="button" class="btn light" @click="showCreatePassword = !showCreatePassword">
              {{ showCreatePassword ? 'ซ่อน' : 'ดู' }}
            </button>
            <button type="button" class="btn light" @click="generateSafePassword">🎲 สุ่มรหัส</button>
          </div>
        </div>
        <div class="form-grid-2">
          <div class="field">
            <label>รหัสสมาชิก ERP (ถ้ามี)</label>
            <input v-model="createForm.erp_member_code" placeholder="เช่น MEM001" />
          </div>
          <div class="field">
            <label>หมายเหตุหลังบ้าน</label>
            <input v-model="createForm.verification_note" placeholder="คีย์สมัครโดยแอดมินหลังบ้าน" />
          </div>
        </div>
        <label
          v-if="createForm.erp_customer_id"
          style="
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            padding: 6px 0;
          "
        >
          <input v-model="createForm.auto_verify" type="checkbox" style="width: 16px; height: 16px" />
          <span>ยืนยันสิทธิ์ทันที (member_verified) ให้ร้านค้าเห็นบิลขายและแต้มสะสมได้ทันที</span>
        </label>
      </div>

      <template #footer="{ requestClose }">
        <template v-if="createdCredentials">
          <button type="button" class="btn light" @click="resetCreateForm">➕ คีย์สมาชิกคนถัดไป</button>
          <button type="button" class="btn primary" @click="requestClose">เสร็จสิ้น</button>
        </template>
        <template v-else>
          <button type="button" class="btn" @click="requestClose">ยกเลิก</button>
          <button
            type="button"
            class="btn primary"
            :disabled="
              busy ||
              !createForm.name.trim() ||
              (!createForm.phone.trim() && !createForm.email.trim()) ||
              createForm.password.length < 12
            "
            @click="submitCreateMember"
          >
            {{ busy ? 'กำลังบันทึก...' : '✓ บันทึกและสร้างบัญชีสมาชิก' }}
          </button>
        </template>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useQuery } from '@tanstack/vue-query';
import BaseModal from '../../../shared/components/BaseModal.vue';
import PageHeader from '../../../shared/components/PageHeader.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { askConfirm, toast } from '../../../shared/composables/useUiStore.js';
import { crmGet, crmMutate, fmt } from '../composables/useCrmSession.js';

const route = useRoute();
const router = useRouter();
const memberTab = ref('members');
const search = ref('');
const verifyFilter = ref('pending');
const busy = ref(false);
const erpSearch = ref('');

const reviewTarget = ref(null);
const reviewForm = ref({ erp_customer_id: '', erp_member_code: '', review_note: '' });

const bindTarget = ref(null);
const bindForm = ref({ erp_customer_id: '', verification_note: '' });

const createModalOpen = ref(false);
const showCreatePassword = ref(true);
const createdCredentials = ref(null);
const createForm = ref({
  erp_customer_id: '',
  name: '',
  phone: '',
  email: '',
  password: '',
  erp_member_code: '',
  auto_verify: true,
  verification_note: 'สร้างบัญชีและยืนยันโดยทีมหลังบ้าน',
});

const memberPortalUrl = computed(() => {
  if (typeof window === 'undefined') return 'http://member.popstar.localhost/';
  const { protocol, hostname, port } = window.location;
  if (hostname === 'popstar.localhost') return `${protocol}//member.popstar.localhost${port ? `:${port}` : ''}/`;
  if (hostname.startsWith('crm.')) return `${protocol}//member.${hostname.slice(4)}${port ? `:${port}` : ''}/`;
  if (port === '18081') return `${protocol}//${hostname}:18082/`;
  return `${protocol}//member.${hostname}${port ? `:${port}` : ''}/`;
});

const {
  data: members,
  isLoading: membersLoading,
  refetch: refetchMembers,
} = useQuery({
  queryKey: computed(() => ['crm', 'members-registry', search.value]),
  queryFn: ({ signal }) => {
    const p = new URLSearchParams();
    if (search.value.trim()) p.set('q', search.value.trim());
    return crmGet(`/members?${p.toString()}`, { signal });
  },
});

const { data: verifications, refetch: refetchVerifs } = useQuery({
  queryKey: computed(() => ['crm', 'loyalty-verifications', verifyFilter.value]),
  queryFn: ({ signal }) =>
    crmGet(`/loyalty/verifications?status=${encodeURIComponent(verifyFilter.value)}`, { signal }),
});

const { data: erpData } = useQuery({
  queryKey: computed(() => ['crm', 'member-erp-options', erpSearch.value]),
  queryFn: ({ signal }) => {
    const p = new URLSearchParams({ picker: '1', per_page: '25' });
    if (erpSearch.value.trim()) p.set('q', erpSearch.value.trim());
    return crmGet(`/customers?${p.toString()}`, { signal });
  },
  enabled: computed(() => Boolean(reviewTarget.value || bindTarget.value || createModalOpen.value)),
});
const erpOptions = computed(() => erpData.value?.data || []);

function generateSafePassword() {
  const digits = String(Math.floor(100000 + Math.random() * 900000));
  createForm.value.password = `Popstar@${digits}`;
  showCreatePassword.value = true;
}

function resetCreateForm() {
  createdCredentials.value = null;
  erpSearch.value = '';
  createForm.value = {
    erp_customer_id: '',
    name: '',
    phone: '',
    email: '',
    password: '',
    erp_member_code: '',
    auto_verify: true,
    verification_note: 'สร้างบัญชีและยืนยันโดยทีมหลังบ้าน',
  };
  generateSafePassword();
}

function openCreateMemberModal() {
  resetCreateForm();
  createModalOpen.value = true;
}

function closeCreateMemberModal() {
  createModalOpen.value = false;
  if (route.query.create) {
    router.replace({ path: '/members', query: {} });
  }
}

function onSelectCreateErpCustomer() {
  const picked = erpOptions.value.find((c) => String(c.id) === String(createForm.value.erp_customer_id));
  if (!picked) return;
  if (!createForm.value.name.trim()) createForm.value.name = picked.customer_name || '';
  if (!createForm.value.phone.trim() && picked.phone) {
    const firstPhone =
      String(picked.phone)
        .split(/[,/\s]+/)[0]
        ?.trim() || '';
    if (firstPhone) createForm.value.phone = firstPhone;
  }
}

async function submitCreateMember() {
  busy.value = true;
  try {
    const payload = {
      name: createForm.value.name.trim(),
      phone: createForm.value.phone.trim() || null,
      email: createForm.value.email.trim() || null,
      password: createForm.value.password,
      erp_customer_id: createForm.value.erp_customer_id ? Number(createForm.value.erp_customer_id) : null,
      erp_member_code: createForm.value.erp_member_code.trim() || null,
      auto_verify: Boolean(createForm.value.auto_verify),
      verification_note: createForm.value.verification_note.trim() || 'สร้างบัญชีและยืนยันโดยทีมหลังบ้าน',
    };
    const res = await crmMutate('/members', 'POST', payload);
    createdCredentials.value = {
      name: payload.name,
      phone: payload.phone,
      email: payload.email,
      password: payload.password,
      verification_status: res?.verification_status || 'unverified',
    };
    toast.success('สร้างบัญชีสมาชิกจากหลังบ้านเรียบร้อยแล้ว');
    refetchAll();
  } catch (err) {
    toast.error('สร้างบัญชีสมาชิกไม่สำเร็จ', err);
  } finally {
    busy.value = false;
  }
}

async function copyCreatedCredentials() {
  if (!createdCredentials.value) return;
  const loginId = createdCredentials.value.phone || createdCredentials.value.email;
  const text = [
    `บัญชีสมาชิก POPSTAR สำหรับ ${createdCredentials.value.name}`,
    `ลิงก์เข้าใช้งาน: ${memberPortalUrl.value}`,
    `ชื่อผู้ใช้: ${loginId}`,
    `รหัสผ่าน: ${createdCredentials.value.password}`,
  ].join('\n');
  try {
    await navigator.clipboard.writeText(text);
    toast.success('คัดลอกข้อความสำหรับส่งให้ร้านค้าแล้ว');
  } catch {
    toast.info(text);
  }
}

onMounted(() => {
  if (route.query.create === '1') {
    openCreateMemberModal();
  }
});

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
        verification_status: 'verified',
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
    await crmMutate(`/member-links/${linkId}/verify`, 'POST', {
      verification_status: 'verified',
      verification_note: 'ยืนยันจาก Member Registry',
    });
    toast.success('ยืนยันบัญชีสมาชิกแล้ว');
    refetchAll();
  } catch (err) {
    toast.error('ยืนยันไม่สำเร็จ', err);
  }
}
</script>
