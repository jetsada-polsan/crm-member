<template>
  <div>
    <PageHeader
      class="desktop-only"
      :title="isManager ? 'ภาพรวมฝ่ายขาย' : 'งานวันนี้'"
      :subtitle="isManager ? 'สรุปยอดขาย งานเข้าพบ และติดตามลูกค้า' : 'แผนวันนี้ งานติดตาม และบันทึกเข้าพบ'"
    >
      <template #secondary>
        <button class="btn" type="button" @click="refetchAll">⟳ รีเฟรช</button>
        <button v-if="isManager" class="btn light" type="button" @click="openVisitWizard()">📸 บันทึกเข้าพบ</button>
        <button v-else class="btn light" type="button" @click="openQuickAdd()">＋ เพิ่มร้านใหม่</button>
      </template>
      <template #primary>
        <button v-if="isManager" class="btn primary" type="button" @click="openQuickAdd()">＋ เพิ่มร้านใหม่</button>
        <button v-else class="btn primary" type="button" @click="openVisitWizard()">📸 บันทึกเข้าพบ</button>
      </template>
    </PageHeader>

    <div v-if="offlineDrafts.length" class="notice row-between">
      <span>มีข้อมูลเข้าพบค้างส่ง <b>{{ offlineDrafts.length }}</b> รายการ</span>
      <button type="button" class="btn primary" :disabled="offlineSyncing" @click="syncPendingOfflineDrafts">
        {{ offlineSyncing ? 'กำลังส่ง...' : 'ส่งข้อมูลทันที' }}
      </button>
    </div>

    <!-- Mobile Native App Hero Card + 4 One-Thumb Shortcuts (<= 800px) -->
    <div v-if="activeDashTab === 'overview'" class="mob-only mob-app-home-top">
      <div class="mob-hero-card">
        <div class="mob-hero-head">
          <span class="mob-hero-kicker">ยอดขายสุทธิก่อน VAT</span>
          <div class="mob-hero-pills" role="group" aria-label="เลือกช่วงเวลาด่วน">
            <button
              v-for="p in MOB_PERIODS"
              :key="p.id"
              type="button"
              :class="['mob-hero-pill', { active: quickPeriodId === p.id }]"
              @click="applyQuickPeriod(p.id)"
            >
              {{ p.label }}
            </button>
          </div>
        </div>
        <div class="mob-hero-amount">
          ฿{{ fmt(dashboard?.sales_kpis?.net_sales_before_vat ?? dashboard?.posted_sales_net ?? 0) }}
        </div>
        <div class="mob-hero-stats">
          <div>
            <small>บิลขาย</small>
            <strong>{{ fmt(dashboard?.posted_invoices) }}</strong>
          </div>
          <div>
            <small>เข้าพบ</small>
            <strong>{{ fmt(dashboard?.visit_performance?.period_visits) }}</strong>
          </div>
          <div>
            <small>ลูกหนี้</small>
            <strong>{{ fmt(dashboard?.customers) }}</strong>
          </div>
        </div>
      </div>

      <div class="mob-quick-dock">
        <button type="button" class="mob-dock-item" @click="openVisitWizard()">
          <span class="mob-dock-icon blue">📸</span>
          <span>เช็คอิน</span>
        </button>
        <RouterLink to="/products" class="mob-dock-item">
          <span class="mob-dock-icon amber">📦</span>
          <span>ราคาสินค้า</span>
        </RouterLink>
        <RouterLink to="/map" class="mob-dock-item">
          <span class="mob-dock-icon green">🗺️</span>
          <span>แผนที่</span>
        </RouterLink>
        <RouterLink v-if="isManager" to="/sync" class="mob-dock-item">
          <span class="mob-dock-icon purple">🔄</span>
          <span>ซิงก์ API</span>
        </RouterLink>
        <RouterLink v-else to="/plans" class="mob-dock-item">
          <span class="mob-dock-icon purple">📅</span>
          <span>แผนวันนี้</span>
        </RouterLink>
      </div>
    </div>

    <!-- Slim Scope Strip (Desktop only — mobile already shows role/route in App Bar) -->
    <div class="dash-scope-strip desktop-only">
      <div class="dash-scope-main">
        <span :class="['tag', me?.role === 'executive' ? 'orange' : isManager ? 'purple' : 'blue']">
          {{ me?.role === 'executive' ? '👔 ผู้บริหาร' : isManager ? '👑 เห็นทุกสาย' : '🔒 เฉพาะสายของคุณ' }}
        </span>
        <span v-if="isManager" class="small muted">
          {{ fmt((dashboard?.reservation_by_doc_type || []).length) }} สายใบจอง · ลูกหนี้ {{ fmt(dashboard?.customers) }} ราย
        </span>
        <template v-else>
          <span v-if="boundDocTypes.length" class="tag orange">สาย {{ boundDocTypes.join(', ') }}</span>
          <span v-if="me?.salesperson_code" class="tag green">รหัส {{ me.salesperson_code }}</span>
          <span v-if="!boundDocTypes.length && !me?.salesperson_code" class="small" style="color:var(--orange)">
            ยังไม่ได้ผูกสายใบจอง — กรุณาแจ้งแอดมิน
          </span>
        </template>
      </div>
      <RouterLink v-if="isManager" to="/settings" class="small" style="font-weight:600;white-space:nowrap">
        ⚙️ ตั้งค่าสายขาย →
      </RouterLink>
    </div>

    <!-- Clean 4-Tab Workspace Switcher -->
    <div class="dash-tabs" role="tablist" aria-label="หมวดหมู่แดชบอร์ด">
      <button
        v-for="tab in DASH_TABS"
        :key="tab.id"
        type="button"
        role="tab"
        :aria-selected="String(activeDashTab === tab.id)"
        :class="['dash-tab', { active: activeDashTab === tab.id }]"
        @click="activeDashTab = tab.id"
      >
        {{ tab.label }}
      </button>
    </div>

    <!-- TAB 1: OVERVIEW (CALM & FOCUSED) -->
    <template v-if="activeDashTab === 'overview'">
      <div class="mb-md desktop-only">
        <DateRangePicker v-model:from="dateFrom" v-model:to="dateTo" />
        <p v-if="dashError" class="small mt-sm" style="color:var(--red)" role="alert">โหลดข้อมูลไม่สำเร็จ กรุณากดรีเฟรช</p>
      </div>

      <div class="dash-stack">
        <!-- Today Hero (Desktop Salesperson only — mobile uses mob-hero-card) -->
        <div v-if="!isManager" class="card today-hero desktop-only">
          <div class="today-hero-meta">
            <h3>{{ todayThaiLabel }}</h3>
            <p>แผนวันนี้ {{ fmt(todayPlans.length) }} เส้นทาง · งานค้าง {{ fmt(dueAndOpenTasks.length) }} รายการ</p>
          </div>
          <button type="button" class="btn primary today-visit-btn" @click="openVisitWizard()">
            📸 บันทึกเข้าพบ
          </button>
        </div>

        <!-- 4 Clean KPIs (on mobile, Hero Card already shows Sales & Visits so keep only Leads & Open Tasks) -->
        <SkeletonBlock v-if="(dashLoading || dashFetching) && !dashboard" variant="kpis" />
        <div v-else-if="dashboard && !dashError && validPeriod" class="kpis" style="margin-bottom:0">
          <div class="card kpi desktop-only">
            <div class="kpi-top">
              <div class="kpi-icon purple">📊</div>
              <span class="tag blue">ก่อน VAT</span>
            </div>
            <div class="kpi-label">ยอดขายสุทธิจริง</div>
            <div class="kpi-value">{{ fmt(dashboard?.sales_kpis?.net_sales_before_vat ?? dashboard?.posted_sales_net) }} <span class="kpi-unit">฿</span></div>
            <div class="kpi-foot">{{ fmt(dashboard?.posted_invoices) }} บิลขาย · ลดหนี้ {{ fmt(dashboard?.sales_kpis?.return_308_before_vat) }} ฿</div>
          </div>

          <div class="card kpi desktop-only">
            <div class="kpi-top">
              <div class="kpi-icon green">📍</div>
              <span class="tag green">เดือนนี้ {{ fmt(dashboard?.visit_performance?.visits_this_month) }}</span>
            </div>
            <div class="kpi-label">เข้าพบลูกค้า</div>
            <div class="kpi-value">{{ fmt(dashboard?.visit_performance?.period_visits) }} <span class="kpi-unit">ครั้ง</span></div>
            <div class="kpi-foot">ปิดงานติดตาม {{ fmt(dashboard?.visit_performance?.completed_tasks) }} งาน</div>
          </div>

          <div class="card kpi">
            <div class="kpi-top">
              <div class="kpi-icon">🏪</div>
              <span class="tag green">วันนี้ +{{ fmt(dashboard?.acquisition?.leads_today) }}</span>
            </div>
            <div class="kpi-label">ลูกค้าใหม่</div>
            <div class="kpi-value">{{ fmt(dashboard?.acquisition?.period_leads) }} <span class="kpi-unit">ราย</span></div>
            <div class="kpi-foot">สะสม {{ fmt(dashboard?.acquisition?.total_leads) }} ร้าน · รออนุมัติ {{ fmt(dashboard?.acquisition?.pending_approval) }}</div>
          </div>

          <div class="card kpi">
            <div class="kpi-top">
              <div class="kpi-icon orange">🕒</div>
              <span class="tag orange">ค้างติดตาม</span>
            </div>
            <div class="kpi-label">งานติดตามคงค้าง</div>
            <div class="kpi-value">{{ fmt(dashboard?.visit_performance?.open_tasks) }} <span class="kpi-unit">งาน</span></div>
            <div class="kpi-foot">ลูกหนี้ ERP {{ fmt(dashboard?.customers) }} ราย</div>
          </div>
        </div>

        <!-- Plans & Followups -->
        <div class="middle">
          <div class="card panel-pad">
            <div class="row-between mb-sm">
              <h3 style="margin:0">ร้านที่ต้องไปวันนี้</h3>
              <RouterLink to="/plans" class="small">แผนทั้งหมด →</RouterLink>
            </div>
            <SkeletonBlock v-if="plansLoading && !(plans || []).length" variant="list" :rows="3" />
            <template v-else>
              <div v-for="p in displayPlans" :key="p.id" class="minirow">
                <div>
                  <strong>{{ p.route_name }}</strong>
                  <div class="small muted">
                    {{ p.visit_date === todayIso ? 'วันนี้' : p.visit_date }}
                    <template v-if="p.district"> · {{ p.district }}</template>
                  </div>
                </div>
                <span :class="['tag', p.visit_date === todayIso ? 'blue' : 'gray']">
                  {{ p.visit_date === todayIso ? 'วันนี้' : 'เร็วๆ นี้' }}
                </span>
              </div>
              <div v-if="!displayPlans.length" class="small muted" style="padding:10px 0">
                ยังไม่มีแผนออกตลาดสำหรับวันนี้
              </div>
            </template>
          </div>

          <div class="card panel-pad">
            <div class="row-between mb-sm">
              <h3 style="margin:0">งานติดตามครบกำหนด</h3>
              <RouterLink to="/followups" class="small">ดูทั้งหมด →</RouterLink>
            </div>
            <SkeletonBlock v-if="tasksLoading && !(tasks || []).length" variant="list" :rows="3" />
            <template v-else>
              <div v-for="t in dueAndOpenTasks.slice(0, 4)" :key="t.id" class="minirow">
                <div style="min-width:0;flex:1 1 160px">
                  <strong>{{ t.lead_shop_name || (t.crm_lead_id ? `ร้าน #${t.crm_lead_id}` : 'ลูกค้าทั่วไป') }}</strong>
                  <div class="small muted">{{ t.title }}</div>
                </div>
                <div class="row-actions">
                  <span :class="['tag', taskDueTagClass(t.due_date)]">{{ taskDueText(t.due_date) }}</span>
                  <button type="button" class="btn light" style="min-height:36px;padding:6px 10px;font-size:13px" @click="completeTask(t.id)">✓ ปิด</button>
                </div>
              </div>
              <div v-if="!dueAndOpenTasks.length" class="small muted" style="padding:10px 0">
                ไม่มีงานติดตามที่ครบกำหนด
              </div>
            </template>
          </div>
        </div>

        <!-- Monthly Sales Chart & Recent Activity -->
        <div class="dash-grid" style="margin-bottom:0">
          <div class="card">
            <div class="section-head">
              <div>
                <h3>แนวโน้มยอดขายจริงรายเดือน</h3>
                <small>7 เดือนล่าสุด (ก่อน VAT · หักใบคืนแล้ว)</small>
              </div>
              <span class="tag blue">Bplus</span>
            </div>
            <div class="chart">
              <svg v-if="monthlySalesBars.some((bar) => bar.value !== 0)" viewBox="0 0 650 212" preserveAspectRatio="none" role="img" aria-label="ยอดซื้อสุทธิจริงรายเดือนจาก Bplus">
                <g stroke="#eaf0f7" stroke-width="1"><path d="M0 46H650 M0 88H650 M0 130H650" /></g>
                <line x1="0" y1="162" x2="650" y2="162" stroke="#aeb9c8" stroke-width="1.5" />
                <g v-for="bar in monthlySalesBars" :key="bar.month">
                  <rect :x="bar.x" :y="bar.y" width="58" :height="bar.height" rx="5" :fill="bar.value < 0 ? '#dc4b62' : '#0284c7'">
                    <title>{{ bar.label }} · {{ fmt(bar.value) }} ฿ · {{ bar.saleCount }} บิลขาย / {{ bar.returnCount }} ใบคืน</title>
                  </rect>
                  <text :x="bar.x + 29" y="205" text-anchor="middle" font-size="13" fill="#5f6b80">{{ bar.label }}</text>
                </g>
              </svg>
              <div v-else class="placeholder">ยังไม่มีบิลขายในช่วง 7 เดือนล่าสุด</div>
            </div>
          </div>

          <div class="card">
            <div class="section-head">
              <div>
                <h3>เข้าพบล่าสุด</h3>
                <small>กิจกรรมหน้างานล่าสุด</small>
              </div>
              <RouterLink to="/visits" class="small">ดูทั้งหมด →</RouterLink>
            </div>
            <div class="activity">
              <div v-for="act in (dashboard?.recent_activities || []).slice(0, 5)" :key="act.id" class="activity-row">
                <div class="bullet">📍</div>
                <div>
                  <strong>{{ act.shop_name || 'ร้านค้า' }} · {{ act.result }}</strong>
                  <small>{{ act.staff_name || 'ทีมขาย' }} · {{ act.visited_at ? new Date(act.visited_at).toLocaleString('th-TH') : '' }}</small>
                </div>
              </div>
              <div v-if="!(dashboard?.recent_activities || []).length" class="small muted" style="padding:16px 0">
                ยังไม่มีรายการเข้าพบล่าสุด
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- TAB 2: SALES TARGETS & INACTIVE ALERTS -->
    <template v-else-if="activeDashTab === 'targets'">
      <SalesTargetAndAlertBoard />
    </template>

    <!-- TAB 3: RESERVATION ROUTES & SALESPERSON BREAKDOWN -->
    <template v-else-if="activeDashTab === 'breakdown'">
      <div class="mb-md">
        <DateRangePicker v-model:from="dateFrom" v-model:to="dateTo" />
      </div>

      <div class="dash-stack">
        <div class="middle">
          <div class="card">
            <div class="section-head" style="padding-bottom:12px">
              <div>
                <h3>{{ isManager ? 'ยอดตามสายใบจอง (207)' : 'ใบจองในสายของคุณ' }}</h3>
                <small>แยกตามรหัสใบจองในช่วงที่เลือก</small>
              </div>
              <span class="tag orange">{{ fmt((dashboard?.reservation_by_doc_type || []).length) }} สาย</span>
            </div>

            <div v-if="(dashboard?.reservation_by_doc_type || []).length" class="res-breakdown-list">
              <div v-for="row in dashboard.reservation_by_doc_type" :key="row.doc_type" class="res-breakdown-row">
                <div class="res-breakdown-top">
                  <div class="res-breakdown-title">
                    <span class="tag blue">{{ row.doc_type }}</span>
                    <strong>{{ row.salesperson_name || `สาย ${row.doc_type}` }}</strong>
                  </div>
                  <div class="res-breakdown-amount">
                    <b>{{ fmt(row.total_before_vat) }} ฿</b>
                    <small class="muted">{{ reservationSharePct(row.total_before_vat) }}%</small>
                  </div>
                </div>
                <div class="reportbar">
                  <span :style="{ width: `${Math.max(4, reservationBarPct(row.total_before_vat))}%` }"></span>
                </div>
                <div class="res-breakdown-meta small muted">
                  <span>{{ fmt(row.customer_count) }} ลูกหนี้</span>
                  <span>·</span>
                  <span>{{ fmt(row.doc_count) }} ใบจอง</span>
                </div>
              </div>
            </div>
            <div v-else class="placeholder">ยังไม่มีข้อมูลใบจองในช่วงที่เลือก</div>
          </div>

          <div class="card">
            <div class="section-head" style="padding-bottom:12px">
              <div>
                <h3>{{ isManager ? 'ยอดขายสุทธิจริงตามเซลล์' : 'ยอดขายสุทธิจริงของคุณ' }}</h3>
                <small>ก่อน VAT · หักใบลดหนี้ 308 แล้ว</small>
              </div>
              <span class="tag green">{{ fmt((dashboard?.sales_by_salesperson || []).length) }} คน</span>
            </div>

            <div v-if="(dashboard?.sales_by_salesperson || []).length" class="res-breakdown-list">
              <div
                v-for="sp in dashboard.sales_by_salesperson"
                :key="`${sp.salesperson_code}-${sp.salesperson_name}`"
                class="res-breakdown-row"
              >
                <div class="res-breakdown-top">
                  <div class="res-breakdown-title">
                    <span class="tag gray">{{ sp.salesperson_code }}</span>
                    <strong>{{ sp.salesperson_name || 'ไม่ระบุชื่อ' }}</strong>
                  </div>
                  <div class="res-breakdown-amount">
                    <b style="color:var(--green)">{{ fmt(sp.net_sales_before_vat) }} ฿</b>
                  </div>
                </div>
                <div class="reportbar">
                  <span
                    :style="{
                      width: `${Math.max(4, salespersonBarPct(sp.net_sales_before_vat))}%`,
                      background: 'linear-gradient(90deg, #0f9d7a, #34d399)',
                    }"
                  ></span>
                </div>
                <div class="res-breakdown-meta small muted">
                  <span>{{ fmt(sp.customer_count) }} ร้านซื้อจริง</span>
                  <span>·</span>
                  <span>{{ fmt(sp.invoice_count) }} บิล</span>
                </div>
              </div>
            </div>
            <div v-else class="placeholder">ยังไม่มีข้อมูลยอดขายในช่วงที่เลือก</div>
          </div>
        </div>

        <div class="card">
          <div class="section-head" style="padding-bottom:13px">
            <div>
              <h3>ลูกค้าล่าสุด</h3>
              <small>รายชื่อร้านค้าล่าสุดในระบบ</small>
            </div>
            <RouterLink to="/customers" class="btn light">ดูทั้งหมด →</RouterLink>
          </div>
          <div class="table-wrap desktop-table-only">
            <table class="table">
              <thead>
                <tr><th>รหัส</th><th>ชื่อร้านค้า</th><th>รหัส ERP</th><th>สถานะ</th></tr>
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
                  <td><span class="tag" :class="row.link_status === 'linked' ? 'green' : 'orange'">{{ row.link_status === 'linked' ? 'เชื่อมแล้ว' : 'รอเชื่อม' }}</span></td>
                </tr>
                <tr v-if="!(masterPreview?.data || []).length">
                  <td colspan="4" class="placeholder">ยังไม่มีข้อมูลลูกค้าในระบบ</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="mob-cards panel-pad">
            <div
              v-for="row in (masterPreview?.data || []).slice(0, 5)"
              :key="row.customer_master_id"
              class="card mob-card"
              @click="openDetailDrawer('master', row.customer_master_id)"
            >
              <div class="row-between">
                <b>{{ row.display_name }}</b>
                <span class="tag blue">{{ row.internal_customer_code }}</span>
              </div>
              <div class="small muted mt-sm">{{ row.phone || 'ไม่มีเบอร์' }} · {{ row.erp_customer_code || 'ยังไม่เชื่อม ERP' }}</div>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- TAB 4: CUSTOMER MAP -->
    <template v-else>
      <CustomerMapBoard />
    </template>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useMutation, useQuery } from '@tanstack/vue-query';
import { RouterLink } from 'vue-router';
import PageHeader from '../../../shared/components/PageHeader.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import CustomerMapBoard from '../components/CustomerMapBoard.vue';
import SalesTargetAndAlertBoard from '../components/SalesTargetAndAlertBoard.vue';
import DateRangePicker from '../components/DateRangePicker.vue';
import { toast } from '../../../shared/composables/useUiStore.js';
import {
  crmGet,
  crmMutate,
  crmQueryClient,
  fmt,
  invalidateCrmData,
  isManager,
  me,
  offlineDrafts,
  offlineSyncing,
  openDetailDrawer,
  openQuickAdd,
  openVisitWizard,
  syncPendingOfflineDrafts,
} from '../composables/useCrmSession.js';

const activeDashTab = ref('overview');
const DASH_TABS = [
  { id: 'overview', label: '📊 ภาพรวม' },
  { id: 'targets', label: '🎯 เป้าขาย & ร้านต้องดูแล' },
  { id: 'breakdown', label: '🧾 ยอดตามสาย & เซลล์' },
  { id: 'map', label: '🗺️ แผนที่ลูกค้า' },
];

const nowDate = new Date();
const toIsoDate = (d) =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const todayIso = toIsoDate(nowDate);
const dateFrom = ref(`${nowDate.getFullYear()}-${String(nowDate.getMonth() + 1).padStart(2, '0')}-01`);
const dateTo = ref(todayIso);

const MOB_PERIODS = [
  { id: 'today', label: 'วันนี้' },
  { id: '7d', label: '7 วัน' },
  { id: 'month', label: 'เดือนนี้' },
];

const quickPeriodId = computed(() => {
  const monthStart = `${nowDate.getFullYear()}-${String(nowDate.getMonth() + 1).padStart(2, '0')}-01`;
  const sevenStart = toIsoDate(new Date(nowDate.getFullYear(), nowDate.getMonth(), nowDate.getDate() - 6));
  if (dateFrom.value === todayIso && dateTo.value === todayIso) return 'today';
  if (dateFrom.value === sevenStart && dateTo.value === todayIso) return '7d';
  if (dateFrom.value === monthStart && dateTo.value === todayIso) return 'month';
  return 'custom';
});

function applyQuickPeriod(id) {
  if (id === 'today') {
    dateFrom.value = todayIso;
    dateTo.value = todayIso;
  } else if (id === '7d') {
    dateFrom.value = toIsoDate(new Date(nowDate.getFullYear(), nowDate.getMonth(), nowDate.getDate() - 6));
    dateTo.value = todayIso;
  } else {
    dateFrom.value = `${nowDate.getFullYear()}-${String(nowDate.getMonth() + 1).padStart(2, '0')}-01`;
    dateTo.value = todayIso;
  }
}
const validPeriod = computed(() => !!dateFrom.value && !!dateTo.value && dateFrom.value <= dateTo.value);
const todayThaiLabel = nowDate.toLocaleDateString('th-TH', {
  weekday: 'long',
  day: 'numeric',
  month: 'long',
});

const { data: dashboard, isLoading: dashLoading, isFetching: dashFetching, isError: dashError, refetch } = useQuery({
  queryKey: ['crm', 'dashboard', dateFrom, dateTo],
  queryFn: ({ signal }) => crmGet(`/dashboard?${new URLSearchParams({ from: dateFrom.value, to: dateTo.value })}`, { signal }),
  enabled: validPeriod,
});

const { data: plans, isLoading: plansLoading, refetch: refetchPlans } = useQuery({
  queryKey: ['crm', 'plans'],
  queryFn: ({ signal }) => crmGet('/plans', { signal }),
});

const { data: tasks, isLoading: tasksLoading, refetch: refetchTasks } = useQuery({
  queryKey: ['crm', 'tasks'],
  queryFn: ({ signal }) => crmGet('/tasks', { signal }),
});

const { data: masterPreview, refetch: refetchMaster } = useQuery({
  queryKey: ['crm', 'master', { page: 1, perPage: 10 }],
  queryFn: ({ signal }) => crmGet('/customer-master?per_page=10&page=1', { signal }),
});

const todayPlans = computed(() =>
  (plans.value || []).filter((p) => p.visit_date === todayIso)
);

const displayPlans = computed(() => {
  if (todayPlans.value.length) return todayPlans.value.slice(0, 4);
  return (plans.value || []).filter((p) => p.visit_date && p.visit_date >= todayIso).slice(0, 3);
});

const dueAndOpenTasks = computed(() => {
  const open = (tasks.value || []).filter((t) => t.status !== 'done');
  return [...open].sort((a, b) => {
    const ad = a.due_date || '9999-12-31';
    const bd = b.due_date || '9999-12-31';
    return ad.localeCompare(bd);
  });
});

function taskDueTagClass(dueDate) {
  if (!dueDate) return 'gray';
  if (dueDate < todayIso) return 'red';
  if (dueDate === todayIso) return 'orange';
  return 'blue';
}

function taskDueText(dueDate) {
  if (!dueDate) return 'ไม่ระบุ';
  if (dueDate < todayIso) return 'เลยกำหนด';
  if (dueDate === todayIso) return 'วันนี้';
  return dueDate;
}

const completeMutation = useMutation({
  mutationFn: (id) => crmMutate(`/tasks/${id}/complete`, 'POST', {}),
  onMutate: async (id) => {
    await crmQueryClient.cancelQueries({ queryKey: ['crm', 'tasks'] });
    const prev = crmQueryClient.getQueryData(['crm', 'tasks']);
    crmQueryClient.setQueryData(['crm', 'tasks'], (old) =>
      Array.isArray(old) ? old.map((x) => (x.id === id ? { ...x, status: 'done' } : x)) : old
    );
    return { prev };
  },
  onError: (err, _id, ctx) => {
    if (ctx?.prev) crmQueryClient.setQueryData(['crm', 'tasks'], ctx.prev);
    toast.error('ปิดงานไม่สำเร็จ', err);
  },
  onSuccess: () => {
    toast.success('ปิดงานติดตามแล้ว');
    invalidateCrmData(['dashboard', 'tasks']);
  },
});

function completeTask(id) {
  completeMutation.mutate(id);
}

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

const boundDocTypes = computed(() => {
  const fromDash = dashboard.value?.scope_info?.reservation_doc_types;
  if (Array.isArray(fromDash) && fromDash.length) return fromDash;
  if (Array.isArray(me.value?.reservation_doc_types)) return me.value.reservation_doc_types;
  return [];
});

const totalReservationAmount = computed(() =>
  (dashboard.value?.reservation_by_doc_type || []).reduce(
    (sum, r) => sum + Number(r.total_before_vat || 0),
    0
  )
);

const maxReservationAmount = computed(() =>
  Math.max(1, ...(dashboard.value?.reservation_by_doc_type || []).map((r) => Number(r.total_before_vat || 0)))
);

const maxSalespersonAmount = computed(() =>
  Math.max(1, ...(dashboard.value?.sales_by_salesperson || []).map((s) => Number(s.net_sales_before_vat || 0)))
);

function reservationSharePct(amount) {
  if (!totalReservationAmount.value) return '0.0';
  return ((Number(amount || 0) / totalReservationAmount.value) * 100).toFixed(1);
}

function reservationBarPct(amount) {
  return Math.min(100, (Number(amount || 0) / maxReservationAmount.value) * 100);
}

function salespersonBarPct(amount) {
  return Math.min(100, (Math.max(0, Number(amount || 0)) / maxSalespersonAmount.value) * 100);
}

function refetchAll() {
  invalidateCrmData();
  refetch();
  refetchPlans();
  refetchTasks();
  refetchMaster();
}
</script>

<style scoped>
.mob-app-home-top {
  margin-bottom: 14px;
}

.mob-hero-card {
  border-radius: 24px;
  padding: 18px 18px 15px;
  background: linear-gradient(135deg, #0369a1 0%, #0284c7 52%, #0ea5e9 100%);
  color: #ffffff;
  box-shadow: 0 12px 28px rgba(2, 132, 199, 0.28);
  margin-bottom: 12px;
}

.mob-hero-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 8px;
}

.mob-hero-kicker {
  font-size: 12.5px;
  font-weight: 500;
  opacity: 0.92;
}

.mob-hero-pills {
  display: inline-flex;
  background: rgba(255, 255, 255, 0.18);
  padding: 2px;
  border-radius: 999px;
  gap: 2px;
}

.mob-hero-pill {
  border: 0;
  background: transparent;
  color: rgba(255, 255, 255, 0.92);
  font-size: 11.5px;
  font-weight: 600;
  padding: 4px 9px;
  border-radius: 999px;
}

.mob-hero-pill.active {
  background: #ffffff;
  color: #0369a1;
  box-shadow: 0 2px 6px rgba(15, 23, 42, 0.14);
}

.mob-hero-amount {
  font-size: clamp(26px, 7.5vw, 32px);
  font-weight: 700;
  line-height: 1.2;
  letter-spacing: -0.4px;
  font-variant-numeric: tabular-nums;
}

.mob-hero-stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px solid rgba(255, 255, 255, 0.22);
  text-align: center;
}

.mob-hero-stats small {
  display: block;
  font-size: 11.5px;
  opacity: 0.85;
}

.mob-hero-stats strong {
  display: block;
  font-size: 15px;
  font-weight: 700;
  margin-top: 1px;
}

.mob-quick-dock {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 8px;
}

.mob-dock-item {
  border: 1px solid rgba(226, 232, 240, 0.9);
  background: #ffffff;
  border-radius: 18px;
  padding: 10px 4px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 5px;
  color: #1e293b;
  font-size: 12px;
  font-weight: 600;
  box-shadow: 0 3px 12px rgba(15, 23, 42, 0.04);
  transition: transform 0.14s ease;
}

.mob-dock-item:active {
  transform: scale(0.95);
}

.mob-dock-icon {
  width: 38px;
  height: 38px;
  border-radius: 13px;
  display: grid;
  place-items: center;
  font-size: 19px;
}

.mob-dock-icon.blue { background: #e0f2fe; }
.mob-dock-icon.amber { background: #fef3c7; }
.mob-dock-icon.green { background: #dcfce7; }
.mob-dock-icon.purple { background: #ede9fe; }

.dash-scope-strip {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px 14px;
  flex-wrap: wrap;
  margin-bottom: 12px;
  padding: 2px 2px;
}

.dash-scope-main {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.dash-tabs {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  padding: 4px;
  margin-bottom: 16px;
  background: #ffffff;
  border: 1px solid var(--border);
  border-radius: 14px;
}

.dash-tab {
  border: 0;
  background: transparent;
  color: #475569;
  font-size: 13.5px;
  font-weight: 600;
  padding: 9px 15px;
  border-radius: 10px;
  white-space: nowrap;
  flex-shrink: 0;
  transition: background-color 0.14s ease, color 0.14s ease;
}

.dash-tab:hover {
  background: #f1f5f9;
  color: #0f172a;
}

.dash-tab.active {
  background: var(--blue);
  color: #ffffff;
}

.res-breakdown-list {
  max-height: 380px;
  overflow: auto;
  padding: 4px 20px 18px;
}

.res-breakdown-row {
  padding: 11px 0;
  border-bottom: 1px solid #edf1f6;
}

.res-breakdown-row:last-child {
  border-bottom: 0;
}

.res-breakdown-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
}

.res-breakdown-title {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  min-width: 0;
}

.res-breakdown-amount {
  display: flex;
  align-items: baseline;
  gap: 6px;
  flex-shrink: 0;
}

.res-breakdown-meta {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
</style>
