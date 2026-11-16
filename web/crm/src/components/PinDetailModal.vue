<template>
  <BaseModal
    :open="Boolean(pin)"
    :title="pin ? pinDisplayName(pin) : ''"
    :subtitle="modalSubtitle"
    :persistent="lightboxState.open"
    max-width="760px"
    @close="$emit('close')"
  >
    <template v-if="pin">
      <!-- Top hero summary -->
      <div class="pin-hero">
        <div class="pin-badges">
          <span :class="['tag', typeMeta.tag]">{{ typeMeta.label }}</span>
          <span v-if="codeLabel" class="tag gray">{{ codeLabel }}</span>
          <span class="tag blue">📍 {{ pinSourceLabel(pin.source) }}</span>
          <span v-if="photos.length" class="tag green">📷 {{ photos.length }} รูปถ่าย</span>
        </div>

        <div class="pin-meta-grid">
          <div class="pin-meta-item">
            <span class="pin-meta-label">ที่อยู่ / พื้นที่</span>
            <strong>{{ locationSummary }}</strong>
          </div>
          <div class="pin-meta-item">
            <span class="pin-meta-label">โทรศัพท์ / ผู้ดูแล</span>
            <strong>
              {{ pin.phone || 'ไม่ระบุเบอร์' }}
              <template v-if="pin.salesperson_name"> · {{ pin.salesperson_name }}</template>
            </strong>
          </div>
          <div class="pin-meta-item">
            <span class="pin-meta-label">พิกัด GPS บนแผนที่</span>
            <strong>
              {{ coordLabel }}
              <span v-if="pin.accuracy_m != null" class="muted"> (±{{ pin.accuracy_m }} ม.)</span>
            </strong>
          </div>
          <div class="pin-meta-item">
            <span class="pin-meta-label">ปักหมุดเมื่อ</span>
            <strong>{{ formatThaiDateTime(pin.pinned_at) }}</strong>
          </div>
        </div>
      </div>

      <!-- Latest field visit summary -->
      <div class="pin-section">
        <div class="pin-section-head">
          <h4>📋 ข้อมูลเข้าพบล่าสุด</h4>
          <span v-if="pin.visit_count" class="small muted">เข้าพบสะสม {{ pin.visit_count }} ครั้ง</span>
        </div>

        <div v-if="pin.latest_visit" class="pin-visit-card">
          <div class="pin-visit-top">
            <span :class="['tag', resultMeta.tag]">{{ resultMeta.label }}</span>
            <span class="small muted">
              โดย <b>{{ pin.latest_visit.staff_name || 'ทีมขาย' }}</b> · {{ formatThaiDateTime(pin.latest_visit.visited_at) }}
            </span>
          </div>
          <div v-if="pin.latest_visit.follow_up_action || pin.latest_visit.follow_up_date" class="pin-visit-detail">
            <span>🔔 นัดติดตาม:</span>
            <b>{{ pin.latest_visit.follow_up_action || 'ติดตามรอบถัดไป' }}</b>
            <span v-if="pin.latest_visit.follow_up_date" class="tag orange">กำหนด {{ pin.latest_visit.follow_up_date }}</span>
          </div>
          <div v-if="pin.latest_visit.supplier_name || pin.latest_visit.product_types" class="pin-visit-detail">
            <span>🛒 แหล่งซื้อ/สินค้า:</span>
            <span>{{ [pin.latest_visit.supplier_name, pin.latest_visit.product_types].filter(Boolean).join(' · ') }}</span>
          </div>
          <div v-if="pin.latest_visit.note" class="pin-visit-note">
            “{{ pin.latest_visit.note }}”
          </div>
        </div>

        <div v-else class="pin-empty-box">
          <span>ยังไม่มีประวัติบันทึกเข้าพบของร้านนี้</span>
        </div>
      </div>

      <!-- Photo gallery -->
      <div class="pin-section">
        <div class="pin-section-head">
          <h4>📸 รูปถ่ายหลักฐานหน้างาน ({{ photos.length }})</h4>
          <span v-if="photos.length" class="small muted">แตะที่รูปเพื่อดูภาพขยาย</span>
        </div>

        <div v-if="photos.length" class="pin-photo-grid">
          <button
            v-for="(photo, idx) in photos"
            :key="`${photo.kind}-${photo.id}`"
            type="button"
            class="pin-photo-card"
            @click="openPhotoAt(idx)"
          >
            <div class="pin-photo-frame">
              <img
                :src="photo.url"
                :alt="`${pinDisplayName(pin)} - ${photoCategoryMeta(photo.category).label}`"
                loading="lazy"
              />
              <span :class="['tag', photoCategoryMeta(photo.category).tag, 'pin-photo-cat']">
                {{ photoCategoryMeta(photo.category).icon }} {{ photoCategoryMeta(photo.category).label }}
              </span>
              <span class="pin-photo-zoom" aria-hidden="true">🔍</span>
            </div>
            <div class="pin-photo-foot">
              <span class="pin-photo-date">{{ formatThaiDateTime(photo.captured_at) }}</span>
              <span v-if="photo.consent_confirmed" class="pin-consent-dot" title="ยืนยันความยินยอมแล้ว">✓</span>
            </div>
          </button>
        </div>

        <div v-else class="pin-empty-box">
          <span>ยังไม่มีรูปถ่ายประกอบหมุดนี้</span>
          <button type="button" class="btn light" @click="launchVisit">📸 ถ่ายรูปบันทึกเข้าพบ</button>
        </div>
      </div>
    </template>

    <template v-if="pin" #footer>
      <a :href="googleMapsUrl(pin)" target="_blank" rel="noopener" class="btn">
        🧭 เปิด Google Maps
      </a>
      <button v-if="detailTarget" type="button" class="btn light" @click="openFullDetail">
        🔍 ดูประวัติร้านเต็ม
      </button>
      <button v-if="detailTarget" type="button" class="btn primary" @click="launchVisit">
        📸 บันทึกเข้าพบ
      </button>
    </template>
  </BaseModal>
</template>

<script setup>
import { computed } from 'vue';
import BaseModal from '../../../shared/components/BaseModal.vue';
import { lightboxState, openDetailDrawer, openLightbox, openVisitWizard } from '../composables/useCrmSession.js';
import {
  formatThaiDateTime,
  googleMapsUrl,
  photoCategoryMeta,
  pinCodeLabel,
  pinDisplayName,
  pinLightboxItems,
  pinSourceLabel,
  pinTypeMeta,
  visitResultMeta,
} from '../utils/pinPresentation.js';

const props = defineProps({
  pin: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const typeMeta = computed(() => pinTypeMeta(props.pin));
const codeLabel = computed(() => pinCodeLabel(props.pin));
const photos = computed(() => props.pin?.photos || []);
const resultMeta = computed(() => visitResultMeta(props.pin?.latest_visit?.result));

const coordLabel = computed(() => {
  const lat = Number(props.pin?.latitude);
  const lng = Number(props.pin?.longitude);
  if (!Number.isFinite(lat) || !Number.isFinite(lng)) return '—';
  return `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
});

const locationSummary = computed(() => {
  if (!props.pin) return '—';
  const parts = [props.pin.shop_address, props.pin.district, props.pin.province].filter(Boolean);
  return parts.length ? parts.join(' · ') : 'ยังไม่ระบุที่อยู่';
});

const modalSubtitle = computed(() => {
  if (!props.pin) return '';
  const bits = [typeMeta.value.label, codeLabel.value, props.pin.province].filter(Boolean);
  return bits.join(' · ');
});

const detailTarget = computed(() => {
  if (!props.pin) return null;
  if (props.pin.erp_customer_id) return { kind: 'erp', id: Number(props.pin.erp_customer_id) };
  if (props.pin.crm_lead_id) return { kind: 'lead', id: Number(props.pin.crm_lead_id) };
  return null;
});

function openPhotoAt(index) {
  if (!props.pin || !photos.value.length) return;
  openLightbox(pinLightboxItems(props.pin), index);
}

function openFullDetail() {
  if (!detailTarget.value) return;
  const target = detailTarget.value;
  emit('close');
  openDetailDrawer(target.kind, target.id);
}

function launchVisit() {
  if (!detailTarget.value) return;
  const target = detailTarget.value;
  emit('close');
  openVisitWizard(target);
}
</script>

<style scoped>
.pin-hero {
  background: linear-gradient(135deg, #f5f8ff 0%, #ffffff 70%);
  border: 1px solid #dbe5f7;
  border-radius: 16px;
  padding: 16px;
  margin-bottom: 16px;
}

.pin-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}

.pin-meta-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px 16px;
}

.pin-meta-item {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.pin-meta-label {
  font-size: 12.5px;
  color: var(--muted);
}

.pin-meta-item strong {
  font-size: 14px;
  color: var(--ink);
  font-weight: 600;
  overflow-wrap: anywhere;
}

.pin-section {
  margin-bottom: 16px;
}

.pin-section:last-child {
  margin-bottom: 0;
}

.pin-section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 10px;
  flex-wrap: wrap;
}

.pin-section-head h4 {
  margin: 0;
  font-size: 15.5px;
  color: var(--ink);
}

.pin-visit-card {
  background: #f8fafd;
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 13px 15px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.pin-visit-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  flex-wrap: wrap;
}

.pin-visit-detail {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  font-size: 14px;
  color: var(--ink);
}

.pin-visit-note {
  background: #fff;
  border-left: 3px solid var(--blue);
  border-radius: 8px;
  padding: 8px 11px;
  font-size: 13.5px;
  color: var(--ink);
}

.pin-photo-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(156px, 1fr));
  gap: 12px;
}

.pin-photo-card {
  border: 1px solid var(--border);
  background: #fff;
  border-radius: 14px;
  padding: 0;
  overflow: hidden;
  text-align: left;
  transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
  box-shadow: 0 3px 10px rgba(21, 41, 79, 0.05);
}

.pin-photo-card:hover {
  transform: translateY(-2px);
  border-color: #9bb8ff;
  box-shadow: 0 10px 22px rgba(47, 99, 232, 0.14);
}

.pin-photo-frame {
  position: relative;
  aspect-ratio: 4 / 3;
  background: #0f172a;
  overflow: hidden;
}

.pin-photo-frame img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  transition: transform 0.25s ease;
}

.pin-photo-card:hover .pin-photo-frame img {
  transform: scale(1.04);
}

.pin-photo-cat {
  position: absolute;
  top: 8px;
  left: 8px;
  backdrop-filter: blur(4px);
  box-shadow: 0 2px 8px rgba(15, 23, 42, 0.16);
}

.pin-photo-zoom {
  position: absolute;
  right: 8px;
  bottom: 8px;
  width: 30px;
  height: 30px;
  border-radius: 99px;
  background: rgba(15, 23, 42, 0.72);
  color: #fff;
  display: grid;
  place-items: center;
  font-size: 14px;
}

.pin-photo-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  padding: 8px 10px;
  background: #fff;
}

.pin-photo-date {
  font-size: 12.5px;
  color: var(--muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.pin-consent-dot {
  color: var(--green);
  font-weight: 700;
  font-size: 13px;
  flex-shrink: 0;
}

.pin-empty-box {
  border: 1px dashed #cbd5e1;
  border-radius: 14px;
  padding: 18px;
  text-align: center;
  color: var(--muted);
  font-size: 14px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
}

@media (max-width: 640px) {
  .pin-meta-grid {
    grid-template-columns: 1fr;
  }
  .pin-photo-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }
}
</style>
