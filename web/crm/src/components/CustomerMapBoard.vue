<template>
  <section class="card map-board-card" aria-label="แผนที่ร้านค้าและภาพถ่ายหน้างาน">
    <div class="map-board-head">
      <div class="map-board-title">
        <div class="map-board-icon" aria-hidden="true">🗺️</div>
        <div>
          <h3>แผนที่ร้านค้า &amp; ภาพเข้าพบหน้างาน</h3>
          <p class="small muted">
            ปักหมุดแล้ว <b>{{ fmt(allPins.length) }}</b> ร้าน · มีรูปถ่าย <b>{{ fmt(pinsWithPhotosCount) }}</b> ร้าน (<b>{{ fmt(totalPhotosCount) }}</b> รูป)
          </p>
        </div>
      </div>

      <div class="map-board-controls">
        <div class="map-filter-pills" role="group" aria-label="กรองประเภทร้านบนแผนที่">
          <button
            v-for="tab in FILTER_TABS"
            :key="tab.id"
            type="button"
            :class="['map-filter-chip', { active: filterKind === tab.id }]"
            @click="filterKind = tab.id"
          >
            {{ tab.label }} ({{ tab.count }})
          </button>
        </div>

        <input
          v-model="search"
          type="search"
          class="map-board-search"
          placeholder="ค้นหาชื่อร้าน, รหัส ERP, จังหวัด..."
          aria-label="ค้นหาร้านบนแผนที่"
        />

        <RouterLink to="/map" class="btn light">📌 ปักหมุด / แผนที่เต็ม →</RouterLink>
      </div>
    </div>

    <SkeletonBlock v-if="isLoading && !allPins.length" variant="cards" :rows="2" />
    <div v-else-if="isError" class="panel-pad">
      <div class="error" style="margin-bottom:0">{{ error?.message || 'โหลดข้อมูลแผนที่ไม่สำเร็จ' }}</div>
    </div>

    <div v-else class="map-board-body">
      <!-- Left: Interactive Leaflet Map -->
      <div class="map-board-canvas-col">
        <CustomerMap
          :pins="filteredPins"
          :selected-pin-id="selectedPinId"
          height="420px"
          @select="openPinPopup"
        />
        <div class="map-foot" style="margin-top:12px">
          <div class="map-legend-group">
            <span><i class="legend-dot erp"></i>ลูกค้า ERP</span>
            <span><i class="legend-dot approved"></i>Lead อนุมัติ</span>
            <span><i class="legend-dot pending"></i>Lead รออนุมัติ</span>
          </div>
          <span v-if="overlapCount > 1" class="tag blue">
            แยกจุดพิกัดใกล้กัน {{ overlapCount }} ร้านให้กดได้ครบแล้ว
          </span>
        </div>
      </div>

      <!-- Right: Searchable Store Map Table + Photo Thumbnails -->
      <div class="map-board-list-col">
        <div class="map-list-head">
          <strong>ตารางร้านบนแผนที่ ({{ fmt(filteredPins.length) }})</strong>
          <span class="small muted">กดที่ร้านเพื่อดูป๊อปอัพ &amp; รูปถ่าย</span>
        </div>

        <div v-if="!filteredPins.length" class="map-list-empty">
          <div style="font-size:32px;margin-bottom:6px">📍</div>
          <strong>ไม่พบร้านที่ตรงกับตัวกรอง</strong>
          <p class="small muted">ลองล้างคำค้นหา หรือบันทึกเข้าพบพร้อม GPS เพื่อแสดงหมุดร้านค้า</p>
        </div>

        <template v-else>
          <!-- Desktop structured table -->
          <div class="map-table-scroll desktop-table-only">
            <table class="table map-store-table">
              <thead>
                <tr>
                  <th>ร้านค้า &amp; รูป</th>
                  <th>เข้าพบล่าสุด</th>
                  <th style="text-align:right">ดูข้อมูล</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="pin in filteredPins"
                  :key="pin.id"
                  :class="['map-store-row', { 'is-selected': String(pin.id) === String(selectedPinId) }]"
                  @click="openPinPopup(pin)"
                >
                  <td style="white-space:normal">
                    <div class="map-shop-cell">
                      <button
                        type="button"
                        class="map-thumb-btn"
                        :title="pin.photos?.length ? 'กดดูรูปหลักฐาน' : 'ยังไม่มีรูปถ่าย'"
                        @click.stop="pin.photos?.length ? openFirstPhoto(pin) : openPinPopup(pin)"
                      >
                        <img
                          v-if="pin.photos?.length"
                          :src="pin.photos[0].url"
                          :alt="pinDisplayName(pin)"
                        />
                        <span v-else class="map-thumb-placeholder">🏪</span>
                        <span v-if="pin.photo_count > 1" class="map-thumb-more">+{{ pin.photo_count - 1 }}</span>
                      </button>
                      <div class="map-shop-info">
                        <b class="map-shop-name">{{ pinDisplayName(pin) }}</b>
                        <div class="map-shop-sub">
                          <span :class="['tag', pinTypeMeta(pin).tag]">{{ pinCodeLabel(pin) || pinTypeMeta(pin).label }}</span>
                          <span v-if="pin.province" class="small muted">{{ pin.province }}</span>
                        </div>
                      </div>
                    </div>
                  </td>
                  <td style="white-space:normal">
                    <template v-if="pin.latest_visit">
                      <span :class="['tag', visitResultMeta(pin.latest_visit.result).tag]">
                        {{ visitResultMeta(pin.latest_visit.result).label }}
                      </span>
                      <div class="small muted" style="margin-top:4px">
                        {{ pin.latest_visit.staff_name || 'ทีมขาย' }} · {{ formatThaiDateTime(pin.latest_visit.visited_at) }}
                      </div>
                    </template>
                    <span v-else class="small muted">ยังไม่เคยเข้าพบ</span>
                  </td>
                  <td style="text-align:right">
                    <button type="button" class="btn light map-row-btn" @click.stop="openPinPopup(pin)">
                      {{ pin.photo_count > 0 ? `📷 ${pin.photo_count} รูป` : 'ดูหมุด' }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Mobile card list with arranged mini photo strip -->
          <div class="mob-cards map-mob-list">
            <article
              v-for="pin in filteredPins"
              :key="`m-${pin.id}`"
              :class="['card mob-card map-mob-card', { 'is-selected': String(pin.id) === String(selectedPinId) }]"
              @click="openPinPopup(pin)"
            >
              <div class="map-mob-top">
                <div style="min-width:0">
                  <strong class="map-shop-name">{{ pinDisplayName(pin) }}</strong>
                  <div class="small muted" style="margin-top:2px">
                    {{ pinCodeLabel(pin) }}
                    <template v-if="pin.province"> · {{ pin.province }}</template>
                  </div>
                </div>
                <span :class="['tag', pin.latest_visit ? visitResultMeta(pin.latest_visit.result).tag : pinTypeMeta(pin).tag]">
                  {{ pin.latest_visit ? visitResultMeta(pin.latest_visit.result).label : pinTypeMeta(pin).label }}
                </span>
              </div>

              <div v-if="pin.photos?.length" class="map-mob-strip" @click.stop>
                <button
                  v-for="(photo, idx) in pin.photos.slice(0, 4)"
                  :key="`${photo.kind}-${photo.id}`"
                  type="button"
                  class="map-mob-thumb"
                  @click="openPinPhoto(pin, idx)"
                >
                  <img :src="photo.url" :alt="`${pinDisplayName(pin)} #${idx + 1}`" loading="lazy" />
                </button>
              </div>

              <div class="map-mob-foot">
                <span class="small muted">
                  {{ pin.latest_visit ? formatThaiDateTime(pin.latest_visit.visited_at) : 'แตะเพื่อดูรายละเอียด' }}
                </span>
                <button type="button" class="btn light" @click.stop="openPinPopup(pin)">
                  {{ pin.photo_count > 0 ? `เปิดดู (${pin.photo_count} รูป)` : 'เปิดรายละเอียด' }}
                </button>
              </div>
            </article>
          </div>
        </template>
      </div>
    </div>

    <PinDetailModal :pin="activePin" @close="activePin = null" />
  </section>
</template>

<script setup>
import { computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { useQuery } from '@tanstack/vue-query';
import CustomerMap from '../CustomerMap.vue';
import PinDetailModal from './PinDetailModal.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { crmGet, fmt, openLightbox } from '../composables/useCrmSession.js';
import {
  formatThaiDateTime,
  pinCodeLabel,
  pinDisplayName,
  pinLightboxItems,
  pinTypeMeta,
  visitResultMeta,
} from '../utils/pinPresentation.js';

const search = ref('');
const filterKind = ref('all');
const selectedPinId = ref(null);
const activePin = ref(null);

const { data: pinsData, isLoading, isError, error } = useQuery({
  queryKey: ['crm', 'pins'],
  queryFn: ({ signal }) => crmGet('/pins', { signal }),
});

const allPins = computed(() => pinsData.value || []);
const pinsWithPhotosCount = computed(() => allPins.value.filter((p) => Number(p.photo_count || 0) > 0).length);
const totalPhotosCount = computed(() => allPins.value.reduce((acc, p) => acc + Number(p.photo_count || 0), 0));

const FILTER_TABS = computed(() => [
  { id: 'all', label: 'ทั้งหมด', count: allPins.value.length },
  { id: 'photos', label: 'มีรูปถ่าย', count: pinsWithPhotosCount.value },
  { id: 'erp', label: 'ลูกค้า ERP', count: allPins.value.filter((p) => p.erp_customer_id).length },
  { id: 'lead', label: 'Lead ใหม่', count: allPins.value.filter((p) => !p.erp_customer_id).length },
]);

const filteredPins = computed(() => {
  const q = search.value.trim().toLowerCase();
  return allPins.value.filter((p) => {
    if (filterKind.value === 'photos' && Number(p.photo_count || 0) === 0) return false;
    if (filterKind.value === 'erp' && !p.erp_customer_id) return false;
    if (filterKind.value === 'lead' && p.erp_customer_id) return false;
    if (!q) return true;
    const haystack = [
      p.customer_name,
      p.shop_name,
      p.erp_customer_code,
      p.province,
      p.district,
      p.phone,
      p.latest_visit?.staff_name,
    ]
      .filter(Boolean)
      .join(' ')
      .toLowerCase();
    return haystack.includes(q);
  });
});

const overlapCount = computed(() => {
  const counts = new Map();
  for (const p of filteredPins.value) {
    const lat = Number(p.latitude);
    const lng = Number(p.longitude);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) continue;
    const key = `${lat.toFixed(4)}:${lng.toFixed(4)}`;
    counts.set(key, (counts.get(key) || 0) + 1);
  }
  let overlapping = 0;
  for (const c of counts.values()) {
    if (c > 1) overlapping += c;
  }
  return overlapping;
});

function openPinPopup(pin) {
  selectedPinId.value = pin.id;
  activePin.value = pin;
}

function openPinPhoto(pin, idx) {
  selectedPinId.value = pin.id;
  if (!(pin?.photos || []).length) {
    activePin.value = pin;
    return;
  }
  openLightbox(pinLightboxItems(pin), idx);
}

function openFirstPhoto(pin) {
  openPinPhoto(pin, 0);
}
</script>

<style scoped>
.map-board-card {
  overflow: hidden;
}

.map-board-head {
  padding: 18px 20px 14px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  flex-wrap: wrap;
}

.map-board-title {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.map-board-icon {
  width: 44px;
  height: 44px;
  border-radius: 13px;
  background: var(--blue-soft);
  display: grid;
  place-items: center;
  font-size: 22px;
  flex-shrink: 0;
}

.map-board-title h3 {
  margin: 0;
  font-size: 17px;
  color: var(--ink);
}

.map-board-title p {
  margin: 2px 0 0;
}

.map-board-controls {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.map-filter-pills {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.map-filter-chip {
  border: 1px solid var(--border);
  background: #f8fafc;
  color: var(--muted);
  border-radius: 99px;
  padding: 7px 12px;
  font-size: 13px;
  font-weight: 600;
  min-height: 36px;
  transition: all 0.14s ease;
}

.map-filter-chip.active {
  background: var(--blue);
  border-color: var(--blue);
  color: #fff;
}

.map-board-search {
  border: 1px solid var(--border);
  background: #f6f8fc;
  border-radius: 11px;
  padding: 8px 13px;
  font-size: 14px;
  min-height: var(--tap);
  min-width: 220px;
  color: var(--ink);
  outline: none;
}

.map-board-search:focus {
  border-color: #7da5ff;
  background: #fff;
}

.map-board-body {
  display: grid;
  grid-template-columns: 1.15fr 1fr;
  gap: 18px;
  padding: 18px 20px 20px;
  align-items: start;
}

.map-board-canvas-col,
.map-board-list-col {
  min-width: 0;
}

.map-legend-group {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  font-size: 13px;
  color: var(--muted);
}

.legend-dot {
  display: inline-block;
  width: 9px;
  height: 9px;
  border-radius: 50%;
  margin-right: 5px;
}

.legend-dot.erp {
  background: #2f63e8;
}

.legend-dot.approved {
  background: #0cab7e;
}

.legend-dot.pending {
  background: #ed9b2d;
}

.map-list-head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 8px;
  margin-bottom: 10px;
  flex-wrap: wrap;
}

.map-table-scroll {
  border: 1px solid var(--border);
  border-radius: 14px;
  max-height: 420px;
  overflow: auto;
  background: #fff;
}

.map-store-table {
  white-space: normal;
}

.map-store-table th {
  position: sticky;
  top: 0;
  z-index: 1;
  white-space: nowrap;
}

.map-store-row {
  cursor: pointer;
  transition: background-color 0.12s ease;
}

.map-store-row:hover td,
.map-store-row.is-selected td {
  background: #f1f6ff;
}

.map-shop-cell {
  display: flex;
  align-items: center;
  gap: 11px;
  min-width: 0;
}

.map-thumb-btn {
  position: relative;
  width: 52px;
  height: 52px;
  border-radius: 12px;
  border: 1px solid var(--border);
  background: #f1f5f9;
  padding: 0;
  overflow: hidden;
  flex-shrink: 0;
}

.map-thumb-btn img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.map-thumb-placeholder {
  font-size: 22px;
  display: grid;
  place-items: center;
  width: 100%;
  height: 100%;
}

.map-thumb-more {
  position: absolute;
  right: 3px;
  bottom: 3px;
  background: rgba(15, 26, 51, 0.8);
  color: #fff;
  font-size: 12px;
  font-weight: 700;
  padding: 1px 5px;
  border-radius: 99px;
  line-height: 1.25;
}

.map-shop-info {
  min-width: 0;
}

.map-shop-name {
  display: block;
  font-size: 14.5px;
  color: var(--ink);
  line-height: 1.35;
}

.map-shop-sub {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  margin-top: 4px;
}

.map-row-btn {
  min-height: 36px;
  padding: 6px 11px;
  font-size: 13px;
  white-space: nowrap;
}

.map-list-empty {
  border: 1px dashed #cbd5e1;
  border-radius: 14px;
  padding: 36px 18px;
  text-align: center;
}

.map-mob-list {
  display: none;
}

.map-mob-card {
  cursor: pointer;
  margin-bottom: 10px;
}

.map-mob-card.is-selected {
  border-color: var(--blue);
  background: #f6f9ff;
}

.map-mob-top {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 8px;
}

.map-mob-strip {
  display: flex;
  gap: 8px;
  margin-top: 10px;
  overflow-x: auto;
  padding-bottom: 2px;
}

.map-mob-thumb {
  width: 68px;
  height: 56px;
  border-radius: 10px;
  border: 1px solid var(--border);
  padding: 0;
  overflow: hidden;
  flex-shrink: 0;
  background: #0f172a;
}

.map-mob-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.map-mob-foot {
  margin-top: 10px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

@media (max-width: 1120px) {
  .map-board-body {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 800px) {
  .map-board-head {
    padding: 16px;
  }
  .map-board-body {
    padding: 14px 16px 16px;
    gap: 14px;
  }
  .map-board-search {
    width: 100%;
    font-size: 16px;
  }
  .map-mob-list {
    display: block;
  }
}
</style>
