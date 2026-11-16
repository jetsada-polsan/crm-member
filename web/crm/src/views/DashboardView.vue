<template>
  <div>
    <PageHeader
      title="ภาพรวมฝ่ายขาย"
      subtitle="ลูกค้าใหม่ การเข้าพบ งานค้าง และยอดขายก่อน VAT"
    >
      <template #secondary>
        <button class="btn" type="button" @click="refetchAll">⟳ รีเฟรช</button>
        <button class="btn light" type="button" @click="openVisitWizard()">📸 บันทึกเข้าพบ</button>
      </template>
      <template #primary>
        <button class="btn primary" type="button" @click="openQuickAdd()">＋ เพิ่มร้านใหม่</button>
      </template>
    </PageHeader>

    <div v-if="offlineDrafts.length" class="notice" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <span>มีข้อมูลเข้าพบค้างใน Offline Draft Queue จำนวน <b>{{ offlineDrafts.length }}</b> รายการ</span>
      <button type="button" class="btn primary" :disabled="offlineSyncing" @click="syncPendingOfflineDrafts">
        {{ offlineSyncing ? 'กำลังซิงค์...' : 'ซิงค์ Offline Draft ทันที' }}
      </button>
    </div>

    <SkeletonBlock v-if="dashLoading && !dashboard" variant="kpis" />

    <div v-else class="kpis">
      <div class="card kpi">
        <div class="kpi-top">
          <div class="kpi-icon">🏪</div>
          <span class="tag green">วันนี้ +{{ fmt(dashboard?.acquisition?.leads_today) }}</span>
        </div>
        <div class="kpi-label">ลูกค้าใหม่เดือนนี้</div>
        <div class="kpi-value">{{ fmt(dashboard?.acquisition?.leads_this_month ?? dashboard?.acquisition?.total_leads) }} <span style="font-size:14px;font-weight:500;color:#5f6b80">ราย</span></div>
        <div class="kpi-foot">สะสมทั้งหมด {{ fmt(dashboard?.acquisition?.total_leads) }} ร้าน (รออนุมัติ {{ fmt(dashboard?.acquisition?.pending_approval) }})</div>
      </div>

      <div class="card kpi">
        <div class="kpi-top">
          <div class="kpi-icon green">📍</div>
          <span class="tag green">เดือนนี้ {{ fmt(dashboard?.visit_performance?.visits_this_month) }}</span>
        </div>
        <div class="kpi-label">เข้าพบลูกค้าสะสม</div>
        <div class="kpi-value">{{ fmt(dashboard?.visit_performance?.total_visits) }} <span style="font-size:14px;font-weight:500;color:#5f6b80">ครั้ง</span></div>
        <div class="kpi-foot">เสร็จสิ้นติดตาม {{ fmt(dashboard?.visit_performance?.completed_tasks) }} งาน</div>
      </div>

      <div class="card kpi">
        <div class="kpi-top">
          <div class="kpi-icon orange">🕒</div>
          <span class="tag orange">ต้องติดตาม</span>
        </div>
        <div class="kpi-label">งานติดตามที่ค้าง</div>
        <div class="kpi-value">{{ fmt(dashboard?.visit_performance?.open_tasks) }} <span style="font-size:14px;font-weight:500;color:#5f6b80">งาน</span></div>
        <div class="kpi-foot">ข้อมูลขั้นต่ำรอเติม {{ fmt(dashboard?.acquisition?.minimal_drafts) }} ร้าน</div>
      </div>

      <div class="card kpi">
        <div class="kpi-top">
          <div class="kpi-icon purple">📊</div>
          <span class="tag blue">BPLUS ERP</span>
        </div>
        <div class="kpi-label">ยอดซื้อสุทธิจริงก่อน VAT ({{ conversionRate }}% ผูกเป็น ERP)</div>
        <div class="kpi-value">{{ fmt(dashboard?.sales_kpis?.net_sales_before_vat ?? dashboard?.posted_sales_net) }} <span style="font-size:14px;font-weight:500;color:#5f6b80">฿</span></div>
        <div class="kpi-foot">ลูกค้า ERP {{ fmt(dashboard?.customers) }} ราย · บิลขาย {{ fmt(dashboard?.posted_invoices) }} ใบ · คืน/ลดหนี้ 308: {{ fmt(dashboard?.sales_kpis?.return_308_before_vat) }} ฿</div>
      </div>
    </div>

    <div class="dash-grid">
      <div class="card">
        <div class="section-head">
          <div>
            <h3>ยอดซื้อสุทธิจริงจาก Bplus รายเดือน</h3>
            <small>ยอดก่อน VAT หลังหักใบคืน: {{ fmt(dashboard?.sales_kpis?.net_sales_before_vat) }} ฿ · VAT {{ fmt(dashboard?.sales_kpis?.vat_amount) }} ฿ · รวม VAT {{ fmt(dashboard?.sales_kpis?.gross_amount) }} ฿</small>
          </div>
          <span class="tag blue">ERP Mirror · รายเดือน</span>
        </div>
        <div class="chart">
          <svg v-if="monthlySalesBars.some((bar) => bar.value !== 0)" viewBox="0 0 650 212" preserveAspectRatio="none" role="img" aria-label="ยอดซื้อสุทธิจริงรายเดือนจาก Bplus">
            <g stroke="#eaf0f7" stroke-width="1"><path d="M0 46H650 M0 88H650 M0 130H650" /></g>
            <line x1="0" y1="162" x2="650" y2="162" stroke="#aeb9c8" stroke-width="1.5" />
            <g v-for="bar in monthlySalesBars" :key="bar.month">
              <rect :x="bar.x" :y="bar.y" width="58" :height="bar.height" rx="5" :fill="bar.value < 0 ? '#e45a68' : '#3c6ded'">
                <title>{{ bar.label }} · {{ fmt(bar.value) }} ฿ · {{ bar.saleCount }} บิลขาย / {{ bar.returnCount }} ใบคืน</title>
              </rect>
              <text :x="bar.x + 29" y="205" text-anchor="middle" font-size="11" fill="#8290a3">{{ bar.label }}</text>
            </g>
          </svg>
          <div v-else class="placeholder">ยังไม่มีใบขายจริงหรือใบคืนใน ERP mirror ช่วง 7 เดือนล่าสุด</div>
        </div>
        <div class="legend">
          <i></i> ยอดซื้อสุทธิจริงก่อน VAT ({{ fmt(dashboard?.sales_kpis?.net_sales_before_vat) }} ฿) · รวมใบคืนแล้ว &nbsp;
          <i style="background:#e45a68"></i> เดือนที่ยอดสุทธิติดลบ &nbsp;
          <span>ใบจอง 207 ไม่รวมในกราฟ: {{ fmt(dashboard?.sales_kpis?.reservation_207_count) }} ใบ</span>
        </div>
      </div>

      <div class="card">
        <div class="section-head">
          <div>
            <h3>กิจกรรมล่าสุด</h3>
            <small>รายการเข้าพบล่าสุดในระบบ</small>
          </div>
          <RouterLink to="/visits" class="small">ดูทั้งหมด →</RouterLink>
        </div>
        <div class="activity">
          <div v-for="act in (dashboard?.recent_activities || [])" :key="act.id" class="activity-row">
            <div class="bullet">📍</div>
            <div>
              <strong>{{ act.shop_name || 'ร้านค้า' }} · {{ act.result }}</strong>
              <small>{{ act.staff_name || 'ทีมขาย' }} · {{ act.visited_at ? new Date(act.visited_at).toLocaleString('th-TH') : '' }}</small>
            </div>
          </div>
          <div v-if="!(dashboard?.recent_activities || []).length" class="activity-row">
            <div class="bullet">✨</div>
            <div>
              <strong>พร้อมบันทึกกิจกรรมภาคสนาม</strong>
              <small>กด "＋ เพิ่มลูกค้าใหม่" หรือ "📸 บันทึกเข้าพบ" เพื่อเริ่มต้นทันที</small>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="middle">
      <div class="card">
        <div class="section-head" style="padding-bottom:13px">
          <div>
            <h3>Customer Master ล่าสุด</h3>
            <small>แสดงรายการลูกค้าแบบแยกตัวตน (ERP · CRM Lead · Member)</small>
          </div>
          <RouterLink to="/customers" class="btn light">ดูทั้งหมด →</RouterLink>
        </div>
        <div class="table-wrap desktop-table-only">
          <table class="table">
            <thead>
              <tr><th>รหัสภายใน</th><th>ชื่อร้านค้า</th><th>รหัส ERP</th><th>สถานะเชื่อม</th></tr>
            </thead>
            <tbody>
              <tr
                v-for="row in (masterPreview?.data || []).slice(0, 6)"
                :key="row.customer_master_id"
                style="cursor:pointer"
                @click="openDetailDrawer('master', row.customer_master_id)"
              >
                <td><span class="tag blue">{{ row.internal_customer_code }}</span></td>
                <td><b>{{ row.display_name }}</b></td>
                <td>{{ row.erp_customer_code || '—' }}</td>
                <td><span class="tag" :class="row.link_status === 'linked' ? 'green' : 'orange'">{{ row.link_status }}</span></td>
              </tr>
              <tr v-if="!(masterPreview?.data || []).length">
                <td colspan="4" style="text-align:center;color:#8b98aa;padding:24px">ยังไม่มีข้อมูลลูกค้าในระบบ</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="mob-cards" style="padding:0 14px 14px">
          <div
            v-for="row in (masterPreview?.data || []).slice(0, 5)"
            :key="row.customer_master_id"
            class="card mob-card"
            @click="openDetailDrawer('master', row.customer_master_id)"
          >
            <div style="display:flex;justify-content:space-between;gap:8px">
              <b>{{ row.display_name }}</b>
              <span class="tag blue">{{ row.internal_customer_code }}</span>
            </div>
            <div class="small muted" style="margin-top:4px">{{ row.phone || 'ไม่มีเบอร์' }} · {{ row.erp_customer_code || 'ยังไม่เชื่อม ERP' }}</div>
          </div>
        </div>
      </div>

      <div class="card panel-pad">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
          <div>
            <h3 style="margin:0;font-size:15px">ทางลัดภาคสนาม</h3>
            <small class="muted">เครื่องมือสำหรับทีมขายออกตลาด</small>
          </div>
        </div>
        <div style="display:grid;gap:10px">
          <button type="button" class="btn primary" style="justify-content:flex-start;padding:12px 14px" @click="openQuickAdd()">
            🏪 ＋ เพิ่มร้านค้าใหม่ด่วน (3 ช่อง + GPS + ถ่ายภาพ)
          </button>
          <button type="button" class="btn light" style="justify-content:flex-start;padding:12px 14px" @click="openVisitWizard()">
            📸 บันทึกเข้าพบร้านค้า &amp; หลักฐาน (9 ขั้นตอน)
          </button>
          <RouterLink to="/map" class="btn" style="justify-content:flex-start;padding:12px 14px">
            🗺 เปิดแผนที่หมุดร้านค้า &amp; ปักหมุด GPS
          </RouterLink>
          <RouterLink to="/followups" class="btn" style="justify-content:flex-start;padding:12px 14px">
            ☑ งานติดตามผลคงค้าง ({{ fmt(dashboard?.visit_performance?.open_tasks) }})
          </RouterLink>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { RouterLink } from 'vue-router';
import PageHeader from '../../../shared/components/PageHeader.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import {
  crmGet,
  fmt,
  invalidateCrmData,
  offlineDrafts,
  offlineSyncing,
  openDetailDrawer,
  openQuickAdd,
  openVisitWizard,
  syncPendingOfflineDrafts,
} from '../composables/useCrmSession.js';

const { data: dashboard, isLoading: dashLoading, refetch } = useQuery({
  queryKey: ['crm', 'dashboard'],
  queryFn: ({ signal }) => crmGet('/dashboard', { signal }),
});

const { data: masterPreview, refetch: refetchMaster } = useQuery({
  queryKey: ['crm', 'master', { page: 1, perPage: 10 }],
  queryFn: ({ signal }) => crmGet('/customer-master?per_page=10&page=1', { signal }),
});

const monthlySalesBars = computed(() => {
  const rows = new Map((dashboard.value?.monthly_sales_chart || []).map((row) => [row.month, row]));
  const end = new Date();
  const months = [];
  for (let offset = 6; offset >= 0; offset--) {
    const date = new Date(end.getFullYear(), end.getMonth() - offset, 1);
    const month = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
    const row = rows.get(month) || {};
    months.push({
      month,
      label: date.toLocaleDateString('th-TH', { month: 'short' }),
      value: Number(row.net_sales_before_vat || 0),
      saleCount: Number(row.sale_invoice_count || 0),
      returnCount: Number(row.return_count || 0),
    });
  }
  const maxPositive = Math.max(0, ...months.map((item) => item.value));
  const maxNegative = Math.max(0, ...months.map((item) => Math.abs(Math.min(0, item.value))));
  return months.map((item, index) => {
    const height = item.value >= 0
      ? (maxPositive ? (item.value / maxPositive) * 112 : 0)
      : (maxNegative ? (Math.abs(item.value) / maxNegative) * 28 : 0);
    return {
      ...item,
      x: 12 + index * 90,
      y: item.value >= 0 ? 162 - height : 162,
      height,
    };
  });
});

const conversionRate = computed(() => {
  const total = Number(dashboard.value?.acquisition?.total_leads || 0);
  const conv = Number(dashboard.value?.acquisition?.converted_leads || 0);
  return total ? ((conv / total) * 100).toFixed(1) : '0.0';
});

function refetchAll() {
  invalidateCrmData();
  refetch();
  refetchMaster();
}
</script>
