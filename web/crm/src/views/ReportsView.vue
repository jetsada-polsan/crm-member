<template>
  <div>
    <div class="pagehead">
      <div>
        <div class="eyebrow">REPORTS &amp; RECONCILIATION</div>
        <h2>รายงานการขาย &amp; รายงานออกตลาด 6 ข้อ</h2>
        <p>เทียบยอดขายจริงก่อน VAT (แยกใบจอง 207 และลดหนี้ 308) และส่งออก CSV / พิมพ์รายงานเข้าพบ</p>
      </div>
      <div class="head-actions">
        <a :href="reconUrl('csv')" class="btn">⬇ Reconciliation CSV</a>
        <a :href="fieldUrl('csv')" class="btn light">⬇ Field Report CSV</a>
        <a :href="fieldUrl('print')" target="_blank" class="btn primary">🖨 พิมพ์รายงานเข้าพบ</a>
      </div>
    </div>

    <div class="card panel-pad" style="margin-bottom:16px">
      <div class="head-filter" style="margin-bottom:0">
        <div>
          <label class="small muted" style="display:block">ตั้งแต่วันที่</label>
          <input v-model="from" type="date" min="2026-01-01" />
        </div>
        <div>
          <label class="small muted" style="display:block">ถึงวันที่</label>
          <input v-model="to" type="date" min="2026-01-01" />
        </div>
      </div>
    </div>

    <section class="card panel-pad" style="margin-bottom:16px">
      <div class="section-head" style="padding:0 0 12px">
        <div>
          <h3 style="margin:0">ยอดซื้อสุทธิรายลูกค้า</h3>
          <div class="small muted">ยอดก่อน VAT หลังหักคืน เริ่ม 1 มกราคม 2569</div>
        </div>
      </div>
      <div class="notice" style="margin-bottom:12px">
        ยอดอยู่ระหว่างตรวจเทียบกับรายงาน Bplus
      </div>
      <div class="head-filter" style="margin-bottom:12px;flex-wrap:wrap">
        <div>
          <label class="small muted" style="display:block">สาย/พื้นที่ (ARLINE)</label>
          <select v-model="routeCode"><option value="">ทุกสาย</option><option v-for="r in dimensions?.routes || []" :key="r.route_code" :value="r.route_code">[{{ r.route_code }}] {{ r.route_name || 'ไม่ระบุชื่อ' }}</option></select>
        </div>
        <div>
          <label class="small muted" style="display:block">แสดงยอด</label>
          <select v-model="period"><option value="day">รายวัน</option><option value="month">รายเดือน</option></select>
        </div>
        <div>
          <label class="small muted" style="display:block">ค้นหาร้าน / รหัส ERP</label>
          <input v-model="customerSearch" placeholder="ชื่อร้านหรือรหัสลูกค้า" />
        </div>
      </div>

      <div v-if="matrixLoading" class="placeholder">กำลังอ่านยอดจาก ERP mirror…</div>
      <div v-else-if="matrixError" class="error" role="alert">โหลดตารางยอดขายรายวันไม่สำเร็จ: {{ matrixErrorMessage?.message || 'ตรวจการเชื่อมต่อและลองรีเฟรช' }}</div>
      <template v-else>
        <div class="small muted" style="margin:0 0 8px">พบ {{ fmt(matrix?.meta?.total_customers || 0) }} ร้าน · หน้า {{ matrix?.meta?.current_page || 1 }} / {{ matrix?.meta?.last_page || 1 }} · ช่วง {{ matrix?.filters?.from }} ถึง {{ matrix?.filters?.to }}</div>
        <div class="table-wrap" style="max-height:560px;overflow:auto">
          <table class="table" style="min-width:max-content">
            <thead><tr>
              <th style="position:sticky;left:0;z-index:2;background:#f8fafc;min-width:220px">ลูกค้า</th>
              <th>สาย/พื้นที่</th>
              <th v-for="day in matrix?.dates || []" :key="day">{{ dayLabel(day) }}</th>
              <th>ซื้อสุทธิ</th>
            </tr></thead>
            <tbody>
              <template v-for="row in matrixRows" :key="row.key">
              <tr v-if="row.type === 'route'" style="background:#15233f;color:#fff">
                <td :colspan="(matrix?.dates?.length || 0) + 3" style="font-weight:700;padding:9px 12px">
                  ▾ [{{ row.routeCode || '—' }}] {{ row.routeName || 'ไม่ระบุพื้นที่' }}
                  <span style="float:right;font-weight:500">{{ fmt(row.customerCount) }} ร้าน · ซื้อสุทธิ {{ fmt(row.net) }} ฿</span>
                </td>
              </tr>
              <tr v-else>
                <td style="position:sticky;left:0;z-index:1;background:#fff;min-width:220px"><b>{{ row.customer_name }}</b><div class="small muted">{{ row.erp_customer_code }}</div></td>
                <td>[{{ row.route_code || '—' }}] {{ row.route_name || '' }}</td>
                <td v-for="day in matrix?.dates || []" :key="day" :style="{color: Number(row[`day_${dayKey(day)}`]) < 0 ? 'var(--red)' : 'var(--ink)',textAlign:'right'}">{{ Number(row[`day_${dayKey(day)}`]) ? fmt(row[`day_${dayKey(day)}`]) : '—' }}</td>
                <td><b>{{ fmt(row.net_purchase_before_vat) }}</b></td>
              </tr>
              </template>
              <tr v-if="!(matrix?.data || []).length"><td :colspan="(matrix?.dates?.length || 0) + 3" class="placeholder">ไม่พบยอดซื้อจริงในช่วงและตัวกรองนี้</td></tr>
            </tbody>
          </table>
        </div>
        <div style="display:flex;align-items:center;justify-content:flex-end;gap:8px;margin-top:10px">
          <button type="button" class="btn light" :disabled="(matrix?.meta?.current_page || 1) <= 1" @click="page--">ก่อนหน้า</button>
          <span class="small muted">{{ matrix?.meta?.current_page || 1 }} / {{ matrix?.meta?.last_page || 1 }}</span>
          <button type="button" class="btn light" :disabled="(matrix?.meta?.current_page || 1) >= (matrix?.meta?.last_page || 1)" @click="page++">ถัดไป</button>
        </div>
      </template>
    </section>

    <section v-if="false && isManager" class="card panel-pad" style="margin-bottom:16px">
      <h3 style="margin-top:0">กำหนดกลุ่มไซส์ให้ SKU</h3>
      <p class="small muted">Bplus mirror ไม่มีฟิลด์ไซส์ที่ยืนยันแล้ว ผู้ดูแลจึงกำหนดกลุ่มให้รหัสสินค้าใน CRM ได้ กลุ่มนี้ใช้กับรายงานเท่านั้นและไม่เขียนกลับ ERP</p>
      <form class="head-filter" style="margin-bottom:12px;flex-wrap:wrap" @submit.prevent="saveSizeSegment">
        <div style="min-width:260px">
          <label class="small muted" style="display:block">ค้นหา SKU / ชื่อสินค้า</label>
          <input v-model="productSearch" placeholder="พิมพ์รหัสหรือชื่อสินค้าเพื่อค้นหา" />
        </div>
        <div style="min-width:260px">
          <label class="small muted" style="display:block">สินค้า ERP ({{ sizeProducts?.length || 0 }} รายการ)</label>
          <select v-model="sizeForm.erp_product_code" required><option value="">เลือกสินค้า</option><option v-for="p in sizeProducts || []" :key="p.erp_product_id" :value="p.erp_product_code">{{ p.erp_product_code }} · {{ p.product_name }}</option></select>
        </div>
        <div><label class="small muted" style="display:block">รหัสกลุ่มไซส์</label><input v-model="sizeForm.size_code" required maxlength="40" placeholder="เช่น SIZE-300" /></div>
        <div><label class="small muted" style="display:block">ชื่อกลุ่มที่แสดง</label><input v-model="sizeForm.size_label" required maxlength="80" placeholder="เช่น ขนาด 300 กรัม" /></div>
        <button type="submit" class="btn primary" :disabled="savingSize">บันทึกกลุ่มไซส์</button>
      </form>
      <div v-if="sizeNotice" class="small" role="status" style="margin-bottom:8px">{{ sizeNotice }}</div>
      <div class="table-wrap" style="max-height:240px;overflow:auto">
        <table class="table"><thead><tr><th>SKU</th><th>สินค้า</th><th>กลุ่มไซส์</th><th></th></tr></thead><tbody>
          <tr v-for="p in mappedProducts" :key="p.segment_id"><td>{{ p.erp_product_code }}</td><td>{{ p.product_name }}</td><td>{{ p.size_label }} ({{ p.size_code }})</td><td><button type="button" class="btn danger" @click="removeSizeSegment(p)">เอาออก</button></td></tr>
          <tr v-if="!mappedProducts.length"><td colspan="4" class="placeholder">ยังไม่ได้จัดกลุ่มไซส์ให้ SKU</td></tr>
        </tbody></table>
      </div>
    </section>

    <SkeletonBlock v-if="isLoading" variant="kpis" />
    <template v-else>
      <div class="card panel-pad" style="margin-bottom:16px">
        <h3 style="margin-top:0">สรุปตัวเลข Reconciliation Bplus (ก่อน VAT / VAT / รวม)</h3>
        <div v-if="recon?.summary" class="kpis" style="margin-bottom:0">
          <div class="card kpi">
            <div class="kpi-label">ยอดขายสุทธิก่อน VAT</div>
            <div class="kpi-value">{{ fmt(recon.summary.net_sales_before_vat) }} ฿</div>
          </div>
          <div class="card kpi">
            <div class="kpi-label">VAT</div>
            <div class="kpi-value">{{ fmt(recon.summary.vat_amount) }} ฿</div>
          </div>
          <div class="card kpi">
            <div class="kpi-label">ลดหนี้ (Doc 308)</div>
            <div class="kpi-value">{{ fmt(recon.summary.return_308_before_vat) }} ฿</div>
          </div>
          <div class="card kpi">
            <div class="kpi-label">ใบจอง (Doc 207 แยกจากขายจริง)</div>
            <div class="kpi-value">{{ fmt(recon.summary.reservation_207_before_vat) }} ฿</div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="section-head" style="padding-bottom:12px">
          <h3>รายงานการเข้าพบภาคสนาม 6 ข้อ ({{ fieldReport?.summary?.total_visits || 0 }} รายการ)</h3>
        </div>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr><th>วันที่</th><th>ร้านค้า</th><th>พนักงาน</th><th>ผล</th><th>Q1 แหล่งซื้อ</th><th>Q2 สินค้า</th><th>รูป</th></tr>
            </thead>
            <tbody>
              <tr v-for="r in (fieldReport?.rows || []).slice(0, 50)" :key="r.id">
                <td>{{ new Date(r.visited_at).toLocaleDateString('th-TH') }}</td>
                <td><b>{{ r.shop_name }}</b></td>
                <td>{{ r.staff_name }}</td>
                <td><span class="tag blue">{{ r.result }}</span></td>
                <td>{{ r.supplier_name || '—' }}</td>
                <td>{{ r.product_types || '—' }}</td>
                <td>{{ r.photo_count }}</td>
              </tr>
              <tr v-if="!(fieldReport?.rows || []).length">
                <td colspan="7" class="placeholder">ยังไม่มีข้อมูลเข้าพบในช่วงเวลาที่เลือก</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { crmGet, crmMutate, fmt, isManager } from '../composables/useCrmSession.js';

const isoDate = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const now = new Date();
const from = ref(isoDate(new Date(now.getFullYear(), now.getMonth(), 1)));
const to = ref(isoDate(now));
const routeCode = ref('');
const sizeCode = ref('');
const period = ref('day');
const customerSearch = ref('');
const productSearch = ref('');
const page = ref(1);
const sizeForm = ref({ erp_product_code: '', size_code: '', size_label: '' });
const savingSize = ref(false);
const sizeNotice = ref('');

const { data: dimensions, refetch: refetchDimensions } = useQuery({
  queryKey: ['crm', 'sales-dimensions', 'BPLUS'],
  queryFn: ({ signal }) => crmGet('/reports/sales-dimensions?source_system=BPLUS', { signal }),
});

const matrixUrl = computed(() => `/reports/customer-sales-matrix${qs({
  source_system: 'BPLUS', route_code: routeCode.value, period: period.value,
  q: customerSearch.value, page: page.value, per_page: 200,
})}`);
const { data: matrix, isLoading: matrixLoading, isError: matrixError, error: matrixErrorMessage } = useQuery({
  queryKey: computed(() => ['crm', 'customer-sales-matrix', from.value, to.value, routeCode.value, period.value, customerSearch.value, page.value]),
  queryFn: ({ signal }) => crmGet(matrixUrl.value, { signal }),
});

const { data: sizeProducts, refetch: refetchSizeProducts } = useQuery({
  queryKey: computed(() => ['crm', 'product-size-segments', 'BPLUS', productSearch.value]),
  queryFn: ({ signal }) => crmGet(`/reports/product-size-segments${productSearch.value ? `?source_system=BPLUS&q=${encodeURIComponent(productSearch.value)}` : '?source_system=BPLUS'}`, { signal }),
  enabled: false,
});

const mappedProducts = computed(() => (sizeProducts.value || []).filter((p) => p.segment_id));
const matrixRows = computed(() => {
  const rows = matrix.value?.data || [];
  const grouped = [];
  let currentRoute = null;
  for (const row of rows) {
    const code = row.route_code || '';
    if (code !== currentRoute) {
      const members = rows.filter((candidate) => (candidate.route_code || '') === code);
      grouped.push({
        type: 'route',
        key: `route-${code || 'unassigned'}`,
        routeCode: code,
        routeName: row.route_name,
        customerCount: members.length,
        net: members.reduce((sum, customer) => sum + Number(customer.net_purchase_before_vat || 0), 0),
      });
      currentRoute = code;
    }
    grouped.push({ ...row, type: 'customer', key: `customer-${row.customer_id}` });
  }
  return grouped;
});

watch([from, to, routeCode, period, customerSearch], () => { page.value = 1; });
watch(period, (value) => {
  from.value = value === 'month' ? '2026-01-01' : isoDate(new Date(now.getFullYear(), now.getMonth(), 1));
  to.value = isoDate(now);
});

function dayLabel(value) {
  return value.length === 7 ? new Date(`${value}-01T00:00:00`).toLocaleDateString('th-TH', { month: 'short', year: '2-digit' }) : new Date(`${value}T00:00:00`).toLocaleDateString('th-TH', { day: '2-digit', month: '2-digit' });
}

function dayKey(value) { return value.replaceAll('-', ''); }

async function saveSizeSegment() {
  savingSize.value = true;
  sizeNotice.value = '';
  try {
    await crmMutate('/reports/product-size-segments', 'POST', { source_system: 'BPLUS', ...sizeForm.value });
    sizeNotice.value = 'บันทึกกลุ่มสินค้าแล้ว';
    await Promise.all([refetchSizeProducts(), refetchDimensions()]);
    sizeCode.value = sizeForm.value.size_code.toUpperCase();
  } catch (error) {
    sizeNotice.value = error?.message || 'บันทึกกลุ่มสินค้าไม่สำเร็จ';
  } finally {
    savingSize.value = false;
  }
}

async function removeSizeSegment(product) {
  try {
    await crmMutate(`/reports/product-size-segments/${product.segment_id}`, 'DELETE');
    await Promise.all([refetchSizeProducts(), refetchDimensions()]);
    if (sizeCode.value === product.size_code) sizeCode.value = '';
  } catch (error) {
    sizeNotice.value = error?.message || 'นำกลุ่มไซส์ออกไม่สำเร็จ';
  }
}

function qs(extra = {}) {
  const p = new URLSearchParams();
  if (from.value) p.set('from', from.value);
  if (to.value) p.set('to', to.value);
  for (const [k, v] of Object.entries(extra)) p.set(k, v);
  const s = p.toString();
  return s ? `?${s}` : '';
}

const reconUrl = (fmtType) => `/api/crm/reports/reconciliation${qs({ format: fmtType })}`;
const fieldUrl = (fmtType) => `/api/crm/reports/field-visits${qs({ format: fmtType })}`;

const { data: recon, isLoading: l1 } = useQuery({
  queryKey: computed(() => ['crm', 'recon', from.value, to.value]),
  queryFn: ({ signal }) => crmGet(`/reports/reconciliation${qs({ format: 'json' })}`, { signal }),
});

const { data: fieldReport, isLoading: l2 } = useQuery({
  queryKey: computed(() => ['crm', 'field-report', from.value, to.value]),
  queryFn: ({ signal }) => crmGet(`/reports/field-visits${qs({ format: 'json' })}`, { signal }),
});

const isLoading = computed(() => l1.value && l2.value);
</script>
