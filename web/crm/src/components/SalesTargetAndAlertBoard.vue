<template>
  <section class="card target-alert-board" aria-label="เป้ายอดขายและรายงานร้านค้าขาดการติดต่อ">
    <div class="tab-header-bar">
      <div class="tab-buttons">
        <button
          type="button"
          :class="['board-tab-btn', { active: activeTab === 'targets' }]"
          @click="activeTab = 'targets'"
        >
          🎯 เป้ายอดขาย &amp; เป้าเข้าพบรายเซลล์
          <span v-if="targetsData?.summary" class="tag blue" style="margin-left:6px">
            {{ targetsData.summary.overall_sales_pct }}%
          </span>
        </button>
        <button
          type="button"
          :class="['board-tab-btn', { active: activeTab === 'inactive' }]"
          @click="activeTab = 'inactive'"
        >
          ⚠️ ร้านค้าหยุดซื้อเกิน 30 วัน / ยังไม่เคยเข้าพบ
          <span v-if="inactiveData?.summary" class="tag orange" style="margin-left:6px">
            {{ fmt(inactiveData.summary.unvisited_count) }} ร้าน
          </span>
        </button>
      </div>

      <div v-if="activeTab === 'targets' && canEditTargets" class="tab-header-actions">
        <button type="button" class="btn light" @click="openBulkTargetModal">
          ⚡ ตั้งเป้ารวมให้เซลล์ทุกคน
        </button>
      </div>
    </div>

    <!-- TAB 1: SALES & VISIT TARGETS BY SALESPERSON -->
    <div v-if="activeTab === 'targets'" class="board-body">
      <div v-if="targetsData?.summary" class="target-summary-strip">
        <div class="ts-item">
          <span class="small muted">เป้ายอดขายรวม</span>
          <strong>฿{{ money(targetsData.summary.total_target_amount) }}</strong>
        </div>
        <div class="ts-item">
          <span class="small muted">ยอดขายจริงสะสม</span>
          <strong style="color:var(--green)">฿{{ money(targetsData.summary.total_actual_sales) }}</strong>
        </div>
        <div class="ts-item">
          <span class="small muted">% ทะลุเป้ายอดขาย</span>
          <strong style="color:var(--blue)">{{ targetsData.summary.overall_sales_pct }}%</strong>
        </div>
        <div class="ts-item">
          <span class="small muted">เข้าพบจริง / เป้าเข้าพบ</span>
          <strong>{{ fmt(targetsData.summary.total_actual_visits) }} / {{ fmt(targetsData.summary.total_target_visits) }} ครั้ง</strong>
        </div>
      </div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>พนักงานขาย &amp; สายใบจอง</th>
              <th>ยอดใบจอง (207)</th>
              <th style="min-width:210px">ยอดขายจริง vs เป้ายอดขาย</th>
              <th style="min-width:170px">เข้าพบร้านค้า vs เป้าเข้าพบ</th>
              <th v-if="canEditTargets" style="text-align:right">ตั้งเป้า</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in targetsData?.rows || []" :key="row.staff_user_id">
              <td>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                  <strong>{{ row.staff_name }}</strong>
                  <span v-if="row.salesperson_code" class="tag gray">รหัส {{ row.salesperson_code }}</span>
                  <span
                    v-for="dt in row.reservation_doc_types || []"
                    :key="dt"
                    class="tag blue"
                  >
                    {{ dt }}
                  </span>
                </div>
              </td>
              <td>
                <strong>฿{{ money(row.reservation_amount) }}</strong>
                <div class="small muted">{{ fmt(row.reservation_count) }} ใบจอง</div>
              </td>
              <td>
                <div class="prog-head">
                  <strong>฿{{ money(row.actual_sales) }}</strong>
                  <span class="small muted">/ เป้า ฿{{ money(row.target_amount) }} ({{ row.sales_achievement_pct }}%)</span>
                </div>
                <div class="prog-track">
                  <div
                    class="prog-fill sales"
                    :style="{ width: `${Math.min(100, Math.max(3, row.sales_achievement_pct || 0))}%` }"
                  ></div>
                </div>
              </td>
              <td>
                <div class="prog-head">
                  <strong>{{ fmt(row.actual_visits) }} ครั้ง</strong>
                  <span class="small muted">/ เป้า {{ fmt(row.target_visits) }} ครั้ง ({{ row.visit_achievement_pct }}%)</span>
                </div>
                <div class="prog-track">
                  <div
                    class="prog-fill visit"
                    :style="{ width: `${Math.min(100, Math.max(3, row.visit_achievement_pct || 0))}%` }"
                  ></div>
                </div>
              </td>
              <td v-if="canEditTargets" style="text-align:right">
                <button type="button" class="btn light" style="min-height:32px;padding:4px 10px;font-size:12.5px" @click="openEditTargetModal(row)">
                  ⚙️ ตั้งเป้า
                </button>
              </td>
            </tr>
            <tr v-if="!(targetsData?.rows || []).length">
              <td :colspan="canEditTargets ? 5 : 4" class="placeholder">ยังไม่มีข้อมูลเป้าหมายพนักงานขาย</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- TAB 2: INACTIVE / UNVISITED CUSTOMER ALERTS -->
    <div v-else class="board-body">
      <div class="alert-toolbar">
        <div class="alert-filter-pills">
          <button
            v-for="f in ALERT_FILTERS"
            :key="f.id"
            type="button"
            :class="['alert-chip', { active: alertMode === f.id }]"
            @click="alertMode = f.id"
          >
            {{ f.label }}
            <b v-if="f.count != null">{{ fmt(f.count) }}</b>
          </button>
        </div>

        <input
          v-model="alertSearch"
          type="search"
          class="alert-search-input"
          placeholder="ค้นหาชื่อร้าน, รหัสลูกหนี้, จังหวัด, เซลล์..."
        />
      </div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>รหัส / ชื่อร้านค้า</th>
              <th>พื้นที่ / เบอร์โทร</th>
              <th>ผู้ดูแล</th>
              <th style="text-align:right">ยอดซื้อสะสม</th>
              <th>บิลล่าสุด / สถานะเข้าพบ</th>
              <th style="text-align:right">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in inactiveData?.rows || []" :key="c.id">
              <td>
                <strong>{{ c.customer_name }}</strong>
                <div class="small muted">{{ c.erp_customer_code }}</div>
              </td>
              <td>
                <div>{{ c.province || '—' }}</div>
                <div class="small muted">{{ c.phone || 'ไม่มีเบอร์' }}</div>
              </td>
              <td>
                <span class="tag blue">{{ c.salesperson_name || c.salesperson_code || 'ส่วนกลาง' }}</span>
              </td>
              <td style="text-align:right">
                <strong>฿{{ money(c.total_sales_net) }}</strong>
                <div class="small muted">{{ fmt(c.sale_invoice_count) }} บิล</div>
              </td>
              <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
                  <span v-if="Number(c.visit_count || 0) === 0" class="tag orange">ยังไม่เคยเข้าพบ</span>
                  <span v-else class="tag green">เข้าพบ {{ c.visit_count }} ครั้ง</span>

                  <span v-if="c.days_since_purchase != null && c.days_since_purchase >= 30" class="tag red">
                    ขาดซื้อ {{ c.days_since_purchase }} วัน
                  </span>
                  <span v-else-if="!c.last_purchase_at" class="tag gray">ยังไม่มีบิลขาย</span>
                  <span v-if="!c.has_pin" class="tag gray">📍 รอปักหมุด</span>
                </div>
              </td>
              <td style="text-align:right">
                <div style="display:inline-flex;gap:6px">
                  <RouterLink :to="`/customer-360/${c.id}`" class="btn light" style="min-height:32px;padding:4px 9px;font-size:12.5px">
                    🔍 360°
                  </RouterLink>
                  <button
                    type="button"
                    class="btn primary"
                    style="min-height:32px;padding:4px 10px;font-size:12.5px"
                    @click="openVisitWizard({ kind: 'erp', id: Number(c.id) })"
                  >
                    📸 เช็คอิน
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!(inactiveData?.rows || []).length">
              <td colspan="6" class="placeholder">ไม่พบร้านค้าในเงื่อนไขนี้</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal: Edit Sales & Visit Target -->
    <BaseModal
      :open="targetModalOpen"
      :title="targetForm.apply_to_all ? '⚡ ตั้งเป้ายอดขายและเป้าเข้าพบรวมให้เซลล์ทุกคน' : `🎯 ตั้งเป้าหมาย — ${targetForm.staff_name}`"
      @close="targetModalOpen = false"
    >
      <form id="targetEditForm" class="form" @submit.prevent="submitTarget">
        <div class="field span2">
          <label>เป้ายอดขายสุทธิต่อเดือน (บาท) *</label>
          <input v-model.number="targetForm.target_amount" type="number" min="0" step="1000" required />
        </div>
        <div class="field span2">
          <label>เป้าเข้าพบร้านค้าต่อเดือน (ครั้ง) *</label>
          <input v-model.number="targetForm.target_visits" type="number" min="0" step="1" required />
        </div>
      </form>
      <template #footer="{ requestClose }">
        <button type="button" class="btn" @click="requestClose">ยกเลิก</button>
        <button type="submit" form="targetEditForm" class="btn primary" :disabled="savingTarget">
          {{ savingTarget ? 'กำลังบันทึก...' : '💾 บันทึกเป้าหมาย' }}
        </button>
      </template>
    </BaseModal>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useQuery } from '@tanstack/vue-query';
import BaseModal from '../../../shared/components/BaseModal.vue';
import { toast } from '../../../shared/composables/useUiStore.js';
import { crmGet, crmMutate, fmt, me, money, openVisitWizard } from '../composables/useCrmSession.js';

const activeTab = ref('targets');
const alertMode = ref('all_alerts');
const alertSearch = ref('');

const canEditTargets = computed(() => ['admin', 'manager'].includes(me.value?.role || ''));

const { data: targetsData, refetch: refetchTargets } = useQuery({
  queryKey: ['crm', 'sales-targets'],
  queryFn: ({ signal }) => crmGet('/targets', { signal }),
});

const { data: inactiveData } = useQuery({
  queryKey: computed(() => ['crm', 'inactive-customers', alertMode.value, alertSearch.value]),
  queryFn: ({ signal }) =>
    crmGet(
      `/inactive-customers?mode=${encodeURIComponent(alertMode.value)}&q=${encodeURIComponent(alertSearch.value.trim())}`,
      { signal }
    ),
});

const ALERT_FILTERS = computed(() => [
  { id: 'all_alerts', label: '⚠️ ทั้งหมดที่ต้องดูแล', count: null },
  { id: 'unvisited', label: '🚫 ยังไม่เคยเข้าพบ', count: inactiveData.value?.summary?.unvisited_count },
  { id: 'inactive', label: '📉 หยุดซื้อเกิน 30 วัน', count: inactiveData.value?.summary?.inactive_count },
  { id: 'no_pin', label: '📍 ยังไม่มีหมุด GPS', count: inactiveData.value?.summary?.unpinned_count },
]);

const targetModalOpen = ref(false);
const savingTarget = ref(false);
const targetForm = ref({
  staff_user_id: null,
  staff_name: '',
  apply_to_all: false,
  target_amount: 150000,
  target_visits: 30,
});

function openEditTargetModal(row) {
  targetForm.value = {
    staff_user_id: row.staff_user_id,
    staff_name: row.staff_name,
    apply_to_all: false,
    target_amount: Number(row.target_amount || 150000),
    target_visits: Number(row.target_visits || 30),
  };
  targetModalOpen.value = true;
}

function openBulkTargetModal() {
  targetForm.value = {
    staff_user_id: null,
    staff_name: 'เซลล์ทุกคน',
    apply_to_all: true,
    target_amount: 150000,
    target_visits: 30,
  };
  targetModalOpen.value = true;
}

async function submitTarget() {
  savingTarget.value = true;
  try {
    await crmMutate('/targets', 'POST', {
      staff_user_id: targetForm.value.staff_user_id,
      apply_to_all: targetForm.value.apply_to_all,
      target_amount: targetForm.value.target_amount,
      target_visits: targetForm.value.target_visits,
    });
    targetModalOpen.value = false;
    toast.success('บันทึกเป้าหมายเรียบร้อย');
    await refetchTargets();
  } catch (err) {
    toast.error('บันทึกเป้าหมายไม่สำเร็จ', err);
  } finally {
    savingTarget.value = false;
  }
}
</script>

<style scoped>
.target-alert-board {
  margin-bottom: 16px;
  overflow: hidden;
}

.tab-header-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 12px 16px;
  background: #f8fafc;
  border-bottom: 1px solid var(--border);
  flex-wrap: wrap;
}

.tab-buttons {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.board-tab-btn {
  border: 1px solid var(--border);
  background: #ffffff;
  color: var(--muted);
  border-radius: 10px;
  padding: 8px 14px;
  font-size: 13.5px;
  font-weight: 700;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  transition: all 0.14s ease;
}

.board-tab-btn.active {
  background: var(--blue-soft);
  border-color: var(--blue);
  color: var(--blue);
}

.board-body {
  padding: 14px 16px 16px;
}

.target-summary-strip {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
  margin-bottom: 14px;
}

.ts-item {
  background: #f8fafc;
  border: 1px solid var(--border);
  border-radius: 11px;
  padding: 10px 12px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.ts-item strong {
  font-size: 16px;
}

.prog-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 6px;
  margin-bottom: 4px;
}

.prog-track {
  height: 8px;
  background: #e2e8f0;
  border-radius: 99px;
  overflow: hidden;
}

.prog-fill {
  height: 100%;
  border-radius: 99px;
}

.prog-fill.sales {
  background: linear-gradient(90deg, #0284c7, #10b981);
}

.prog-fill.visit {
  background: linear-gradient(90deg, #6366f1, #0ea5e9);
}

.alert-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}

.alert-filter-pills {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.alert-chip {
  border: 1px solid var(--border);
  background: #f8fafc;
  color: var(--muted);
  border-radius: 99px;
  padding: 6px 12px;
  font-size: 12.5px;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  cursor: pointer;
}

.alert-chip.active {
  background: var(--blue);
  border-color: var(--blue);
  color: #ffffff;
}

.alert-search-input {
  border: 1px solid var(--border);
  background: #f8fafc;
  border-radius: 10px;
  padding: 7px 12px;
  font-size: 13.5px;
  min-width: 240px;
}

@media (max-width: 820px) {
  .target-summary-strip {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
