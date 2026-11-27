<template>
  <div>
    <!-- Mobile Native App Visits Screen (<= 800px — Matches Mockup) -->
    <div class="mob-only mob-visits-shell">
      <div class="app-section-head">
        <h3>ประวัติเข้าพบล่าสุด</h3>
        <button
          type="button"
          class="btn primary"
          style="min-height: 34px; padding: 5px 12px; font-size: 12.5px; border-radius: 999px"
          @click="openVisitWizard()"
        >
          + บันทึกเข้าพบ
        </button>
      </div>

      <div class="app-search-pill">
        <span class="app-search-ico" aria-hidden="true">🔍</span>
        <input v-model="searchRaw" type="search" aria-label="ค้นหาประวัติเข้าพบ" placeholder="ค้นหาชื่อร้าน, หมายเหตุ..." />
        <button
          v-if="searchRaw"
          type="button"
          class="search-clear-btn"
          aria-label="ล้างคำค้นหา"
          @click="
            searchRaw = '';
            search = '';
            page = 1;
          "
        >
          ✕
        </button>
      </div>

      <details class="card mobile-visit-filters">
        <summary>
          <span
            >📅 ช่วงวันที่และตัวกรอง<span v-if="routeCode || resultFilter" class="filter-indicator"> · ใช้ตัวกรอง</span></span
          ><strong>{{ mobileRangeLabel }}</strong>
        </summary>
        <div class="mobile-visit-filter-body">
          <DateRangePicker v-model:from="from" v-model:to="to" />
          <label
            >สายการขาย
            <select v-model="routeCode" aria-label="สายการขาย">
              <option value="">ทุกสายที่มีสิทธิ์</option>
              <option v-for="r in dimensions?.routes || []" :key="r.route_code" :value="r.route_code">
                [{{ r.route_code }}] {{ r.route_name || 'ไม่ระบุชื่อ' }}
              </option>
            </select>
          </label>
          <label
            >ผลการเข้าพบ
            <select v-model="resultFilter" @change="page = 1">
              <option value="">ทุกผลการเข้าพบ</option>
              <option value="visited">เข้าพบเรียบร้อย</option>
              <option value="interested">สนใจสินค้า</option>
              <option value="follow_up">ต้องติดตาม</option>
              <option value="not_interested">ยังไม่สนใจ</option>
              <option value="not_found">ไม่พบลูกค้า</option>
            </select>
          </label>
        </div>
      </details>

      <div v-if="offlineDrafts.length" class="card panel-pad" style="border-left: 4px solid var(--blue)">
        <div class="row-between">
          <span class="small"
            >ค้างส่ง <b>{{ offlineDrafts.length }}</b> รายการ</span
          >
          <button
            type="button"
            class="btn primary"
            style="min-height: 36px; padding: 6px 12px; font-size: 12.5px"
            :disabled="offlineSyncing"
            @click="syncPendingOfflineDrafts"
          >
            {{ offlineSyncing ? 'กำลังส่ง...' : 'ส่งทั้งหมด' }}
          </button>
        </div>
      </div>

      <div v-if="!validRange" class="card placeholder">กรุณาเลือกช่วงวันที่ให้ถูกต้อง</div>
      <div v-else-if="isFetching" aria-live="polite">
        <p class="small muted">กำลังโหลดประวัติตามตัวกรอง...</p>
        <SkeletonBlock variant="cards" :rows="3" />
      </div>
      <div v-else-if="isError" class="error">{{ error?.message || 'โหลดประวัติเข้าพบไม่สำเร็จ' }}</div>
      <div v-else class="stack-sm">
        <article v-for="v in rows" :key="`mv-${v.id}`" class="card mob-visit-card">
          <div class="row-between-start">
            <div style="min-width: 0; flex: 1 1 160px">
              <strong style="display: block; font-size: 14.5px; line-height: 1.3">{{
                v.customer_name || v.shop_name || 'ร้านค้า'
              }}</strong>
              <div class="small muted" style="margin-top: 2px">
                {{
                  new Date(v.server_recorded_at || v.visited_at).toLocaleString('th-TH', {
                    day: 'numeric',
                    month: 'short',
                    hour: '2-digit',
                    minute: '2-digit',
                  })
                }}
                <template v-if="v.staff_name"> · {{ v.staff_name }}</template>
              </div>
            </div>
            <span class="tag blue">{{ visitResultLabel(v.result) }}</span>
          </div>
          <p
            v-if="v.supplier_name || v.product_types || v.note"
            class="small"
            style="margin: 8px 0 0; color: #334155; line-height: 1.5"
          >
            {{ v.note || `${v.supplier_name || ''}${v.product_types ? ` · ${v.product_types}` : ''}` }}
          </p>
          <div class="mob-visit-foot">
            <span class="small" style="color: var(--blue); font-weight: 600">
              📷 {{ Number(v.photo_count || 0) }} รูป · 📍
              {{ v.checkin_latitude != null && v.checkin_longitude != null ? 'มีพิกัด GPS' : 'ไม่มีพิกัด' }}
            </span>
            <button
              v-if="Number(v.photo_count) > 0"
              type="button"
              class="app-section-link"
              style="
                font-size: 12.5px;
                font-weight: 600;
                color: #475569;
                border: 0;
                background: transparent;
                padding: 0;
              "
              @click="openVisitPhotos(v)"
            >
              ดูรูป ›
            </button>
          </div>
        </article>
        <div v-if="!rows.length" class="card placeholder" style="padding: 32px 16px">ยังไม่มีบันทึกเข้าพบในช่วงนี้</div>
      </div>

      <div v-if="meta.last_page > 1" class="pager" style="border-top: 0; padding: 4px 0">
        <span class="small muted">หน้า {{ meta.current_page }} / {{ meta.last_page }}</span>
        <div class="pager-btns">
          <button type="button" class="btn" :disabled="page <= 1" @click="page--">← ก่อนหน้า</button>
          <button type="button" class="btn" :disabled="page >= meta.last_page" @click="page++">ถัดไป →</button>
        </div>
      </div>
    </div>

    <!-- Desktop Visits Workspace (> 800px) -->
    <div class="desktop-only">
      <PageHeader title="ออกตลาด" subtitle="ประวัติการเข้าพบ พร้อมรูปถ่าย เวลา และพิกัด GPS (แตะรูปเพื่อดูขยาย)">
        <template #primary>
          <button type="button" class="btn primary" @click="openVisitWizard()">📸 บันทึกเข้าพบ</button>
        </template>
      </PageHeader>

      <div
        v-if="offlineDrafts.length"
        class="card panel-pad"
        style="margin-bottom: 16px; border-left: 4px solid var(--blue)"
      >
        <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap">
          <div>
            <strong>Offline Draft Queue ({{ offlineDrafts.length }} รายการค้างส่ง)</strong>
            <div class="small muted">ระบบใช้ client_draft_id เพื่อป้องกันการซิงค์ซ้ำหากสัญญาณขาดช่วง</div>
          </div>
          <button type="button" class="btn primary" :disabled="offlineSyncing" @click="syncPendingOfflineDrafts">
            {{ offlineSyncing ? 'กำลังซิงค์...' : 'ซิงค์ Offline Draft ทั้งหมด' }}
          </button>
        </div>
        <div v-for="d in offlineDrafts" :key="d.client_draft_id" class="minirow">
          <div>
            <b>{{ d.shopLabel || d.client_draft_id }}</b>
            <div class="small muted">เวลาเครื่อง: {{ d.updatedAt }} · ภาพแนบ: {{ d.photos?.length || 0 }} ภาพ</div>
          </div>
          <button type="button" class="btn danger" style="padding: 5px 10px" @click="discardDraft(d.client_draft_id)">
            ลบ Draft
          </button>
        </div>
      </div>

      <div class="card panel-pad" style="margin-bottom: 16px">
        <DateRangePicker v-model:from="from" v-model:to="to" />
        <p class="small muted">แสดงบันทึกตามวันที่เข้าพบ · เมื่อเลือกสายจะแสดงเฉพาะร้านที่เชื่อมสาย ERP แล้ว</p>
        <div class="head-filter" style="margin-bottom: 0">
          <input v-model="searchRaw" class="filter-search-input" placeholder="ค้นหาชื่อร้าน, แหล่งซื้อ, หมายเหตุ..." />
          <select v-model="routeCode" aria-label="สายการขาย">
            <option value="">ทุกสายที่มีสิทธิ์</option>
            <option v-for="r in dimensions?.routes || []" :key="r.route_code" :value="r.route_code">
              [{{ r.route_code }}] {{ r.route_name || 'ไม่ระบุชื่อ' }}
            </option>
          </select>
          <select v-model="resultFilter" @change="page = 1">
            <option value="">ทุกผลการเข้าพบ</option>
            <option value="visited">เข้าพบเรียบร้อย</option>
            <option value="interested">สนใจสินค้า</option>
            <option value="follow_up">ต้องติดตาม</option>
            <option value="not_interested">ยังไม่สนใจ</option>
            <option value="not_found">ไม่พบลูกค้า</option>
          </select>
        </div>
      </div>

      <div v-if="!validRange" class="card panel-pad">กรุณาเลือกช่วงวันที่ให้ถูกต้อง</div>
      <SkeletonBlock v-else-if="isLoading && !rows.length" variant="table" :rows="6" />
      <div v-else-if="isError" class="error">{{ error?.message || 'โหลดประวัติเข้าพบไม่สำเร็จ' }}</div>

      <div v-else class="card">
        <div class="table-wrap desktop-table-only">
          <table class="table">
            <thead>
              <tr>
                <th>ร้านค้า</th>
                <th>ผลเข้าพบ</th>
                <th>แหล่งซื้อ / สินค้า</th>
                <th>GPS &amp; เวลาบันทึก</th>
                <th>พนักงาน</th>
                <th>รูปหลักฐาน</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="v in rows" :key="v.id">
                <td>
                  <b>{{ v.customer_name || v.shop_name || 'ร้านค้า' }}</b>
                  <div class="small muted">
                    {{ v.erp_customer_code || (v.crm_lead_id ? `LEAD-${v.crm_lead_id}` : '') }}
                  </div>
                </td>
                <td>
                  <span class="tag blue">{{ visitResultLabel(v.result) }}</span>
                </td>
                <td style="white-space: normal; max-width: 240px">
                  <div>{{ v.supplier_name || '—' }}</div>
                  <div class="small muted">{{ v.product_types || '' }}</div>
                </td>
                <td style="white-space: normal; min-width: 190px">
                  <div class="small">
                    Server:
                    {{
                      v.server_recorded_at
                        ? new Date(v.server_recorded_at).toLocaleString('th-TH')
                        : new Date(v.visited_at).toLocaleString('th-TH')
                    }}
                  </div>
                  <div v-if="v.device_recorded_at" class="small muted">
                    Device: {{ new Date(v.device_recorded_at).toLocaleString('th-TH') }}
                  </div>
                  <div class="small muted">
                    GPS: {{ v.gps_permission_status || '—' }}
                    <span v-if="v.checkin_latitude"
                      >({{ Number(v.checkin_latitude).toFixed(4) }}, {{ Number(v.checkin_longitude).toFixed(4) }})</span
                    >
                  </div>
                </td>
                <td>{{ v.staff_name || '—' }}</td>
                <td>
                  <button v-if="Number(v.photo_count) > 0" type="button" class="btn light" @click="openVisitPhotos(v)">
                    🖼 ดูรูป ({{ v.photo_count }})
                  </button>
                  <span v-else class="small muted">ไม่มีรูป</span>
                </td>
              </tr>
              <tr v-if="!rows.length">
                <td colspan="6" class="placeholder">ยังไม่มีบันทึกเข้าพบร้านค้า</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="mob-cards panel-pad">
          <div v-for="v in rows" :key="v.id" class="card mob-card">
            <div class="row-between-start">
              <strong style="min-width: 0; flex: 1 1 180px">{{ v.customer_name || v.shop_name || 'ร้านค้า' }}</strong>
              <span class="tag blue">{{ visitResultLabel(v.result) }}</span>
            </div>
            <div class="small muted" style="margin-top: 4px">
              {{ new Date(v.server_recorded_at || v.visited_at).toLocaleString('th-TH') }} · GPS:
              {{ v.gps_permission_status || '—' }}
            </div>
            <div v-if="v.supplier_name || v.product_types" class="small" style="margin-top: 6px">
              แหล่งซื้อ: {{ v.supplier_name || '—' }} · {{ v.product_types || '' }}
            </div>
            <div style="margin-top: 8px; display: flex; justify-content: flex-end">
              <button v-if="Number(v.photo_count) > 0" type="button" class="btn light" @click="openVisitPhotos(v)">
                🖼 ดูภาพหลักฐาน ({{ v.photo_count }})
              </button>
            </div>
          </div>
          <div v-if="!rows.length" class="placeholder">ยังไม่มีบันทึกการเข้าพบ</div>
        </div>

        <div class="pager">
          <span>ทั้งหมด {{ fmt(meta.total) }} รายการ (หน้า {{ meta.current_page }} / {{ meta.last_page }})</span>
          <div class="pager-btns">
            <button type="button" class="btn" :disabled="page <= 1" @click="page--">← ก่อนหน้า</button>
            <button type="button" class="btn" :disabled="page >= meta.last_page" @click="page++">ถัดไป →</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
import { keepPreviousData, useQuery } from '@tanstack/vue-query';
import PageHeader from '../../../shared/components/PageHeader.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import DateRangePicker from '../components/DateRangePicker.vue';
import { askConfirm, toast } from '../../../shared/composables/useUiStore.js';
import { removeOfflineDraft } from '../../../shared/utils/offlineDrafts.js';
import { visitResultLabel } from '../utils/visitResult.js';
import {
  crmGet,
  fmt,
  offlineDrafts,
  offlineSyncing,
  openLightbox,
  openVisitWizard,
  reloadOfflineDrafts,
  syncPendingOfflineDrafts,
} from '../composables/useCrmSession.js';

const searchRaw = ref('');
const search = ref('');
const resultFilter = ref('');
const page = ref(1);
const today = new Date();
const iso = (d) =>
  `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const from = ref(iso(new Date(today.getFullYear(), today.getMonth(), 1)));
const to = ref(iso(today));
const routeCode = ref('');
const validRange = computed(() => Boolean(from.value && to.value && from.value <= to.value));
const mobileRangeLabel = computed(() =>
  validRange.value
    ? [from.value, to.value]
        .map((value) =>
          new Date(`${value}T00:00:00`).toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: '2-digit' })
        )
        .join(' – ')
    : 'เลือกวันที่'
);
watch([from, to, routeCode], () => {
  page.value = 1;
});
const { data: dimensions } = useQuery({
  queryKey: ['crm', 'sales-dimensions', 'BPLUS'],
  queryFn: ({ signal }) => crmGet('/reports/sales-dimensions?source_system=BPLUS', { signal }),
});

let t = null;
watch(searchRaw, (v) => {
  if (t) clearTimeout(t);
  t = setTimeout(() => {
    search.value = v.trim();
    page.value = 1;
  }, 300);
});
onUnmounted(() => {
  if (t) clearTimeout(t);
});

const { data, isLoading, isFetching, isError, error } = useQuery({
  queryKey: computed(() => [
    'crm',
    'visits',
    {
      page: page.value,
      q: search.value,
      result: resultFilter.value,
      from: from.value,
      to: to.value,
      route: routeCode.value,
    },
  ]),
  queryFn: ({ signal }) => {
    const p = new URLSearchParams({ paginate: '1', per_page: '20', page: String(page.value) });
    if (search.value) p.set('q', search.value);
    if (resultFilter.value) p.set('result', resultFilter.value);
    p.set('from', from.value);
    p.set('to', to.value);
    if (routeCode.value) p.set('route_code', routeCode.value);
    return crmGet(`/visits?${p.toString()}`, { signal });
  },
  placeholderData: keepPreviousData,
  enabled: validRange,
});

const rows = computed(() => data.value?.data || []);
const meta = computed(() => data.value?.meta || { current_page: 1, last_page: 1, total: 0 });

async function openVisitPhotos(v) {
  try {
    const photos = await crmGet(`/field-visits/${v.id}/photos`);
    if (!photos.length) {
      toast.info('ไม่มีรูปในรายการนี้');
      return;
    }
    openLightbox(
      photos.map((p) => ({
        src: `/api/crm/photos/${p.id}?inline=1`,
        title: v.customer_name || v.shop_name || `Visit #${v.id}`,
        category: p.category,
        capturedAt: p.captured_at ? new Date(p.captured_at).toLocaleString('th-TH') : '',
        consentConfirmed: p.consent_confirmed,
        compressedOnClient: p.compressed_on_client,
        latitude: p.latitude,
        longitude: p.longitude,
      })),
      0
    );
  } catch (err) {
    toast.error('โหลดรูปหลักฐานไม่สำเร็จ', err);
  }
}

async function discardDraft(id) {
  const ok = await askConfirm({
    title: 'ลบ Offline Draft',
    message: 'ต้องการลบ Draft ที่ยังไม่ส่งนี้ออกจากอุปกรณ์หรือไม่?',
    confirmLabel: 'ลบ Draft',
    tone: 'danger',
  });
  if (!ok) return;
  await removeOfflineDraft(id);
  await reloadOfflineDrafts();
  toast.info('ลบ Draft ออกจากอุปกรณ์แล้ว');
}
</script>

<style scoped>
.mob-visits-shell {
  display: none;
}

@media (max-width: 800px) {
  .mob-visits-shell {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }
}

.mobile-visit-filters {
  padding: 0;
  overflow: hidden;
}
.mobile-visit-filters summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 12px 14px;
  cursor: pointer;
  font-size: 13px;
  font-weight: 700;
}
.mobile-visit-filters summary strong {
  color: #0369a1;
  font-size: 12px;
  text-align: right;
}
.filter-indicator {
  color: #0369a1;
  font-size: 11px;
}
.mobile-visit-filter-body {
  display: grid;
  gap: 10px;
  padding: 0 12px 12px;
}
.mobile-visit-filter-body label {
  display: grid;
  gap: 4px;
  color: #475569;
  font-size: 13px;
  font-weight: 600;
}
.mobile-visit-filter-body select {
  min-height: 44px;
  width: 100%;
  font-size: 16px;
}

.mob-visit-card {
  padding: 15px;
}

.mob-visit-foot {
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px solid #f1f5f9;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}
</style>
