<template>
  <div>
    <PageHeader
      title="สถานะระบบ & API Health Monitor"
      subtitle="ตรวจสอบสถานะ API, ฐานข้อมูล PostgreSQL, ตัวเชื่อม Bplus ERP Bridge และ LINE OA แบบเรียลไทม์ (แยกอิสระจากโปรเจกต์อื่น)"
    >
      <template #secondary>
        <label class="auto-refresh-toggle">
          <input v-model="autoRefresh" type="checkbox" />
          <span>รีเฟรชอัตโนมัติ (15 วิ)</span>
        </label>
        <button type="button" class="btn light" :disabled="testingSql" @click="runSqlTest">
          {{ testingSql ? 'กำลังทดสอบ Bplus SQL...' : '🔌 ทดสอบ Bplus SQL สด' }}
        </button>
      </template>
      <template #primary>
        <button type="button" class="btn primary" :disabled="isFetching" @click="refetch">
          {{ isFetching ? 'กำลังตรวจสอบ...' : '⟳ ตรวจสอบทั้งหมดทันที' }}
        </button>
      </template>
    </PageHeader>

    <div v-if="!isManager" class="error">เฉพาะผู้ดูแลระบบ (Admin) หรือผู้บริหารเท่านั้นที่ดูหน้าสถานะระบบได้</div>
    <SkeletonBlock v-else-if="isLoading && !data" variant="cards" :rows="4" />
    <div v-else-if="isError" class="error">{{ error?.message || 'โหลดข้อมูลสถานะระบบไม่สำเร็จ' }}</div>

    <template v-else-if="data">
      <!-- Overall Banner -->
      <div
        class="card panel-pad status-banner"
        :class="data.overall_status"
        style="margin-bottom:16px"
      >
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
          <div style="display:flex;align-items:center;gap:12px">
            <div class="status-dot-big" :class="data.overall_status"></div>
            <div>
              <h3 style="margin:0">
                {{
                  data.overall_status === 'healthy'
                    ? 'ระบบ POPSTAR CRM & API ทำงานปกติทุกบริการ'
                    : data.overall_status === 'degraded'
                      ? 'API หลักทำงานปกติ — มีบริการเสริมที่ต้องตรวจสอบ'
                      : 'พบปัญหาการเชื่อมต่อฐานข้อมูลหลัก'
                }}
              </h3>
              <div class="small muted">
                ตรวจล่าสุด: {{ formatDateTime(data.checked_at) }} · ใช้เวลาตรวจรวม {{ data.total_latency_ms }} ms
              </div>
            </div>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a href="/api/health" target="_blank" rel="noopener noreferrer" class="btn light">
              ↗ เปิด /api/health (JSON)
            </a>
            <RouterLink to="/sync" class="btn light">🔄 ไปหน้าซิงก์ ERP</RouterLink>
            <RouterLink to="/line-inbox" class="btn light">💬 ไปหน้าแชท LINE OA</RouterLink>
          </div>
        </div>
      </div>

      <!-- 4 Core Service Health Cards -->
      <div class="health-grid" style="margin-bottom:16px">
        <!-- 1. Laravel API & PostgreSQL -->
        <div class="card panel-pad health-card">
          <div class="hc-top">
            <strong>⚡ Laravel API &amp; PostgreSQL</strong>
            <span class="tag" :class="data.api?.ok ? 'green' : 'red'">
              {{ data.api?.ok ? '200 OK' : 'ERROR' }}
            </span>
          </div>
          <div class="minirow"><span>ฐานข้อมูล</span><b>{{ data.api?.db_driver }} ({{ data.api?.db_latency_ms }} ms)</b></div>
          <div class="minirow"><span>PHP Version</span><b>{{ data.api?.php_version }}</b></div>
          <div class="minirow">
            <span>Cloud Push API Token</span>
            <span class="tag" :class="data.api?.push_api_token_configured ? 'green' : 'orange'">
              {{ data.api?.push_api_token_configured ? 'พร้อมใช้งาน' : 'ยังไม่ตั้งค่า' }}
            </span>
          </div>
          <div class="minirow"><span>Endpoint</span><code>/api/health</code></div>
        </div>

        <!-- 2. Local ERP Bridge & Bplus SQL -->
        <div class="card panel-pad health-card">
          <div class="hc-top">
            <strong>🔌 Local ERP Bridge (พอร์ต 18900)</strong>
            <span class="tag" :class="data.bridge?.ok ? 'green' : 'red'">
              {{ data.bridge?.ok ? 'ONLINE' : 'OFFLINE' }}
            </span>
          </div>
          <div class="minirow"><span>Bridge URL</span><code>{{ data.bridge?.url }}</code></div>
          <div class="minirow"><span>Latency</span><b>{{ data.bridge?.latency_ms }} ms</b></div>
          <div class="minirow">
            <span>SQL Config</span>
            <span class="tag" :class="data.bridge?.sql_configured ? 'green' : 'orange'">
              {{ data.bridge?.sql_configured ? 'ตั้งค่าครบ (Read-Only)' : 'ยังไม่ตั้งค่า' }}
            </span>
          </div>
          <div class="small muted" style="margin-top:6px">{{ data.bridge?.message }}</div>
          <div v-if="sqlTestResult" class="sql-test-box" :class="sqlTestResult.ok ? 'ok' : 'err'">
            <b>{{ sqlTestResult.ok ? '✓ เชื่อม Bplus SQL สำเร็จ' : '✕ เชื่อม Bplus SQL ไม่สำเร็จ' }}</b>
            <div class="small">
              {{ sqlTestResult.database || '' }} {{ sqlTestResult.server_name ? `(${sqlTestResult.server_name})` : '' }} · {{ sqlTestResult.latency_ms }} ms
            </div>
            <div class="small">{{ sqlTestResult.message }}</div>
          </div>
        </div>

        <!-- 3. LINE OA Messaging API -->
        <div class="card panel-pad health-card">
          <div class="hc-top">
            <strong>💬 LINE OA Messaging API</strong>
            <span class="tag" :class="data.line?.ok ? 'green' : data.line?.configured ? 'orange' : 'gray'">
              {{ data.line?.ok ? 'CONNECTED' : data.line?.configured ? 'CHECK TOKEN' : 'NOT SET' }}
            </span>
          </div>
          <div class="minirow"><span>ชื่อ LINE OA</span><b>{{ data.line?.display_name || '—' }}</b></div>
          <div class="minirow"><span>Basic ID</span><b>{{ data.line?.basic_id || '—' }}</b></div>
          <div class="minirow">
            <span>โควต้าส่งเดือนนี้</span>
            <b>{{ data.line?.quota_used ?? '—' }} / {{ data.line?.quota_limit ?? '—' }} ข้อความ</b>
          </div>
          <div class="minirow">
            <span>Webhook</span>
            <span class="tag" :class="data.line?.webhook_active ? 'green' : 'orange'">
              {{ data.line?.webhook_active ? 'Active' : 'Inactive' }}
            </span>
          </div>
          <div v-if="data.line?.webhook_endpoint" class="small muted" style="margin-top:6px;word-break:break-all">
            {{ data.line.webhook_endpoint }}
          </div>
        </div>

        <!-- 4. Member Portal & Mirror Summary -->
        <div class="card panel-pad health-card">
          <div class="hc-top">
            <strong>🪪 Member Portal &amp; ข้อมูล Mirror</strong>
            <span class="tag blue">BPLUS MIRROR</span>
          </div>
          <div class="minirow"><span>ลูกหนี้ Active</span><b>{{ fmt(data.mirror?.active_customers) }} ราย</b></div>
          <div class="minirow"><span>บิลขาย &amp; ใบจอง</span><b>{{ fmt(data.mirror?.invoices) }} ใบ</b></div>
          <div class="minirow"><span>บิลล่าสุดถึงวันที่</span><b>{{ shortDate(data.mirror?.invoice_max_date) }}</b></div>
          <div class="minirow"><span>สินค้ามีราคา</span><b>{{ fmt(data.mirror?.product_skus) }} SKU</b></div>
          <div class="minirow"><span>พนักงาน / สมาชิก</span><b>{{ fmt(data.crm?.staff_users) }} / {{ fmt(data.crm?.member_accounts) }} คน</b></div>
        </div>
      </div>

      <!-- Recent Sync Runs & Errors -->
      <div class="split">
        <div class="card">
          <div class="section-head" style="padding:14px 18px 0">
            <div>
              <h3>ประวัติการซิงก์ล่าสุด (8 รอบ)</h3>
              <small>Cursor บิลปัจจุบัน: {{ data.sync_cursor?.cursor_value || '—' }}</small>
            </div>
          </div>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>ประเภท</th>
                  <th>สถานะ</th>
                  <th>ดึง/เพิ่ม/อัปเดต</th>
                  <th>เวลาเสร็จ</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in data.recent_runs || []" :key="r.id">
                  <td>#{{ r.id }}</td>
                  <td><code>{{ r.kind }}</code></td>
                  <td>
                    <span class="tag" :class="r.status === 'success' ? 'green' : r.status === 'running' ? 'blue' : 'red'">
                      {{ r.status }}
                    </span>
                  </td>
                  <td>{{ fmt(r.fetched_count) }} / +{{ fmt(r.inserted_count) }} / ~{{ fmt(r.updated_count) }}</td>
                  <td class="small muted">{{ formatDateTime(r.finished_at || r.started_at) }}</td>
                </tr>
                <tr v-if="!(data.recent_runs || []).length">
                  <td colspan="5" class="placeholder">ยังไม่มีประวัติการซิงก์</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card">
          <div class="section-head" style="padding:14px 18px 0">
            <div>
              <h3>บันทึกรายการผิดพลาดล่าสุด</h3>
              <small>ใช้ตรวจสอบเวลาเกิด Timeout หรือการเชื่อมต่อหลุด</small>
            </div>
          </div>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>เวลา</th>
                  <th>ประเภท</th>
                  <th>รายละเอียด</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="e in data.recent_errors || []" :key="e.id">
                  <td class="small muted" style="white-space:nowrap">{{ formatDateTime(e.created_at) }}</td>
                  <td><code>{{ e.kind }}</code></td>
                  <td class="small" style="white-space:normal;word-break:break-word">{{ e.error_message }}</td>
                </tr>
                <tr v-if="!(data.recent_errors || []).length">
                  <td colspan="3" class="placeholder">ไม่พบข้อผิดพลาด</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useQuery } from '@tanstack/vue-query';
import PageHeader from '../../../shared/components/PageHeader.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { toast } from '../../../shared/composables/useUiStore.js';
import { crmGet, fmt, isManager } from '../composables/useCrmSession.js';

const autoRefresh = ref(true);
const testingSql = ref(false);
const sqlTestResult = ref(null);

const { data, isLoading, isFetching, isError, error, refetch } = useQuery({
  queryKey: ['crm', 'system-health'],
  queryFn: ({ signal }) => crmGet('/system-health', { signal }),
  enabled: isManager,
  refetchInterval: computed(() => (autoRefresh.value ? 15000 : false)),
});

async function runSqlTest() {
  testingSql.value = true;
  try {
    const res = await crmGet('/system-health?test_sql=1');
    sqlTestResult.value = res?.bridge?.sql_test || { ok: false, message: 'Bridge offline' };
    if (sqlTestResult.value.ok) {
      toast.success('เชื่อมต่อ Bplus SQL Server สำเร็จ', `${sqlTestResult.value.database} (${sqlTestResult.value.latency_ms} ms)`);
    } else {
      toast.error('เชื่อมต่อ Bplus SQL ไม่สำเร็จ', sqlTestResult.value.message || '');
    }
    refetch();
  } catch (err) {
    toast.error('ทดสอบ SQL ไม่สำเร็จ', err);
  } finally {
    testingSql.value = false;
  }
}

function formatDateTime(ts) {
  if (!ts) return '—';
  const d = new Date(ts);
  if (Number.isNaN(d.getTime())) return String(ts);
  return d.toLocaleString('th-TH', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', second: '2-digit' });
}

function shortDate(ts) {
  if (!ts) return '—';
  return String(ts).slice(0, 10);
}
</script>

<style scoped>
.status-banner.healthy {
  border-color: #86efac;
  background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 70%);
}
.status-banner.degraded {
  border-color: #fde68a;
  background: linear-gradient(135deg, #fffbeb 0%, #ffffff 70%);
}
.status-banner.down {
  border-color: #fca5a5;
  background: linear-gradient(135deg, #fef2f2 0%, #ffffff 70%);
}
.status-dot-big {
  width: 16px;
  height: 16px;
  border-radius: 50%;
  flex-shrink: 0;
}
.status-dot-big.healthy {
  background: #16a34a;
  box-shadow: 0 0 0 5px rgba(22, 163, 74, 0.18);
}
.status-dot-big.degraded {
  background: #d97706;
  box-shadow: 0 0 0 5px rgba(217, 119, 6, 0.18);
}
.status-dot-big.down {
  background: #dc2626;
  box-shadow: 0 0 0 5px rgba(220, 38, 38, 0.18);
}
.auto-refresh-toggle {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  cursor: pointer;
  padding: 6px 10px;
  min-height: 38px;
}
.health-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(270px, 100%), 1fr));
  gap: 14px;
}
.hc-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 10px;
}
.sql-test-box {
  margin-top: 10px;
  padding: 8px 10px;
  border-radius: 10px;
  font-size: 12.5px;
}
.sql-test-box.ok {
  background: #f0fdf4;
  border: 1px solid #86efac;
  color: #166534;
}
.sql-test-box.err {
  background: #fef2f2;
  border: 1px solid #fca5a5;
  color: #991b1b;
}
</style>
