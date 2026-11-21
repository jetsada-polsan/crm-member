<template>
  <div>
    <PageHeader
      title="ข้อมูลลูกค้าแบบละเอียด"
      subtitle="ดูข้อมูลร้าน ยอดซื้อใน ERP ประวัติเข้าพบ และสถานะสมาชิกได้ในหน้าเดียว"
    >
      <template #secondary>
        <RouterLink to="/customers" class="btn">← กลับรายการลูกค้า</RouterLink>
        <button
          v-if="selectedMasterId"
          type="button"
          class="btn light"
          @click="refetchDetail"
        >
          ⟳ รีเฟรช
        </button>
      </template>
      <template v-if="detail?.erp_customer || detail?.crm_lead" #primary>
        <button
          v-if="detail?.erp_customer"
          type="button"
          class="btn primary"
          @click="openVisitWizard({ kind: 'erp', id: detail.erp_customer.id })"
        >
          📸 บันทึกเข้าพบลูกค้านี้
        </button>
        <button
          v-else-if="detail?.crm_lead"
          type="button"
          class="btn primary"
          @click="openVisitWizard({ kind: 'lead', id: detail.crm_lead.id })"
        >
          📸 บันทึกเข้าพบร้านนี้
        </button>
      </template>
    </PageHeader>

    <!-- Searchable Customer Master Picker -->
    <div class="card panel-pad" style="margin-bottom:16px">
      <div class="head-filter" style="margin-bottom:0">
        <input
          v-model="pickerSearch"
          class="filter-search-input"
          placeholder="ค้นหา Customer Master เพื่อดู 360° (ชื่อร้าน, รหัส CRM, รหัส ERP, เบอร์โทร)..."
        />
        <select
          :value="selectedMasterId || ''"
          class="filter-search-input"
          @change="selectMaster($event.target.value)"
        >
          <option value="">-- เลือกลูกค้าจากรายการ --</option>
          <option
            v-for="item in pickerRows"
            :key="item.customer_master_id"
            :value="String(item.customer_master_id)"
          >
            {{ item.internal_customer_code }} · {{ item.display_name }}
            {{ item.erp_customer_code ? `(${item.erp_customer_code})` : '(Lead Only)' }}
          </option>
        </select>
      </div>
    </div>

    <div v-if="!selectedMasterId" class="card panel-pad">
      <h3 style="margin:0 0 8px">เลือกลูกค้าเพื่อเปิดมุมมอง Customer 360°</h3>
      <p class="small muted" style="margin:0 0 14px">
        คลิกรายการด้านล่างเพื่อดูยอดขายสุทธิจาก Bplus ERP, ประวัติเข้าพบ, หลักฐานภาพถ่าย และ Audit History
      </p>
      <div class="table-wrap desktop-table-only">
        <table class="table">
          <thead>
            <tr>
              <th>รหัส CRM</th>
              <th>ชื่อร้านค้า</th>
              <th>รหัส ERP</th>
              <th>Pipeline</th>
              <th>ยอดสุทธิก่อน VAT</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="item in pickerRows"
              :key="item.customer_master_id"
              style="cursor:pointer"
              @click="selectMaster(item.customer_master_id)"
            >
              <td><span class="tag blue">{{ item.internal_customer_code }}</span></td>
              <td>
                <b>{{ item.display_name }}</b>
                <div class="small muted">{{ item.phone || '—' }} · {{ item.province || '—' }}</div>
              </td>
              <td>
                <span v-if="item.erp_customer_code" class="tag gray">{{ item.erp_customer_code }}</span>
                <span v-else class="small muted">ยังไม่เชื่อม ERP</span>
              </td>
              <td>
                <span v-if="item.pipeline_stage" class="tag" :class="stageTagClass(item.pipeline_stage)">
                  {{ stageLabel(item.pipeline_stage) }}
                </span>
                <span v-else class="tag green">ERP Direct</span>
              </td>
              <td><b>{{ fmt(item.total_sales_net) }} ฿</b></td>
              <td>
                <button type="button" class="btn light" @click.stop="selectMaster(item.customer_master_id)">
                  เปิด 360°
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="mob-cards">
        <div
          v-for="item in pickerRows"
          :key="`m-${item.customer_master_id}`"
          class="card mob-card"
          @click="selectMaster(item.customer_master_id)"
        >
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
            <strong>{{ item.display_name }}</strong>
            <span class="tag blue">{{ item.internal_customer_code }}</span>
          </div>
          <div class="small muted" style="margin-top:4px">
            ERP: {{ item.erp_customer_code || 'ยังไม่เชื่อม' }} · ยอดสุทธิ {{ fmt(item.total_sales_net) }} ฿
          </div>
        </div>
      </div>
    </div>

    <SkeletonBlock v-else-if="detailLoading && !detail" variant="cards" :rows="4" />
    <div v-else-if="detailError" class="error">{{ detailErr?.message || 'โหลด Customer 360° ไม่สำเร็จ' }}</div>

    <template v-else-if="detail">
      <!-- Top Identity Summary Banner -->
      <div class="card panel-pad" style="margin-bottom:16px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
          <div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
              <span class="tag blue">{{ detail.internal_customer.internal_customer_code }}</span>
              <span
                class="tag"
                :class="detail.erp_customer ? 'green' : 'orange'"
              >
                {{ detail.erp_customer ? `ERP: ${detail.erp_customer.erp_customer_code}` : 'ยังไม่เชื่อม ERP' }}
              </span>
              <span
                v-if="detail.crm_lead"
                class="tag"
                :class="stageTagClass(detail.crm_lead.pipeline_stage)"
              >
                {{ stageLabel(detail.crm_lead.pipeline_stage) }}
              </span>
              <span
                class="tag"
                :class="detail.member_link?.verification_status === 'verified' ? 'purple' : 'gray'"
              >
                Member: {{ detail.member_link?.verification_status || 'ยังไม่ผูก' }}
              </span>
            </div>
            <h3 style="margin:0 0 4px">{{ detail.internal_customer.display_name }}</h3>
            <div class="small muted">
              โทร: {{ detail.internal_customer.phone || '—' }} ·
              {{ detail.internal_customer.shop_type || 'ร้านค้า' }} ·
              {{ detail.internal_customer.province || detail.internal_customer.district || 'ไม่ระบุจังหวัด' }}
            </div>
          </div>

          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button
              v-if="detail.crm_lead && !detail.erp_customer && detail.crm_lead.pipeline_stage !== 'erp_creation_requested'"
              type="button"
              class="btn light"
              :disabled="actionBusy"
              @click="requestErpCreation"
            >
              📨 ขอเปิดบัญชี ERP ใหม่
            </button>
            <button
              v-if="detail.crm_lead"
              type="button"
              class="btn"
              @click="openDetailDrawer('lead', detail.crm_lead.id)"
            >
              ✏️ แก้ไข Lead / อัปโหลดรูป
            </button>
            <button
              v-if="isManager && detail.crm_lead && !detail.erp_customer"
              type="button"
              class="btn primary"
              @click="linkErpModalOpen = true"
            >
              🔗 เชื่อมบัญชี ERP
            </button>
            <button
              v-if="isManager && detail.erp_customer"
              type="button"
              class="btn danger"
              :disabled="actionBusy"
              @click="unlinkErp"
            >
              ⛔ ยกเลิกเชื่อม ERP
            </button>
          </div>
        </div>
      </div>

      <section v-if="detail.erp_customer" class="card panel-pad" style="margin-bottom:16px">
        <h3 style="margin:0 0 12px">ยอดซื้อและประวัติบิลตามช่วงวันที่</h3>
        <CustomerPurchaseHistory :customer-id="detail.erp_customer.id" />
      </section>

      <!-- 4 Separated Domains Grid -->
      <div class="kpis customer-domains" style="margin-bottom:16px">
        <div class="card panel-pad">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <strong>1. ข้อมูลลูกค้า ERP</strong>
            <span class="tag gray">MIRROR</span>
          </div>
          <template v-if="detail.erp_customer">
            <div class="minirow"><span>รหัส ERP</span><b>{{ detail.erp_customer.erp_customer_code }}</b></div>
            <div class="minirow"><span>ชื่อใน Bplus</span><span>{{ detail.erp_customer.customer_name }}</span></div>
            <div class="minirow"><span>พนักงานขาย</span><span>{{ detail.erp_customer.salesperson_name || detail.erp_customer.salesperson_code || '—' }}</span></div>
            <div class="minirow"><span>บิลขายจริง / ยอดซื้อสุทธิสะสม</span><b>{{ fmt(detail.sales_summary?.sale_invoice_count || 0) }} บิล · {{ fmt(detail.sales_summary?.net_purchase_before_vat || 0) }} ฿</b></div>
            <div class="minirow"><span>วงเงินเครดิต</span><span>{{ fmt(detail.erp_customer.credit_limit) }} ฿ ({{ detail.erp_customer.credit_term_days || 0 }} วัน)</span></div>
          </template>
          <div v-else class="small muted">
            ยังไม่ผูกกับลูกค้า Bplus ERP — ข้อมูลภาคสนามถูกเก็บแยกใน CRM Lead และ Internal Master โดยไม่เขียนทับ ERP
          </div>
        </div>

        <div class="card panel-pad">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <strong>2. ข้อมูลร้านและพิกัด</strong>
            <span class="tag blue">FIELD OPS</span>
          </div>
          <template v-if="detail.crm_lead">
            <div class="minirow"><span>ผู้ติดต่อ</span><span>{{ detail.crm_lead.contact_name || '—' }}</span></div>
            <div class="minirow"><span>LINE</span><span>{{ detail.crm_lead.line_contact || '—' }}</span></div>
            <div class="minirow"><span>แหล่งซื้อสินค้า</span><span>{{ detail.crm_lead.supplier_source || '—' }}</span></div>
            <div class="minirow"><span>หมวดสินค้า</span><span>{{ detail.crm_lead.product_categories || '—' }}</span></div>
            <div class="minirow"><span>พิกัด GPS</span><span>{{ detail.crm_location ? `${detail.crm_location.latitude}, ${detail.crm_location.longitude}` : 'ยังไม่ปักหมุด' }}</span></div>
          </template>
          <div v-else class="small muted">
            ลูกค้า ERP ตรง (ไม่มี CRM Lead ตั้งต้น) · พิกัด CRM:
            {{ detail.crm_location ? `${detail.crm_location.latitude}, ${detail.crm_location.longitude}` : 'ยังไม่ปักหมุด' }}
          </div>
        </div>

        <div class="card panel-pad">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <strong>3. ข้อมูลสมาชิก</strong>
            <span class="tag" :class="detail.member_link?.verification_status === 'verified' ? 'green' : 'orange'">
              {{ detail.member_link?.verification_status || 'UNLINKED' }}
            </span>
          </div>
          <template v-if="detail.member_link">
            <div class="minirow"><span>ชื่อสมาชิก</span><b>{{ detail.member_link.member_name }}</b></div>
            <div class="minirow"><span>เบอร์/อีเมล</span><span>{{ detail.member_link.member_phone || detail.member_link.member_email || '—' }}</span></div>
            <div class="minirow"><span>รหัสสมาชิก ERP</span><span>{{ detail.member_link.erp_member_code || '—' }}</span></div>
            <div class="minirow"><span>คะแนนสะสมใน CRM</span><b>{{ fmt(detail.member_link.loyalty_points_balance || 0) }} แต้ม</b></div>
          </template>
          <div v-else class="small muted">
            ยังไม่มีบัญชีสมาชิกที่ผ่านการตรวจสอบและยืนยันตัวตน
          </div>
        </div>

        <div class="card panel-pad">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <strong>4. งานติดตาม</strong>
            <span class="tag teal">ISOLATED</span>
          </div>
          <div class="minirow"><span>เข้าพบสะสม</span><b>{{ fmt((detail.visits || []).length) }} ครั้ง</b></div>
          <div class="minirow"><span>งานติดตามค้าง</span><b>{{ fmt((detail.follow_up_tasks || []).length) }} งาน</b></div>
          <div class="small muted" style="margin-top:8px">
            การเข้าพบ ภาพถ่าย และพิกัด GPS เก็บผูกกับ Customer Master ทำให้เปลี่ยนหรือยกเลิกการเชื่อม ERP ได้โดยประวัติไม่สูญหาย
          </div>
        </div>
      </div>

      <!-- Visits Timeline + Audit Log Split -->
      <div class="split">
        <div class="card panel-pad">
          <h3 style="margin:0 0 12px">ไทม์ไลน์การเข้าพบ &amp; หลักฐานภาคสนาม ({{ (detail.visits || []).length }})</h3>
          <div v-if="(detail.visits || []).length" class="timeline">
            <div v-for="v in detail.visits" :key="v.id" class="timeline-item">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap">
                <div>
                  <b>{{ v.visited_at ? new Date(v.visited_at).toLocaleString('th-TH') : '—' }}</b>
                  <div class="small muted">โดย {{ v.staff_name || 'พนักงานขาย' }} · GPS: {{ v.gps_permission_status || '—' }}</div>
                </div>
                <div style="display:flex;gap:6px;align-items:center">
                  <span class="tag blue">{{ v.result || 'visited' }}</span>
                  <button
                    v-if="v.photo_count > 0"
                    type="button"
                    class="btn light"
                    style="padding:5px 10px"
                    @click="viewVisitPhotos(v.id)"
                  >
                    🖼 {{ v.photo_count }} รูป
                  </button>
                </div>
              </div>
              <div v-if="v.follow_up_action" class="small" style="margin-top:4px">แผนติดตาม: {{ v.follow_up_action }}</div>
              <div v-if="v.notes" class="small muted" style="margin-top:4px">{{ v.notes }}</div>
            </div>
          </div>
          <div v-else class="small muted">ยังไม่มีประวัติเข้าพบร้านค้านี้</div>
        </div>

        <div class="card panel-pad">
          <h3 style="margin:0 0 12px">Audit Log ประวัติเชื่อมตัวตน ({{ (auditLogs || []).length }})</h3>
          <div v-for="log in auditLogs || []" :key="log.id" class="minirow" style="align-items:flex-start">
            <div>
              <b>{{ log.action }}</b>
              <div class="small muted">{{ log.actor_name || 'System' }} · {{ log.created_at ? new Date(log.created_at).toLocaleString('th-TH') : '' }}</div>
              <div v-if="log.changes" class="small muted" style="word-break:break-word">{{ JSON.stringify(log.changes) }}</div>
            </div>
            <span class="tag gray">#{{ log.id }}</span>
          </div>
          <div v-if="!(auditLogs || []).length" class="small muted">ยังไม่มีประวัติการเปลี่ยนแปลงการเชื่อมตัวตน</div>
        </div>
      </div>
    </template>

    <!-- Manager Manual ERP Link Modal -->
    <BaseModal :open="linkErpModalOpen" title="เชื่อม CRM Lead กับลูกค้า ERP" @close="linkErpModalOpen = false">
      <div class="field" style="margin-bottom:10px">
        <label>ค้นหาลูกค้า ERP จาก Bplus Mirror</label>
        <input v-model="erpSearch" placeholder="พิมพ์ชื่อร้านหรือรหัสลูกค้า ERP..." />
      </div>
      <div class="field" style="margin-bottom:10px">
        <label>เลือกลูกค้า ERP *</label>
        <select v-model="selectedErpCustomerId">
          <option value="">-- เลือกลูกค้า ERP --</option>
          <option v-for="c in erpOptions" :key="c.id" :value="c.id">
            {{ c.erp_customer_code }} · {{ c.customer_name }} ({{ c.province || '—' }})
          </option>
        </select>
      </div>
      <div class="field">
        <label>หมายเหตุยืนยันการเชื่อม *</label>
        <input v-model="linkNote" placeholder="เช่น ตรวจสอบชื่อร้านและเบอร์โทรตรงกับ Bplus แล้ว" />
      </div>
      <template #footer="{ requestClose }">
        <button type="button" class="btn" @click="requestClose">ยกเลิก</button>
        <button
          type="button"
          class="btn primary"
          :disabled="!selectedErpCustomerId || actionBusy"
          @click="confirmLinkErp"
        >
          {{ actionBusy ? 'กำลังเชื่อม...' : 'ยืนยันเชื่อม ERP' }}
        </button>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useQuery } from '@tanstack/vue-query';
import BaseModal from '../../../shared/components/BaseModal.vue';
import PageHeader from '../../../shared/components/PageHeader.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import CustomerPurchaseHistory from '../components/CustomerPurchaseHistory.vue';
import { askConfirm, toast } from '../../../shared/composables/useUiStore.js';
import {
  crmGet,
  crmMutate,
  fmt,
  invalidateCrmData,
  isManager,
  openDetailDrawer,
  openLightbox,
  openVisitWizard,
  stageLabel,
  stageTagClass,
} from '../composables/useCrmSession.js';

const route = useRoute();
const router = useRouter();

const pickerSearch = ref('');
const selectedMasterId = computed(() => {
  const raw = route.params.id;
  return raw ? Number(raw) : null;
});

const { data: pickerData } = useQuery({
  queryKey: computed(() => ['crm', 'customer360-picker', pickerSearch.value]),
  queryFn: ({ signal }) => {
    const p = new URLSearchParams({ paginate: '1', per_page: '20' });
    if (pickerSearch.value.trim()) p.set('q', pickerSearch.value.trim());
    return crmGet(`/customer-master?${p.toString()}`, { signal });
  },
});

const pickerRows = computed(() => pickerData.value?.data || []);

const {
  data: detail,
  isLoading: detailLoading,
  isError: detailError,
  error: detailErr,
  refetch: refetchDetail,
} = useQuery({
  queryKey: computed(() => ['crm', 'customer360-detail', selectedMasterId.value]),
  queryFn: ({ signal }) => crmGet(`/customer-master/${selectedMasterId.value}`, { signal }),
  enabled: computed(() => Boolean(selectedMasterId.value)),
});

const { data: auditLogs, refetch: refetchAudit } = useQuery({
  queryKey: computed(() => ['crm', 'customer360-audit', selectedMasterId.value]),
  queryFn: ({ signal }) => crmGet(`/customer-master/${selectedMasterId.value}/audit`, { signal }),
  enabled: computed(() => Boolean(selectedMasterId.value) && isManager.value),
});

const linkErpModalOpen = ref(false);
const erpSearch = ref('');
const selectedErpCustomerId = ref('');
const linkNote = ref('');
const actionBusy = ref(false);

const { data: erpSearchData } = useQuery({
  queryKey: computed(() => ['crm', 'erp-link-options', erpSearch.value]),
  queryFn: ({ signal }) => {
    const p = new URLSearchParams({ paginate: '1', per_page: '20' });
    if (erpSearch.value.trim()) p.set('q', erpSearch.value.trim());
    return crmGet(`/customers?${p.toString()}`, { signal });
  },
  enabled: computed(() => linkErpModalOpen.value && isManager.value),
});
const erpOptions = computed(() => erpSearchData.value?.data || []);

watch(selectedMasterId, () => {
  selectedErpCustomerId.value = '';
  linkNote.value = '';
});

function selectMaster(id) {
  if (!id) {
    router.push('/customer-360');
    return;
  }
  router.push(`/customer-360/${id}`);
}

async function requestErpCreation() {
  if (!detail.value?.crm_lead) return;
  const ok = await askConfirm({
    title: 'ส่งคำขอเปิดรหัสลูกค้า Bplus ERP',
    message: `ส่งคำขอเปิดบัญชี ERP สำหรับร้าน "${detail.value.crm_lead.shop_name}" เข้าคิวงานผู้ดูแล?`,
    confirmLabel: 'ส่งคำขอ',
  });
  if (!ok) return;
  actionBusy.value = true;
  try {
    await crmMutate(`/customer-master/leads/${detail.value.crm_lead.id}/request-erp-creation`, 'POST', {
      note: 'ส่งคำขอจากหน้า Customer 360°',
    });
    toast.success('ส่งคำขอเปิดบัญชี ERP เรียบร้อย');
    invalidateCrmData(['leads', 'master', 'dashboard']);
    await Promise.all([refetchDetail(), refetchAudit()]);
  } catch (err) {
    toast.error('ส่งคำขอไม่สำเร็จ', err);
  } finally {
    actionBusy.value = false;
  }
}

async function confirmLinkErp() {
  if (!detail.value?.crm_lead || !selectedErpCustomerId.value) return;
  actionBusy.value = true;
  try {
    await crmMutate(`/customer-master/leads/${detail.value.crm_lead.id}/link-erp`, 'POST', {
      erp_customer_id: Number(selectedErpCustomerId.value),
      note: linkNote.value.trim() || 'เชื่อมบัญชี ERP จาก Customer 360°',
    });
    linkErpModalOpen.value = false;
    toast.success('เชื่อม Customer Master กับ ERP แล้ว');
    invalidateCrmData(['leads', 'master', 'dashboard']);
    await Promise.all([refetchDetail(), refetchAudit()]);
  } catch (err) {
    toast.error('เชื่อม ERP ไม่สำเร็จ', err);
  } finally {
    actionBusy.value = false;
  }
}

async function unlinkErp() {
  if (!selectedMasterId.value) return;
  const ok = await askConfirm({
    title: 'ยืนยันยกเลิกการเชื่อม ERP',
    message: 'ประวัติเข้าพบ ภาพถ่าย และพิกัด GPS ใน CRM จะยังคงอยู่ครบถ้วน ต้องการยกเลิกเชื่อม ERP ใช่หรือไม่?',
    confirmLabel: 'ยกเลิกเชื่อม ERP',
    tone: 'danger',
  });
  if (!ok) return;
  actionBusy.value = true;
  try {
    await crmMutate(`/customer-master/${selectedMasterId.value}/unlink-erp`, 'POST', {
      note: 'ยกเลิกการเชื่อม ERP จาก Customer 360°',
    });
    toast.success('ยกเลิกการเชื่อม ERP แล้ว (ข้อมูล CRM ถูกเก็บรักษาไว้)');
    invalidateCrmData(['leads', 'master', 'dashboard']);
    await Promise.all([refetchDetail(), refetchAudit()]);
  } catch (err) {
    toast.error('ยกเลิกไม่สำเร็จ', err);
  } finally {
    actionBusy.value = false;
  }
}

async function viewVisitPhotos(visitId) {
  try {
    const photos = await crmGet(`/field-visits/${visitId}/photos`);
    if (!photos.length) {
      toast.info('ไม่มีรูปถ่ายในการเข้าพบนี้');
      return;
    }
    openLightbox(
      photos.map((p) => ({
        src: `/api/crm/photos/${p.id}?inline=1`,
        title: p.original_name || `หลักฐานการเข้าพบ #${visitId}`,
        category: p.category,
        capturedAt: p.captured_at ? new Date(p.captured_at).toLocaleString('th-TH') : '',
        consentConfirmed: p.consent_confirmed,
        compressedOnClient: p.compressed_on_client,
        latitude: p.latitude,
        longitude: p.longitude,
      })),
      0
    );
  } catch (err) {
    toast.error('เปิดรูปไม่สำเร็จ', err);
  }
}
</script>

<style scoped>
@media (max-width: 900px) {
  .customer-domains {
    grid-template-columns: 1fr;
  }
}
</style>
