<template>
  <div>
    <PageHeader
      title="ซิงก์ข้อมูล Bplus ERP"
      subtitle="รับข้อมูลผ่าน HTTPS Push API อัตโนมัติ (ไม่ใช้ VPN)"
    >
      <template v-if="isManager" #secondary>
        <button
          type="button"
          class="btn"
          :disabled="busy"
          @click="runAction('/settings/staff/auto-sync', 'ผูกพนักงานขายกับสายใบจอง')"
        >
          🔗 ผูกเซลล์อัตโนมัติ
        </button>
        <button type="button" class="btn" :disabled="busy" @click="refetch">⟳ รีเฟรช</button>
      </template>
      <template v-if="isManager" #primary>
        <button
          type="button"
          class="btn primary"
          :disabled="busy"
          @click="runAction('/sync-monitor/test-push-api', 'ทดสอบ Push API')"
        >
          🚀 ทดสอบ Push API
        </button>
      </template>
    </PageHeader>

    <div v-if="!isManager" class="error">เฉพาะแอดมิน ผู้จัดการ หรือผู้บริหาร เท่านั้นที่เข้าดูสถานะการซิงก์ได้</div>
    <SkeletonBlock v-else-if="isLoading && !status" variant="kpis" />

    <template v-else-if="status">
      <!-- Clear Sync Status Banner -->
      <div :class="['card', 'sync-status-banner', officeConnected ? 'ok' : 'warn']">
        <div class="ssb-dot" aria-hidden="true"></div>
        <div class="ssb-main">
          <strong v-if="officeConnected">
            เครื่องออฟฟิศส่งข้อมูลเข้ามาแล้ว · ล่าสุด {{ formatTime(status.push_api?.last_received_at) }}
          </strong>
          <strong v-else>ยังไม่มีเครื่องออฟฟิศส่งข้อมูลจริงเข้ามา</strong>
          <div class="small">
            <template v-if="officeConnected">
              รับแล้ว {{ fmt(status.push_api?.total_batches) }} ชุดผ่าน Push API (ไม่ใช้ VPN)
            </template>
            <template v-else>
              ฝั่งเว็บพร้อมรับแล้ว
              <template v-if="status.push_api?.last_test">
                (ทดสอบล่าสุด {{ status.push_api.last_test.status === 'success' ? 'สำเร็จ' : 'ไม่สำเร็จ' }}
                {{ formatTime(status.push_api.last_test.started_at) }})
              </template>
              — ต้องเปิดตัวส่งข้อมูลที่เครื่องในออฟฟิศให้ยิงเข้า <code>{{ pushBaseUrl }}</code>
            </template>
          </div>
        </div>
      </div>

      <!-- Per-kind Data Freshness -->
      <div class="card mb-md">
        <div class="section-head" style="padding:14px 16px 10px">
          <div>
            <h3 style="margin:0">สถานะข้อมูลแต่ละหมวด</h3>
            <small>🟢 อัปเดตภายใน 24 ชม. · 🟠 ข้อมูลเก่า · 🔴 ยังไม่เคยรับ</small>
          </div>
        </div>
        <div class="table-wrap desktop-table-only">
          <table class="table">
            <thead>
              <tr><th>หมวดข้อมูล</th><th>จำนวนในระบบ</th><th>อัปเดตล่าสุด</th><th>ช่องทาง</th><th>สถานะ</th></tr>
            </thead>
            <tbody>
              <tr v-for="ep in PUSH_ENDPOINTS" :key="`f-${ep.kind}`">
                <td><b>{{ ep.icon }} {{ ep.label }}</b></td>
                <td>{{ fmt(countForKind(ep.kind)) }}</td>
                <td>{{ formatTime(freshFor(ep.kind).last_updated_at) }}</td>
                <td class="small muted">{{ channelLabel(ep.kind) }}</td>
                <td><span :class="['tag', stateMeta(ep.kind).tag]">{{ stateMeta(ep.kind).label }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="mob-cards panel-pad">
          <div v-for="ep in PUSH_ENDPOINTS" :key="`fm-${ep.kind}`" class="card mob-card">
            <div class="row-between">
              <strong>{{ ep.icon }} {{ ep.label }}</strong>
              <span :class="['tag', stateMeta(ep.kind).tag]">{{ stateMeta(ep.kind).label }}</span>
            </div>
            <div class="small muted mt-sm">
              {{ fmt(countForKind(ep.kind)) }} รายการ · ล่าสุด {{ formatTime(freshFor(ep.kind).last_updated_at) }} · {{ channelLabel(ep.kind) }}
            </div>
          </div>
        </div>
      </div>
      <!-- Clean 4 KPIs -->
      <div class="kpis">
        <div class="card kpi">
          <div class="kpi-label">ช่องทางซิงก์หลัก</div>
          <div class="kpi-value" :style="{ fontSize: '18px', color: status.push_api?.ready ? 'var(--green)' : 'var(--orange)' }">
            {{ status.push_api?.ready ? '🟢 Push API พร้อมรับ' : '🟠 รอตั้งค่า Token' }}
          </div>
          <div class="kpi-foot">
            {{ status.push_api?.last_received_at ? `รับล่าสุด ${formatTime(status.push_api.last_received_at)}` : 'POST /api/sync/v1/* · ไม่ใช้ VPN' }}
          </div>
        </div>
        <div class="card kpi">
          <div class="kpi-label">ลูกหนี้ ERP</div>
          <div class="kpi-value">{{ fmt(status.counts?.customers) }}</div>
          <div class="kpi-foot">เซลล์ {{ fmt(status.counts?.salespersons) }} · สมาชิก {{ fmt(status.counts?.members) }}</div>
        </div>
        <div class="card kpi">
          <div class="kpi-label">สินค้า ERP</div>
          <div class="kpi-value">{{ fmt(status.counts?.products) }}</div>
          <div class="kpi-foot">รายการท้ายบิล {{ fmt(status.counts?.sales_items) }} แถว</div>
        </div>
        <div class="card kpi">
          <div class="kpi-label">บิลขาย &amp; ใบจอง</div>
          <div class="kpi-value">{{ fmt(status.counts?.invoices) }}</div>
          <div class="kpi-foot">ใบจอง {{ fmt(status.counts?.reservations) }} · ลดหนี้ {{ fmt(status.counts?.returns) }}</div>
        </div>
      </div>

      <!-- Clean Section Switcher -->
      <div class="segmented-tabs mb-md">
        <button type="button" class="btn" :class="activeTab === 'api' ? 'primary' : 'light'" @click="activeTab = 'api'">
          ☁️ Push API (ไม่ใช้ VPN)
        </button>
        <button type="button" class="btn" :class="activeTab === 'history' ? 'primary' : 'light'" @click="activeTab = 'history'">
          📜 ประวัติการซิงก์ ({{ fmt((status.runs || []).length) }})
        </button>
        <button type="button" class="btn" :class="activeTab === 'lan' ? 'primary' : 'light'" @click="activeTab = 'lan'">
          🔌 ดึงตรง (เฉพาะในวงแลนออฟฟิศ)
        </button>
      </div>

      <!-- TAB 1: PUSH API (PRIMARY - NO VPN) -->
      <template v-if="activeTab === 'api'">
        <div class="card panel-pad mb-md">
          <div class="row-between-start mb-md">
            <div>
              <h3 style="margin:0 0 4px">ปลายทาง Push API สำหรับเครื่องออฟฟิศ</h3>
              <p class="small muted" style="margin:0">
                ตั้งค่า <code>SYNC_BASE_URL</code> ในฝั่งออฟฟิศให้ยิง HTTPS POST เข้ามาโดยตรง ไม่ต้องต่อ VPN
              </p>
            </div>
            <span class="tag green">Bearer Token + Idempotent</span>
          </div>

          <div class="api-base-bar mb-md">
            <div style="min-width:0;flex:1 1 240px">
              <div class="small muted">Base URL ปัจจุบัน</div>
              <code class="api-base-url">{{ pushBaseUrl }}</code>
            </div>
            <button type="button" class="btn light" @click="copyBaseUrl">📋 คัดลอก URL</button>
          </div>

          <div class="table-wrap desktop-table-only">
            <table class="table">
              <thead>
                <tr>
                  <th>หมวดข้อมูล</th>
                  <th>Endpoint</th>
                  <th>จำนวนในระบบ</th>
                  <th>รับผ่าน API ล่าสุด</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="ep in PUSH_ENDPOINTS" :key="ep.kind">
                  <td><b>{{ ep.icon }} {{ ep.label }}</b></td>
                  <td><span class="tag blue" style="margin-right:6px">POST</span><code>{{ ep.path }}</code></td>
                  <td><b>{{ fmt(countForKind(ep.kind)) }}</b></td>
                  <td>
                    <span v-if="latestBatchFor(ep.kind)?.last_received_at" class="tag green">
                      {{ formatTime(latestBatchFor(ep.kind).last_received_at) }}
                    </span>
                    <span v-else class="small muted">รอรับชุดแรก</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="mob-cards">
            <div v-for="ep in PUSH_ENDPOINTS" :key="`m-${ep.kind}`" class="card mob-card">
              <div class="row-between">
                <strong>{{ ep.icon }} {{ ep.label }}</strong>
                <b>{{ fmt(countForKind(ep.kind)) }}</b>
              </div>
              <div class="small muted mt-sm"><code>POST {{ ep.path }}</code></div>
              <div class="small muted mt-sm">
                รับล่าสุด: {{ latestBatchFor(ep.kind)?.last_received_at ? formatTime(latestBatchFor(ep.kind).last_received_at) : 'รอรับชุดแรก' }}
              </div>
            </div>
          </div>
        </div>

        <!-- Preview Result Table (only after testing) -->
        <div v-if="previewRows.length" class="card mb-md">
          <div class="section-head" style="padding:14px 16px 10px">
            <h3 style="margin:0">ผลทดสอบล่าสุด ({{ previewRows.length }} รายการ)</h3>
            <button type="button" class="btn light" @click="previewRows = []">ปิด</button>
          </div>
          <div class="table-wrap desktop-table-only">
            <table class="table">
              <thead><tr><th v-for="col in Object.keys(previewRows[0])" :key="col">{{ col }}</th></tr></thead>
              <tbody>
                <tr v-for="(r, i) in previewRows" :key="i">
                  <td v-for="col in Object.keys(previewRows[0])" :key="col">{{ r[col] }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="mob-cards panel-pad">
            <div v-for="(r, i) in previewRows" :key="`pm-${i}`" class="card mob-card">
              <div v-for="col in Object.keys(previewRows[0])" :key="col" class="minirow">
                <span class="muted">{{ col }}</span>
                <strong>{{ r[col] ?? '—' }}</strong>
              </div>
            </div>
          </div>
        </div>
      </template>

      <!-- TAB 2: HISTORY & ERRORS -->
      <template v-else-if="activeTab === 'history'">
        <div v-if="(status.push_api?.recent_batches || []).length" class="card mb-md">
          <div class="section-head" style="padding:14px 16px 10px">
            <h3 style="margin:0">ชุดข้อมูลที่รับผ่าน Push API ล่าสุด</h3>
          </div>
          <div class="table-wrap desktop-table-only">
            <table class="table">
              <thead>
                <tr><th>เวลา</th><th>หมวด</th><th>จำนวนรายการ</th><th>แหล่งข้อมูล</th></tr>
              </thead>
              <tbody>
                <tr v-for="b in status.push_api.recent_batches" :key="b.id">
                  <td>{{ formatTime(b.created_at) }}</td>
                  <td><b>{{ kindLabel(b.kind) }}</b></td>
                  <td>{{ fmt(b.record_count) }}</td>
                  <td><span class="tag blue">{{ b.source_system }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="mob-cards panel-pad">
            <div v-for="b in status.push_api.recent_batches" :key="`bm-${b.id}`" class="card mob-card">
              <div class="row-between">
                <strong>{{ kindLabel(b.kind) }}</strong>
                <span class="tag green">{{ fmt(b.record_count) }} รายการ</span>
              </div>
              <div class="small muted mt-sm">{{ formatTime(b.created_at) }} · {{ b.source_system }}</div>
            </div>
          </div>
        </div>

        <div class="card mb-md">
          <div class="section-head" style="padding:14px 16px 10px">
            <h3 style="margin:0">ประวัติการรันล่าสุด</h3>
          </div>
          <div class="table-wrap desktop-table-only">
            <table class="table">
              <thead>
                <tr><th>เวลา</th><th>หมวด</th><th>สถานะ</th><th>ดึง / เพิ่ม / อัปเดต</th><th>รายละเอียด</th></tr>
              </thead>
              <tbody>
                <tr v-for="run in (status.runs || []).slice(0, 10)" :key="run.id">
                  <td>{{ formatTime(run.started_at) }}</td>
                  <td><b>{{ kindLabel(run.kind) }}</b></td>
                  <td>
                    <span :class="['tag', run.status === 'success' ? 'green' : run.status === 'running' ? 'blue' : 'red']">
                      {{ run.status === 'success' ? 'สำเร็จ' : run.status === 'running' ? 'กำลังทำงาน' : 'ไม่สำเร็จ' }}
                    </span>
                  </td>
                  <td>{{ fmt(run.fetched_count) }} / {{ fmt(run.inserted_count) }} / {{ fmt(run.updated_count) }}</td>
                  <td style="white-space:normal">{{ friendlyRunMessage(run.message) }}</td>
                </tr>
                <tr v-if="!(status.runs || []).length">
                  <td colspan="5" class="placeholder">ยังไม่มีประวัติการซิงก์</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="mob-cards panel-pad">
            <div v-for="run in (status.runs || []).slice(0, 8)" :key="`rm-${run.id}`" class="card mob-card">
              <div class="row-between">
                <strong>{{ kindLabel(run.kind) }}</strong>
                <span :class="['tag', run.status === 'success' ? 'green' : run.status === 'running' ? 'blue' : 'red']">
                  {{ run.status === 'success' ? 'สำเร็จ' : run.status === 'running' ? 'กำลังทำงาน' : 'ไม่สำเร็จ' }}
                </span>
              </div>
              <div class="small muted mt-sm">{{ formatTime(run.started_at) }} · {{ fmt(run.fetched_count) }} / {{ fmt(run.inserted_count) }} / {{ fmt(run.updated_count) }}</div>
              <div v-if="run.message" class="small mt-sm">{{ friendlyRunMessage(run.message) }}</div>
            </div>
            <div v-if="!(status.runs || []).length" class="placeholder">ยังไม่มีประวัติการซิงก์</div>
          </div>
        </div>

        <details v-if="(status.errors || []).length" class="card panel-pad">
          <summary style="cursor:pointer;font-weight:700">
            ⚠️ บันทึกข้อผิดพลาดล่าสุด ({{ (status.errors || []).length }} รายการ)
          </summary>
          <div class="table-wrap mt-md">
            <table class="table">
              <thead><tr><th>เวลา</th><th>หมวด</th><th>รายละเอียด</th></tr></thead>
              <tbody>
                <tr v-for="e in status.errors || []" :key="e.id">
                  <td>{{ formatTime(e.created_at) }}</td>
                  <td><b>{{ kindLabel(e.kind) }}</b></td>
                  <td style="white-space:normal">{{ friendlyRunMessage(e.error_message) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </details>
      </template>

      <!-- TAB 3: OFFICE LAN DIRECT PULL (SECONDARY / OPTIONAL) -->
      <template v-else>
        <div
          v-if="status.bridge?.sql_reachable === false || !status.bridge?.reachable"
          class="notice mb-md"
          style="background:#fffbeb;color:#92400e;border:1px solid #fde68a"
        >
          <b>เครื่องเซิร์ฟเวอร์นี้ไม่ได้อยู่ในวงแลนออฟฟิศ</b> — ปุ่มด้านล่างใช้ได้เฉพาะตอนอยู่ในวงแลนเดียวกับ SQL Server (192.168.88.200)
          สำหรับการใช้งานปกติโดยไม่ใช้ VPN ให้ยิงข้อมูลเข้าผ่านแท็บ <b>☁️ Push API</b> ครับ
        </div>

        <div class="card panel-pad mb-md">
          <div class="row-between mb-md">
            <div>
              <h3 style="margin:0">ดึงข้อมูลตรงจาก Bplus SQL (เฉพาะในวงแลนออฟฟิศ)</h3>
              <small class="muted">สถานะ SQL: {{ status.bridge?.sql_reachable ? '🟢 เชื่อมต่อวงแลนออฟฟิศได้' : '🟠 นอกวงแลนออฟฟิศ' }}</small>
            </div>
            <button
              type="button"
              class="btn light"
              :disabled="busy"
              @click="runAction('/sync-monitor/test-connection', 'ทดสอบเชื่อมต่อ Bplus SQL')"
            >
              🔌 ทดสอบ SQL
            </button>
          </div>

          <div class="sync-buttons-grid">
            <button
              v-for="item in SYNC_KINDS"
              :key="item.kind"
              type="button"
              class="sync-action-card"
              :disabled="busy"
              @click="runSync(item.kind)"
            >
              <span class="sync-action-icon">{{ item.icon }}</span>
              <div class="sync-action-text">
                <strong>{{ item.title }}</strong>
                <span class="small muted">{{ item.sub }}</span>
              </div>
            </button>
          </div>

          <div class="row-between mt-md" style="padding-top:12px;border-top:1px solid var(--border)">
            <span class="small muted">สอบทานบิลย้อนหลังทั้งชุดให้ตรงกับ Bplus 100%</span>
            <button type="button" class="btn light" :disabled="busy" @click="runSync('invoices', { fullReconcile: true })">
              🔄 สอบทานบิลทั้งหมด
            </button>
          </div>

          <div v-if="syncProgress" class="notice mt-md" role="status" aria-live="polite" style="margin-bottom:0">
            ⏳ กำลังดำเนินการ: <b>{{ syncProgress }}</b>
          </div>
        </div>
      </template>
    </template>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import PageHeader from '../../../shared/components/PageHeader.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { toast } from '../../../shared/composables/useUiStore.js';
import { crmGet, crmMutate, fmt, invalidateCrmData, isManager } from '../composables/useCrmSession.js';

const activeTab = ref('api');
const busy = ref(false);
const previewRows = ref([]);
const syncProgress = ref('');

const pushBaseUrl = computed(() =>
  typeof window !== 'undefined' ? window.location.origin : 'https://crm.popstarmember.com'
);

const PUSH_ENDPOINTS = [
  { kind: 'customers', icon: '👥', path: '/api/sync/v1/customers', label: 'ลูกหนี้ ERP' },
  { kind: 'products', icon: '📦', path: '/api/sync/v1/products', label: 'สินค้าและราคา' },
  { kind: 'salespersons', icon: '🧑‍💼', path: '/api/sync/v1/salespersons', label: 'พนักงานขาย' },
  { kind: 'invoices', icon: '🧾', path: '/api/sync/v1/invoices', label: 'บิลขาย & ใบจอง' },
  { kind: 'members', icon: '🎖️', path: '/api/sync/v1/members', label: 'สมาชิกสะสมแต้ม' },
];

const SYNC_KINDS = [
  { kind: 'customers', icon: '👥', title: 'ซิงก์ลูกค้า ERP', sub: 'รายชื่อ ที่อยู่ เบอร์โทร เครดิต' },
  { kind: 'products', icon: '📦', title: 'ซิงก์สินค้า', sub: 'รหัส ชื่อสินค้า หน่วย หมวดหมู่' },
  { kind: 'salespersons', icon: '🧑‍💼', title: 'ซิงก์พนักงานขาย', sub: 'ดึงเซลล์และผูกสายใบจอง' },
  { kind: 'invoices', icon: '🧾', title: 'ซิงก์บิลขาย & ใบจอง', sub: 'บิลขาย ใบลดหนี้ และใบจอง' },
  { kind: 'members', icon: '🎖️', title: 'ซิงก์สมาชิก', sub: 'ทะเบียนสมาชิกและแต้มจาก ERP' },
];

const KIND_LABELS = {
  customers: 'ลูกหนี้ ERP',
  products: 'สินค้า',
  salespersons: 'พนักงานขาย',
  invoices: 'บิลขาย & ใบจอง',
  members: 'สมาชิก',
  test_connection: 'ทดสอบ SQL',
  test_customers_10: 'ตัวอย่างลูกค้า',
  test_products_10: 'ตัวอย่างสินค้า',
  test_invoices_10: 'ตัวอย่างบิลขาย',
  test_push_api_v1: 'ทดสอบ Push API',
};

function kindLabel(k) {
  return KIND_LABELS[k] || k;
}

function formatTime(val) {
  if (!val) return '—';
  return new Date(val).toLocaleString('th-TH', {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function friendlyRunMessage(msg) {
  if (!msg) return '—';
  if (msg.includes('wait operation timed out') || msg.includes('server was not found')) {
    return 'เชื่อมต่อ Bplus SQL ไม่ได้ (อยู่นอกวงแลนออฟฟิศ — ใช้ Push API แทน)';
  }
  return msg;
}

const { data: status, isLoading, refetch } = useQuery({
  queryKey: ['crm', 'sync-status'],
  queryFn: ({ signal }) => crmGet('/sync-monitor/status', { signal }),
  enabled: isManager,
});

function countForKind(kind) {
  return status.value?.counts?.[kind] ?? 0;
}

const officeConnected = computed(() => Boolean(status.value?.push_api?.office_agent_connected));

function freshFor(kind) {
  return status.value?.freshness?.[kind] || {};
}

function stateMeta(kind) {
  const f = freshFor(kind);
  if (f.state === 'fresh') return { tag: 'green', label: '🟢 ล่าสุด' };
  if (f.state === 'stale') {
    const days = Math.max(1, Math.round((f.age_hours || 0) / 24));
    return { tag: 'orange', label: `🟠 เก่า ${days} วัน` };
  }
  return { tag: 'red', label: '🔴 ยังไม่เคยรับ' };
}

function channelLabel(kind) {
  const f = freshFor(kind);
  if (!f.last_updated_at) return '—';
  if (f.last_push_at && (!f.last_pull_at || new Date(f.last_push_at) >= new Date(f.last_pull_at))) return 'Push API';
  return 'ดึงตรง (ตอนอยู่ออฟฟิศ)';
}

function latestBatchFor(kind) {
  return status.value?.push_api?.latest_by_kind?.[kind] || null;
}

async function copyBaseUrl() {
  try {
    await navigator.clipboard.writeText(pushBaseUrl.value);
    toast.success('คัดลอก Base URL แล้ว', pushBaseUrl.value);
  } catch {
    toast.info('Base URL', pushBaseUrl.value);
  }
}

async function runAction(path, label) {
  busy.value = true;
  try {
    const res = await crmMutate(path, 'POST', {});
    if (Array.isArray(res.records)) {
      previewRows.value = res.records;
    }
    toast.success(label, res.message || `สำเร็จ (${res.fetched ?? res.count ?? res.bound_staff ?? 0} รายการ)`);
    await refetch();
    invalidateCrmData(['dashboard', 'customers', 'master', 'settings-overview']);
  } catch (err) {
    toast.error(`${label} ไม่สำเร็จ`, err);
    await refetch();
  } finally {
    busy.value = false;
  }
}

async function runSync(kind, { fullReconcile = false } = {}) {
  busy.value = true;
  const thLabel = kindLabel(kind);
  syncProgress.value = `กำลังซิงก์ ${thLabel}...`;
  previewRows.value = [];
  let afterKey = null;
  let fetched = 0;
  let inserted = 0;
  let updated = 0;
  let skipped = 0;
  let pages = 0;
  const limit = kind === 'invoices' ? 100 : 1000;

  try {
    while (true) {
      const body = { limit };
      if (afterKey !== null) body.after_key = afterKey;
      if (kind === 'invoices' && fullReconcile) body.full_reconcile = true;

      const res = await crmMutate(`/sync-monitor/sync/${kind}`, 'POST', body);
      if (res.ok === false) throw new Error(`พบข้อผิดพลาด ${res.row_errors ?? 0} รายการ`);
      fetched += Number(res.fetched || 0);
      inserted += Number(res.inserted || 0);
      updated += Number(res.updated || 0);
      skipped += Number(res.skipped || 0);
      pages += 1;
      syncProgress.value = `${thLabel}: รอบที่ ${pages} · ดึงแล้ว ${fetched.toLocaleString()} รายการ`;

      if (!res.has_more) break;
      const next = Number(res.next_cursor);
      if (!Number.isSafeInteger(next) || next <= (afterKey ?? 0)) {
        throw new Error('ตัวชี้ตำแหน่งข้อมูลไม่ขยับ ระบบหยุดเพื่อป้องกันข้อมูลซ้ำ');
      }
      afterKey = next;
    }

    const label = fullReconcile ? 'สอบทานบิลทั้งหมด' : `ซิงก์ ${thLabel}`;
    toast.success(label, `ดึง ${fetched.toLocaleString()} · ใหม่ ${inserted.toLocaleString()} · อัปเดต ${updated.toLocaleString()}${skipped ? ` · ข้าม ${skipped.toLocaleString()}` : ''}`);
    await refetch();
    invalidateCrmData(['dashboard', 'customers', 'master', 'settings-overview']);
  } catch (err) {
    toast.error(`ซิงก์ ${thLabel} ไม่สำเร็จ`, err);
    await refetch();
  } finally {
    busy.value = false;
    syncProgress.value = '';
  }
}
</script>

<style scoped>
.sync-status-banner {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 14px 16px;
  margin-bottom: 16px;
  border-left: 4px solid var(--orange);
  background: #fffbeb;
}

.sync-status-banner.ok {
  border-left-color: var(--green);
  background: #f0fdf4;
}

.sync-status-banner .ssb-dot {
  width: 12px;
  height: 12px;
  border-radius: 50%;
  margin-top: 6px;
  flex-shrink: 0;
  background: var(--orange);
}

.sync-status-banner.ok .ssb-dot {
  background: var(--green);
}

.sync-status-banner .ssb-main {
  min-width: 0;
}

.sync-status-banner strong {
  display: block;
  font-size: 15px;
}

.sync-status-banner code {
  word-break: break-all;
}

.api-base-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
  padding: 12px 14px;
  border-radius: 12px;
  background: #f8fafc;
  border: 1px solid var(--border);
}

.api-base-url {
  display: inline-block;
  margin-top: 2px;
  font-size: 14px;
  font-weight: 700;
  color: #0369a1;
  word-break: break-all;
}

.sync-buttons-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(220px, 100%), 1fr));
  gap: 10px;
}

.sync-action-card {
  border: 1px solid var(--border);
  background: #f8fafc;
  border-radius: 12px;
  padding: 12px 14px;
  text-align: left;
  display: flex;
  align-items: flex-start;
  gap: 10px;
  cursor: pointer;
  transition: border-color 0.14s ease, background-color 0.14s ease;
}

.sync-action-card:hover:not(:disabled) {
  background: #f0f9ff;
  border-color: var(--blue);
}

.sync-action-icon {
  font-size: 20px;
  line-height: 1;
  flex-shrink: 0;
  margin-top: 2px;
}

.sync-action-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.sync-action-text strong {
  font-size: 13.5px;
  color: var(--ink);
}
</style>
