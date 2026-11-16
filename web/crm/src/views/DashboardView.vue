<template>
  <div>
    <PageHeader
      :title="isManager ? 'ภาพรวมฝ่ายขาย' : 'งานวันนี้'"
      :subtitle="isManager ? 'ลูกค้าใหม่ การเข้าพบ งานค้าง และยอดขายก่อน VAT' : 'ร้านที่ต้องไปวันนี้ งานติดตามครบกำหนด และบันทึกเข้าพบ'"
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

    <div v-if="offlineDrafts.length" class="notice" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
      <span>มีข้อมูลเข้าพบค้างส่ง <b>{{ offlineDrafts.length }}</b> รายการ</span>
      <button type="button" class="btn primary" :disabled="offlineSyncing" @click="syncPendingOfflineDrafts">
        {{ offlineSyncing ? 'กำลังส่ง...' : 'ส่งข้อมูลทันที' }}
      </button>
    </div>

    <!-- Role & Reservation Binding Scope Banner -->
    <div class="card dash-scope-bar">
      <div class="dash-scope-main">
        <span :class="['tag', me?.role === 'executive' ? 'orange' : isManager ? 'purple' : 'blue']">
          {{
            me?.role === 'executive'
              ? '👔 ผู้บริหาร (ดูข้อมูลทุกคน · แก้ไขระบบไม่ได้)'
              : isManager
                ? '👑 แอดมิน / ผู้จัดการ (เห็นทั้งหมด)'
                : '🔒 ข้อมูลเฉพาะสายของคุณ'
          }}
        </span>
        <template v-if="isManager">
          <span class="small">
            แสดงยอดขาย ลูกหนี้ และใบจองครบทุกสายในระบบ ({{ fmt((dashboard?.reservation_by_doc_type || []).length) }} สายใบจอง · {{ fmt(dashboard?.customers) }} ลูกหนี้)
          </span>
        </template>
        <template v-else>
          <span v-if="boundDocTypes.length" class="tag orange">
            สายใบจอง: {{ boundDocTypes.join(', ') }}
          </span>
          <span v-if="me?.salesperson_code" class="tag green">
            รหัสเซลล์: {{ me.salesperson_code }}
          </span>
          <span v-if="boundDocTypes.length || me?.salesperson_code" class="small">
            เห็นเฉพาะลูกหนี้ในสายของคุณ <b>{{ fmt(dashboard?.customers) }}</b> ราย และยอดขายของคุณเท่านั้น
          </span>
          <span v-else class="small" style="color:var(--orange)">
            บัญชีนี้ยังไม่ได้ผูกสายใบจองหรือรหัสเซลล์ กรุณาแจ้งแอดมินให้ผูกในหน้าตั้งค่า
          </span>
        </template>
      </div>
      <RouterLink v-if="isManager" to="/settings" class="btn light dash-scope-btn">
        ⚙️ ผูกใบจองให้เซลล์ →
      </RouterLink>
    </div>

    <div class="dash-stack">
      <!-- Today Field Focus (topmost for salespeople, below analytics for managers) -->
      <section :class="['today-work-section', { 'order-first': !isManager }]" aria-label="งานวันนี้">
        <div v-if="!isManager" class="card today-hero">
          <div class="today-hero-meta">
            <h3>{{ todayThaiLabel }}</h3>
            <p>แผนวันนี้ {{ fmt(todayPlans.length) }} เส้นทาง · งานติดตามค้าง {{ fmt(dueAndOpenTasks.length) }} รายการ</p>
          </div>
          <button type="button" class="btn primary today-visit-btn" @click="openVisitWizard()">
            📸 บันทึกเข้าพบ
          </button>
        </div>

        <div class="middle">
          <div class="card panel-pad">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px">
              <div>
                <h3 style="margin:0">ร้านที่ต้องไปวันนี้</h3>
                <small class="muted">จากแผนออกตลาด ({{ todayIso }})</small>
              </div>
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
                    <template v-if="p.note"> · {{ p.note }}</template>
                  </div>
                </div>
                <span :class="['tag', p.visit_date === todayIso ? 'blue' : 'gray']">
                  {{ p.visit_date === todayIso ? 'วันนี้' : 'เร็วๆ นี้' }}
                </span>
              </div>
              <div v-if="!displayPlans.length" class="small muted" style="padding:12px 0">
                ยังไม่มีแผนออกตลาดสำหรับวันนี้
              </div>
              <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
                <RouterLink to="/map" class="btn light">🗺️ เปิดแผนที่ร้าน</RouterLink>
                <RouterLink to="/plans" class="btn">📅 ดูตารางแผน</RouterLink>
              </div>
            </template>
          </div>

          <div class="card panel-pad">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px">
              <div>
                <h3 style="margin:0">งานติดตามที่ครบกำหนด</h3>
                <small class="muted">งานที่ต้องโทรหรือกลับไปเยี่ยมร้าน</small>
              </div>
              <RouterLink to="/followups" class="small">ดูทั้งหมด →</RouterLink>
            </div>

            <SkeletonBlock v-if="tasksLoading && !(tasks || []).length" variant="list" :rows="3" />
            <template v-else>
              <div v-for="t in dueAndOpenTasks.slice(0, 5)" :key="t.id" class="minirow">
                <div style="min-width:0">
                  <strong>{{ t.lead_shop_name || (t.crm_lead_id ? `ร้าน #${t.crm_lead_id}` : 'ลูกค้าทั่วไป') }}</strong>
                  <div class="small muted">
                    {{ t.title }} · กำหนด {{ t.due_date || 'ไม่ระบุ' }}
                  </div>
                </div>
                <div style="display:flex;align-items:center;gap:6px;flex-shrink:0">
                  <span :class="['tag', taskDueTagClass(t.due_date)]">{{ taskDueText(t.due_date) }}</span>
                  <button type="button" class="btn light" @click="completeTask(t.id)">✓ ปิดงาน</button>
                </div>
              </div>
              <div v-if="!dueAndOpenTasks.length" class="small muted" style="padding:12px 0">
                ไม่มีงานติดตามที่ครบกำหนดวันนี้
              </div>
            </template>
          </div>
        </div>
      </section>

      <!-- KPIs + Bplus Sales Chart + Recent Visits + Recent Customers -->
      <section class="today-work-section" aria-label="สรุปตัวเลขและยอดขาย">
        <SkeletonBlock v-if="dashLoading && !dashboard" variant="kpis" />

        <div v-else class="kpis">
          <div class="card kpi">
            <div class="kpi-top">
              <div class="kpi-icon">🏪</div>
              <span class="tag green">วันนี้ +{{ fmt(dashboard?.acquisition?.leads_today) }}</span>
            </div>
            <div class="kpi-label">ลูกค้าใหม่เดือนนี้</div>
            <div class="kpi-value">{{ fmt(dashboard?.acquisition?.leads_this_month ?? dashboard?.acquisition?.total_leads) }} <span class="kpi-unit">ราย</span></div>
            <div class="kpi-foot">สะสมทั้งหมด {{ fmt(dashboard?.acquisition?.total_leads) }} ร้าน (รออนุมัติ {{ fmt(dashboard?.acquisition?.pending_approval) }})</div>
          </div>

          <div class="card kpi">
            <div class="kpi-top">
              <div class="kpi-icon green">📍</div>
              <span class="tag green">เดือนนี้ {{ fmt(dashboard?.visit_performance?.visits_this_month) }}</span>
            </div>
            <div class="kpi-label">เข้าพบลูกค้าสะสม</div>
            <div class="kpi-value">{{ fmt(dashboard?.visit_performance?.total_visits) }} <span class="kpi-unit">ครั้ง</span></div>
            <div class="kpi-foot">เสร็จสิ้นติดตาม {{ fmt(dashboard?.visit_performance?.completed_tasks) }} งาน</div>
          </div>

          <div class="card kpi">
            <div class="kpi-top">
              <div class="kpi-icon orange">🕒</div>
              <span class="tag orange">ต้องติดตาม</span>
            </div>
            <div class="kpi-label">งานติดตามที่ค้าง</div>
            <div class="kpi-value">{{ fmt(dashboard?.visit_performance?.open_tasks) }} <span class="kpi-unit">งาน</span></div>
            <div class="kpi-foot">ข้อมูลรอเติม {{ fmt(dashboard?.acquisition?.minimal_drafts) }} ร้าน</div>
          </div>

          <div class="card kpi">
            <div class="kpi-top">
              <div class="kpi-icon purple">📊</div>
              <span class="tag blue">Bplus ERP</span>
            </div>
            <div class="kpi-label">ยอดซื้อสุทธิจริงก่อน VAT ({{ conversionRate }}% เชื่อม ERP)</div>
            <div class="kpi-value">{{ fmt(dashboard?.sales_kpis?.net_sales_before_vat ?? dashboard?.posted_sales_net) }} <span class="kpi-unit">฿</span></div>
            <div class="kpi-foot">ลูกค้า ERP {{ fmt(dashboard?.customers) }} ราย · บิลขาย {{ fmt(dashboard?.posted_invoices) }} ใบ · ลดหนี้ 308: {{ fmt(dashboard?.sales_kpis?.return_308_before_vat) }} ฿</div>
          </div>
        </div>

        <CustomerMapBoard />

        <!-- Reservation (ใบจอง 207) & Salesperson Breakdown (Admin sees all; Sales sees only own) -->
        <div class="middle">
          <div class="card">
            <div class="section-head" style="padding-bottom:12px">
              <div>
                <h3>{{ isManager ? 'สรุปยอดตามสายใบจอง (Bplus 207)' : 'สรุปใบจองและลูกหนี้ในสายของคุณ' }}</h3>
                <small>{{ isManager ? 'แยกตามรหัสใบจอง จำนวนลูกหนี้ในสาย และยอดใบจองสะสม' : 'แสดงเฉพาะสายใบจองและลูกหนี้ที่ผูกกับบัญชีของคุณ' }}</small>
              </div>
              <span class="tag orange">{{ fmt((dashboard?.reservation_by_doc_type || []).length) }} สายใบจอง</span>
            </div>

            <div v-if="(dashboard?.reservation_by_doc_type || []).length" class="res-breakdown-list">
              <div
                v-for="row in dashboard.reservation_by_doc_type"
                :key="row.doc_type"
                class="res-breakdown-row"
              >
                <div class="res-breakdown-top">
                  <div class="res-breakdown-title">
                    <span class="tag blue">{{ row.doc_type }}</span>
                    <strong>ใบจองสินค้า {{ row.doc_type }}</strong>
                    <span v-if="row.salesperson_name" class="small muted">({{ row.salesperson_name }})</span>
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
                  <span>ลูกหนี้ในใบจอง <b>{{ fmt(row.customer_count) }}</b> ราย</span>
                  <span>·</span>
                  <span>จำนวนใบจอง <b>{{ fmt(row.doc_count) }}</b> ใบ</span>
                </div>
              </div>
            </div>
            <div v-else class="placeholder">
              {{ isManager ? 'ยังไม่มีข้อมูลใบจอง 207' : 'ยังไม่มีใบจองที่ผูกกับบัญชีของคุณ' }}
            </div>
          </div>

          <div class="card">
            <div class="section-head" style="padding-bottom:12px">
              <div>
                <h3>{{ isManager ? 'สรุปยอดขายสุทธิจริงตามพนักงานขาย' : 'ยอดขายสุทธิจริงของคุณ' }}</h3>
                <small>ยอดขายจริงก่อน VAT (หักใบลดหนี้ 308 แล้ว · ไม่รวมใบจอง)</small>
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
                  <span>ลูกหนี้ซื้อจริง <b>{{ fmt(sp.customer_count) }}</b> ราย</span>
                  <span>·</span>
                  <span>บิลขาย/คืน <b>{{ fmt(sp.invoice_count) }}</b> ใบ</span>
                </div>
              </div>
            </div>
            <div v-else class="placeholder">ยังไม่มีข้อมูลยอดขายในขอบเขตของคุณ</div>
          </div>
        </div>

        <div class="dash-grid">
          <div class="card">
            <div class="section-head">
              <div>
                <h3>ยอดซื้อสุทธิจริงจาก Bplus รายเดือน</h3>
                <small>ยอดก่อน VAT หลังหักใบคืน: {{ fmt(dashboard?.sales_kpis?.net_sales_before_vat) }} ฿ · VAT {{ fmt(dashboard?.sales_kpis?.vat_amount) }} ฿ · รวม VAT {{ fmt(dashboard?.sales_kpis?.gross_amount) }} ฿</small>
              </div>
              <span class="tag blue">รายเดือน</span>
            </div>
            <div class="chart">
              <svg v-if="monthlySalesBars.some((bar) => bar.value !== 0)" viewBox="0 0 650 212" preserveAspectRatio="none" role="img" aria-label="ยอดซื้อสุทธิจริงรายเดือนจาก Bplus">
                <g stroke="#eaf0f7" stroke-width="1"><path d="M0 46H650 M0 88H650 M0 130H650" /></g>
                <line x1="0" y1="162" x2="650" y2="162" stroke="#aeb9c8" stroke-width="1.5" />
                <g v-for="bar in monthlySalesBars" :key="bar.month">
                  <rect :x="bar.x" :y="bar.y" width="58" :height="bar.height" rx="5" :fill="bar.value < 0 ? '#dc4b62' : '#2f63e8'">
                    <title>{{ bar.label }} · {{ fmt(bar.value) }} ฿ · {{ bar.saleCount }} บิลขาย / {{ bar.returnCount }} ใบคืน</title>
                  </rect>
                  <text :x="bar.x + 29" y="205" text-anchor="middle" font-size="13" fill="#5f6b80">{{ bar.label }}</text>
                </g>
              </svg>
              <div v-else class="placeholder">ยังไม่มีใบขายจริงหรือใบคืนในช่วง 7 เดือนล่าสุด</div>
            </div>
            <div class="legend">
              <i></i> ยอดซื้อสุทธิจริงก่อน VAT ({{ fmt(dashboard?.sales_kpis?.net_sales_before_vat) }} ฿) · รวมใบคืนแล้ว &nbsp;
              <i style="background:var(--red)"></i> เดือนที่ยอดสุทธิติดลบ &nbsp;
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
                  <strong>พร้อมบันทึกงานออกตลาด</strong>
                  <small>กด "＋ เพิ่มร้านใหม่" หรือ "📸 บันทึกเข้าพบ" เพื่อเริ่มต้นทันที</small>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="middle">
          <div class="card">
            <div class="section-head" style="padding-bottom:13px">
              <div>
                <h3>ลูกค้าล่าสุด</h3>
                <small>รายชื่อร้านค้าในระบบและสถานะเชื่อม ERP</small>
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
                <h3 style="margin:0">ทางลัดออกตลาด</h3>
                <small class="muted">เครื่องมือสำหรับทีมขายออกตลาด</small>
              </div>
            </div>
            <div style="display:grid;gap:10px">
              <button type="button" class="btn primary" style="justify-content:flex-start;padding:12px 14px" @click="openQuickAdd()">
                🏪 ＋ เพิ่มร้านใหม่
              </button>
              <button type="button" class="btn light" style="justify-content:flex-start;padding:12px 14px" @click="openVisitWizard()">
                📸 บันทึกเข้าพบ
              </button>
              <RouterLink to="/map" class="btn" style="justify-content:flex-start;padding:12px 14px">
                🗺️ เปิดแผนที่ร้าน &amp; ปักหมุด GPS
              </RouterLink>
              <RouterLink to="/followups" class="btn" style="justify-content:flex-start;padding:12px 14px">
                ✅ งานติดตามผลคงค้าง ({{ fmt(dashboard?.visit_performance?.open_tasks) }})
              </RouterLink>
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { useMutation, useQuery } from '@tanstack/vue-query';
import { RouterLink } from 'vue-router';
import PageHeader from '../../../shared/components/PageHeader.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import CustomerMapBoard from '../components/CustomerMapBoard.vue';
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

const nowDate = new Date();
const todayIso = `${nowDate.getFullYear()}-${String(nowDate.getMonth() + 1).padStart(2, '0')}-${String(nowDate.getDate()).padStart(2, '0')}`;
const todayThaiLabel = nowDate.toLocaleDateString('th-TH', {
  weekday: 'long',
  day: 'numeric',
  month: 'long',
});

const { data: dashboard, isLoading: dashLoading, refetch } = useQuery({
  queryKey: ['crm', 'dashboard'],
  queryFn: ({ signal }) => crmGet('/dashboard', { signal }),
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
  if (todayPlans.value.length) return todayPlans.value.slice(0, 5);
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
  if (!dueDate) return 'ไม่ระบุวัน';
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

const conversionRate = computed(() => {
  const total = Number(dashboard.value?.acquisition?.total_leads || 0);
  const conv = Number(dashboard.value?.acquisition?.converted_leads || 0);
  return total ? ((conv / total) * 100).toFixed(1) : '0.0';
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
.dash-scope-bar {
  padding: 13px 18px;
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  background: linear-gradient(135deg, #f5f8ff 0%, #ffffff 100%);
  border-left: 4px solid var(--blue);
}

.dash-scope-main {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.dash-scope-btn {
  padding: 7px 12px;
  min-height: 36px;
  font-size: 13px;
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
