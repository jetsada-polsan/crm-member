<template>
  <div>
    <div class="pagehead">
      <div>
        <div class="eyebrow">FIELD VISIT EVIDENCE</div>
        <h2>บันทึกเข้าพบร้าน</h2>
        <p>ประวัติการเข้าพบ พร้อมรูปถ่าย เวลา และพิกัด GPS (แตะรูปเพื่อดูขยาย)</p>
      </div>
      <div class="head-actions">
        <button type="button" class="btn primary" @click="openVisitWizard()">📸 เริ่มบันทึกเข้าพบ (9 ขั้นตอน)</button>
      </div>
    </div>

    <div v-if="offlineDrafts.length" class="card panel-pad" style="margin-bottom:16px;border-left:4px solid var(--blue)">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
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
        <button type="button" class="btn danger" style="padding:5px 10px" @click="discardDraft(d.client_draft_id)">ลบ Draft</button>
      </div>
    </div>

    <div class="card panel-pad" style="margin-bottom:16px">
      <div class="head-filter" style="margin-bottom:0">
        <input v-model="searchRaw" style="flex:1;min-width:200px" placeholder="ค้นหาชื่อร้าน, แหล่งซื้อ, หมายเหตุ..." />
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

    <SkeletonBlock v-if="isLoading && !rows.length" variant="table" :rows="6" />
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
                <div class="small muted">{{ v.erp_customer_code || (v.crm_lead_id ? `LEAD-${v.crm_lead_id}` : '') }}</div>
              </td>
              <td><span class="tag blue">{{ v.result }}</span></td>
              <td style="white-space:normal;max-width:240px">
                <div>{{ v.supplier_name || '—' }}</div>
                <div class="small muted">{{ v.product_types || '' }}</div>
              </td>
              <td style="white-space:normal;min-width:190px">
                <div class="small">Server: {{ v.server_recorded_at ? new Date(v.server_recorded_at).toLocaleString('th-TH') : new Date(v.visited_at).toLocaleString('th-TH') }}</div>
                <div v-if="v.device_recorded_at" class="small muted">Device: {{ new Date(v.device_recorded_at).toLocaleString('th-TH') }}</div>
                <div class="small muted">
                  GPS: {{ v.gps_permission_status || '—' }}
                  <span v-if="v.checkin_latitude">({{ Number(v.checkin_latitude).toFixed(4) }}, {{ Number(v.checkin_longitude).toFixed(4) }})</span>
                </div>
              </td>
              <td>{{ v.staff_name || '—' }}</td>
              <td>
                <button
                  v-if="Number(v.photo_count) > 0"
                  type="button"
                  class="btn light"
                  @click="openVisitPhotos(v)"
                >
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

      <div class="mob-cards" style="padding:12px">
        <div v-for="v in rows" :key="v.id" class="card mob-card">
          <div style="display:flex;justify-content:space-between;gap:8px">
            <strong>{{ v.customer_name || v.shop_name || 'ร้านค้า' }}</strong>
            <span class="tag blue">{{ v.result }}</span>
          </div>
          <div class="small muted" style="margin-top:4px">
            {{ new Date(v.server_recorded_at || v.visited_at).toLocaleString('th-TH') }} · GPS: {{ v.gps_permission_status || '—' }}
          </div>
          <div v-if="v.supplier_name || v.product_types" class="small" style="margin-top:6px">
            แหล่งซื้อ: {{ v.supplier_name || '—' }} · {{ v.product_types || '' }}
          </div>
          <div style="margin-top:8px;display:flex;justify-content:flex-end">
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
</template>

<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
import { keepPreviousData, useQuery } from '@tanstack/vue-query';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { askConfirm, toast } from '../../../shared/composables/useUiStore.js';
import { removeOfflineDraft } from '../../../shared/utils/offlineDrafts.js';
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

const { data, isLoading, isError, error } = useQuery({
  queryKey: computed(() => ['crm', 'visits', { page: page.value, q: search.value, result: resultFilter.value }]),
  queryFn: ({ signal }) => {
    const p = new URLSearchParams({ paginate: '1', per_page: '20', page: String(page.value) });
    if (search.value) p.set('q', search.value);
    if (resultFilter.value) p.set('result', resultFilter.value);
    return crmGet(`/visits?${p.toString()}`, { signal });
  },
  placeholderData: keepPreviousData,
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
