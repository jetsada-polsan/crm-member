<template>
  <div>
    <PageHeader title="คำขอเปิดรหัส ERP" subtitle="ร้านใหม่ที่ตรวจแล้วและรอเปิดรหัสใน Bplus (ต้องเปิดรหัสใน Bplus เอง)">
      <template #secondary>
        <RouterLink to="/matching" class="btn light">🔗 จับคู่ลูกค้ากับ ERP</RouterLink>
      </template>
      <template #primary>
        <button type="button" class="btn primary" @click="refetchAll">⟳ รีเฟรช</button>
      </template>
    </PageHeader>

    <div class="card panel-pad" style="margin-bottom: 16px">
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 10px">
        <div class="minirow" style="border: 1px solid var(--border); border-radius: 12px; padding: 10px 12px">
          <div>
            <b>1. CRM Lead</b>
            <div class="small muted">ทีมขายเก็บข้อมูลภาคสนาม</div>
          </div>
          <span class="tag blue">STEP 1</span>
        </div>
        <div class="minirow" style="border: 1px solid var(--border); border-radius: 12px; padding: 10px 12px">
          <div>
            <b>2. ขอเปิดบัญชี ERP</b>
            <div class="small muted">ส่งคำขอเข้าคิวนี้</div>
          </div>
          <span class="tag orange">STEP 2</span>
        </div>
        <div class="minirow" style="border: 1px solid var(--border); border-radius: 12px; padding: 10px 12px">
          <div>
            <b>3. เปิดรหัสใน Bplus &amp; Sync</b>
            <div class="small muted">ฝ่ายบัญชีเปิด AR แล้วซิงค์</div>
          </div>
          <span class="tag purple">STEP 3</span>
        </div>
        <div class="minirow" style="border: 1px solid var(--border); border-radius: 12px; padding: 10px 12px">
          <div>
            <b>4. เชื่อมรหัสเข้าด้วยกัน</b>
            <div class="small muted">ประวัติ CRM คงอยู่ครบ</div>
          </div>
          <span class="tag green">STEP 4</span>
        </div>
      </div>
    </div>

    <SkeletonBlock v-if="isLoading && !requests.length" variant="table" :rows="5" />
    <div v-else-if="isError" class="error">
      {{ error?.message || 'โหลดคิวคำขอเปิด ERP ไม่สำเร็จ (สงวนสิทธิ์สำหรับผู้จัดการ/แอดมิน)' }}
    </div>

    <div v-else class="card" style="margin-bottom: 16px">
      <div class="section-head" style="padding: 16px 18px 0">
        <div>
          <h3>คิวคำขอเปิดรหัสลูกค้า ERP ({{ requests.length }})</h3>
          <small>เรียงตามเวลาที่ส่งคำขอก่อน-หลัง</small>
        </div>
      </div>
      <div class="table-wrap desktop-table-only">
        <table class="table">
          <thead>
            <tr>
              <th>รหัส Master</th>
              <th>ชื่อร้านค้า</th>
              <th>เบอร์โทร / ประเภท</th>
              <th>พื้นที่ / ที่อยู่</th>
              <th>ผู้ขอ</th>
              <th>วันที่ส่งคำขอ</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in requests" :key="r.crm_lead_id">
              <td>
                <span class="tag blue">{{ r.internal_customer_code || `LEAD-${r.crm_lead_id}` }}</span>
              </td>
              <td>
                <b>{{ r.shop_name }}</b>
              </td>
              <td>{{ r.phone || '—' }} · {{ r.shop_type || 'ร้านค้า' }}</td>
              <td>
                <div>{{ r.province || '—' }} {{ r.district || '' }}</div>
                <div class="small muted">{{ r.address || 'ยังไม่ระบุที่อยู่' }}</div>
              </td>
              <td>{{ r.requested_by || '—' }}</td>
              <td>{{ r.created_at ? new Date(r.created_at).toLocaleString('th-TH') : '—' }}</td>
              <td>
                <div style="display: flex; gap: 6px; flex-wrap: wrap">
                  <RouterLink
                    v-if="r.customer_master_id"
                    :to="`/customer-360/${r.customer_master_id}`"
                    class="btn light"
                  >
                    360°
                  </RouterLink>
                  <button type="button" class="btn primary" @click="openLinkModal(r)">🔗 เชื่อมบัญชี ERP</button>
                </div>
              </td>
            </tr>
            <tr v-if="!requests.length">
              <td colspan="7" class="placeholder">ยังไม่มีคำขอเปิดบัญชี ERP ที่ค้างดำเนินการ</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="mob-cards" style="padding: 12px">
        <div v-for="r in requests" :key="`req-m-${r.crm_lead_id}`" class="card mob-card">
          <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px">
            <strong>{{ r.shop_name }}</strong>
            <span class="tag orange">{{ r.internal_customer_code || `LEAD-${r.crm_lead_id}` }}</span>
          </div>
          <div class="small muted" style="margin: 6px 0">
            โทร: {{ r.phone || '—' }} · {{ r.province || '—' }} · ขอโดย {{ r.requested_by || '—' }}
          </div>
          <div style="display: flex; gap: 8px; flex-wrap: wrap">
            <RouterLink v-if="r.customer_master_id" :to="`/customer-360/${r.customer_master_id}`" class="btn light"
              >360°</RouterLink
            >
            <button type="button" class="btn primary" @click="openLinkModal(r)">🔗 เชื่อมบัญชี ERP</button>
          </div>
        </div>
        <div v-if="!requests.length" class="placeholder">ยังไม่มีคำขอเปิดบัญชี ERP</div>
      </div>
    </div>

    <!-- Link Existing ERP Account Modal -->
    <BaseModal :open="Boolean(linkingReq)" title="เชื่อมคำขอเปิด ERP กับรหัสลูกค้า Bplus" @close="linkingReq = null">
      <div v-if="linkingReq" style="display: grid; gap: 10px">
        <div class="notice" style="margin-bottom: 0">
          ร้านจาก CRM: <b>{{ linkingReq.shop_name }}</b> ({{ linkingReq.internal_customer_code }})
        </div>
        <div class="field">
          <label>ค้นหาลูกค้า ERP ที่เปิดแล้วใน Bplus Mirror</label>
          <input v-model="erpSearch" placeholder="พิมพ์รหัส AR หรือชื่อร้านใน Bplus..." />
        </div>
        <div class="field">
          <label>เลือกลูกค้า ERP *</label>
          <select v-model="selectedErpId">
            <option value="">-- เลือกลูกค้า ERP --</option>
            <option v-for="c in erpOptions" :key="c.id" :value="c.id">
              {{ c.erp_customer_code }} · {{ c.customer_name }} ({{ c.phone || c.province || '—' }})
            </option>
          </select>
        </div>
        <div class="field">
          <label>หมายเหตุยืนยัน *</label>
          <input v-model="linkNote" placeholder="เช่น เปิดบัญชี AR เรียบร้อยและเชื่อมตัวตนแล้ว" />
        </div>
      </div>
      <template #footer="{ requestClose }">
        <button type="button" class="btn" @click="requestClose">ยกเลิก</button>
        <button type="button" class="btn primary" :disabled="!selectedErpId || linkBusy" @click="submitLink">
          {{ linkBusy ? 'กำลังเชื่อม...' : 'ยืนยันเชื่อมบัญชี ERP' }}
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
import PageHeader from '../../../shared/components/PageHeader.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { toast } from '../../../shared/composables/useUiStore.js';
import { crmGet, crmMutate, invalidateCrmData } from '../composables/useCrmSession.js';

const {
  data,
  isLoading,
  isError,
  error,
  refetch: refetchAll,
} = useQuery({
  queryKey: ['crm', 'erp-creation-requests'],
  queryFn: ({ signal }) => crmGet('/customer-master/erp-creation-requests', { signal }),
});

const requests = computed(() => data.value || []);

const linkingReq = ref(null);
const erpSearch = ref('');
const selectedErpId = ref('');
const linkNote = ref('');
const linkBusy = ref(false);

const { data: erpData } = useQuery({
  queryKey: computed(() => ['crm', 'erp-req-link-options', erpSearch.value]),
  queryFn: ({ signal }) => {
    const p = new URLSearchParams({ paginate: '1', per_page: '20' });
    if (erpSearch.value.trim()) p.set('q', erpSearch.value.trim());
    return crmGet(`/customers?${p.toString()}`, { signal });
  },
  enabled: computed(() => Boolean(linkingReq.value)),
});
const erpOptions = computed(() => erpData.value?.data || []);

function openLinkModal(req) {
  linkingReq.value = req;
  erpSearch.value = req.shop_name || '';
  selectedErpId.value = '';
  linkNote.value = `เชื่อมจากคิวเปิดบัญชี ERP (${req.internal_customer_code || req.crm_lead_id})`;
}

async function submitLink() {
  if (!linkingReq.value || !selectedErpId.value) return;
  linkBusy.value = true;
  try {
    await crmMutate(`/customer-master/leads/${linkingReq.value.crm_lead_id}/link-erp`, 'POST', {
      erp_customer_id: Number(selectedErpId.value),
      note: linkNote.value.trim() || 'เชื่อมบัญชี ERP แล้ว',
    });
    linkingReq.value = null;
    toast.success('เชื่อม Customer Master กับ ERP เรียบร้อย');
    invalidateCrmData(['leads', 'master', 'dashboard']);
    await refetchAll();
  } catch (err) {
    toast.error('เชื่อมบัญชี ERP ไม่สำเร็จ', err);
  } finally {
    linkBusy.value = false;
  }
}
</script>
