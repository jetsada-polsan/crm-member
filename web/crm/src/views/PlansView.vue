<template>
  <div>
    <PageHeader
      title="แผนออกตลาดประจำวัน"
      subtitle="วางแผนร้านที่ต้องไปเยี่ยม ดึงลูกหนี้จากสายใบจองเข้าแผนอัตโนมัติ และกดนำทาง Google Maps ต่อเนื่อง"
    >
      <template #primary>
        <button type="button" class="btn primary" @click="openCreateModal">＋ สร้างแผนออกตลาด</button>
      </template>
    </PageHeader>

    <SkeletonBlock v-if="isLoading && !(plans || []).length" variant="table" :rows="5" />
    <div v-else-if="isError" class="error">{{ error?.message || 'โหลดแผนออกตลาดไม่สำเร็จ' }}</div>

    <div v-else class="plans-layout">
      <div v-if="!(plans || []).length" class="card panel-pad" style="text-align: center; padding: 40px 20px">
        <div style="font-size: 38px; margin-bottom: 8px">🗓️</div>
        <strong>ยังไม่มีแผนออกตลาดในระบบ</strong>
        <p class="small muted">กดปุ่ม “＋ สร้างแผนออกตลาด” แล้วเลือกดึงร้านค้าจากสายใบจองของคุณเข้าแผนอัตโนมัติได้เลย</p>
        <button type="button" class="btn primary" @click="openCreateModal">＋ สร้างแผนออกตลาดแรก</button>
      </div>

      <article v-for="p in plans || []" :key="p.id" class="card plan-card">
        <div class="plan-card-head">
          <div class="plan-head-left">
            <div class="plan-title-row">
              <span class="tag blue">#{{ p.id }}</span>
              <span class="tag gray">📅 {{ p.visit_date }}</span>
              <strong class="plan-route-title">{{ p.route_name }}</strong>
              <span v-if="p.assigned_name" class="tag purple">👤 {{ p.assigned_name }}</span>
              <span v-for="dt in p.assigned_reservation_doc_types || []" :key="dt" class="tag green">
                สาย {{ dt }}
              </span>
            </div>
            <div v-if="p.district || p.note" class="small muted" style="margin-top: 4px">
              {{ [p.district, p.note].filter(Boolean).join(' · ') }}
            </div>
          </div>

          <div class="plan-head-actions">
            <span class="tag" :class="p.stop_count > 0 && p.completed_stop_count === p.stop_count ? 'green' : 'orange'">
              จุดแวะ {{ p.completed_stop_count || 0 }} / {{ p.stop_count || 0 }} ร้าน
            </span>
            <a
              v-if="multiStopDirectionsUrl(p)"
              :href="multiStopDirectionsUrl(p)"
              target="_blank"
              rel="noopener"
              class="btn light"
            >
              🧭 นำทางต่อเนื่อง Google Maps
            </a>
            <button type="button" class="btn light" @click="openAutoStopModal(p)">⚡ ดึงร้านจากสายใบจอง</button>
            <button type="button" class="btn light" @click="openAddStopModal(p)">＋ เพิ่มจุดแวะ</button>
          </div>
        </div>

        <!-- Stops list inside plan -->
        <div v-if="(p.stops || []).length" class="plan-stops-list">
          <div v-for="st in p.stops" :key="st.id" :class="['plan-stop-row', { completed: st.status === 'completed' }]">
            <div class="plan-stop-left">
              <span class="plan-stop-num">{{ st.stop_order }}</span>
              <div class="plan-stop-info">
                <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap">
                  <strong>{{ st.shop_name || 'ร้านค้า' }}</strong>
                  <span v-if="st.code" class="tag gray">{{ st.code }}</span>
                  <span :class="['tag', st.status === 'completed' ? 'green' : 'orange']">
                    {{ st.status === 'completed' ? '✅ เข้าพบแล้ว' : '⏳ รอเข้าพบ' }}
                  </span>
                </div>
                <div class="small muted" style="margin-top: 2px">
                  {{
                    [st.address, st.province, st.phone ? `📞 ${st.phone}` : ''].filter(Boolean).join(' · ') ||
                    'ยังไม่ระบุที่อยู่'
                  }}
                </div>
              </div>
            </div>

            <div class="plan-stop-actions">
              <a
                v-if="st.latitude && st.longitude"
                :href="`https://www.google.com/maps?q=${st.latitude},${st.longitude}`"
                target="_blank"
                rel="noopener"
                class="btn light stop-btn"
              >
                🧭 นำทาง
              </a>
              <button type="button" class="btn primary stop-btn" @click="checkinStop(st)">📸 เช็คอิน</button>
              <button type="button" class="btn light stop-btn" @click="toggleStopDone(p.id, st)">
                {{ st.status === 'completed' ? '↩️ ยกเลิกเสร็จ' : '✓ ทำเครื่องหมายเสร็จ' }}
              </button>
              <button
                type="button"
                class="btn light stop-btn stop-btn-icon"
                title="ลบจุดแวะ"
                aria-label="ลบจุดแวะ"
                @click="removeStop(p.id, st.id)"
              >
                🗑️
              </button>
            </div>
          </div>
        </div>

        <div v-else class="plan-stops-empty">
          <span class="small muted"
            >ยังไม่มีร้านค้าในแผนนี้ — กด “⚡ ดึงร้านจากสายใบจอง” เพื่อให้ระบบจัดรายการร้านที่ยังไม่เคยเข้าพบให้ทันที</span
          >
        </div>
      </article>
    </div>

    <!-- Modal: Create Plan (with Auto-Fill from Reservation Route) -->
    <BaseModal :open="modalOpen" title="สร้างแผนออกตลาดประจำวัน" @close="modalOpen = false">
      <form id="planForm" class="form" @submit.prevent="createPlan">
        <div class="field span2">
          <label>ชื่อเส้นทาง / แผนออกตลาด *</label>
          <input v-model="planForm.route_name" required placeholder="เช่น สายใบจองประจำวัน / โซนอุบล-ตระการ" />
        </div>
        <div class="field">
          <label>วันที่ออกตลาด *</label>
          <NiceDateInput v-model="planForm.visit_date" :clearable="false" placeholder="เลือกวันที่ออกตลาด" />
        </div>
        <div class="field">
          <label>พื้นที่ / อำเภอ</label>
          <input v-model="planForm.district" placeholder="เช่น เมือง, ตระการพืชผล, วารินชำราบ" />
        </div>
        <div class="field">
          <label>ดึงร้านจากสายใบจองเข้าแผนอัตโนมัติ</label>
          <select v-model="planForm.auto_fill_mode">
            <option value="unvisited">⚡ ดึงร้านในสายที่ยังไม่เคยเข้าพบ (แนะนำ)</option>
            <option value="inactive">⚠️ ดึงร้านในสายที่หยุดซื้อเกิน 30 วัน</option>
            <option value="top_sales">🏆 ดึงร้านยอดซื้อสูงสุดในสาย</option>
            <option value="none">ไม่ดึงอัตโนมัติ (เลือกเพิ่มร้านเองภายหลัง)</option>
          </select>
        </div>
        <div class="field">
          <label>จำนวนร้านที่ดึงเข้าแผน</label>
          <input v-model.number="planForm.max_stops" type="number" min="1" max="40" />
        </div>
        <div class="field span2">
          <label>หมายเหตุ / เป้าหมายวันนี้</label>
          <input v-model="planForm.note" placeholder="เช่น เก็บภาพหน้าร้าน + แนะนำสินค้าใหม่" />
        </div>
      </form>
      <template #footer="{ requestClose }">
        <button type="button" class="btn" @click="requestClose">ยกเลิก</button>
        <button type="submit" form="planForm" class="btn primary" :disabled="saving">
          {{ saving ? 'กำลังสร้างแผน...' : '💾 บันทึกแผนออกตลาด' }}
        </button>
      </template>
    </BaseModal>

    <!-- Modal: Auto-Populate Stops into Existing Plan -->
    <BaseModal :open="Boolean(autoStopPlan)" title="⚡ ดึงร้านจากสายใบจองเข้าแผนอัตโนมัติ" @close="autoStopPlan = null">
      <form id="autoStopForm" class="form" @submit.prevent="runAutoPopulate">
        <div class="field span2">
          <label>เงื่อนไขการดึงลูกหนี้ในสายใบจอง</label>
          <select v-model="autoStopForm.mode">
            <option value="unvisited">⚡ เฉพาะร้านในสายที่ยังไม่เคยเช็คอินเข้าพบ</option>
            <option value="inactive">⚠️ เฉพาะร้านในสายที่หยุดซื้อเกิน 30 วัน</option>
            <option value="top_sales">🏆 ร้านยอดซื้อสูงสุดในสายใบจอง</option>
          </select>
        </div>
        <div class="field span2">
          <label>จำนวนร้านสูงสุดที่จะเพิ่มเข้าแผน</label>
          <input v-model.number="autoStopForm.max_stops" type="number" min="1" max="40" />
        </div>
      </form>
      <template #footer="{ requestClose }">
        <button type="button" class="btn" @click="requestClose">ยกเลิก</button>
        <button type="submit" form="autoStopForm" class="btn primary" :disabled="saving">
          {{ saving ? 'กำลังดึงร้าน...' : '⚡ ดึงร้านเข้าแผนทันที' }}
        </button>
      </template>
    </BaseModal>

    <!-- Modal: Manually Add Single Stop -->
    <BaseModal :open="Boolean(addStopPlan)" title="＋ เพิ่มร้านเข้าแผนออกตลาด" @close="addStopPlan = null">
      <div class="form">
        <div class="field span2">
          <label>ค้นหาร้านค้าในสายของคุณ</label>
          <input v-model="stopSearch" type="search" placeholder="พิมพ์ชื่อร้าน หรือ รหัสลูกหนี้..." />
        </div>
        <div class="field span2">
          <label>เลือกร้านค้า</label>
          <select v-model="selectedCustomerId">
            <option value="">-- เลือกร้านค้า --</option>
            <option v-for="c in filteredCustomers.slice(0, 80)" :key="c.id" :value="c.id">
              [{{ c.erp_customer_code }}] {{ c.customer_name }} {{ c.province ? `(${c.province})` : '' }}
            </option>
          </select>
        </div>
      </div>
      <template #footer="{ requestClose }">
        <button type="button" class="btn" @click="requestClose">ยกเลิก</button>
        <button type="button" class="btn primary" :disabled="saving || !selectedCustomerId" @click="submitAddStop">
          {{ saving ? 'กำลังเพิ่ม...' : '＋ เพิ่มจุดแวะ' }}
        </button>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import BaseModal from '../../../shared/components/BaseModal.vue';
import NiceDateInput from '../../../shared/components/NiceDateInput.vue';
import PageHeader from '../../../shared/components/PageHeader.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { toast } from '../../../shared/composables/useUiStore.js';
import { crmGet, crmMutate, me, openVisitWizard } from '../composables/useCrmSession.js';

const todayStr = new Date().toISOString().slice(0, 10);
const modalOpen = ref(false);
const saving = ref(false);
const planForm = ref({
  route_name: '',
  visit_date: todayStr,
  district: '',
  assigned_to: me.value?.id || null,
  note: '',
  auto_fill_mode: 'unvisited',
  max_stops: 10,
});

const autoStopPlan = ref(null);
const autoStopForm = ref({ mode: 'unvisited', max_stops: 10 });

const addStopPlan = ref(null);
const stopSearch = ref('');
const selectedCustomerId = ref('');

const {
  data: plans,
  isLoading,
  isError,
  error,
  refetch,
} = useQuery({
  queryKey: ['crm', 'plans'],
  queryFn: ({ signal }) => crmGet('/plans', { signal }),
});

const { data: customers } = useQuery({
  queryKey: ['crm', 'plan-customers'],
  queryFn: ({ signal }) => crmGet('/customers', { signal }),
});

const filteredCustomers = computed(() => {
  const q = stopSearch.value.trim().toLowerCase();
  const list = customers.value || [];
  if (!q) return list;
  return list.filter((c) => `${c.erp_customer_code} ${c.customer_name} ${c.province || ''}`.toLowerCase().includes(q));
});

function openCreateModal() {
  planForm.value = {
    route_name: `แผนออกตลาด ${todayStr}`,
    visit_date: todayStr,
    district: '',
    assigned_to: me.value?.id || null,
    note: '',
    auto_fill_mode: 'unvisited',
    max_stops: 10,
  };
  modalOpen.value = true;
}

async function createPlan() {
  saving.value = true;
  try {
    const res = await crmMutate('/plans', 'POST', planForm.value);
    modalOpen.value = false;
    toast.success('สร้างแผนออกตลาดเรียบร้อย', res.added_stops ? `ดึงร้านเข้าแผนอัตโนมัติ ${res.added_stops} ร้าน` : '');
    await refetch();
  } catch (err) {
    toast.error('สร้างแผนไม่สำเร็จ', err);
  } finally {
    saving.value = false;
  }
}

function openAutoStopModal(plan) {
  autoStopPlan.value = plan;
  autoStopForm.value = { mode: 'unvisited', max_stops: 10 };
}

async function runAutoPopulate() {
  if (!autoStopPlan.value) return;
  saving.value = true;
  try {
    const res = await crmMutate(`/plans/${autoStopPlan.value.id}/auto-stops`, 'POST', autoStopForm.value);
    autoStopPlan.value = null;
    toast.success('ดึงร้านเข้าแผนเรียบร้อย', `เพิ่มจุดแวะใหม่ ${res.added_stops || 0} ร้าน`);
    await refetch();
  } catch (err) {
    toast.error('ดึงร้านเข้าแผนไม่สำเร็จ', err);
  } finally {
    saving.value = false;
  }
}

function openAddStopModal(plan) {
  addStopPlan.value = plan;
  stopSearch.value = '';
  selectedCustomerId.value = '';
}

async function submitAddStop() {
  if (!addStopPlan.value || !selectedCustomerId.value) return;
  saving.value = true;
  try {
    await crmMutate(`/plans/${addStopPlan.value.id}/stops`, 'POST', {
      erp_customer_id: Number(selectedCustomerId.value),
    });
    addStopPlan.value = null;
    toast.success('เพิ่มจุดแวะเรียบร้อย');
    await refetch();
  } catch (err) {
    toast.error('เพิ่มจุดแวะไม่สำเร็จ', err);
  } finally {
    saving.value = false;
  }
}

async function toggleStopDone(planId, stop) {
  const nextStatus = stop.status === 'completed' ? 'pending' : 'completed';
  try {
    await crmMutate(`/plans/${planId}/stops/${stop.id}`, 'PATCH', { status: nextStatus });
    await refetch();
  } catch (err) {
    toast.error('อัปเดตสถานะจุดแวะไม่สำเร็จ', err);
  }
}

async function removeStop(planId, stopId) {
  try {
    await crmMutate(`/plans/${planId}/stops/${stopId}`, 'DELETE', {});
    toast.success('ลบจุดแวะแล้ว');
    await refetch();
  } catch (err) {
    toast.error('ลบจุดแวะไม่สำเร็จ', err);
  }
}

function checkinStop(stop) {
  if (stop.erp_customer_id) {
    openVisitWizard({
      kind: 'erp',
      id: Number(stop.erp_customer_id),
      planStopId: Number(stop.id),
      label: stop.shop_name,
    });
  } else if (stop.crm_lead_id) {
    openVisitWizard({ kind: 'lead', id: Number(stop.crm_lead_id), planStopId: Number(stop.id), label: stop.shop_name });
  }
}

function multiStopDirectionsUrl(plan) {
  const pinned = (plan.stops || []).filter(
    (s) =>
      s.latitude != null &&
      s.longitude != null &&
      s.latitude !== '' &&
      s.longitude !== '' &&
      Number.isFinite(Number(s.latitude)) &&
      Number.isFinite(Number(s.longitude)) &&
      Math.abs(Number(s.latitude)) <= 90 &&
      Math.abs(Number(s.longitude)) <= 180
  );
  if (!pinned.length) return '';
  if (pinned.length === 1) {
    return `https://www.google.com/maps?q=${pinned[0].latitude},${pinned[0].longitude}`;
  }
  const coords = pinned
    .slice(0, 10)
    .map((s) => `${s.latitude},${s.longitude}`)
    .join('/');
  return `https://www.google.com/maps/dir/${coords}`;
}
</script>

<style scoped>
.plans-layout {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.plan-card {
  overflow: hidden;
}

.plan-card-head {
  padding: 14px 16px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  background: #f8fafc;
}

.plan-title-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.plan-route-title {
  font-size: 15.5px;
  color: var(--ink);
}

.plan-head-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.plan-stops-list {
  display: flex;
  flex-direction: column;
}

.plan-stop-row {
  padding: 11px 16px;
  border-bottom: 1px solid #f1f5f9;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}

.plan-stop-row:last-child {
  border-bottom: 0;
}

.plan-stop-row.completed {
  background: #f0fdf4;
}

.plan-stop-left {
  display: flex;
  align-items: flex-start;
  gap: 11px;
  min-width: 0;
  flex: 1 1 240px;
}

.plan-stop-info {
  flex: 1;
  min-width: 0;
}

.plan-stop-num {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--blue-soft);
  color: var(--blue);
  font-weight: 800;
  font-size: 13px;
  display: grid;
  place-items: center;
  flex-shrink: 0;
  margin-top: 2px;
}

.plan-stop-actions {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.stop-btn {
  min-height: 36px;
  padding: 6px 11px;
  font-size: 12.5px;
}

.stop-btn-icon {
  flex: 0 0 auto;
  min-width: 40px;
  padding: 6px 10px;
}

.plan-stops-empty {
  padding: 16px;
  text-align: center;
}

@media (max-width: 720px) {
  .plan-card-head {
    flex-direction: column;
    align-items: stretch;
    padding: 13px 14px;
  }

  .plan-head-actions {
    width: 100%;
  }

  .plan-head-actions > :is(.btn, a) {
    flex: 1 1 140px;
    font-size: 13px;
    padding: 8px 10px;
  }

  .plan-stop-row {
    flex-direction: column;
    align-items: stretch;
    gap: 10px;
    padding: 13px 14px;
  }

  .plan-stop-left {
    flex: 1 1 auto;
    width: 100%;
  }

  .plan-stop-actions {
    width: 100%;
  }

  .stop-btn {
    flex: 1 1 110px;
    min-height: 40px;
    font-size: 13px;
  }

  .stop-btn-icon {
    flex: 0 0 44px;
  }
}
</style>
