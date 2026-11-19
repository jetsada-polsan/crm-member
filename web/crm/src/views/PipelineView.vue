<template>
  <div>
    <PageHeader
      title="โอกาสการขาย & ใบเสนอราคา"
      subtitle="ติดตามร้านใหม่ทีละขั้น ออกใบเสนอราคาสินค้า Bplus (พิมพ์/บันทึก PDF) และส่งคำขอเปิดรหัสลูกหนี้ ERP"
    >
      <template #primary>
        <button type="button" class="btn primary" @click="openQuickAdd()">＋ เพิ่มร้านใหม่</button>
      </template>
    </PageHeader>

    <SkeletonBlock v-if="isLoading && !leadsList.length" variant="cards" :rows="4" />
    <div v-else-if="isError" class="error">{{ error?.message || 'โหลด Pipeline ไม่สำเร็จ' }}</div>

    <div v-else class="kanban">
      <div v-for="lane in LANES" :key="lane.id" class="lane">
        <h4>
          <span>{{ lane.title }}</span>
          <span class="tag" :class="lane.tag">{{ laneLeads(lane.id).length }}</span>
        </h4>

        <div
          v-for="l in laneLeads(lane.id)"
          :key="l.id"
          class="lead"
          style="cursor:pointer"
          @click="openDetailDrawer('lead', l.id)"
        >
          <strong>{{ l.shop_name }}</strong>
          <p>{{ l.shop_type || 'ร้านค้า' }} · {{ l.phone || 'ไม่มีเบอร์' }}</p>
          <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;flex-wrap:wrap">
            <span class="tag" :class="stageTagClass(l.pipeline_stage)">{{ stageLabel(l.pipeline_stage) }}</span>
            <select
              :value="l.pipeline_stage"
              style="font-size:13px;padding:5px 8px;min-height:36px;border-radius:9px;border:1px solid var(--border);background:#fff"
              @click.stop
              @change.stop="moveStage(l, $event.target.value)"
            >
              <option v-for="st in PIPELINE_STAGES" :key="st.id" :value="st.id">{{ st.label }}</option>
            </select>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:8px" @click.stop>
            <button
              type="button"
              class="btn light"
              style="font-size:12.5px;padding:6px 8px;min-height:34px"
              @click="openQuotationModal(l)"
            >
              📄 ใบเสนอราคา
            </button>
            <button
              v-if="!['converted', 'erp_creation_requested', 'erp_match_pending'].includes(l.pipeline_stage)"
              type="button"
              class="btn light"
              style="font-size:12.5px;padding:6px 8px;min-height:34px"
              @click="requestErp(l)"
            >
              📨 ขอเปิดรหัส ERP
            </button>
          </div>
        </div>

        <div v-if="!laneLeads(lane.id).length" class="small muted" style="text-align:center;padding:24px 8px">
          ยังไม่มีรายการในสถานะนี้
        </div>
      </div>
    </div>

    <!-- Modal: Quotation Builder & Printable A4 PDF -->
    <BaseModal
      :open="Boolean(quoteLead)"
      :title="quoteLead ? `📄 ออกใบเสนอราคา — ${quoteLead.shop_name}` : 'ใบเสนอราคา'"
      subtitle="ดึงสินค้าจาก Bplus ERP คำนวณส่วนลด/VAT และสั่งพิมพ์หรือบันทึกเป็น PDF ได้ทันที"
      max-width="860px"
      @close="quoteLead = null"
    >
      <div v-if="quoteLead" class="quote-builder">
        <div class="quote-meta-row">
          <div class="field">
            <label>เลขที่ใบเสนอราคา</label>
            <input v-model="quoteDoc.quoteNo" />
          </div>
          <div class="field">
            <label>วันที่เสนอราคา</label>
            <input v-model="quoteDoc.quoteDate" type="date" />
          </div>
          <div class="field">
            <label>ยืนราคา (วัน)</label>
            <input v-model.number="quoteDoc.validDays" type="number" min="1" max="180" />
          </div>
        </div>

        <!-- Product Search & Quick Add from Bplus ERP -->
        <div class="quote-product-picker">
          <label class="small muted" style="display:block;margin-bottom:4px">
            🔍 ค้นหาและดึงรายการสินค้าจาก Bplus ERP (4,208 รายการ)
          </label>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <input
              v-model="productSearch"
              type="search"
              placeholder="พิมพ์ชื่อสินค้า หรือ รหัสสินค้า Bplus..."
              style="flex:1;min-width:220px"
            />
            <select v-model="selectedProductCode" style="flex:1.4;min-width:240px" @change="addSelectedErpProduct">
              <option value="">-- เลือกสินค้าจาก Bplus เพื่อเพิ่มลงตาราง --</option>
              <option v-for="p in (erpProducts || [])" :key="p.erp_product_code" :value="p.erp_product_code">
                [{{ p.erp_product_code }}] {{ p.product_name }} ({{ p.unit || 'ชิ้น' }} · ฿{{ money(p.default_price || 0) }})
              </option>
            </select>
            <button type="button" class="btn light" @click="addBlankItem">＋ เพิ่มรายการเอง</button>
          </div>
        </div>

        <!-- Line Items Table -->
        <div class="table-wrap" style="border:1px solid var(--border);border-radius:12px;margin-top:12px">
          <table class="table">
            <thead>
              <tr>
                <th style="width:40px">#</th>
                <th>รหัส / รายการสินค้า</th>
                <th style="width:90px">จำนวน</th>
                <th style="width:90px">หน่วย</th>
                <th style="width:120px">ราคา/หน่วย</th>
                <th style="width:120px;text-align:right">รวม (บาท)</th>
                <th style="width:44px"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(item, idx) in quoteDoc.items" :key="idx">
                <td>{{ idx + 1 }}</td>
                <td>
                  <input
                    v-model="item.name"
                    placeholder="ชื่อสินค้า / รายละเอียด"
                    style="width:100%;padding:6px 8px;border:1px solid var(--border);border-radius:8px"
                  />
                </td>
                <td>
                  <input
                    v-model.number="item.qty"
                    type="number"
                    min="1"
                    step="1"
                    style="width:76px;padding:6px 8px;border:1px solid var(--border);border-radius:8px"
                  />
                </td>
                <td>
                  <input
                    v-model="item.unit"
                    placeholder="ชิ้น/ลัง"
                    style="width:76px;padding:6px 8px;border:1px solid var(--border);border-radius:8px"
                  />
                </td>
                <td>
                  <input
                    v-model.number="item.price"
                    type="number"
                    min="0"
                    step="0.01"
                    style="width:106px;padding:6px 8px;border:1px solid var(--border);border-radius:8px"
                  />
                </td>
                <td style="text-align:right;font-weight:700">
                  {{ money((Number(item.qty) || 0) * (Number(item.price) || 0)) }}
                </td>
                <td>
                  <button
                    v-if="quoteDoc.items.length > 1"
                    type="button"
                    class="btn light"
                    style="min-height:30px;padding:2px 8px"
                    @click="quoteDoc.items.splice(idx, 1)"
                  >
                    ✕
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Totals & Note -->
        <div class="quote-footer-grid">
          <div class="field">
            <label>เงื่อนไข / หมายเหตุใบเสนอราคา</label>
            <textarea
              v-model="quoteDoc.note"
              rows="3"
              placeholder="เช่น จัดส่งฟรีตามรอบสายรถใบจอง POPSTAR / เครดิตตามเงื่อนไขบริษัท"
            ></textarea>
            <label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;cursor:pointer">
              <input v-model="quoteDoc.includeVat" type="checkbox" />
              <span>คำนวณภาษีมูลค่าเพิ่ม (VAT 7%)</span>
            </label>
          </div>

          <div class="quote-totals-box">
            <div class="quote-total-line">
              <span>รวมเป็นเงิน (ก่อนส่วนลด)</span>
              <strong>฿{{ money(quoteSubtotal) }}</strong>
            </div>
            <div class="quote-total-line">
              <span>ส่วนลดท้ายบิล (บาท)</span>
              <input
                v-model.number="quoteDoc.discount"
                type="number"
                min="0"
                step="1"
                style="width:110px;text-align:right;padding:4px 8px;border:1px solid var(--border);border-radius:8px"
              />
            </div>
            <div v-if="quoteDoc.includeVat" class="quote-total-line">
              <span>ภาษีมูลค่าเพิ่ม (VAT 7%)</span>
              <strong>฿{{ money(quoteVat) }}</strong>
            </div>
            <div class="quote-total-line grand">
              <span>ยอดสุทธิทั้งสิ้น</span>
              <strong>฿{{ money(quoteGrandTotal) }}</strong>
            </div>
          </div>
        </div>
      </div>

      <template #footer="{ requestClose }">
        <button type="button" class="btn" @click="requestClose">ปิด</button>
        <button type="button" class="btn primary" @click="printQuotationAndMarkQuoted">
          🖨️ พิมพ์ใบเสนอราคา / บันทึก PDF
        </button>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useMutation, useQuery } from '@tanstack/vue-query';
import BaseModal from '../../../shared/components/BaseModal.vue';
import PageHeader from '../../../shared/components/PageHeader.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { askConfirm, toast } from '../../../shared/composables/useUiStore.js';
import {
  crmGet,
  crmMutate,
  crmQueryClient,
  invalidateCrmData,
  me,
  money,
  openDetailDrawer,
  openQuickAdd,
  PIPELINE_STAGES,
  stageLabel,
  stageTagClass,
} from '../composables/useCrmSession.js';

const LANES = [
  { id: 'new', title: 'ลูกค้าใหม่', tag: 'blue' },
  { id: 'follow', title: 'กำลังติดตาม / เสนอราคา', tag: 'orange' },
  { id: 'visited', title: 'อนุมัติ / เข้าพบแล้ว', tag: 'green' },
  { id: 'erp_pending', title: 'ขอเปิด / จับคู่ ERP', tag: 'purple' },
  { id: 'converted', title: 'ซื้อจริง / เชื่อม ERP', tag: 'gray' },
];

const queryKey = ['crm', 'pipeline-leads'];

const { data: leadsData, isLoading, isError, error } = useQuery({
  queryKey,
  queryFn: ({ signal }) => crmGet('/leads', { signal }),
});

const leadsList = computed(() => leadsData.value || []);

// Quotation state
const quoteLead = ref(null);
const productSearch = ref('');
const selectedProductCode = ref('');
const todayStr = new Date().toISOString().slice(0, 10);

const quoteDoc = ref({
  quoteNo: `QT-${todayStr.replace(/-/g, '')}-001`,
  quoteDate: todayStr,
  validDays: 15,
  discount: 0,
  includeVat: true,
  note: 'จัดส่งตามสายรถใบจอง POPSTAR · ยืนราคาตามกำหนด',
  items: [{ code: '', name: '', qty: 1, unit: 'ลัง', price: 0 }],
});

const { data: erpProducts } = useQuery({
  queryKey: computed(() => ['crm', 'quote-products', productSearch.value]),
  queryFn: ({ signal }) =>
    crmGet(`/products?limit=60&q=${encodeURIComponent(productSearch.value.trim())}`, { signal }),
  enabled: computed(() => Boolean(quoteLead.value)),
});

const quoteSubtotal = computed(() =>
  quoteDoc.value.items.reduce((sum, it) => sum + (Number(it.qty) || 0) * (Number(it.price) || 0), 0)
);
const quoteAfterDiscount = computed(() => Math.max(0, quoteSubtotal.value - (Number(quoteDoc.value.discount) || 0)));
const quoteVat = computed(() => (quoteDoc.value.includeVat ? quoteAfterDiscount.value * 0.07 : 0));
const quoteGrandTotal = computed(() => quoteAfterDiscount.value + quoteVat.value);

function openQuotationModal(lead) {
  quoteLead.value = lead;
  productSearch.value = '';
  selectedProductCode.value = '';
  quoteDoc.value = {
    quoteNo: `QT-${todayStr.replace(/-/g, '')}-${String(lead.id).padStart(3, '0')}`,
    quoteDate: todayStr,
    validDays: 15,
    discount: 0,
    includeVat: true,
    note: 'จัดส่งตามสายรถใบจอง POPSTAR · ยืนราคาตามกำหนด',
    items: [{ code: '', name: 'สินค้าตัวอย่าง POPSTAR', qty: 10, unit: 'ลัง', price: 350 }],
  };
}

function addSelectedErpProduct() {
  if (!selectedProductCode.value) return;
  const prod = (erpProducts.value || []).find((p) => p.erp_product_code === selectedProductCode.value);
  if (prod) {
    // Replace initial blank row if empty
    if (quoteDoc.value.items.length === 1 && !quoteDoc.value.items[0].code && quoteDoc.value.items[0].name === 'สินค้าตัวอย่าง POPSTAR') {
      quoteDoc.value.items = [];
    }
    quoteDoc.value.items.push({
      code: prod.erp_product_code,
      name: `[${prod.erp_product_code}] ${prod.product_name}`,
      qty: 1,
      unit: prod.unit || 'ชิ้น',
      price: Number(prod.default_price || 0),
    });
  }
  selectedProductCode.value = '';
}

function addBlankItem() {
  quoteDoc.value.items.push({ code: '', name: '', qty: 1, unit: 'ชิ้น', price: 0 });
}

function printQuotationAndMarkQuoted() {
  const lead = quoteLead.value;
  if (!lead) return;

  if (lead.pipeline_stage === 'new_lead' || lead.pipeline_stage === 'contacted' || lead.pipeline_stage === 'interested') {
    moveStage(lead, 'quoted');
  }

  const esc = (s) =>
    String(s ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));

  const rowsHtml = quoteDoc.value.items
    .map(
      (it, idx) => `
      <tr>
        <td style="text-align:center">${idx + 1}</td>
        <td>${esc(it.name || 'สินค้า')}</td>
        <td style="text-align:center">${Number(it.qty || 0).toLocaleString('th-TH')}</td>
        <td style="text-align:center">${esc(it.unit || 'ชิ้น')}</td>
        <td style="text-align:right">${money(it.price || 0)}</td>
        <td style="text-align:right;font-weight:700">${money((Number(it.qty) || 0) * (Number(it.price) || 0))}</td>
      </tr>`
    )
    .join('');

  const html = `<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8" />
  <title>ใบเสนอราคา ${esc(quoteDoc.value.quoteNo)} - ${esc(lead.shop_name)}</title>
  <style>
    body { font-family: 'Sarabun', 'Segoe UI', Tahoma, sans-serif; color: #0f172a; margin: 28px; font-size: 13.5px; }
    .head { display: flex; justify-content: space-between; border-bottom: 3px solid #0284c7; padding-bottom: 14px; margin-bottom: 18px; }
    .brand h1 { margin: 0; color: #0284c7; font-size: 22px; }
    .brand p { margin: 3px 0 0; color: #475569; font-size: 12.5px; }
    .doc-box { text-align: right; }
    .doc-box h2 { margin: 0; font-size: 20px; color: #0f172a; }
    .meta-grid { display: grid; grid-template-columns: 1.2fr 1fr; gap: 16px; margin-bottom: 18px; }
    .box { border: 1px solid #cbd5e1; border-radius: 10px; padding: 12px 14px; background: #f8fafc; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
    th, td { border: 1px solid #cbd5e1; padding: 8px 10px; }
    th { background: #e0f2fe; color: #0369a1; font-weight: 700; }
    .totals { width: 320px; margin-left: auto; border: 1px solid #cbd5e1; border-radius: 10px; padding: 10px 14px; }
    .t-row { display: flex; justify-content: space-between; padding: 4px 0; }
    .t-row.grand { border-top: 2px solid #0284c7; margin-top: 6px; padding-top: 8px; font-size: 16px; font-weight: 800; color: #0284c7; }
    .sign-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 48px; text-align: center; }
    .sign-line { border-top: 1px dashed #64748b; margin-top: 42px; padding-top: 6px; }
    @media print { .no-print { display: none; } body { margin: 12mm; } }
  </style>
</head>
<body>
  <div class="no-print" style="margin-bottom:14px;text-align:right">
    <button onclick="window.print()" style="background:#0284c7;color:#fff;border:0;padding:8px 16px;border-radius:8px;font-weight:700;cursor:pointer">🖨️ สั่งพิมพ์ / บันทึก PDF</button>
  </div>
  <div class="head">
    <div class="brand">
      <h1>POPSTAR FOOD &amp; BUSINESS</h1>
      <p>ระบบบริหารงานขายและลูกค้าสัมพันธ์ (POPSTAR Connect CRM)</p>
    </div>
    <div class="doc-box">
      <h2>ใบเสนอราคา (QUOTATION)</h2>
      <div>เลขที่: <b>${esc(quoteDoc.value.quoteNo)}</b></div>
      <div>วันที่: <b>${esc(quoteDoc.value.quoteDate)}</b> (ยืนราคา ${Number(quoteDoc.value.validDays || 15)} วัน)</div>
    </div>
  </div>
  <div class="meta-grid">
    <div class="box">
      <strong>เสนอต่อ (ลูกค้า / ร้านค้า):</strong><br/>
      <b>${esc(lead.shop_name)}</b><br/>
      โทร: ${esc(lead.phone || '—')} · พื้นที่: ${esc([lead.address, lead.district, lead.province].filter(Boolean).join(' ') || '—')}
    </div>
    <div class="box">
      <strong>ผู้เสนอราคา (พนักงานขาย):</strong><br/>
      <b>${esc(me.value?.name || 'ฝ่ายขาย POPSTAR')}</b><br/>
      รหัสเซลล์: ${esc(me.value?.salesperson_code || '—')}
    </div>
  </div>
  <table>
    <thead>
      <tr>
        <th style="width:45px">ลำดับ</th>
        <th>รายการสินค้า</th>
        <th style="width:80px">จำนวน</th>
        <th style="width:80px">หน่วย</th>
        <th style="width:110px">ราคา/หน่วย</th>
        <th style="width:120px">จำนวนเงิน (บาท)</th>
      </tr>
    </thead>
    <tbody>${rowsHtml}</tbody>
  </table>
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px">
    <div style="flex:1">
      <strong>หมายเหตุ / เงื่อนไข:</strong>
      <p style="margin:4px 0 0;color:#334155">${esc(quoteDoc.value.note || '—')}</p>
    </div>
    <div class="totals">
      <div class="t-row"><span>รวมเป็นเงิน</span><strong>฿${money(quoteSubtotal.value)}</strong></div>
      <div class="t-row"><span>ส่วนลด</span><strong>฿${money(quoteDoc.value.discount || 0)}</strong></div>
      ${quoteDoc.value.includeVat ? `<div class="t-row"><span>ภาษีมูลค่าเพิ่ม 7%</span><strong>฿${money(quoteVat.value)}</strong></div>` : ''}
      <div class="t-row grand"><span>ยอดสุทธิทั้งสิ้น</span><span>฿${money(quoteGrandTotal.value)}</span></div>
    </div>
  </div>
  <div class="sign-grid">
    <div><div class="sign-line">ผู้สั่งซื้อ / ลูกค้า</div></div>
    <div><div class="sign-line">ผู้เสนอราคา (${esc(me.value?.name || 'ฝ่ายขาย POPSTAR')})</div></div>
  </div>
</body>
</html>`;

  const win = window.open('', '_blank', 'width=920,height=760');
  if (win) {
    win.document.open();
    win.document.write(html);
    win.document.close();
    setTimeout(() => win.print(), 250);
  } else {
    toast.warning('เบราว์เซอร์บล็อกหน้าต่างป๊อปอัป กรุณาอนุญาตป๊อปอัปเพื่อพิมพ์ใบเสนอราคา');
  }
}

function laneLeads(laneId) {
  if (laneId === 'new') return leadsList.value.filter((l) => l.pipeline_stage === 'new_lead' && l.approval_status !== 'approved');
  if (laneId === 'follow') return leadsList.value.filter((l) => ['contacted', 'interested', 'quoted', 'follow_up'].includes(l.pipeline_stage));
  if (laneId === 'visited') return leadsList.value.filter((l) => l.approval_status === 'approved' && !['converted', 'erp_creation_requested', 'erp_match_pending'].includes(l.pipeline_stage));
  if (laneId === 'erp_pending') return leadsList.value.filter((l) => ['erp_creation_requested', 'erp_match_pending'].includes(l.pipeline_stage));
  if (laneId === 'converted') return leadsList.value.filter((l) => l.pipeline_stage === 'converted');
  return [];
}

const stageMutation = useMutation({
  mutationFn: ({ leadId, stage }) => crmMutate(`/leads/${leadId}/stage`, 'PATCH', { pipeline_stage: stage }),
  onMutate: async ({ leadId, stage }) => {
    await crmQueryClient.cancelQueries({ queryKey });
    const previous = crmQueryClient.getQueryData(queryKey);
    crmQueryClient.setQueryData(queryKey, (old) =>
      Array.isArray(old) ? old.map((x) => (x.id === leadId ? { ...x, pipeline_stage: stage } : x)) : old
    );
    return { previous };
  },
  onError: (err, _vars, ctx) => {
    if (ctx?.previous) crmQueryClient.setQueryData(queryKey, ctx.previous);
    toast.error('ย้ายสถานะ Pipeline ไม่สำเร็จ (คืนค่าเดิมแล้ว)', err);
  },
  onSuccess: () => {
    toast.success('อัปเดตสถานะ Pipeline แล้ว');
    invalidateCrmData(['leads', 'master', 'dashboard']);
  },
});

function moveStage(lead, stage) {
  stageMutation.mutate({ leadId: lead.id, stage });
}

async function requestErp(lead) {
  const ok = await askConfirm({
    title: 'ส่งคำขอเปิดรหัสลูกค้า Bplus ERP',
    message: `ส่งร้าน "${lead.shop_name}" เข้าคิวขอเปิดบัญชี ERP โดยไม่สร้างรหัสลงฐาน ERP อัตโนมัติ?`,
    confirmLabel: 'ส่งคำขอ',
  });
  if (!ok) return;
  try {
    await crmMutate(`/customer-master/leads/${lead.id}/request-erp-creation`, 'POST', {
      note: 'ส่งคำขอเปิดบัญชี ERP จากหน้า Lead Pipeline',
    });
    toast.success('ส่งคำขอเปิดบัญชี ERP เรียบร้อย');
    invalidateCrmData(['leads', 'master', 'dashboard']);
  } catch (err) {
    toast.error('ส่งคำขอไม่สำเร็จ', err);
  }
}
</script>

<style scoped>
.quote-meta-row {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
  margin-bottom: 12px;
}

.quote-product-picker {
  background: #f0f9ff;
  border: 1px solid #bae6fd;
  border-radius: 12px;
  padding: 10px 12px;
}

.quote-footer-grid {
  display: grid;
  grid-template-columns: 1.2fr 1fr;
  gap: 16px;
  margin-top: 14px;
  align-items: start;
}

.quote-totals-box {
  background: #f8fafc;
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.quote-total-line {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  font-size: 13.5px;
}

.quote-total-line.grand {
  border-top: 2px solid var(--blue);
  padding-top: 8px;
  font-size: 16px;
  color: var(--blue);
}

@media (max-width: 720px) {
  .quote-meta-row,
  .quote-footer-grid {
    grid-template-columns: 1fr;
  }
}
</style>
