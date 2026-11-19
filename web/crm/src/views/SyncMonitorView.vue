<template>
  <div>
    <PageHeader
      title="ซิงก์ข้อมูล Bplus ERP & ทดสอบ Cloud Push API"
      subtitle="ดึงข้อมูลจาก Bplus ERP แบบอ่านอย่างเดียว (Read-Only) และรองรับการส่งข้อมูลผ่าน API ขึ้น Cloud Host โดยไม่ต้องใช้ VPN"
    >
      <template v-if="isManager" #secondary>
        <button
          type="button"
          class="btn"
          :disabled="busy"
          @click="runAction('/sync-monitor/test-connection', 'ทดสอบเชื่อมต่อฐานข้อมูล Bplus SQL')"
        >
          🔌 ทดสอบเชื่อมต่อ Bplus SQL
        </button>
      </template>
      <template v-if="isManager" #primary>
        <button
          type="button"
          class="btn primary"
          :disabled="busy"
          @click="runAction('/sync-monitor/test-push-api', 'ทดสอบส่งข้อมูลผ่าน Cloud Push API (/api/sync/v1)')"
        >
          🚀 ทดสอบยิง Push API (จำลองขึ้น Cloud ไม่ใช้ VPN)
        </button>
      </template>
    </PageHeader>

    <div v-if="!isManager" class="error">เฉพาะผู้ดูแลระบบ (Admin), ผู้จัดการ หรือผู้บริหาร เท่านั้นที่เข้าดูสถานะการซิงก์ ERP ได้</div>
    <SkeletonBlock v-else-if="isLoading && !status" variant="kpis" />

    <template v-else-if="status">
      <!-- KPI Overview -->
      <div class="kpis">
        <div class="card kpi">
          <div class="kpi-label">สถานะตัวเชื่อม Bplus ในเครื่อง</div>
          <div class="kpi-value" :style="{ fontSize: '18px', color: status.bridge?.reachable ? 'var(--green)' : 'var(--orange)' }">
            {{ status.bridge?.reachable ? '🟢 เชื่อมต่อพร้อมซิงก์' : '🟠 โหมด Cloud Push API' }}
          </div>
          <div class="kpi-foot">{{ status.bridge?.reachable ? (status.bridge?.driver || 'เชื่อมต่อ SQL Server สำเร็จ') : 'พร้อมรับข้อมูลผ่าน POST /api/sync/v1/*' }}</div>
        </div>
        <div class="card kpi">
          <div class="kpi-label">👥 ลูกหนี้ ERP ในระบบ</div>
          <div class="kpi-value">{{ fmt(status.counts?.customers) }}</div>
          <div class="kpi-foot">พนักงานขาย {{ fmt(status.counts?.salespersons) }} คน · สมาชิก {{ fmt(status.counts?.members) }} คน</div>
        </div>
        <div class="card kpi">
          <div class="kpi-label">📦 รายการสินค้า ERP</div>
          <div class="kpi-value">{{ fmt(status.counts?.products) }}</div>
          <div class="kpi-foot">รายการสินค้าท้ายบิล {{ fmt(status.counts?.sales_items) }} แถว</div>
        </div>
        <div class="card kpi">
          <div class="kpi-label">🧾 บิลขาย &amp; ใบจองทั้งหมด</div>
          <div class="kpi-value">{{ fmt(status.counts?.invoices) }}</div>
          <div class="kpi-foot">ใบจอง {{ fmt(status.counts?.reservations) }} ใบ · ใบลดหนี้ {{ fmt(status.counts?.returns) }} ใบ</div>
        </div>
      </div>

      <!-- Cloud Host / No-VPN Push API Architecture Card -->
      <div class="card panel-pad" style="margin-bottom:16px;background:linear-gradient(135deg,#f0f9ff 0%,#ffffff 75%);border-color:#bae6fd">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap">
          <div style="flex:1;min-width:260px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
              <span class="tag blue">Cloud Host Ready (ไม่ต้องใช้ VPN)</span>
              <span class="tag green">HMAC-SHA256 + Idempotent Batch</span>
            </div>
            <h3 style="margin:0 0 6px">สถาปัตยกรรมซิงก์ขึ้น Cloud Host ผ่าน API อัตโนมัติ</h3>
            <p class="small muted" style="margin:0;line-height:1.55">
              เมื่อย้ายเว็บ CRM ไปไว้บนโฮสต์จริง (Cloud / VPS) เครื่องเซิร์ฟเวอร์ Bplus ในออฟฟิศจะใช้สคริปต์
              <code>sync-agent/main.py</code> อ่านข้อมูลจาก SQL Server แบบ Read-Only แล้วยิง
              <b>HTTPS POST</b> ขึ้นไปที่ปลายทาง API ของเว็บโดยตรง (ไม่ต้องต่อ VPN เข้าออฟฟิศ)
              และเมื่อมีพนักงานขายเพิ่มใหม่ใน Bplus ระบบจะสร้างรหัสล็อกอินพร้อมผูกสายใบจอง (<code>B10–BK6</code>) ให้อัตโนมัติทันที
            </p>
          </div>
          <div style="display:flex;flex-direction:column;gap:8px;min-width:240px">
            <button
              type="button"
              class="btn primary"
              :disabled="busy"
              @click="runAction('/sync-monitor/test-push-api', 'ทดสอบยิงข้อมูลผ่าน Push API (/api/sync/v1/customers)')"
            >
              🚀 ทดสอบยิง Push API ทันที
            </button>
            <button
              type="button"
              class="btn light"
              :disabled="busy"
              @click="runAction('/ settings/staff/auto-sync'.replace(' ', ''), 'ซิงก์ผูกพนักงานขายกับสายใบจองอัตโนมัติ')"
            >
              🔗 ซิงก์พนักงานขาย &amp; ผูกใบจองใหม่
            </button>
          </div>
        </div>

        <div class="api-endpoints-grid" style="margin-top:12px">
          <div v-for="ep in PUSH_ENDPOINTS" :key="ep.path" class="api-ep-pill">
            <span class="tag blue" style="font-size:11px">POST</span>
            <code>{{ ep.path }}</code>
            <span class="small muted">— {{ ep.label }}</span>
          </div>
        </div>
      </div>

      <!-- Thai Action Buttons for Pulling via Local Bridge -->
      <div class="card panel-pad" style="margin-bottom:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px">
          <div>
            <h3 style="margin:0">สั่งดึงข้อมูลจาก Bplus ERP เข้าฐานข้อมูลระบบ</h3>
            <div class="small muted">กดปุ่มด้านล่างเพื่อดึงข้อมูลล่าสุดจาก Bplus SQL Server เข้าสู่ระบบ CRM ทันที</div>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button
              type="button"
              class="btn light"
              :disabled="busy"
              @click="runAction('/sync-monitor/test-customers-10', 'ดึงตัวอย่างลูกค้า 10 รายการ')"
            >
              🔍 ดูตัวอย่างลูกค้า 10 ราย
            </button>
            <button
              type="button"
              class="btn light"
              :disabled="busy"
              @click="runAction('/sync-monitor/test-products-10', 'ดึงตัวอย่างสินค้า 10 รายการ')"
            >
              🔍 ดูตัวอย่างสินค้า 10 รายการ
            </button>
            <button
              type="button"
              class="btn light"
              :disabled="busy"
              @click="runAction('/sync-monitor/test-invoices-10', 'ดึงตัวอย่างบิลขาย 10 รายการ')"
            >
              🔍 ดูตัวอย่างบิลขาย 10 ใบ
            </button>
          </div>
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

        <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap">
          <div>
            <strong>🔄 สอบทานบิลขายและใบจองย้อนหลังทั้งหมด (Full Reconcile)</strong>
            <div class="small muted">สแกนเอกสารย้อนหลังทั้งชุดเพื่ออัปเดตสถานะบิลยกเลิก/ใบลดหนี้ และผูกสายใบจองให้ตรงกับรายงาน Bplus 100%</div>
          </div>
          <button type="button" class="btn light" :disabled="busy" @click="runSync('invoices', { fullReconcile: true })">
            🔄 ซิงก์สอบทานบิลทั้งหมด
          </button>
        </div>

        <div v-if="syncProgress" class="notice" role="status" aria-live="polite" style="margin-top:12px;margin-bottom:0">
          ⏳ กำลังดำเนินการ: <b>{{ syncProgress }}</b>
        </div>
      </div>

      <!-- Preview Result Table -->
      <div v-if="previewRows.length" class="card" style="margin-bottom:16px">
        <div class="section-head" style="padding:14px 16px 10px">
          <h3 style="margin:0">ผลลัพธ์การทดสอบซิงก์ข้อมูลล่าสุด ({{ previewRows.length }} รายการ)</h3>
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

      <!-- Recent Sync Runs History -->
      <div class="card" style="margin-bottom:16px">
        <div class="section-head" style="padding:14px 16px 10px">
          <h3 style="margin:0">ประวัติการซิงก์ข้อมูลล่าสุด</h3>
        </div>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>เวลา</th>
                <th>หมวดข้อมูล</th>
                <th>สถานะ</th>
                <th>ดึงมา / เพิ่มใหม่ / อัปเดต</th>
                <th>รายละเอียด</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="run in (status.runs || []).slice(0, 8)" :key="run.id">
                <td>{{ run.started_at ? new Date(run.started_at).toLocaleString('th-TH') : '—' }}</td>
                <td><b>{{ kindLabel(run.kind) }}</b></td>
                <td>
                  <span :class="['tag', run.status === 'success' ? 'green' : run.status === 'running' ? 'blue' : 'red']">
                    {{ run.status === 'success' ? 'สำเร็จ' : run.status === 'running' ? 'กำลังทำงาน' : 'ผิดพลาด' }}
                  </span>
                </td>
                <td>{{ fmt(run.fetched_count) }} / {{ fmt(run.inserted_count) }} / {{ fmt(run.updated_count) }}</td>
                <td style="white-space:normal">{{ run.message || '—' }}</td>
              </tr>
              <tr v-if="!(status.runs || []).length">
                <td colspan="5" class="placeholder">ยังไม่มีประวัติการซิงก์</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Error Log -->
      <div class="card">
        <div class="section-head" style="padding:14px 16px 10px"><h3 style="margin:0">ประวัติข้อผิดพลาดล่าสุด</h3></div>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>เวลา</th><th>หมวดข้อมูล</th><th>รหัสอ้างอิง</th><th>รายละเอียดข้อผิดพลาด</th></tr></thead>
            <tbody>
              <tr v-for="e in status.errors || []" :key="e.id">
                <td>{{ new Date(e.created_at).toLocaleString('th-TH') }}</td>
                <td><b>{{ kindLabel(e.kind) }}</b></td>
                <td>{{ e.record_key || '—' }}</td>
                <td style="white-space:normal">{{ e.error_message }}</td>
              </tr>
              <tr v-if="!(status.errors || []).length"><td colspan="4" class="placeholder">✅ ไม่มีข้อผิดพลาดในการดึงข้อมูล</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import PageHeader from '../../../shared/components/PageHeader.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { toast } from '../../../shared/composables/useUiStore.js';
import { crmGet, crmMutate, fmt, invalidateCrmData, isManager } from '../composables/useCrmSession.js';

const busy = ref(false);
const previewRows = ref([]);
const syncProgress = ref('');

const SYNC_KINDS = [
  { kind: 'customers', icon: '👥', title: 'ซิงก์ข้อมูลลูกค้า (ลูกหนี้ ERP)', sub: 'อัปเดตรายชื่อร้านค้า ที่อยู่ เบอร์โทร เครดิต' },
  { kind: 'products', icon: '📦', title: 'ซิงก์รายการสินค้า (Products)', sub: 'อัปเดตรหัสสินค้า ชื่อสินค้า หน่วยนับ หมวดหมู่' },
  { kind: 'salespersons', icon: '🧑‍💼', title: 'ซิงก์พนักงานขาย + ผูกใบจอง', sub: 'ดึงเซลล์ใหม่จาก Bplus และผูกบัญชีเข้าสายใบจองอัตโนมัติ' },
  { kind: 'invoices', icon: '🧾', title: 'ซิงก์บิลขาย & ใบจองรายวัน', sub: 'ดึงบิลขายเชื่อ ขายสด ใบลดหนี้ และใบจอง (B10–BK7)' },
  { kind: 'members', icon: '🎖️', title: 'ซิงก์ข้อมูลสมาชิกสะสมแต้ม', sub: 'อัปเดตทะเบียนสมาชิกและคะแนนสะสมจาก ERP' },
];

const PUSH_ENDPOINTS = [
  { path: '/api/sync/v1/customers', label: 'รับข้อมูลลูกหนี้ ERP' },
  { path: '/api/sync/v1/products', label: 'รับข้อมูลสินค้า' },
  { path: '/api/sync/v1/salespersons', label: 'รับพนักงานขาย + สร้างล็อกอินอัตโนมัติ' },
  { path: '/api/sync/v1/invoices', label: 'รับบิลขาย + ใบลดหนี้ + ใบจอง' },
  { path: '/api/sync/v1/members', label: 'รับทะเบียนสมาชิก' },
];

const KIND_LABELS = {
  customers: 'ข้อมูลลูกค้า (Customers)',
  products: 'รายการสินค้า (Products)',
  salespersons: 'พนักงานขาย (Salespersons)',
  invoices: 'บิลขาย & ใบจอง (Invoices)',
  members: 'สมาชิก (Members)',
  test_connection: 'ทดสอบเชื่อมต่อ SQL',
  test_customers_10: 'ตัวอย่างลูกค้า 10 ราย',
  test_products_10: 'ตัวอย่างสินค้า 10 รายการ',
  test_invoices_10: 'ตัวอย่างบิลขาย 10 ใบ',
  test_push_api_v1: 'ทดสอบ Cloud Push API (/api/sync/v1)',
};

function kindLabel(k) {
  return KIND_LABELS[k] || k;
}

const { data: status, isLoading, refetch } = useQuery({
  queryKey: ['crm', 'sync-status'],
  queryFn: ({ signal }) => crmGet('/sync-monitor/status', { signal }),
  enabled: isManager,
});

async function runAction(path, label) {
  busy.value = true;
  try {
    const res = await crmMutate(path, 'POST', {});
    if (Array.isArray(res.records)) {
      previewRows.value = res.records;
    }
    toast.success(label, res.message || `ดำเนินการสำเร็จ (${res.fetched ?? res.count ?? res.bound_staff ?? 0} รายการ)`);
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
  syncProgress.value = `กำลังเริ่มซิงก์ ${thLabel}...`;
  previewRows.value = [];
  let afterKey = null;
  let fetched = 0;
  let inserted = 0;
  let updated = 0;
  let pages = 0;
  const limit = kind === 'invoices' ? 100 : 1000;

  try {
    while (true) {
      const body = { limit };
      if (afterKey !== null) body.after_key = afterKey;
      if (kind === 'invoices' && fullReconcile) body.full_reconcile = true;

      const res = await crmMutate(`/sync-monitor/sync/${kind}`, 'POST', body);
      if (res.ok === false) throw new Error(`การซิงก์หยุดเนื่องจากพบข้อผิดพลาด ${res.row_errors ?? 0} รายการ`);
      fetched += Number(res.fetched || 0);
      inserted += Number(res.inserted || 0);
      updated += Number(res.updated || 0);
      pages += 1;
      syncProgress.value = `${thLabel}: รอบที่ ${pages} · ดึงแล้ว ${fetched.toLocaleString()} รายการ`;

      if (!res.has_more) break;
      const next = Number(res.next_cursor);
      if (!Number.isSafeInteger(next) || next <= (afterKey ?? 0)) {
        throw new Error('ตัวชี้ตำแหน่งข้อมูล (Cursor) ไม่ขยับ ระบบหยุดเพื่อป้องกันข้อมูลซ้ำ');
      }
      afterKey = next;
    }

    const label = fullReconcile ? 'ซิงก์สอบทานบิลทั้งหมด' : `ซิงก์ ${thLabel}`;
    toast.success(label, `ดึงข้อมูล ${fetched.toLocaleString()} · เพิ่มใหม่ ${inserted.toLocaleString()} · อัปเดต ${updated.toLocaleString()}`);
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
.sync-buttons-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
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
  gap: 11px;
  cursor: pointer;
  transition: all 0.14s ease;
}

.sync-action-card:hover:not(:disabled) {
  background: #f0f9ff;
  border-color: var(--blue);
  transform: translateY(-1px);
}

.sync-action-icon {
  font-size: 22px;
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

.api-endpoints-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.api-ep-pill {
  background: #ffffff;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  padding: 5px 10px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12.5px;
}
</style>
