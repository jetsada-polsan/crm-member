<template>
  <div>
    <div class="pagehead">
      <div>
        <div class="eyebrow">CUSTOMER DIRECTORY</div>
        <h2>ทะเบียนลูกค้า &amp; Customer Master</h2>
        <p>ค้นหาลูกค้าแบบ Server-side Pagination พร้อมแยกตัวตน ERP, CRM Lead และ Member ชัดเจน</p>
      </div>
      <div class="head-actions">
        <button v-if="isManager" type="button" class="btn" @click="openWorkflow('requests')">คำขอเปิดรหัส ERP</button>
        <button v-if="isManager" type="button" class="btn" @click="openWorkflow('matches')">คิวจับคู่ / ตรวจรายการซ้ำ</button>
        <button type="button" class="btn" @click="csvModalOpen = true">📥 นำเข้า CSV</button>
        <button type="button" class="btn primary" @click="openQuickAdd()">＋ เพิ่มร้านค้าใหม่</button>
      </div>
    </div>

    <div class="card panel-pad" style="margin-bottom:16px">
      <div class="head-filter" style="margin-bottom:10px">
        <button
          type="button"
          class="btn"
          :class="{ primary: mode === 'master' }"
          @click="mode = 'master'; page = 1"
        >
          Customer Master (แยกตัวตน)
        </button>
        <button
          type="button"
          class="btn"
          :class="{ primary: mode === 'erp' }"
          @click="mode = 'erp'; page = 1"
        >
          ลูกค้า ERP (Bplus)
        </button>
        <button
          type="button"
          class="btn"
          :class="{ primary: mode === 'leads' }"
          @click="mode = 'leads'; page = 1"
        >
          ลูกค้าใหม่ (CRM Leads)
        </button>
      </div>

      <div class="head-filter" style="margin-bottom:0">
        <input
          v-model="searchRaw"
          style="min-width:220px;flex:1"
          placeholder="ค้นหาชื่อร้าน, รหัสภายใน, รหัส ERP, เบอร์โทร..."
        />
        <select v-if="mode === 'master'" v-model="masterFilter" @change="page = 1">
          <option value="all">ทุกสถานะเชื่อม</option>
          <option value="erp_linked">เชื่อม ERP แล้ว</option>
          <option value="lead_only">CRM Lead ยังไม่เชื่อม ERP</option>
          <option value="member_verified">Member ยืนยันแล้ว</option>
          <option value="member_pending">Member รอตรวจ</option>
        </select>
        <select v-else-if="mode === 'erp'" v-model="erpStatus" @change="page = 1">
          <option value="all">ลูกค้า ERP ทั้งหมด</option>
          <option value="purchasing">มีบิลซื้อแล้ว</option>
          <option value="no_purchase">ยังไม่เคยเปิดบิล</option>
        </select>
        <select v-else v-model="leadStage" @change="page = 1">
          <option value="">ทุก Pipeline Stage</option>
          <option v-for="st in PIPELINE_STAGES" :key="st.id" :value="st.id">{{ st.label }}</option>
        </select>
        <select v-model.number="perPage" @change="page = 1">
          <option :value="10">10 / หน้า</option>
          <option :value="25">25 / หน้า</option>
          <option :value="50">50 / หน้า</option>
        </select>
      </div>
    </div>

    <SkeletonBlock v-if="isLoading && !rows.length" variant="table" :rows="7" />
    <div v-else-if="isError" class="error">{{ error?.message || 'โหลดทะเบียนลูกค้าไม่สำเร็จ' }}</div>

    <div v-else class="card">
      <div class="table-wrap desktop-table-only virt-scroll">
        <table class="table">
          <thead v-if="mode === 'master'">
            <tr>
              <th>รหัสภายใน</th>
              <th>ชื่อร้านค้า</th>
              <th>รหัส ERP (Read-Only)</th>
              <th>CRM Pipeline</th>
              <th>Member Link</th>
              <th>ยอดซื้อสุทธิก่อน VAT</th>
              <th></th>
            </tr>
          </thead>
          <thead v-else-if="mode === 'erp'">
            <tr>
              <th>รหัส ERP</th>
              <th>ชื่อร้านค้า</th>
              <th>จังหวัด / เบอร์โทร</th>
              <th>พนักงานขาย</th>
              <th>บิลขาย</th>
              <th>ยอดขายสุทธิก่อน VAT</th>
              <th></th>
            </tr>
          </thead>
          <thead v-else>
            <tr>
              <th>รหัส</th>
              <th>ชื่อร้านค้า</th>
              <th>ประเภท / พื้นที่</th>
              <th>Pipeline</th>
              <th>การอนุมัติ</th>
              <th>ผู้รับผิดชอบ</th>
              <th></th>
            </tr>
          </thead>

          <tbody v-if="mode === 'master'">
            <tr
              v-for="r in rows"
              :key="r.customer_master_id"
              style="cursor:pointer"
              @click="openDetailDrawer('master', r.customer_master_id)"
            >
              <td><span class="tag blue">{{ r.internal_customer_code }}</span></td>
              <td>
                <b>{{ r.display_name }}</b>
                <div class="small muted">{{ r.phone || '—' }} · {{ r.province || r.district || 'ยังไม่ระบุ' }}</div>
              </td>
              <td>
                <span v-if="r.erp_customer_code" class="tag gray">{{ r.erp_customer_code }}</span>
                <span v-else class="small muted">ยังไม่เชื่อม ERP</span>
              </td>
              <td>
                <span v-if="r.pipeline_stage" class="tag" :class="stageTagClass(r.pipeline_stage)">{{ stageLabel(r.pipeline_stage) }}</span>
                <span v-else class="small muted">ลูกค้า ERP ตรง</span>
              </td>
              <td>
                <span v-if="r.member_verification_status" class="tag" :class="r.member_verification_status === 'verified' ? 'green' : 'orange'">
                  {{ r.member_verification_status }}
                </span>
                <span v-else class="small muted">—</span>
              </td>
              <td><b>{{ fmt(r.total_sales_net) }} ฿</b></td>
              <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                  <RouterLink
                    :to="`/customer-360/${r.customer_master_id}`"
                    class="btn light"
                    @click.stop
                  >
                    360°
                  </RouterLink>
                  <button type="button" class="btn" @click.stop="openDetailDrawer('master', r.customer_master_id)">ดูแยกตัวตน</button>
                  <button
                    v-if="r.crm_lead_id && !r.erp_customer_id && r.pipeline_stage !== 'erp_creation_requested'"
                    type="button"
                    class="btn primary"
                    @click.stop="requestErpFromMaster(r)"
                  >
                    ขอเปิด ERP
                  </button>
                </div>
              </td>
            </tr>
          </tbody>

          <tbody v-else-if="mode === 'erp'">
            <tr
              v-for="c in rows"
              :key="c.id"
              style="cursor:pointer"
              @click="openDetailDrawer('erp', c.id)"
            >
              <td><span class="tag gray">{{ c.erp_customer_code }}</span></td>
              <td><b>{{ c.customer_name }}</b></td>
              <td>{{ c.province || '—' }} · {{ c.phone || '—' }}</td>
              <td>{{ c.salesperson_name || c.salesperson_code || '—' }}</td>
              <td>{{ fmt(c.sale_invoice_count) }}</td>
              <td><b>{{ fmt(c.total_sales_net) }} ฿</b></td>
              <td><button type="button" class="btn light" @click.stop="openVisitWizard({ kind: 'erp', id: c.id })">📸 เข้าพบ</button></td>
            </tr>
          </tbody>

          <tbody v-else>
            <tr
              v-for="l in rows"
              :key="l.id"
              style="cursor:pointer"
              @click="openDetailDrawer('lead', l.id)"
            >
              <td><span class="tag blue">{{ l.internal_customer_code || `LEAD-${l.id}` }}</span></td>
              <td>
                <b>{{ l.shop_name }}</b>
                <div class="small muted">{{ l.phone || '—' }}</div>
              </td>
              <td>{{ l.shop_type || 'ร้านค้า' }} · {{ l.province || l.district || '—' }}</td>
              <td><span class="tag" :class="stageTagClass(l.pipeline_stage)">{{ stageLabel(l.pipeline_stage) }}</span></td>
              <td><span class="tag" :class="l.approval_status === 'approved' ? 'green' : 'orange'">{{ APPROVAL_LABELS[l.approval_status] || l.approval_status }}</span></td>
              <td>{{ l.assigned_salesperson_code || l.owner_staff_name || '—' }}</td>
              <td><button type="button" class="btn light" @click.stop="openVisitWizard({ kind: 'lead', id: l.id })">📸 เข้าพบ</button></td>
            </tr>
          </tbody>

          <tbody v-if="!rows.length">
            <tr><td colspan="7" class="placeholder">ไม่พบข้อมูลที่ตรงกับเงื่อนไขการค้นหา</td></tr>
          </tbody>
        </table>
      </div>

      <!-- Mobile Card List (<800px) -->
      <div class="mob-cards" style="padding:12px">
        <div
          v-for="r in rows"
          :key="r.customer_master_id || r.id"
          class="card mob-card"
          @click="openMobileRow(r)"
        >
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
            <strong>{{ r.display_name || r.customer_name || r.shop_name }}</strong>
            <span class="tag blue">{{ r.internal_customer_code || r.erp_customer_code || `LEAD-${r.id}` }}</span>
          </div>
          <div class="small muted" style="margin:6px 0">
            โทร: {{ r.phone || '—' }} · {{ r.province || r.district || 'ไม่ระบุพื้นที่' }}
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:8px;flex-wrap:wrap">
            <span v-if="r.total_sales_net != null" class="small">ยอดสุทธิ <b>{{ fmt(r.total_sales_net) }} ฿</b></span>
            <div style="display:flex;gap:6px;flex-wrap:wrap">
              <RouterLink
                v-if="mode === 'master'"
                :to="`/customer-360/${r.customer_master_id}`"
                class="btn"
                style="padding:6px 10px"
                @click.stop
              >
                360°
              </RouterLink>
              <button
                type="button"
                class="btn light"
                style="padding:6px 10px"
                @click.stop="openVisitWizard({ kind: mode === 'erp' || r.erp_customer_id ? 'erp' : 'lead', id: mode === 'master' ? (r.erp_customer_id || r.crm_lead_id) : r.id })"
              >
                📸 บันทึกเข้าพบ
              </button>
            </div>
          </div>
        </div>
        <div v-if="!rows.length" class="placeholder">ไม่พบข้อมูลลูกค้า</div>
      </div>

      <div class="pager">
        <span>แสดง {{ rows.length }} จาก {{ fmt(meta.total) }} รายการ (หน้า {{ meta.current_page }} / {{ meta.last_page }})</span>
        <div class="pager-btns">
          <button type="button" class="btn" :disabled="page <= 1" @click="page--">← ก่อนหน้า</button>
          <button type="button" class="btn" :disabled="page >= meta.last_page" @click="page++">ถัดไป →</button>
        </div>
      </div>
    </div>

    <BaseModal :open="workflowPanel !== ''" :title="workflowPanel === 'requests' ? 'คำขอเปิดรหัสลูกหนี้ Bplus' : 'คิวจับคู่ ERP และตรวจรายการซ้ำ'" @close="workflowPanel = ''">
      <div v-if="workflowBusy" class="small muted">กำลังโหลดคิว...</div>
      <template v-else-if="workflowPanel === 'requests'">
        <div v-if="!erpRequests.length" class="placeholder">ไม่มีคำขอที่รอสร้างลูกหนี้</div>
        <div v-for="item in erpRequests" :key="item.crm_lead_id" class="minirow">
          <span><b>{{ item.shop_name }}</b><div class="small muted">{{ item.internal_customer_code }} · {{ item.phone || 'ไม่มีเบอร์' }} · {{ item.requested_by || '—' }}</div></span>
          <button type="button" class="btn light" @click="openLeadRequest(item.crm_lead_id)">ดู Lead</button>
        </div>
        <p class="small muted">สร้างลูกหนี้ใน Bplus ตามขั้นตอนเดิม จากนั้นซิงก์ ERP แล้วกลับมาตรวจ Candidate เพื่อยืนยันการผูก</p>
      </template>
      <template v-else>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
          <span class="small muted">ชื่อ เบอร์ และที่อยู่เป็นเพียงหลักฐานเสนอคู่ ระบบไม่ผูกให้อัตโนมัติ</span>
          <button type="button" class="btn" :disabled="workflowBusy" @click="refreshMatches">อัปเดต Candidates</button>
        </div>
        <div v-if="!matchCandidates.length" class="placeholder">ไม่มี Candidate ที่รอตรวจ</div>
        <div v-for="item in matchCandidates" :key="item.id" class="card panel-pad" style="margin-bottom:10px">
          <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start">
            <div><b>{{ item.crm_shop_name }}</b><div class="small muted">{{ item.crm_phone || '—' }} · {{ item.crm_address || 'ไม่มีที่อยู่' }}</div></div>
            <span class="tag orange">คะแนน {{ Math.round(item.score) }}</span>
          </div>
          <div class="minirow"><span>ERP {{ item.source_system }} / {{ item.erp_customer_code }}</span><b>{{ item.erp_customer_name }}</b></div>
          <div class="small muted">โทรตรงกัน: {{ item.match_reasons?.phone_match ? 'ใช่' : 'ไม่ใช่' }} · จังหวัดตรงกัน: {{ item.match_reasons?.same_province ? 'ใช่' : 'ไม่ใช่' }}</div>
          <div style="display:flex;gap:8px;margin-top:10px">
            <button type="button" class="btn primary" @click="confirmMatch(item)">ยืนยันผูกบัญชีนี้</button>
            <button type="button" class="btn danger" @click="dismissMatch(item)">ไม่ใช่บัญชีเดียวกัน</button>
          </div>
        </div>
      </template>
    </BaseModal>
    <BaseModal :open="csvModalOpen" title="นำเข้าข้อมูลร้านค้าใหม่จากไฟล์ CSV" @close="csvModalOpen = false">
      <p class="small muted" style="margin-top:0">
        ระบบตรวจสอบข้อมูลซ้ำ (ชื่อร้าน / เบอร์โทร) ก่อนสร้าง Lead ใหม่ทุกแถว และไม่สร้างข้อมูลเข้า Bplus ERP อัตโนมัติ
      </p>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
        <a href="/api/crm/leads/import-template" class="btn light">⬇ ดาวน์โหลดไฟล์ Template</a>
      </div>
      <div class="field">
        <label>เลือกไฟล์ CSV / TXT *</label>
        <input type="file" accept=".csv,.txt" @change="csvFile = $event.target.files?.[0] || null" />
      </div>
      <div v-if="csvResult" class="success" style="margin-top:12px">
        นำเข้าสำเร็จ {{ csvResult.imported }} ร้าน · ข้ามซ้ำ {{ csvResult.skipped_duplicates }} รายการ
      </div>
      <template #footer="{ requestClose }">
        <button type="button" class="btn" @click="requestClose">ปิด</button>
        <button type="button" class="btn primary" :disabled="!csvFile || csvBusy" @click="runCsvImport">
          {{ csvBusy ? 'กำลังนำเข้า...' : 'เริ่มนำเข้า CSV' }}
        </button>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { keepPreviousData, useQuery } from '@tanstack/vue-query';
import BaseModal from '../../../shared/components/BaseModal.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { askConfirm, toast } from '../../../shared/composables/useUiStore.js';
import {
  APPROVAL_LABELS,
  crmGet,
  crmMutate,
  fmt,
  invalidateCrmData,
  openDetailDrawer,
  openQuickAdd,
  openVisitWizard,
  PIPELINE_STAGES,
  stageLabel,
  stageTagClass,
  isManager,
} from '../composables/useCrmSession.js';

const mode = ref('master');
const searchRaw = ref('');
const search = ref('');
const masterFilter = ref('all');
const erpStatus = ref('all');
const leadStage = ref('');
const page = ref(1);
const perPage = ref(25);

const csvModalOpen = ref(false);
const csvFile = ref(null);
const csvBusy = ref(false);
const csvResult = ref(null);
const workflowPanel = ref('');
const workflowBusy = ref(false);
const erpRequests = ref([]);
const matchCandidates = ref([]);

let debounceTimer = null;
watch(searchRaw, (val) => {
  if (debounceTimer) clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => {
    search.value = val.trim();
    page.value = 1;
  }, 300);
});
onUnmounted(() => {
  if (debounceTimer) clearTimeout(debounceTimer);
});

const { data, isLoading, isError, error } = useQuery({
  queryKey: computed(() => [
    'crm',
    mode.value === 'master' ? 'master' : mode.value === 'erp' ? 'customers' : 'leads',
    {
      page: page.value,
      perPage: perPage.value,
      q: search.value,
      masterFilter: masterFilter.value,
      erpStatus: erpStatus.value,
      leadStage: leadStage.value,
    },
  ]),
  queryFn: ({ signal }) => {
    const p = new URLSearchParams({
      paginate: '1',
      page: String(page.value),
      per_page: String(perPage.value),
    });
    if (search.value) p.set('q', search.value);
    if (mode.value === 'master') {
      p.set('filter', masterFilter.value);
      return crmGet(`/customer-master?${p.toString()}`, { signal });
    }
    if (mode.value === 'erp') {
      p.set('status', erpStatus.value);
      return crmGet(`/customers?${p.toString()}`, { signal });
    }
    if (leadStage.value) p.set('stage', leadStage.value);
    return crmGet(`/leads?${p.toString()}`, { signal });
  },
  placeholderData: keepPreviousData,
});

const rows = computed(() => data.value?.data || []);
const meta = computed(() => data.value?.meta || { current_page: 1, last_page: 1, total: 0 });

function openMobileRow(r) {
  if (mode.value === 'master') openDetailDrawer('master', r.customer_master_id);
  else if (mode.value === 'erp') openDetailDrawer('erp', r.id);
  else openDetailDrawer('lead', r.id);
}

async function requestErpFromMaster(row) {
  if (!row?.crm_lead_id) return;
  const ok = await askConfirm({
    title: 'ส่งคำขอเปิดบัญชี ERP',
    message: `ส่งร้าน "${row.display_name}" เข้าคิวขอเปิดรหัสลูกค้า Bplus ERP?`,
    confirmLabel: 'ส่งคำขอ',
  });
  if (!ok) return;
  try {
    await crmMutate(`/customer-master/leads/${row.crm_lead_id}/request-erp-creation`, 'POST', {
      note: 'ส่งคำขอเปิดบัญชี ERP จากหน้า Customer Registry',
    });
    invalidateCrmData(['leads', 'master', 'dashboard']);
    toast.success('ส่งคำขอเปิดบัญชี ERP เรียบร้อย');
  } catch (err) {
    toast.error('ส่งคำขอไม่สำเร็จ', err);
  }
}

async function openWorkflow(panel) {
  workflowPanel.value = panel;
  workflowBusy.value = true;
  try {
    if (panel === 'requests') erpRequests.value = await crmGet('/customer-master/erp-creation-requests');
    else matchCandidates.value = await crmGet('/customer-master/match-candidates');
  } catch (err) {
    toast.error('โหลดคิวไม่สำเร็จ', err);
  } finally {
    workflowBusy.value = false;
  }
}

async function refreshMatches() {
  workflowBusy.value = true;
  try {
    await crmMutate('/customer-master/match-candidates/refresh', 'POST', {});
    matchCandidates.value = await crmGet('/customer-master/match-candidates');
    toast.success('อัปเดต Candidate แล้ว โปรดตรวจหลักฐานก่อนยืนยัน');
  } catch (err) {
    toast.error('อัปเดต Candidate ไม่สำเร็จ', err);
  } finally {
    workflowBusy.value = false;
  }
}

async function confirmMatch(item) {
  workflowBusy.value = true;
  try {
    await crmMutate('/customer-master/match-candidates/' + item.id + '/confirm', 'POST', {});
    toast.success('ผูก ' + item.source_system + ' / ' + item.erp_customer_code + ' กับ Lead แล้ว');
    matchCandidates.value = await crmGet('/customer-master/match-candidates');
    invalidateCrmData(['leads', 'master', 'customers']);
  } catch (err) {
    toast.error('ยืนยันการผูกไม่สำเร็จ', err);
  } finally {
    workflowBusy.value = false;
  }
}

async function dismissMatch(item) {
  workflowBusy.value = true;
  try {
    await crmMutate('/customer-master/match-candidates/' + item.id + '/dismiss', 'POST', { note: 'ตรวจโดยผู้ดูแลแล้วไม่ใช่บัญชีเดียวกัน' });
    matchCandidates.value = await crmGet('/customer-master/match-candidates');
  } catch (err) {
    toast.error('ปิด Candidate ไม่สำเร็จ', err);
  } finally {
    workflowBusy.value = false;
  }
}

function openLeadRequest(leadId) {
  workflowPanel.value = '';
  openDetailDrawer('lead', leadId);
}
async function runCsvImport() {
  if (!csvFile.value) return;
  csvBusy.value = true;
  try {
    const fd = new FormData();
    fd.set('file', csvFile.value);
    csvResult.value = await crmMutate('/leads/import', 'POST', fd);
    csvFile.value = null;
    invalidateCrmData(['leads', 'master', 'dashboard']);
    toast.success(`นำเข้าสำเร็จ ${csvResult.value.imported} ร้าน`);
  } catch (err) {
    toast.error('นำเข้า CSV ไม่สำเร็จ', err);
  } finally {
    csvBusy.value = false;
  }
}
</script>
