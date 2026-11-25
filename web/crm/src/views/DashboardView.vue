<template>
  <div>
    <!-- ===================================================== -->
    <!-- MOBILE NATIVE APP HOME SCREEN (<= 800px — Matches Mockup) -->
    <!-- ===================================================== -->
    <div class="mob-only mob-app-shell">
      <div v-if="offlineDrafts.length" class="notice row-between">
        <span
          >มีข้อมูลเข้าพบค้างส่ง <b>{{ offlineDrafts.length }}</b> รายการ</span
        >
        <button type="button" class="btn primary" :disabled="offlineSyncing" @click="syncPendingOfflineDrafts">
          {{ offlineSyncing ? 'กำลังส่ง...' : 'ส่งทันที' }}
        </button>
      </div>

      <!-- Native Bank-App Style Hero Card -->
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

        <div class="mob-hero-progress">
          <div class="mob-hero-progress-head">
            <span>เทียบเป้ารายเดือน</span>
            <b>{{ heroTargetPct != null ? `${heroTargetPct}%` : 'รอตั้งเป้า' }}</b>
          </div>
          <div class="mob-hero-track">
            <div
              class="mob-hero-fill"
              :style="{ width: `${heroTargetPct != null ? Math.min(100, Math.max(4, heroTargetPct)) : 0}%` }"
            ></div>
          </div>
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

      <!-- 4 One-Thumb Native App Icon Shortcuts -->
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

      <!-- Due Follow-Ups (iOS Grouped List with circular check button) -->
      <section class="mob-section">
        <div class="app-section-head">
          <h3>งานต้องติดตามวันนี้</h3>
          <RouterLink to="/followups" class="app-section-link">{{ fmt(dueAndOpenTasks.length) }} งานค้าง ›</RouterLink>
        </div>
        <div class="app-group-list">
          <div v-for="t in dueAndOpenTasks.slice(0, 4)" :key="`mt-${t.id}`" class="app-list-row">
            <button type="button" class="mob-check-circle" :aria-label="`ปิดงาน ${t.title}`" @click="completeTask(t.id)">
              ✓
            </button>
            <div class="app-list-body">
              <strong class="app-list-title">{{
                t.lead_shop_name || (t.crm_lead_id ? `ร้าน #${t.crm_lead_id}` : 'ลูกค้าทั่วไป')
              }}</strong>
              <span class="app-list-sub">{{ t.title }}</span>
            </div>
            <span :class="['tag', taskDueTagClass(t.due_date)]">{{ taskDueText(t.due_date) }}</span>
          </div>
          <div v-if="!dueAndOpenTasks.length" class="mob-empty-row">✨ ไม่มีงานติดตามค้างวันนี้</div>
        </div>
      </section>

      <!-- Horizontal Swipeable Urgent Stores Carousel -->
      <section class="mob-section">
        <div class="app-section-head">
          <h3>ร้านควรเข้าพบด่วน</h3>
          <RouterLink to="/customers" class="app-section-link">ดูทั้งหมด ›</RouterLink>
        </div>
        <div v-if="urgentStores.length" class="mob-h-scroll">
          <button
            v-for="store in urgentStores"
            :key="`us-${store.id}`"
            type="button"
            :class="['mob-alert-card', store.days_since_purchase >= 30 ? 'warn' : 'info']"
            @click="selectedQuickStore = store"
          >
            <div class="mob-alert-top">
              <span :class="['tag', store.days_since_purchase >= 30 ? 'red' : 'orange']">
                {{ store.days_since_purchase >= 30 ? `หยุดซื้อ ${store.days_since_purchase} วัน` : 'ยังไม่เคยเข้าพบ' }}
              </span>
              <span class="small muted">{{ store.province || '—' }}</span>
            </div>
            <strong class="mob-alert-name">{{ store.customer_name }}</strong>
            <div class="small muted">ยอดสะสม ฿{{ fmt(store.total_sales_net) }} · แตะเพื่อเช็คอิน</div>
          </button>
        </div>
        <div v-else class="app-group-list">
          <div class="mob-empty-row">ไม่มีร้านค้างการดูแลในขณะนี้</div>
        </div>
      </section>

      <!-- Today's Route (Compact iOS Grouped List if any) -->
      <section v-if="displayPlans.length" class="mob-section">
        <div class="app-section-head">
          <h3>แผนเดินรถวันนี้</h3>
          <RouterLink to="/plans" class="app-section-link">ดูทั้งหมด ›</RouterLink>
        </div>
        <div class="app-group-list">
          <RouterLink v-for="p in displayPlans.slice(0, 3)" :key="`mp-${p.id}`" to="/plans" class="app-list-row">
            <span class="app-icon-box">📅</span>
            <div class="app-list-body">
              <strong class="app-list-title">{{ p.route_name }}</strong>
              <span class="app-list-sub"
                >{{ p.visit_date === todayIso ? 'วันนี้' : p.visit_date }}{{ p.district ? ` · ${p.district}` : '' }}</span
              >
            </div>
            <span class="app-chevron">›</span>
          </RouterLink>
        </div>
      </section>

      <!-- Quick Mobile Jump to Analytics / Targets -->
      <div class="app-group-list">
        <button type="button" class="app-list-row" @click="mobileAnalyticsSheet = 'targets'">
          <span class="app-icon-box amber">🎯</span>
          <div class="app-list-body">
            <strong class="app-list-title">เป้าขายรายเดือน &amp; แจ้งเตือน</strong>
            <span class="app-list-sub">ดูเป้ายอดขายรายเซลล์และร้านหยุดซื้อ</span>
          </div>
          <span class="app-chevron">›</span>
        </button>
        <button type="button" class="app-list-row" @click="mobileAnalyticsSheet = 'breakdown'">
          <span class="app-icon-box green">🧾</span>
          <div class="app-list-body">
            <strong class="app-list-title">ยอดตามสายใบจอง &amp; เซลล์</strong>
            <span class="app-list-sub">สรุปใบจอง 207 และยอดขายแต่ละคน</span>
          </div>
          <span class="app-chevron">›</span>
        </button>
      </div>

      <!-- Slide-up Quick Store 360 Sheet (Matches Mockup) -->
      <MobileBottomSheet
        :open="Boolean(selectedQuickStore)"
        :title="selectedQuickStore?.customer_name || 'ข้อมูลร้านค้า'"
        @close="selectedQuickStore = null"
      >
        <div v-if="selectedQuickStore" class="mob-store-sheet">
          <div class="row-actions mb-sm">
            <span class="tag blue">{{ selectedQuickStore.erp_customer_code }}</span>
            <span class="small muted">📍 {{ selectedQuickStore.province || 'ไม่ระบุจังหวัด' }}</span>
          </div>
          <div class="mob-sheet-metrics">
            <div class="mob-sheet-box">
              <small class="muted">ยอดซื้อสะสม</small>
              <strong style="color: var(--green)">฿{{ fmt(selectedQuickStore.total_sales_net) }}</strong>
            </div>
            <div class="mob-sheet-box">
              <small class="muted">จำนวนบิล</small>
              <strong>{{ fmt(selectedQuickStore.sale_invoice_count) }} บิล</strong>
            </div>
          </div>
          <div class="mob-sheet-3btns">
            <a
              v-if="selectedQuickStore.phone"
              :href="`tel:${cleanPhone(selectedQuickStore.phone)}`"
              class="btn"
              style="background: #f0fdf4; border-color: #bbf7d0; color: #15803d"
            >
              📞 โทร
            </a>
            <button v-else type="button" class="btn" disabled>📞 ไม่มีเบอร์</button>
            <button
              type="button"
              class="btn primary"
              @click="
                openVisitWizard({ kind: 'erp', id: Number(selectedQuickStore.id) });
                selectedQuickStore = null;
              "
            >
              📸 เช็คอิน
            </button>
            <RouterLink
              :to="`/customer-360/${selectedQuickStore.id}`"
              class="btn light"
              @click="selectedQuickStore = null"
            >
              🔍 360°
            </RouterLink>
          </div>
        </div>
      </MobileBottomSheet>

      <!-- Slide-up Analytics Sheet on Mobile (Targets / Breakdown) -->
      <MobileBottomSheet
        :open="mobileAnalyticsSheet !== ''"
        :title="mobileAnalyticsSheet === 'targets' ? '🎯 เป้าขาย & ร้านต้องดูแล' : '🧾 ยอดตามสาย & เซลล์'"
        @close="mobileAnalyticsSheet = ''"
      >
        <SalesTargetAndAlertBoard v-if="mobileAnalyticsSheet === 'targets'" />
        <div v-else-if="mobileAnalyticsSheet === 'breakdown'" class="stack-md">
          <div class="app-group-list">
            <div
              v-for="row in dashboard?.reservation_by_doc_type || []"
              :key="`mb-${row.doc_type}`"
              class="app-list-row"
            >
              <span class="tag blue">{{ row.doc_type }}</span>
              <div class="app-list-body">
                <strong class="app-list-title">{{ row.salesperson_name || `สาย ${row.doc_type}` }}</strong>
                <span class="app-list-sub">{{ fmt(row.customer_count) }} ลูกหนี้ · {{ fmt(row.doc_count) }} ใบจอง</span>
              </div>
              <b style="color: var(--blue); white-space: nowrap">฿{{ fmt(row.total_before_vat) }}</b>
            </div>
          </div>
        </div>
      </MobileBottomSheet>
    </div>

    <!-- ===================================================== -->
    <!-- DESKTOP EXECUTIVE WORKSPACE (> 800px)                 -->
    <!-- ===================================================== -->
    <div class="desktop-only">
      <div class="exec-head">
        <div class="exec-head-left">
          <div class="exec-title-row">
            <h2>{{ isManager ? 'ภาพรวมฝ่ายขาย' : 'งานวันนี้' }}</h2>
            <span :class="['tag', me?.role === 'executive' ? 'orange' : isManager ? 'purple' : 'blue']">
              {{ me?.role === 'executive' ? 'ผู้บริหาร' : isManager ? 'เห็นทุกสาย' : 'เฉพาะสายของคุณ' }}
            </span>
            <span v-if="isManager" class="small muted">
              {{ fmt((dashboard?.reservation_by_doc_type || []).length) }} สายใบจอง · ลูกหนี้
              {{ fmt(dashboard?.customers) }} ราย
            </span>
            <template v-else>
              <span v-if="boundDocTypes.length" class="tag orange">สาย {{ boundDocTypes.join(', ') }}</span>
              <span v-if="me?.salesperson_code" class="tag green">รหัส {{ me.salesperson_code }}</span>
            </template>
          </div>
          <p class="exec-subtitle">
            {{
              isManager
                ? 'สรุปยอดขายจริงก่อน VAT การเข้าพบ และความคืบหน้าของทีมขาย'
                : 'แผนออกตลาด งานติดตาม และยอดขายในสายของคุณ'
            }}
          </p>
        </div>

        <div class="exec-head-actions">
          <RouterLink v-if="isManager" to="/settings" class="btn">⚙️ สายขาย</RouterLink>
          <button class="btn" type="button" title="รีเฟรชข้อมูล" @click="refetchAll">⟳ รีเฟรช</button>
          <button class="btn primary" type="button" @click="isManager ? openQuickAdd() : openVisitWizard()">
            {{ isManager ? '＋ เพิ่มร้านใหม่' : '📸 บันทึกเข้าพบ' }}
          </button>
        </div>
      </div>

      <div v-if="offlineDrafts.length" class="notice row-between">
        <span
          >มีข้อมูลเข้าพบค้างส่ง <b>{{ offlineDrafts.length }}</b> รายการ</span
        >
        <button type="button" class="btn primary" :disabled="offlineSyncing" @click="syncPendingOfflineDrafts">
          {{ offlineSyncing ? 'กำลังส่ง...' : 'ส่งข้อมูลทันที' }}
        </button>
      </div>

      <!-- Unified Underline Tabs + Inline Date Range Bar -->
      <div class="exec-nav-bar">
        <div class="exec-tabs" role="tablist" aria-label="หมวดหมู่แดชบอร์ด">
          <button
            v-for="tab in DASH_TABS"
            :key="tab.id"
            type="button"
            role="tab"
            :aria-selected="String(activeDashTab === tab.id)"
            :class="['exec-tab', { active: activeDashTab === tab.id }]"
            @click="activeDashTab = tab.id"
          >
            {{ tab.label }}
          </button>
        </div>
        <div v-if="activeDashTab === 'overview' || activeDashTab === 'breakdown'" class="exec-nav-filter">
          <DateRangePicker v-model:from="dateFrom" v-model:to="dateTo" inline />
        </div>
      </div>

      <p v-if="dashError" class="error" role="alert">โหลดข้อมูลไม่สำเร็จ กรุณากดรีเฟรชอีกครั้ง</p>

      <!-- TAB 1: OVERVIEW -->
      <template v-if="activeDashTab === 'overview'">
        <div class="dash-stack">
          <SkeletonBlock v-if="(dashLoading || dashFetching) && !dashboard" variant="kpis" />
          <div v-else-if="dashboard && !dashError && validPeriod" class="kpis" style="margin-bottom: 0">
            <div class="card kpi kpi-primary-accent">
              <div class="kpi-top">
                <span class="kpi-label" style="margin: 0">ยอดขายสุทธิจริง (ก่อน VAT)</span>
                <span class="tag blue">Bplus</span>
              </div>
              <div class="kpi-value" style="margin-top: 8px">
                ฿{{ fmt(dashboard?.sales_kpis?.net_sales_before_vat ?? dashboard?.posted_sales_net) }}
              </div>
              <div class="kpi-foot">
                {{ fmt(dashboard?.posted_invoices) }} บิลขาย · ลดหนี้ ฿{{
                  fmt(dashboard?.sales_kpis?.return_308_before_vat)
                }}
              </div>
            </div>

            <div class="card kpi">
              <div class="kpi-top">
                <span class="kpi-label" style="margin: 0">เข้าพบลูกค้า</span>
                <span class="tag green">เดือนนี้ {{ fmt(dashboard?.visit_performance?.visits_this_month) }}</span>
              </div>
              <div class="kpi-value" style="margin-top: 8px">
                {{ fmt(dashboard?.visit_performance?.period_visits) }} <span class="kpi-unit">ครั้ง</span>
              </div>
              <div class="kpi-foot">ปิดงานติดตามแล้ว {{ fmt(dashboard?.visit_performance?.completed_tasks) }} งาน</div>
            </div>

            <div class="card kpi">
              <div class="kpi-top">
                <span class="kpi-label" style="margin: 0">ลูกค้าใหม่ (Leads)</span>
                <span class="tag green">วันนี้ +{{ fmt(dashboard?.acquisition?.leads_today) }}</span>
              </div>
              <div class="kpi-value" style="margin-top: 8px">
                {{ fmt(dashboard?.acquisition?.period_leads) }} <span class="kpi-unit">ราย</span>
              </div>
              <div class="kpi-foot">
                สะสม {{ fmt(dashboard?.acquisition?.total_leads) }} ร้าน · รออนุมัติ
                {{ fmt(dashboard?.acquisition?.pending_approval) }}
              </div>
            </div>

            <div class="card kpi">
              <div class="kpi-top">
                <span class="kpi-label" style="margin: 0">งานติดตามคงค้าง</span>
                <span :class="['tag', Number(dashboard?.visit_performance?.open_tasks || 0) > 0 ? 'orange' : 'gray']">
                  {{ Number(dashboard?.visit_performance?.open_tasks || 0) > 0 ? 'รอดำเนินการ' : 'ครบแล้ว' }}
                </span>
              </div>
              <div class="kpi-value" style="margin-top: 8px">
                {{ fmt(dashboard?.visit_performance?.open_tasks) }} <span class="kpi-unit">งาน</span>
              </div>
              <div class="kpi-foot">ลูกหนี้ ERP ใช้งาน {{ fmt(dashboard?.customers) }} ราย</div>
            </div>
          </div>

          <!-- Primary Visual: Upgraded Monthly Sales Chart + Recent Activities -->
          <div class="dash-grid" style="margin-bottom: 0">
            <div class="card exec-chart-card">
              <div class="section-head">
                <div>
                  <h3>แนวโน้มยอดขายสุทธิรายเดือน</h3>
                  <small>7 เดือนล่าสุด · ยอดขายจริงก่อน VAT (หักใบลดหนี้ 308 แล้ว · ไม่รวมใบจอง)</small>
                </div>
                <span class="tag blue">รายเดือน</span>
              </div>
              <div class="chart exec-chart">
                <svg
                  v-if="monthlySalesBars.some((bar) => bar.value !== 0)"
                  viewBox="0 0 680 236"
                  role="img"
                  aria-label="ยอดซื้อสุทธิจริงรายเดือนจาก Bplus"
                >
                  <defs>
                    <linearGradient id="popSalesBarGrad" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stop-color="#0284c7" />
                      <stop offset="100%" stop-color="#38bdf8" />
                    </linearGradient>
                  </defs>
                  <g v-for="tick in chartYTicks" :key="tick.y">
                    <line
                      x1="56"
                      :y1="tick.y"
                      x2="666"
                      :y2="tick.y"
                      stroke="#e5e7eb"
                      stroke-dasharray="3 4"
                      stroke-width="1"
                    />
                    <text x="48" :y="tick.y + 4" text-anchor="end" font-size="11" fill="#94a3b8">{{ tick.label }}</text>
                  </g>
                  <line x1="56" y1="184" x2="666" y2="184" stroke="#cbd5e1" stroke-width="1.2" />
                  <g v-for="bar in monthlySalesBars" :key="bar.month" class="exec-bar-col">
                    <rect
                      :x="bar.x"
                      :y="bar.y"
                      width="52"
                      :height="Math.max(2, bar.height)"
                      rx="6"
                      :fill="bar.value < 0 ? '#e11d48' : 'url(#popSalesBarGrad)'"
                    >
                      <title>
                        {{ bar.label }} · ฿{{ fmt(bar.value) }} · {{ bar.saleCount }} บิลขาย / {{ bar.returnCount }} ใบคืน
                      </title>
                    </rect>
                    <text
                      v-if="bar.value !== 0"
                      :x="bar.x + 26"
                      :y="Math.max(16, bar.y - 7)"
                      text-anchor="middle"
                      font-size="11.5"
                      font-weight="600"
                      fill="#334155"
                    >
                      {{ bar.shortVal }}
                    </text>
                    <text :x="bar.x + 26" y="210" text-anchor="middle" font-size="12" font-weight="500" fill="#64748b">
                      {{ bar.label }}
                    </text>
                  </g>
                </svg>
                <div v-else class="placeholder">ยังไม่มีบิลขายในช่วง 7 เดือนล่าสุด</div>
              </div>
            </div>

            <div class="card">
              <div class="section-head">
                <div>
                  <h3>เข้าพบล่าสุด</h3>
                  <small>รายการเช็คอินหน้างานล่าสุด</small>
                </div>
                <RouterLink to="/visits" class="small" style="font-weight: 600">ดูทั้งหมด →</RouterLink>
              </div>
              <div class="activity">
                <div v-for="act in (dashboard?.recent_activities || []).slice(0, 5)" :key="act.id" class="activity-row">
                  <div class="bullet">📍</div>
                  <div style="min-width: 0">
                    <strong>{{ act.shop_name || 'ร้านค้า' }} · {{ visitResultLabel(act.result) }}</strong>
                    <small
                      >{{ act.staff_name || 'ทีมขาย' }} ·
                      {{
                        act.visited_at
                          ? new Date(act.visited_at).toLocaleString('th-TH', {
                              day: 'numeric',
                              month: 'short',
                              hour: '2-digit',
                              minute: '2-digit',
                            })
                          : ''
                      }}</small
                    >
                  </div>
                </div>
                <div
                  v-if="!(dashboard?.recent_activities || []).length"
                  class="small muted"
                  style="padding: 20px 0; text-align: center"
                >
                  ยังไม่มีรายการเข้าพบล่าสุด
                </div>
              </div>
            </div>
          </div>

          <!-- Secondary Row: Plans & Followups -->
          <div class="middle">
            <div class="card panel-pad">
              <div class="row-between mb-sm">
                <div>
                  <h3 style="margin: 0; font-size: 15.5px">แผนออกตลาดวันนี้</h3>
                  <small class="muted">เส้นทางที่กำหนดไว้สำหรับ {{ todayThaiLabel }}</small>
                </div>
                <RouterLink to="/plans" class="small" style="font-weight: 600">แผนทั้งหมด →</RouterLink>
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
                <div v-if="!displayPlans.length" class="small muted" style="padding: 14px 0; text-align: center">
                  ยังไม่มีแผนออกตลาดสำหรับวันนี้
                </div>
              </template>
            </div>

            <div class="card panel-pad">
              <div class="row-between mb-sm">
                <div>
                  <h3 style="margin: 0; font-size: 15.5px">งานติดตามครบกำหนด</h3>
                  <small class="muted">รายการที่ต้องโทรหรือเข้าเยี่ยม</small>
                </div>
                <RouterLink to="/followups" class="small" style="font-weight: 600">ดูทั้งหมด →</RouterLink>
              </div>
              <SkeletonBlock v-if="tasksLoading && !(tasks || []).length" variant="list" :rows="3" />
              <template v-else>
                <div v-for="t in dueAndOpenTasks.slice(0, 4)" :key="t.id" class="minirow">
                  <div style="min-width: 0; flex: 1 1 160px">
                    <strong>{{ t.lead_shop_name || (t.crm_lead_id ? `ร้าน #${t.crm_lead_id}` : 'ลูกค้าทั่วไป') }}</strong>
                    <div class="small muted">{{ t.title }}</div>
                  </div>
                  <div class="row-actions">
                    <span :class="['tag', taskDueTagClass(t.due_date)]">{{ taskDueText(t.due_date) }}</span>
                    <button
                      type="button"
                      class="btn light"
                      style="min-height: 32px; padding: 4px 10px; font-size: 12.5px"
                      @click="completeTask(t.id)"
                    >
                      ✓ ปิด
                    </button>
                  </div>
                </div>
                <div v-if="!dueAndOpenTasks.length" class="small muted" style="padding: 14px 0; text-align: center">
                  ไม่มีงานติดตามที่ครบกำหนด
                </div>
              </template>
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
        <div class="dash-stack">
          <div class="middle">
            <div class="card">
              <div class="section-head" style="padding-bottom: 12px">
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
              <div class="section-head" style="padding-bottom: 12px">
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
                      <b style="color: var(--green)">{{ fmt(sp.net_sales_before_vat) }} ฿</b>
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
            <div class="section-head" style="padding-bottom: 13px">
              <div>
                <h3>ลูกค้าล่าสุด</h3>
                <small>รายชื่อร้านค้าล่าสุดในระบบ</small>
              </div>
              <RouterLink to="/customers" class="btn light">ดูทั้งหมด →</RouterLink>
            </div>
            <div class="table-wrap desktop-table-only">
              <table class="table">
                <thead>
                  <tr>
                    <th>รหัส</th>
                    <th>ชื่อร้านค้า</th>
                    <th>รหัส ERP</th>
                    <th>สถานะ</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="row in (masterPreview?.data || []).slice(0, 6)"
                    :key="row.customer_master_id"
                    style="cursor: pointer"
                    @click="openDetailDrawer('master', row.customer_master_id)"
                  >
                    <td>
                      <span class="tag blue">{{ row.internal_customer_code }}</span>
                    </td>
                    <td>
                      <b>{{ row.display_name }}</b>
                    </td>
                    <td>{{ row.erp_customer_code || '—' }}</td>
                    <td>
                      <span class="tag" :class="row.link_status === 'linked' ? 'green' : 'orange'">{{
                        row.link_status === 'linked' ? 'เชื่อมแล้ว' : 'รอเชื่อม'
                      }}</span>
                    </td>
                  </tr>
                  <tr v-if="!(masterPreview?.data || []).length">
                    <td colspan="4" class="placeholder">ยังไม่มีข้อมูลลูกค้าในระบบ</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </template>

      <!-- TAB 4: CUSTOMER MAP -->
      <template v-else>
        <CustomerMapBoard />
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useMutation, useQuery } from '@tanstack/vue-query';
import { RouterLink } from 'vue-router';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import MobileBottomSheet from '../../../shared/components/MobileBottomSheet.vue';
import CustomerMapBoard from '../components/CustomerMapBoard.vue';
import SalesTargetAndAlertBoard from '../components/SalesTargetAndAlertBoard.vue';
import DateRangePicker from '../components/DateRangePicker.vue';
import { toast } from '../../../shared/composables/useUiStore.js';
import { visitResultLabel } from '../utils/visitResult.js';
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
const selectedQuickStore = ref(null);
const mobileAnalyticsSheet = ref('');

const DASH_TABS = [
  { id: 'overview', label: 'ภาพรวม' },
  { id: 'targets', label: 'เป้าขาย & ร้านต้องดูแล' },
  { id: 'breakdown', label: 'ยอดตามสาย & เซลล์' },
  { id: 'map', label: 'แผนที่ลูกค้า' },
];

const nowDate = new Date();
const toIsoDate = (d) =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const todayIso = toIsoDate(nowDate);
const currentMonth = `${nowDate.getFullYear()}-${String(nowDate.getMonth() + 1).padStart(2, '0')}`;
const dateFrom = ref(`${currentMonth}-01`);
const dateTo = ref(todayIso);

const MOB_PERIODS = [
  { id: 'today', label: 'วันนี้' },
  { id: '7d', label: '7 วัน' },
  { id: 'month', label: 'เดือนนี้' },
];

const quickPeriodId = computed(() => {
  const monthStart = `${currentMonth}-01`;
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
    dateFrom.value = `${currentMonth}-01`;
    dateTo.value = todayIso;
  }
}

const validPeriod = computed(() => !!dateFrom.value && !!dateTo.value && dateFrom.value <= dateTo.value);
const todayThaiLabel = nowDate.toLocaleDateString('th-TH', {
  weekday: 'long',
  day: 'numeric',
  month: 'long',
});

const {
  data: dashboard,
  isLoading: dashLoading,
  isFetching: dashFetching,
  isError: dashError,
  refetch,
} = useQuery({
  queryKey: ['crm', 'dashboard', dateFrom, dateTo],
  queryFn: ({ signal }) =>
    crmGet(`/dashboard?${new URLSearchParams({ from: dateFrom.value, to: dateTo.value })}`, { signal }),
  enabled: validPeriod,
});

const { data: targetsSummary } = useQuery({
  queryKey: ['crm', 'sales-targets', currentMonth],
  queryFn: ({ signal }) => crmGet(`/targets?month=${encodeURIComponent(currentMonth)}`, { signal }),
});

const { data: inactiveAlerts } = useQuery({
  queryKey: ['crm', 'inactive-customers', 'all_alerts', ''],
  queryFn: ({ signal }) => crmGet('/inactive-customers?mode=all_alerts&q=', { signal }),
});

const heroTargetPct = computed(() => targetsSummary.value?.summary?.overall_sales_pct ?? null);
const urgentStores = computed(() => (inactiveAlerts.value?.rows || []).slice(0, 6));

function cleanPhone(raw) {
  return (
    String(raw || '')
      .split(/[,/\s]+/)[0]
      ?.replace(/[^0-9+]/g, '') || ''
  );
}

const {
  data: plans,
  isLoading: plansLoading,
  refetch: refetchPlans,
} = useQuery({
  queryKey: ['crm', 'plans'],
  queryFn: ({ signal }) => crmGet('/plans', { signal }),
});

const {
  data: tasks,
  isLoading: tasksLoading,
  refetch: refetchTasks,
} = useQuery({
  queryKey: ['crm', 'tasks'],
  queryFn: ({ signal }) => crmGet('/tasks', { signal }),
});

const { data: masterPreview, refetch: refetchMaster } = useQuery({
  queryKey: ['crm', 'master', { page: 1, perPage: 10 }],
  queryFn: ({ signal }) => crmGet('/customer-master?per_page=10&page=1', { signal }),
});

const todayPlans = computed(() => (plans.value || []).filter((p) => p.visit_date === todayIso));

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

function formatCompactBaht(v) {
  const n = Number(v || 0);
  const abs = Math.abs(n);
  const sign = n < 0 ? '-' : '';
  if (abs >= 1_000_000) return `${sign}${(abs / 1_000_000).toFixed(1)}M`;
  if (abs >= 1_000) return `${sign}${Math.round(abs / 1_000)}K`;
  return `${sign}${Math.round(abs)}`;
}

const monthlySalesMaxPositive = computed(() => {
  const rows = dashboard.value?.monthly_sales_chart || [];
  return Math.max(0, ...rows.map((r) => Number(r.net_sales_before_vat || 0)));
});

const chartYTicks = computed(() => {
  const top = monthlySalesMaxPositive.value || 1000000;
  return [
    { y: 36, label: formatCompactBaht(top) },
    { y: 85, label: formatCompactBaht(top * 0.66) },
    { y: 134, label: formatCompactBaht(top * 0.33) },
    { y: 184, label: '0' },
  ];
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
    const height =
      item.value >= 0
        ? maxPositive
          ? (item.value / maxPositive) * 144
          : 0
        : maxNegative
          ? (Math.abs(item.value) / maxNegative) * 24
          : 0;
    return {
      ...item,
      shortVal: formatCompactBaht(item.value),
      x: 74 + index * 86,
      y: item.value >= 0 ? 184 - height : 184,
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
  (dashboard.value?.reservation_by_doc_type || []).reduce((sum, r) => sum + Number(r.total_before_vat || 0), 0)
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
.mob-app-shell {
  display: none;
}

@media (max-width: 800px) {
  .mob-app-shell {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }
}

.mob-hero-card {
  border-radius: 26px;
  padding: 18px 18px 15px;
  background: linear-gradient(135deg, #0369a1 0%, #0284c7 52%, #0ea5e9 100%);
  color: #ffffff;
  box-shadow: 0 14px 32px rgba(2, 132, 199, 0.28);
}

.mob-hero-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 10px;
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
  padding: 4px 10px;
  border-radius: 999px;
}

.mob-hero-pill.active {
  background: #ffffff;
  color: #0369a1;
  box-shadow: 0 2px 6px rgba(15, 23, 42, 0.14);
}

.mob-hero-amount {
  font-size: clamp(26px, 7.6vw, 32px);
  font-weight: 700;
  line-height: 1.2;
  letter-spacing: -0.4px;
  font-variant-numeric: tabular-nums;
}

.mob-hero-progress {
  margin-top: 12px;
}

.mob-hero-progress-head {
  display: flex;
  justify-content: space-between;
  font-size: 12px;
  opacity: 0.92;
  margin-bottom: 5px;
}

.mob-hero-track {
  height: 7px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.25);
  overflow: hidden;
}

.mob-hero-fill {
  height: 100%;
  border-radius: 999px;
  background: #ffffff;
  transition: width 0.25s ease;
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
  border: 1px solid rgba(226, 232, 240, 0.85);
  background: #ffffff;
  border-radius: 18px;
  padding: 11px 4px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
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
  width: 40px;
  height: 40px;
  border-radius: 14px;
  display: grid;
  place-items: center;
  font-size: 20px;
}

.mob-dock-icon.blue {
  background: #e0f2fe;
}
.mob-dock-icon.amber {
  background: #fef3c7;
}
.mob-dock-icon.green {
  background: #dcfce7;
}
.mob-dock-icon.purple {
  background: #ede9fe;
}

.mob-section {
  min-width: 0;
}

.mob-check-circle {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  border: 2px solid #cbd5e1;
  background: #ffffff;
  color: transparent;
  display: grid;
  place-items: center;
  font-size: 13px;
  font-weight: 700;
  flex-shrink: 0;
  transition: all 0.14s ease;
}

.mob-check-circle:active {
  background: #0d9488;
  border-color: #0d9488;
  color: #ffffff;
  transform: scale(0.92);
}

.mob-empty-row {
  padding: 18px 14px;
  text-align: center;
  font-size: 13px;
  color: var(--muted);
}

.mob-h-scroll {
  display: flex;
  gap: 10px;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  padding-bottom: 4px;
  scrollbar-width: none;
}

.mob-h-scroll::-webkit-scrollbar {
  display: none;
}

.mob-alert-card {
  width: 244px;
  flex-shrink: 0;
  text-align: left;
  background: #ffffff;
  border-radius: 22px;
  padding: 14px;
  border: 1px solid #bae6fd;
  box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
  transition: transform 0.14s ease;
}

.mob-alert-card.warn {
  border-color: #fecdd3;
}

.mob-alert-card:active {
  transform: scale(0.97);
}

.mob-alert-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 6px;
}

.mob-alert-name {
  display: block;
  font-size: 14.5px;
  color: var(--ink);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-bottom: 2px;
}

.mob-sheet-metrics {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-bottom: 14px;
}

.mob-sheet-box {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 16px;
  padding: 11px 12px;
}

.mob-sheet-box small {
  display: block;
  font-size: 12px;
}

.mob-sheet-box strong {
  display: block;
  font-size: 16px;
  margin-top: 2px;
}

.mob-sheet-3btns {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
}

.mob-sheet-3btns > :is(.btn, a) {
  padding: 10px 6px;
  font-size: 13px;
  min-height: 44px;
}

.exec-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}

.exec-title-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.exec-title-row h2 {
  margin: 0;
  font-size: 22px;
  font-weight: 700;
  letter-spacing: -0.02em;
  color: var(--ink);
}

.exec-subtitle {
  margin: 3px 0 0;
  font-size: 13.5px;
  color: var(--muted);
}

.exec-head-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.exec-nav-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px 16px;
  flex-wrap: nowrap;
  border-bottom: 1px solid var(--border);
  margin-bottom: 18px;
}

.exec-tabs {
  display: flex;
  gap: 2px;
  overflow: visible;
  flex-shrink: 0;
}

.exec-tab {
  border: 0;
  border-bottom: 2px solid transparent;
  background: transparent;
  color: var(--muted);
  font-size: 13.5px;
  font-weight: 600;
  padding: 10px 12px;
  margin-bottom: -1px;
  white-space: nowrap;
  transition:
    color 0.14s ease,
    border-color 0.14s ease;
}

.exec-tab:hover {
  color: var(--ink);
}

.exec-tab.active {
  color: var(--blue);
  border-bottom-color: var(--blue);
}

.exec-nav-filter {
  padding-bottom: 6px;
  flex-shrink: 0;
}

@media (max-width: 1180px) {
  .exec-nav-bar {
    flex-wrap: wrap;
  }
}

.kpi-primary-accent {
  border-top: 3px solid var(--blue);
}

/* The chart grows with the card (stretched to the recent-visits column) instead of
   sitting in a fixed 252px box with empty space below it. */
.exec-chart-card {
  display: flex;
  flex-direction: column;
}

.exec-chart {
  flex: 1;
  height: auto;
  min-height: 252px;
  padding: 12px 16px 14px;
  display: flex;
  align-items: stretch;
}

.exec-chart > * {
  flex: 1;
  height: auto;
}

.exec-bar-col rect {
  transition: opacity 0.14s ease;
}

.exec-bar-col:hover rect {
  opacity: 0.85;
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
