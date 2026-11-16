<template>
  <div>
    <div class="pagehead">
      <div>
        <div class="eyebrow">BPLUS LOCAL BRIDGE</div>
        <h2>สถานะการเชื่อมต่อ ERP BPLUS</h2>
        <p>อ่านข้อมูลแบบ Read-Only SELECT-Only ผ่าน Local VPN Bridge (ไม่ทับพิกัด GPS และภาพ CRM)</p>
      </div>
      <div v-if="isManager" class="head-actions">
        <button type="button" class="btn" :disabled="busy" @click="runAction('/sync-monitor/test-connection', 'ทดสอบ SQL Auth')">
          ทดสอบ SQL Server
        </button>
        <button type="button" class="btn light" :disabled="busy" @click="runAction('/sync-monitor/test-customers-10', 'SELECT ลูกค้า 10 รายการ')">
          ทดสอบดึงลูกค้า 10 รายการ
        </button>
      </div>
    </div>

    <div v-if="!isManager" class="error">เฉพาะ Admin หรือ Manager เท่านั้นที่ใช้งาน ERP Sync Monitor ได้</div>
    <SkeletonBlock v-else-if="isLoading && !status" variant="kpis" />

    <template v-else-if="status">
      <div class="kpis">
        <div class="card kpi">
          <div class="kpi-label">Local Bridge Status</div>
          <div class="kpi-value" style="font-size:20px">{{ status.bridge?.reachable ? 'ONLINE' : 'OFFLINE' }}</div>
          <div class="kpi-foot">{{ status.bridge?.driver || status.bridge?.detail || 'Check Bridge on Windows' }}</div>
        </div>
        <div class="card kpi">
          <div class="kpi-label">ลูกค้า ERP Mirror</div>
          <div class="kpi-value">{{ fmt(status.counts?.customers) }}</div>
        </div>
        <div class="card kpi">
          <div class="kpi-label">สินค้า ERP Mirror</div>
          <div class="kpi-value">{{ fmt(status.counts?.products) }}</div>
        </div>
        <div class="card kpi">
          <div class="kpi-label">บิลขาย ERP Mirror</div>
          <div class="kpi-value">{{ fmt(status.counts?.invoices) }}</div>
        </div>
      </div>

      <div class="card panel-pad" style="margin-bottom:16px">
        <h3 style="margin-top:0">สั่ง Upsert ลง PostgreSQL Local Mirror</h3>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button
            v-for="k in ['customers', 'products', 'salespersons', 'members', 'invoices']"
            :key="k"
            type="button"
            class="btn"
            :disabled="busy"
            @click="runSync(k)"
          >
            ⟳ Sync {{ k }}
          </button>
        </div>
      </div>

      <div class="card panel-pad" style="margin-bottom:16px">
        <button type="button" class="btn light" :disabled="busy" @click="runSync('invoices', { fullReconcile: true })">
          Full reconcile invoices
        </button>
        <span v-if="syncProgress" class="kpi-foot" role="status" aria-live="polite" style="margin-left:12px">{{ syncProgress }}</span>
        <div class="small muted" style="margin-top:8px">สแกนเอกสารย้อนหลังทั้งชุด เพื่อรับการแก้สถานะยกเลิก/คืนสินค้าที่เกิดหลังรอบ Sync ก่อนหน้า แล้วนำผลไปเทียบกับรายงาน Bplus</div>
      </div>

      <div v-if="previewRows.length" class="card" style="margin-bottom:16px">
        <div class="section-head" style="padding-bottom:10px">
          <h3>ผลทดสอบดึงข้อมูลจริง ({{ previewRows.length }} รายการ)</h3>
        </div>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th v-for="col in Object.keys(previewRows[0])" :key="col">{{ col }}</th></tr></thead>
            <tbody>
              <tr v-for="(r, i) in previewRows" :key="i">
                <td v-for="col in Object.keys(previewRows[0])" :key="col">{{ r[col] }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="section-head" style="padding-bottom:10px"><h3>ประวัติข้อผิดพลาดล่าสุด</h3></div>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>เวลา</th><th>Kind</th><th>Key</th><th>รายละเอียด</th></tr></thead>
            <tbody>
              <tr v-for="e in status.errors || []" :key="e.id">
                <td>{{ new Date(e.created_at).toLocaleString('th-TH') }}</td>
                <td><b>{{ e.kind }}</b></td>
                <td>{{ e.record_key || '—' }}</td>
                <td style="white-space:normal">{{ e.error_message }}</td>
              </tr>
              <tr v-if="!(status.errors || []).length"><td colspan="4" class="placeholder">ยังไม่มีข้อผิดพลาดในการซิงค์</td></tr>
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
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { toast } from '../../../shared/composables/useUiStore.js';
import { crmGet, crmMutate, fmt, invalidateCrmData, isManager } from '../composables/useCrmSession.js';

const busy = ref(false);
const previewRows = ref([]);
const syncProgress = ref('');

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
    toast.success(label, res.message || `ดึง ${res.fetched ?? res.count ?? 0} รายการ`);
    await refetch();
    invalidateCrmData(['dashboard', 'customers', 'master']);
  } catch (err) {
    toast.error(`${label} ไม่สำเร็จ`, err);
    await refetch();
  } finally {
    busy.value = false;
  }
}

async function runSync(kind, { fullReconcile = false } = {}) {
  busy.value = true;
  syncProgress.value = `${kind}: starting`;
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
      if (res.ok === false) throw new Error(`Sync stopped with ${res.row_errors ?? 0} row errors`);
      fetched += Number(res.fetched || 0);
      inserted += Number(res.inserted || 0);
      updated += Number(res.updated || 0);
      pages += 1;
      syncProgress.value = `${kind}: page ${pages} · ${fetched.toLocaleString()} fetched`;

      if (!res.has_more) break;
      const next = Number(res.next_cursor);
      if (!Number.isSafeInteger(next) || next <= (afterKey ?? 0)) {
        throw new Error('ERP cursor did not advance; sync stopped to prevent duplicate paging');
      }
      afterKey = next;
    }

    const label = fullReconcile ? 'Full invoice reconciliation' : `Sync ${kind}`;
    toast.success(label, `Fetched ${fetched.toLocaleString()} · inserted ${inserted.toLocaleString()} · updated ${updated.toLocaleString()}`);
    await refetch();
    invalidateCrmData(['dashboard', 'customers', 'master']);
  } catch (err) {
    toast.error(`Sync ${kind} failed`, err);
    await refetch();
  } finally {
    busy.value = false;
    syncProgress.value = '';
  }
}
</script>
