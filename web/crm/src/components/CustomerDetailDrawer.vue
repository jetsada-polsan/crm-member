<template>
  <SlideOverDrawer
    :open="detailModal.open"
    :title="drawerTitle"
    :subtitle="drawerSubtitle"
    width="min(560px, 100vw)"
    :dirty="leadDirty"
    @close="closeDetailDrawer"
  >
    <div v-if="isLoading">
      <SkeletonBlock variant="cards" :rows="3" />
    </div>
    <div v-else-if="isError" class="error">
      {{ error?.message || 'ไม่สามารถโหลดข้อมูลรายละเอียดได้' }}
    </div>

    <!-- CUSTOMER MASTER DETAIL (Separated Identity Architecture) -->
    <template v-else-if="detailModal.kind === 'master' && data">
      <div class="card panel-pad" style="margin-bottom: 14px">
        <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap">
          <span class="tag blue">{{ data.internal_customer.internal_customer_code }}</span>
          <span class="tag" :class="data.internal_customer.link_status === 'linked' ? 'green' : 'orange'">
            {{ data.internal_customer.link_status === 'linked' ? 'เชื่อม ERP แล้ว' : 'ข้อมูล CRM (ยังไม่เปิดหน้าบัญชี ERP)' }}
          </span>
        </div>
        <h4 style="margin: 10px 0 4px">{{ data.internal_customer.display_name }}</h4>
        <div class="small muted">
          โทร: {{ data.internal_customer.phone || '—' }} · {{ data.internal_customer.shop_type || 'ร้านค้า' }} ·
          {{ data.internal_customer.province || data.internal_customer.district || 'ยังไม่ระบุพื้นที่' }}
        </div>
        <div style="display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap">
          <button
            v-if="data.erp_customer"
            type="button"
            class="btn primary"
            @click="launchVisit('erp', data.erp_customer.id)"
          >
            📸 บันทึกเข้าพบลูกค้า ERP
          </button>
          <button
            v-else-if="data.crm_lead"
            type="button"
            class="btn primary"
            @click="launchVisit('lead', data.crm_lead.id)"
          >
            📸 บันทึกเข้าพบร้านค้านี้
          </button>
          <RouterLink :to="`/customer-360/${detailModal.id}`" class="btn light" @click="closeDetailDrawer()">
            🔍 เปิดหน้า Customer 360°
          </RouterLink>
        </div>
      </div>

      <div v-if="isManager && data.crm_lead && !data.erp_customer" class="card panel-pad" style="margin-bottom: 14px">
        <strong style="display: block; margin-bottom: 8px">ผูกกับลูกค้า ERP ที่ซิงก์เข้ามาแล้ว</strong>
        <p class="small muted">
          ใส่ Source และรหัสลูกหนี้จาก Bplus ที่แอดมินสร้างเอง ระบบจะตรวจคู่ Source + ERP Code ก่อนบันทึกการผูก
        </p>
        <div class="form">
          <div class="field"><label>ERP Source</label><input v-model="erpLinkForm.source_system" maxlength="32" /></div>
          <div class="field">
            <label>ERP Customer Code</label><input v-model="erpLinkForm.erp_customer_code" maxlength="64" />
          </div>
          <div class="field span2">
            <label>หมายเหตุการยืนยัน</label><input v-model="erpLinkForm.note" maxlength="1000" />
          </div>
          <div class="field span2">
            <button
              type="button"
              class="btn primary"
              :disabled="linkBusy || !erpLinkForm.erp_customer_code"
              @click="linkErp"
            >
              {{ linkBusy ? 'กำลังตรวจและผูก...' : 'ยืนยันผูก ERP' }}
            </button>
          </div>
        </div>
      </div>

      <div v-if="isManager && data.internal_customer" class="card panel-pad" style="margin-bottom: 14px">
        <div style="display: flex; gap: 8px; flex-wrap: wrap">
          <button type="button" class="btn light" @click="loadAuditHistory">ดูประวัติการผูก</button>
          <button v-if="data.erp_customer" type="button" class="btn danger" :disabled="linkBusy" @click="unlinkErp">
            ยกเลิกการผูก ERP
          </button>
        </div>
        <div v-if="auditHistory.length" style="margin-top: 12px">
          <div v-for="entry in auditHistory" :key="entry.id" class="minirow">
            <span
              ><b>{{ entry.action }}</b>
              <div class="small muted">{{ entry.actor_name || 'System' }} · {{ entry.created_at }}</div></span
            >
            <span class="small">{{ entry.changes?.erp_customer_code || entry.changes?.note || '' }}</span>
          </div>
        </div>
      </div>

      <!-- 1. ERP Customer Mirror (Read-Only) -->
      <div class="card panel-pad" style="margin-bottom: 14px">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px">
          <strong>1. ทะเบียนลูกค้า ERP (Read-Only Mirror)</strong>
          <span class="tag gray">BPLUS SYNC</span>
        </div>
        <template v-if="data.erp_customer">
          <div class="minirow">
            <span>รหัสลูกค้า ERP</span><b>{{ data.erp_customer.erp_customer_code }}</b>
          </div>
          <div class="minirow">
            <span>ชื่อในระบบ Bplus</span><span>{{ data.erp_customer.customer_name }}</span>
          </div>
          <div class="minirow">
            <span>พนักงานขาย ERP</span
            ><span>{{ data.erp_customer.salesperson_name || data.erp_customer.salesperson_code || '—' }}</span>
          </div>
          <div class="minirow">
            <span>ยอดซื้อรวมก่อน VAT</span><b>{{ fmt(data.sales_summary?.gross_purchase_before_vat) }} ฿</b>
          </div>
          <div class="minirow">
            <span>ยอดคืนก่อน VAT</span
            ><b style="color: var(--red)">−{{ fmt(data.sales_summary?.return_amount_before_vat) }} ฿</b>
          </div>
          <div class="minirow">
            <span>ยอดซื้อสุทธิหลังหักคืน</span
            ><b style="color: var(--green)">{{ fmt(data.sales_summary?.net_purchase_before_vat) }} ฿</b>
          </div>
          <div class="minirow">
            <span>บิลขาย / ใบคืน</span
            ><span
              >{{ fmt(data.sales_summary?.sale_invoice_count) }} / {{ fmt(data.sales_summary?.return_count) }}</span
            >
          </div>
          <div class="minirow">
            <span>วันที่ซื้อล่าสุด</span
            ><span>{{
              data.sales_summary?.last_purchase_at
                ? new Date(data.sales_summary.last_purchase_at).toLocaleDateString('th-TH')
                : '—'
            }}</span>
          </div>
          <div class="minirow">
            <span>KPI สินค้าก่อน VAT (หักหมวดบริการ)</span>
            <b style="color: var(--green)">{{ fmt(data.sales_summary?.primary_sales_before_vat || 0) }} ฿</b>
          </div>
        </template>
        <div v-else class="small muted">ยังไม่เชื่อมกับลูกค้า ERP (การสร้างร้านใหม่ใน CRM จะไม่สร้างข้อมูลเข้า Bplus อัตโนมัติ)</div>
      </div>

      <CustomerPurchaseHistory
        v-if="data.erp_customer"
        :customer-id="data.erp_customer.id"
        style="margin-bottom: 14px"
      />

      <div v-if="data.follow_up_tasks?.length" class="card panel-pad" style="margin-bottom: 14px">
        <strong>งานติดตามลูกค้า</strong>
        <div v-for="task in data.follow_up_tasks" :key="task.id" class="minirow">
          <span
            >{{ task.title }}
            <div class="small muted">กำหนด {{ task.due_date || 'ยังไม่กำหนด' }}</div></span
          >
          <span class="tag" :class="task.status === 'completed' ? 'green' : 'orange'">{{ task.status }}</span>
        </div>
      </div>

      <!-- 2. CRM Lead & Acquisition -->
      <div v-if="data.crm_lead" class="card panel-pad" style="margin-bottom: 14px">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px">
          <strong>2. ข้อมูล CRM / แผนที่ / การติดตาม</strong>
          <span class="tag" :class="stageTagClass(data.crm_lead.pipeline_stage)">{{
            stageLabel(data.crm_lead.pipeline_stage)
          }}</span>
        </div>
        <div class="minirow">
          <span>ผู้ติดต่อ</span><span>{{ data.crm_lead.contact_name || '—' }}</span>
        </div>
        <div class="minirow">
          <span>LINE</span><span>{{ data.crm_lead.line_contact || '—' }}</span>
        </div>
        <div class="minirow">
          <span>แหล่งที่ซื้อ</span><span>{{ data.crm_lead.supplier_source || '—' }}</span>
        </div>
        <div class="minirow">
          <span>หมวดสินค้า</span><span>{{ data.crm_lead.product_categories || '—' }}</span>
        </div>
        <div class="minirow">
          <span>พิกัด GPS (รักษาแยกจาก ERP Sync)</span
          ><span>{{
            data.crm_location ? `${data.crm_location.latitude}, ${data.crm_location.longitude}` : 'ยังไม่ปักหมุด'
          }}</span>
        </div>
        <div v-for="loc in data.crm_locations || []" :key="loc.id" class="small muted">
          พิกัดเดิม #{{ loc.id }}: {{ loc.latitude }}, {{ loc.longitude }} · {{ loc.source }}
        </div>
        <button
          type="button"
          class="btn light"
          style="margin-top: 8px"
          @click="openDetailDrawer('lead', data.crm_lead.id)"
        >
          แก้ไขข้อมูล Lead / อนุมัติ / เชื่อม ERP →
        </button>
      </div>

      <!-- 3. Member & Loyalty Account -->
      <div class="card panel-pad" style="margin-bottom: 14px">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px">
          <strong>3. บัญชี Member &amp; คะแนนสะสม</strong>
          <span class="tag" :class="data.member_link?.verification_status === 'verified' ? 'green' : 'gray'">
            {{ data.member_link?.verification_status || 'ยังไม่เชื่อมบัญชี' }}
          </span>
        </div>
        <template v-if="data.member_link">
          <div class="minirow">
            <span>ชื่อสมาชิก</span><b>{{ data.member_link.member_name }}</b>
          </div>
          <div class="minirow">
            <span>เบอร์/อีเมลสมาชิก</span><span>{{ data.member_link.member_phone || data.member_link.member_email }}</span>
          </div>
          <div class="minirow">
            <span>คะแนนสะสมในระบบ</span><b>{{ fmt(data.member_link.loyalty_points_balance) }} แต้ม</b>
          </div>
        </template>
        <div v-else class="small muted">
          ยังไม่มีบัญชี Member ที่ผ่านการตรวจสอบและยืนยัน (เบอร์โทรตรงกันอย่างเดียวจะไม่ถือว่ายืนยันสิทธิ์อัตโนมัติ)
        </div>
      </div>

      <!-- 4. Visits Evidence Timeline -->
      <div class="card panel-pad">
        <strong style="display: block; margin-bottom: 8px"
          >4. ประวัติการเข้าพบ &amp; หลักฐานภาคสนาม ({{ data.visits?.length || 0 }})</strong
        >
        <div v-for="v in data.visits || []" :key="v.id" class="minirow" style="align-items: flex-start">
          <div>
            <b>{{ new Date(v.visited_at).toLocaleString('th-TH') }}</b>
            <div class="small muted">
              โดย {{ v.staff_name || 'ทีมขาย' }} · GPS: {{ v.gps_permission_status || '—' }} · รูป {{ v.photo_count }} ภาพ
            </div>
            <div v-if="v.follow_up_action" class="small">ติดตาม: {{ v.follow_up_action }}</div>
          </div>
          <div style="display: flex; gap: 6px; align-items: center">
            <span class="tag blue">{{ v.result }}</span>
            <button
              v-if="v.photo_count > 0"
              type="button"
              class="btn light"
              style="padding: 4px 8px"
              @click="viewVisitPhotos(v.id)"
            >
              🖼 ดูรูป
            </button>
          </div>
        </div>
        <div v-if="!(data.visits || []).length" class="small muted">ยังไม่มีประวัติเข้าพบ</div>
      </div>
    </template>

    <!-- ERP CUSTOMER DETAIL -->
    <template v-else-if="detailModal.kind === 'erp' && data">
      <div class="card panel-pad" style="margin-bottom: 14px">
        <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap">
          <span class="tag gray">{{ data.customer.erp_customer_code }}</span>
          <button type="button" class="btn primary" @click="launchVisit('erp', data.customer.id)">
            📸 บันทึกเข้าพบลูกค้านี้
          </button>
        </div>
        <h4 style="margin: 10px 0 6px">{{ data.customer.customer_name }}</h4>
        <div class="minirow">
          <span>ยอดซื้อรวมก่อน VAT</span><b>{{ fmt(data.sales_summary?.gross_purchase_before_vat) }} ฿</b>
        </div>
        <div class="minirow">
          <span>ยอดคืนก่อน VAT</span
          ><b style="color: var(--red)">−{{ fmt(data.sales_summary?.return_amount_before_vat) }} ฿</b>
        </div>
        <div class="minirow">
          <span>ยอดซื้อสุทธิหลังหักคืน</span
          ><b style="color: var(--green)">{{ fmt(data.sales_summary?.net_purchase_before_vat) }} ฿</b>
        </div>
        <div class="minirow">
          <span>บิลขาย / ใบคืน</span
          ><span>{{ fmt(data.sales_summary?.sale_invoice_count) }} / {{ fmt(data.sales_summary?.return_count) }}</span>
        </div>
        <div class="minirow">
          <span>วันที่ซื้อล่าสุด</span
          ><span>{{
            data.sales_summary?.last_purchase_at
              ? new Date(data.sales_summary.last_purchase_at).toLocaleDateString('th-TH')
              : '—'
          }}</span>
        </div>
        <div class="minirow">
          <span>เบอร์โทรศัพท์</span><span>{{ data.customer.phone || '—' }}</span>
        </div>
        <div class="minirow">
          <span>พื้นที่</span><span>{{ data.customer.province || '—' }}</span>
        </div>
        <div class="minirow">
          <span>พนักงานขาย</span
          ><span>{{ data.customer.salesperson_name || data.customer.salesperson_code || '—' }}</span>
        </div>
        <div class="minirow">
          <span>วงเงินเครดิต</span
          ><span>{{ fmt(data.customer.credit_limit) }} ฿ ({{ data.customer.credit_term_days || 0 }} วัน)</span>
        </div>
        <div class="minirow">
          <span>ยอดซื้อสุทธิ ERP ก่อน VAT</span>
          <b style="color: var(--green)"
            >{{ fmt(data.sales_summary?.net_purchase_before_vat ?? data.total_posted_net) }} ฿</b
          >
        </div>
      </div>
      <CustomerPurchaseHistory :customer-id="data.customer.id" />
    </template>

    <!-- CRM LEAD DETAIL + EDIT + PHOTOS + REVIEW -->
    <template v-else-if="detailModal.kind === 'lead' && data">
      <div class="card panel-pad" style="margin-bottom: 14px">
        <div
          style="
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            gap: 8px;
            flex-wrap: wrap;
          "
        >
          <span class="tag" :class="stageTagClass(data.lead.pipeline_stage)">{{
            stageLabel(data.lead.pipeline_stage)
          }}</span>
          <div style="display: flex; gap: 8px; flex-wrap: wrap">
            <button
              v-if="!data.lead.converted_erp_customer_id"
              type="button"
              class="btn light"
              @click="requestErpCreation"
            >
              ขอเปิดรหัสลูกหนี้
            </button>
            <button type="button" class="btn primary" @click="launchVisit('lead', data.lead.id)">บันทึกเข้าพบ</button>
          </div>
        </div>
        <form class="form" @submit.prevent="saveLead">
          <div class="field span2"><label>ชื่อร้านค้า *</label><input v-model="leadForm.shop_name" required /></div>
          <div class="field"><label>เบอร์โทร</label><input v-model="leadForm.phone" /></div>
          <div class="field"><label>ผู้ติดต่อ</label><input v-model="leadForm.contact_name" /></div>
          <div class="field">
            <label>ประเภทร้าน</label>
            <select v-model="leadForm.shop_type">
              <option v-for="st in SHOP_TYPES" :key="st" :value="st">{{ st }}</option>
            </select>
          </div>
          <div class="field"><label>จังหวัด</label><input v-model="leadForm.province" /></div>
          <div class="field"><label>อำเภอ / เขต</label><input v-model="leadForm.district" /></div>
          <div class="field"><label>LINE ID</label><input v-model="leadForm.line_contact" /></div>
          <div class="field">
            <label>Pipeline Stage</label>
            <select v-model="leadForm.pipeline_stage">
              <option v-for="p in PIPELINE_STAGES" :key="p.id" :value="p.id">{{ p.label }}</option>
            </select>
          </div>
          <div class="field">
            <label>ติดตามถัดไป</label
            ><NiceDateInput v-model="leadForm.next_follow_up_date" placeholder="เลือกวันติดตามถัดไป..." />
          </div>
          <div class="field span2"><label>แหล่งที่ซื้อสินค้า</label><input v-model="leadForm.supplier_source" /></div>
          <div class="field span2"><label>หมวดสินค้าที่ใช้</label><input v-model="leadForm.product_categories" /></div>
          <div class="field span2"><label>ที่อยู่</label><textarea v-model="leadForm.address" rows="2" /></div>
          <div class="field span2"><label>หมายเหตุ</label><textarea v-model="leadForm.notes" rows="2" /></div>
          <div class="field span2">
            <button type="submit" class="btn primary" style="width: 100%" :disabled="savingLead">
              {{ savingLead ? 'กำลังบันทึก...' : 'บันทึกการแก้ไขข้อมูล Lead' }}
            </button>
          </div>
        </form>
      </div>

      <!-- Lead Photos & Compression Upload -->
      <div class="card panel-pad" style="margin-bottom: 14px">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px">
          <strong>ภาพถ่ายร้านค้า ({{ data.photos?.length || 0 }})</strong>
          <label class="btn light" style="cursor: pointer; margin: 0">
            📷 เพิ่มรูปหน้าร้าน
            <input
              type="file"
              accept="image/jpeg,image/png,image/webp"
              capture="environment"
              hidden
              @change="uploadLeadPhoto"
            />
          </label>
        </div>
        <div v-if="photoUploadPct > 0" style="margin-bottom: 10px">
          <UploadProgressBar :percent="photoUploadPct" label="กำลังอัปโหลดภาพหน้าร้าน..." />
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 8px">
          <button
            v-for="(p, idx) in data.photos || []"
            :key="p.id"
            type="button"
            style="
              border: 1px solid var(--border);
              border-radius: 10px;
              padding: 0;
              overflow: hidden;
              background: #f8fafc;
            "
            @click="openLeadGallery(idx)"
          >
            <img
              :src="`/api/crm/lead-photos/${p.id}?inline=1`"
              alt="ภาพร้าน"
              style="width: 100%; height: 88px; object-fit: cover; display: block"
            />
          </button>
        </div>
        <div v-if="!(data.photos || []).length" class="small muted">ยังไม่มีภาพหน้าร้าน</div>
      </div>

      <!-- Manager Review & ERP Linking -->
      <div v-if="isManager" class="card panel-pad">
        <strong style="display: block; margin-bottom: 8px">อนุมัติ Lead &amp; เชื่อมรหัสลูกค้า ERP (เฉพาะผู้จัดการ)</strong>
        <form class="form" @submit.prevent="submitReview">
          <div class="field">
            <label>ผลการอนุมัติ</label>
            <select v-model="reviewForm.approval_status">
              <option value="pending">รออนุมัติ</option>
              <option value="approved">อนุมัติแล้ว</option>
              <option value="rejected">ไม่อนุมัติ</option>
            </select>
          </div>
          <div class="field span2"><label>หมายเหตุการพิจารณา</label><input v-model="reviewForm.approval_note" /></div>
          <div class="field span2">
            <button type="submit" class="btn primary" :disabled="savingReview">บันทึกผลการพิจารณา</button>
          </div>
        </form>
      </div>
    </template>
  </SlideOverDrawer>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { useQuery } from '@tanstack/vue-query';
import SlideOverDrawer from '../../../shared/components/SlideOverDrawer.vue';
import NiceDateInput from '../../../shared/components/NiceDateInput.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import UploadProgressBar from '../../../shared/components/UploadProgressBar.vue';
import CustomerPurchaseHistory from './CustomerPurchaseHistory.vue';
import { apiUploadWithProgress } from '../../../shared/api/http.js';
import { compressImageFile } from '../../../shared/utils/imageCompress.js';
import { askConfirm, toast } from '../../../shared/composables/useUiStore.js';
import {
  closeDetailDrawer,
  crmGet,
  crmMutate,
  detailModal,
  fmt,
  invalidateCrmData,
  isManager,
  openDetailDrawer,
  openLightbox,
  openVisitWizard,
  PIPELINE_STAGES,
  SHOP_TYPES,
  stageLabel,
  stageTagClass,
} from '../composables/useCrmSession.js';

const { data, isLoading, isError, error, refetch } = useQuery({
  queryKey: computed(() => ['crm', 'detail', detailModal.value.kind, detailModal.value.id]),
  queryFn: ({ signal }) => {
    const { kind, id } = detailModal.value;
    if (kind === 'master') return crmGet(`/customer-master/${id}`, { signal });
    if (kind === 'erp') return crmGet(`/customers/${id}`, { signal });
    return crmGet(`/leads/${id}`, { signal });
  },
  enabled: computed(() => Boolean(detailModal.value.open && detailModal.value.id)),
});

const leadForm = ref({});
const leadSnapshot = ref('');
const reviewForm = ref({ approval_status: 'pending', approval_note: '' });
const erpLinkForm = ref({ source_system: 'BPLUS', erp_customer_code: '', note: '' });
const linkBusy = ref(false);
const auditHistory = ref([]);
const savingLead = ref(false);
const savingReview = ref(false);
const photoUploadPct = ref(0);

watch(data, (d) => {
  if (detailModal.value.kind === 'lead' && d?.lead) {
    const obj = {
      shop_name: d.lead.shop_name || '',
      phone: d.lead.phone || '',
      contact_name: d.lead.contact_name || '',
      shop_type: d.lead.shop_type || 'หมูกระทะ',
      province: d.lead.province || '',
      district: d.lead.district || '',
      line_contact: d.lead.line_contact || '',
      pipeline_stage: d.lead.pipeline_stage || 'new_lead',
      next_follow_up_date: d.lead.next_follow_up_date || '',
      supplier_source: d.lead.supplier_source || '',
      product_categories: d.lead.product_categories || '',
      address: d.lead.address || '',
      notes: d.lead.notes || '',
    };
    leadForm.value = { ...obj };
    leadSnapshot.value = JSON.stringify(obj);
    reviewForm.value = {
      approval_status: d.lead.approval_status || 'pending',
      approval_note: d.lead.approval_note || '',
    };
  } else {
    leadSnapshot.value = '';
  }
});

const leadDirty = computed(
  () =>
    detailModal.value.kind === 'lead' &&
    Boolean(leadSnapshot.value) &&
    JSON.stringify(leadForm.value) !== leadSnapshot.value
);

const drawerTitle = computed(() => {
  if (detailModal.value.kind === 'master') return data.value?.internal_customer?.display_name || 'ข้อมูล Customer Master';
  if (detailModal.value.kind === 'erp') return data.value?.customer?.customer_name || 'รายละเอียดลูกค้า ERP';
  return data.value?.lead?.shop_name || 'รายละเอียดลูกค้าใหม่ (Lead)';
});

const drawerSubtitle = computed(() => {
  if (detailModal.value.kind === 'master') return 'แยกตัวตนชัดเจน: Customer Master · ERP · CRM Lead · Member';
  if (detailModal.value.kind === 'erp') return 'ข้อมูล Read-Only จากระบบบัญชี Bplus';
  return 'ข้อมูลลูกค้าที่เก็บจากทีมขายภาคสนาม';
});

function launchVisit(kind, id) {
  closeDetailDrawer();
  openVisitWizard({ kind, id });
}

async function saveLead() {
  savingLead.value = true;
  try {
    await crmMutate(`/leads/${detailModal.value.id}`, 'PATCH', leadForm.value);
    leadSnapshot.value = JSON.stringify(leadForm.value);
    toast.success('บันทึกข้อมูล Lead แล้ว');
    invalidateCrmData(['leads', 'master', 'dashboard']);
    refetch();
  } catch (err) {
    toast.error('บันทึกข้อมูลไม่สำเร็จ', err);
  } finally {
    savingLead.value = false;
  }
}

async function submitReview() {
  savingReview.value = true;
  try {
    await crmMutate(`/leads/${detailModal.value.id}/review`, 'POST', {
      approval_status: reviewForm.value.approval_status,
      approval_note: reviewForm.value.approval_note || null,
    });
    toast.success('บันทึกผลการตรวจสอบ Lead เรียบร้อย');
    invalidateCrmData(['leads', 'master', 'dashboard']);
    refetch();
  } catch (err) {
    toast.error('อนุมัติไม่สำเร็จ', err);
  } finally {
    savingReview.value = false;
  }
}

async function requestErpCreation() {
  try {
    await crmMutate(`/customer-master/leads/${data.value.lead.id}/request-erp-creation`, 'POST', {});
    toast.success('ส่งคำขอสร้างลูกหนี้ให้แอดมินแล้ว');
    invalidateCrmData(['leads', 'master', 'dashboard']);
    await refetch();
  } catch (err) {
    toast.error('ส่งคำขอไม่สำเร็จ', err);
  }
}

async function linkErp() {
  if (!data.value?.crm_lead) return;
  const rawCode = String(erpLinkForm.value.erp_customer_code || '').trim();
  if (!rawCode) return;
  linkBusy.value = true;
  try {
    const res = await crmGet(`/customers?paginate=1&per_page=15&q=${encodeURIComponent(rawCode)}`);
    const rows = res?.data || [];
    const exact =
      rows.find(
        (c) => String(c.erp_customer_code || '').toUpperCase() === rawCode.toUpperCase() || String(c.id) === rawCode
      ) || rows[0];
    if (!exact?.id) {
      toast.error('ไม่พบรหัสลูกค้า ERP นี้ในระบบ Mirror (กรุณา Sync จาก Bplus ก่อน)');
      return;
    }
    await crmMutate(`/customer-master/leads/${data.value.crm_lead.id}/link-erp`, 'POST', {
      erp_customer_id: exact.id,
      note: erpLinkForm.value.note || `ยืนยันผูก ERP ${exact.erp_customer_code}`,
    });
    toast.success(`ผูกลูกค้า CRM กับ ERP ${exact.erp_customer_code} แล้ว`);
    invalidateCrmData(['leads', 'master', 'customers']);
    await refetch();
  } catch (err) {
    toast.error('ผูก ERP ไม่สำเร็จ', err);
  } finally {
    linkBusy.value = false;
  }
}

async function unlinkErp() {
  const ok = await askConfirm({
    title: 'ยกเลิกการผูก ERP',
    message: 'ประวัติ CRM จะยังอยู่ และสมาชิกต้องยืนยันสิทธิ์ใหม่ก่อนเปิดข้อมูล ERP',
    confirmLabel: 'ยกเลิกการผูก',
    tone: 'danger',
  });
  if (!ok) return;
  linkBusy.value = true;
  try {
    await crmMutate(`/customer-master/${data.value.internal_customer.id}/unlink-erp`, 'POST', {
      note: 'ผู้ดูแลยกเลิกการผูกจาก Customer 360',
    });
    toast.success('ยกเลิกการผูกแล้ว');
    invalidateCrmData(['leads', 'master', 'customers']);
    await refetch();
  } catch (err) {
    toast.error('ยกเลิกการผูกไม่สำเร็จ', err);
  } finally {
    linkBusy.value = false;
  }
}

async function loadAuditHistory() {
  try {
    const rows = await crmGet(`/customer-master/${data.value.internal_customer.id}/audit`);
    auditHistory.value = Array.isArray(rows)
      ? rows.map((row) => ({
          ...row,
          changes: typeof row.changes === 'string' ? JSON.parse(row.changes || '{}') : row.changes || {},
        }))
      : [];
  } catch (err) {
    toast.error('โหลดประวัติไม่สำเร็จ', err);
  }
}

async function uploadLeadPhoto(e) {
  const file = e.target.files?.[0];
  e.target.value = '';
  if (!file) return;
  photoUploadPct.value = 5;
  try {
    const compressed = await compressImageFile(file);
    const fd = new FormData();
    fd.set('photo', compressed.file);
    fd.set('category', 'storefront');
    fd.set('captured_at', compressed.capturedAt);
    fd.set('compressed_on_client', compressed.compressedOnClient ? '1' : '0');
    await apiUploadWithProgress(`/api/crm/leads/${detailModal.value.id}/photos`, fd, {
      csrfEndpoint: '/api/crm/csrf',
      onProgress: ({ percent }) => {
        photoUploadPct.value = percent;
      },
    });
    toast.success('อัปโหลดภาพหน้าร้านแล้ว');
    refetch();
    invalidateCrmData(['leads']);
  } catch (err) {
    toast.error('อัปโหลดรูปไม่สำเร็จ', err);
  } finally {
    photoUploadPct.value = 0;
  }
}

function openLeadGallery(idx) {
  const items = (data.value?.photos || []).map((p) => ({
    src: `/api/crm/lead-photos/${p.id}?inline=1`,
    title: data.value?.lead?.shop_name || 'ภาพหน้าร้าน',
    category: p.category,
    capturedAt: p.captured_at ? new Date(p.captured_at).toLocaleString('th-TH') : '',
    consentConfirmed: p.consent_confirmed,
    compressedOnClient: p.compressed_on_client,
    latitude: p.latitude,
    longitude: p.longitude,
  }));
  openLightbox(items, idx);
}

async function viewVisitPhotos(visitId) {
  try {
    const photos = await crmGet(`/field-visits/${visitId}/photos`);
    if (!photos.length) {
      toast.info('ไม่มีไฟล์รูปในรายการนี้');
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
    toast.error('เปิดดูรูปหลักฐานไม่สำเร็จ', err);
  }
}
</script>
