<template>
  <div class="map-page">
    <PageHeader
      title="แผนที่ร้านค้า & ปักหมุด GPS"
      subtitle="ค้นหาร้านบนแผนที่ ดูรูปถ่ายหน้างาน นำทาง Google Maps และปักหมุดพิกัดร้านง่ายๆ ใน 3 ขั้นตอน"
    >
      <template #primary>
        <div class="map-header-actions">
          <button
            type="button"
            :class="['btn', activeMode === 'explore' ? 'primary' : 'light']"
            @click="activeMode = 'explore'"
          >
            🗺️ สำรวจร้านบนแผนที่ ({{ fmt(allPins.length) }})
          </button>
          <button
            type="button"
            :class="['btn', activeMode === 'pin' ? 'primary' : 'light']"
            @click="switchToPinMode()"
          >
            📌 ปักหมุด / แก้พิกัดร้าน
            <span v-if="unpinnedTotalCount > 0" class="map-header-badge">รอปัก {{ fmt(unpinnedTotalCount) }}</span>
          </button>
        </div>
      </template>
    </PageHeader>

    <!-- Summary KPI Bar -->
    <div class="kpis map-kpi-strip">
      <div class="card kpi map-kpi-card" @click="activeMode = 'explore'; filterKind = 'all'">
        <div class="label">📍 ปักหมุดแล้วทั้งหมด</div>
        <div class="value">{{ fmt(allPins.length) }}</div>
        <div class="sub">ลูกค้า ERP {{ fmt(erpPinCount) }} · ร้านใหม่ {{ fmt(leadPinCount) }}</div>
      </div>
      <div class="card kpi map-kpi-card" @click="activeMode = 'explore'; filterKind = 'photos'">
        <div class="label">📷 ร้านที่มีรูปถ่ายหน้างาน</div>
        <div class="value">{{ fmt(pinsWithPhotosCount) }}</div>
        <div class="sub">รวมรูปหลักฐาน {{ fmt(totalPhotosCount) }} รูป</div>
      </div>
      <div class="card kpi map-kpi-card" @click="switchToPinMode('unpinned')">
        <div class="label">⚠️ ร้านที่ยังไม่มีหมุด GPS</div>
        <div class="value" :style="{ color: unpinnedTotalCount > 0 ? 'var(--orange)' : 'var(--green)' }">
          {{ fmt(unpinnedTotalCount) }}
        </div>
        <div class="sub">ERP รอปัก {{ fmt(unpinnedErpCount) }} · Lead รอปัก {{ fmt(unpinnedLeadCount) }}</div>
      </div>
      <div class="card kpi map-kpi-card" @click="getGpsAndSwitchToPin">
        <div class="label">🛰️ พิกัด GPS ของคุณ</div>
        <div class="value" style="font-size:16px">
          {{ gpsLoc ? `${gpsLoc.latitude.toFixed(4)}, ${gpsLoc.longitude.toFixed(4)}` : 'กดเพื่อรับพิกัด' }}
        </div>
        <div class="sub">
          {{ gpsLoc ? `ความแม่นยำ ±${gpsLoc.accuracy_m || 0} เมตร` : 'แตะเพื่อดึงตำแหน่งปัจจุบันทันที' }}
        </div>
      </div>
    </div>

    <!-- Top Filter & Search Toolbar -->
    <div class="card map-toolbar-card">
      <div class="map-toolbar-row">
        <div class="map-filter-pills" role="group" aria-label="กรองประเภทร้าน">
          <button
            v-for="tab in FILTER_TABS"
            :key="tab.id"
            type="button"
            :class="['map-chip', { active: filterKind === tab.id }]"
            @click="filterKind = tab.id"
          >
            {{ tab.label }} <b>{{ fmt(tab.count) }}</b>
          </button>
        </div>

        <div class="map-toolbar-inputs">
          <select v-model="selectedProvince" class="map-select" aria-label="กรองจังหวัด">
            <option value="">ทุกจังหวัด ({{ provinces.length }})</option>
            <option v-for="prov in provinces" :key="prov" :value="prov">{{ prov }}</option>
          </select>

          <div class="map-search-wrap">
            <input
              v-model="searchQuery"
              type="search"
              class="map-search-input"
              placeholder="ค้นหาชื่อร้าน, รหัสลูกหนี้, อำเภอ, เบอร์โทร, เซลล์..."
            />
            <button v-if="searchQuery" type="button" class="map-clear-btn" @click="searchQuery = ''">✕</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Main Workbench Grid: Left Map Canvas, Right Context Panel -->
    <div class="map-workbench">
      <!-- Left Column: Interactive Map -->
      <div class="card map-canvas-card">
        <CustomerMap
          ref="mapRef"
          :pins="filteredPins"
          :select-mode="activeMode === 'pin'"
          :selected-pin-id="selectedPin?.id"
          :picked-location="activeMode === 'pin' ? activeLoc : null"
          :picking-hint="pickingBannerText"
          height="560px"
          @picked="onMapPicked"
          @select="onPinClicked"
        />

        <div class="map-foot">
          <div class="map-legend-group">
            <span><i class="legend-dot erp"></i>ลูกค้า ERP ({{ fmt(erpPinCount) }})</span>
            <span><i class="legend-dot approved"></i>Lead อนุมัติ</span>
            <span><i class="legend-dot pending"></i>Lead รออนุมัติ</span>
            <span v-if="activeMode === 'pin'"><i class="legend-dot picked"></i>จุดปักหมุดใหม่ (ลากขยับได้)</span>
          </div>
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <span v-if="activeLoc && activeMode === 'pin'" class="tag green">
              📍 พิกัดที่เลือก: {{ activeLoc.latitude }}, {{ activeLoc.longitude }}
            </span>
            <span class="small muted">แสดง {{ fmt(filteredPins.length) }} จาก {{ fmt(allPins.length) }} หมุด</span>
          </div>
        </div>
      </div>

      <!-- Right Column: Mode 1 (Explore Store List) OR Mode 2 (Easy 3-Step Pinning Wizard) -->
      <div class="card map-side-card">
        <!-- Side Tab Switcher -->
        <div class="map-side-tabs">
          <button
            type="button"
            :class="['map-side-tab', { active: activeMode === 'explore' }]"
            @click="activeMode = 'explore'"
          >
            🗂️ รายชื่อร้านบนแผนที่ ({{ fmt(filteredPins.length) }})
          </button>
          <button
            type="button"
            :class="['map-side-tab', { active: activeMode === 'pin' }]"
            @click="switchToPinMode()"
          >
            📌 ปักหมุดร้านค้า
          </button>
        </div>

        <!-- MODE 1: EXPLORE STORE DIRECTORY -->
        <div v-if="activeMode === 'explore'" class="map-side-body">
          <div v-if="!filteredPins.length" class="map-empty-state">
            <div style="font-size:36px">🗺️</div>
            <strong>ไม่พบร้านค้าที่ตรงกับเงื่อนไข</strong>
            <p class="small muted">ลองล้างตัวกรองคำค้นหา หรือกดสลับไปแท็บ “📌 ปักหมุดร้านค้า” เพื่อเพิ่มพิกัดร้าน</p>
            <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
              <button v-if="searchQuery || filterKind !== 'all' || selectedProvince" type="button" class="btn light" @click="resetFilters">
                ล้างตัวกรองทั้งหมด
              </button>
              <button type="button" class="btn primary" @click="switchToPinMode()">📌 ไปหน้าปักหมุดร้านค้า</button>
            </div>
          </div>

          <div v-else class="map-store-list">
            <article
              v-for="pin in filteredPins"
              :key="pin.id"
              :class="['map-store-item', { 'is-active': String(selectedPin?.id) === String(pin.id) }]"
              @click="focusPinOnMap(pin)"
            >
              <div class="map-store-main">
                <button
                  type="button"
                  class="map-store-avatar"
                  :title="pin.photos?.length ? 'กดดูรูปถ่ายขยาย' : 'กดดูรายละเอียดร้าน'"
                  @click.stop="pin.photos?.length ? openPinLightbox(pin) : openPinModal(pin)"
                >
                  <img v-if="pin.photos?.length" :src="pin.photos[0].url" :alt="pinDisplayName(pin)" loading="lazy" />
                  <span v-else class="map-store-avatar-icon">🏪</span>
                  <span v-if="pin.photo_count > 0" class="map-store-photo-count">📷 {{ pin.photo_count }}</span>
                </button>

                <div class="map-store-text">
                  <div class="map-store-title-row">
                    <strong class="map-store-name">{{ pinDisplayName(pin) }}</strong>
                    <span :class="['tag', pinTypeMeta(pin).tag]">{{ pinCodeLabel(pin) || pinTypeMeta(pin).label }}</span>
                  </div>

                  <div class="map-store-meta">
                    <span v-if="pin.district || pin.province">📍 {{ [pin.district, pin.province].filter(Boolean).join(' · ') }}</span>
                    <span v-if="pin.phone">📞 {{ pin.phone }}</span>
                    <span v-if="pin.salesperson_name">👤 {{ pin.salesperson_name }}</span>
                  </div>

                  <div class="map-store-visit-line">
                    <template v-if="pin.latest_visit">
                      <span :class="['tag', visitResultMeta(pin.latest_visit.result).tag]">
                        {{ visitResultMeta(pin.latest_visit.result).label }}
                      </span>
                      <span class="small muted">
                        {{ formatThaiDateTime(pin.latest_visit.visited_at) }}
                      </span>
                    </template>
                    <span v-else class="small muted">ยังไม่เคยบันทึกเข้าพบ</span>
                  </div>
                </div>
              </div>

              <div class="map-store-actions" @click.stop>
                <button type="button" class="btn light map-act-btn" @click="openPinModal(pin)">
                  {{ pin.photo_count > 0 ? `🖼️ ดูรูป (${pin.photo_count})` : '🔍 ข้อมูลหมุด' }}
                </button>
                <button type="button" class="btn light map-act-btn" @click="prefillPinForEdit(pin)">
                  ✏️ ย้ายหมุด
                </button>
                <a :href="googleMapsUrl(pin)" target="_blank" rel="noopener" class="btn light map-act-btn">
                  🧭 นำทาง
                </a>
                <button type="button" class="btn primary map-act-btn" @click="startVisitFromPin(pin)">
                  📸 เช็คอิน
                </button>
              </div>
            </article>
          </div>
        </div>

        <!-- MODE 2: 3-STEP GUIDED PINNING WIZARD -->
        <div v-else class="map-side-body map-pin-wizard">
          <!-- Step 1: Choose Store with Search & Unpinned Filter -->
          <div class="wizard-step-box">
            <div class="wizard-step-head">
              <span class="wizard-step-num">1</span>
              <div>
                <strong>เลือกร้านค้าที่ต้องการปักหมุด</strong>
                <div class="small muted">ค้นหาชื่อร้านหรือกรองเฉพาะร้านที่ยังไม่มีพิกัด</div>
              </div>
            </div>

            <div class="pin-kind-toggle">
              <button
                type="button"
                :class="['pin-Toggle-btn', { active: subjectKind === 'erp' }]"
                @click="subjectKind = 'erp'; subjectId = ''"
              >
                🏢 ลูกหนี้ ERP ({{ fmt((customers || []).length) }})
              </button>
              <button
                type="button"
                :class="['pin-Toggle-btn', { active: subjectKind === 'lead' }]"
                @click="subjectKind = 'lead'; subjectId = ''"
              >
                🌱 ร้านใหม่ Lead ({{ fmt((leads || []).length) }})
              </button>
            </div>

            <div class="pin-filter-row">
              <label class="pin-check-label">
                <input v-model="onlyUnpinned" type="checkbox" />
                <span>แสดงเฉพาะร้านที่ยังไม่มีหมุด ({{ fmt(unpinnedForCurrentKindCount) }})</span>
              </label>
            </div>

            <input
              v-model="storePickerSearch"
              type="search"
              class="map-search-input"
              style="width:100%;margin-bottom:8px"
              placeholder="พิมพ์ชื่อร้าน หรือ รหัสลูกหนี้ เพื่อค้นหาเร็ว..."
            />

            <div class="store-picker-list">
              <button
                v-for="opt in filteredSubjectOptions.slice(0, 60)"
                :key="opt.id"
                type="button"
                :class="['store-picker-item', { selected: String(subjectId) === String(opt.id) }]"
                @click="selectSubjectForPin(opt)"
              >
                <div class="store-picker-left">
                  <strong>{{ opt.name }}</strong>
                  <span class="small muted">{{ opt.code }} <template v-if="opt.area">· {{ opt.area }}</template></span>
                </div>
                <span :class="['tag', opt.hasPin ? 'green' : 'orange']">
                  {{ opt.hasPin ? 'มีหมุดแล้ว' : 'รอปักหมุด' }}
                </span>
              </button>
              <div v-if="!filteredSubjectOptions.length" class="small muted" style="padding:14px;text-align:center">
                ไม่พบร้านค้าที่ค้นหา (ลองติ๊กออก "แสดงเฉพาะร้านที่ยังไม่มีหมุด")
              </div>
            </div>
          </div>

          <!-- Step 2: Pick Coordinates (GPS / Click or Drag on Map / Paste Lat,Lng) -->
          <div class="wizard-step-box">
            <div class="wizard-step-head">
              <span class="wizard-step-num">2</span>
              <div>
                <strong>กำหนดตำแหน่งพิกัดร้าน</strong>
                <div class="small muted">กดดึง GPS ปัจจุบัน หรือแตะ/ลากหมุดสีเขียวบนแผนที่ด้านซ้าย</div>
              </div>
            </div>

            <div class="pin-coord-buttons">
              <button type="button" class="btn primary" style="width:100%" @click="getGps">
                📍 ดึงพิกัด GPS ปัจจุบันของฉัน
              </button>
            </div>

            <div class="pin-coord-paste">
              <label class="small muted">หรือวางพิกัดจาก Google Maps (ละติจูด, ลองจิจูด)</label>
              <div style="display:flex;gap:6px;margin-top:4px">
                <input
                  v-model="coordInputText"
                  type="text"
                  class="map-search-input"
                  style="flex:1"
                  placeholder="เช่น 15.2287, 104.8564"
                  @keyup.enter="applyCoordText"
                />
                <button type="button" class="btn light" @click="applyCoordText">นำใช้</button>
              </div>
            </div>

            <div v-if="activeLoc" class="pin-ready-banner">
              <div>
                <div class="small">พิกัดที่เลือกไว้พร้อมบันทึก:</div>
                <strong>{{ activeLoc.latitude }}, {{ activeLoc.longitude }}</strong>
                <span v-if="activeLoc.accuracy_m != null" class="small"> (±{{ activeLoc.accuracy_m }} ม.)</span>
              </div>
              <button type="button" class="btn light" style="min-height:32px;padding:4px 10px" @click="clearPickedLoc">
                ล้างจุด
              </button>
            </div>
          </div>

          <!-- Step 3: Save Pin & Optional Check-in -->
          <div class="wizard-step-box">
            <div class="wizard-step-head">
              <span class="wizard-step-num">3</span>
              <div>
                <strong>บันทึกหมุดลงแผนที่</strong>
                <div class="small muted">
                  {{ selectedSubjectOption ? `ร้านที่เลือก: ${selectedSubjectOption.name}` : 'กรุณาเลือกร้านในขั้นตอนที่ 1' }}
                </div>
              </div>
            </div>

            <div style="display:grid;gap:8px">
              <button
                type="button"
                class="btn primary"
                :disabled="saving || !subjectId || !activeLoc"
                @click="savePin"
              >
                {{ saving ? 'กำลังบันทึกหมุด...' : '📌 บันทึกหมุดร้านค้านี้' }}
              </button>
              <button
                v-if="subjectId"
                type="button"
                class="btn light"
                @click="openVisitWizard({ kind: subjectKind, id: Number(subjectId) })"
              >
                📸 บันทึกเข้าพบพร้อมถ่ายรูปร้านนี้ต่อทันที
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <PinDetailModal :pin="selectedPin" @close="selectedPin = null" />
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import CustomerMap from '../CustomerMap.vue';
import PinDetailModal from '../components/PinDetailModal.vue';
import PageHeader from '../../../shared/components/PageHeader.vue';
import { toast } from '../../../shared/composables/useUiStore.js';
import { crmGet, crmMutate, fmt, invalidateCrmData, openLightbox, openVisitWizard } from '../composables/useCrmSession.js';
import {
  formatThaiDateTime,
  googleMapsUrl,
  pinCodeLabel,
  pinDisplayName,
  pinLightboxItems,
  pinTypeMeta,
  visitResultMeta,
} from '../utils/pinPresentation.js';

const mapRef = ref(null);
const activeMode = ref('explore'); // 'explore' | 'pin'
const filterKind = ref('all');
const selectedProvince = ref('');
const searchQuery = ref('');

const subjectKind = ref('erp');
const subjectId = ref('');
const storePickerSearch = ref('');
const onlyUnpinned = ref(false);
const coordInputText = ref('');

const gpsLoc = ref(null);
const manualLoc = ref(null);
const selectedPin = ref(null);
const saving = ref(false);

const { data: pins, refetch: refetchPins } = useQuery({
  queryKey: ['crm', 'pins'],
  queryFn: ({ signal }) => crmGet('/pins', { signal }),
});

const { data: leads } = useQuery({
  queryKey: ['crm', 'map-leads'],
  queryFn: ({ signal }) => crmGet('/leads', { signal }),
});

const { data: customers } = useQuery({
  queryKey: ['crm', 'map-customers'],
  queryFn: ({ signal }) => crmGet('/customers', { signal }),
});

const allPins = computed(() => pins.value || []);
const erpPinCount = computed(() => allPins.value.filter((p) => p.erp_customer_id).length);
const leadPinCount = computed(() => allPins.value.filter((p) => !p.erp_customer_id).length);
const pinsWithPhotosCount = computed(() => allPins.value.filter((p) => Number(p.photo_count || 0) > 0).length);
const totalPhotosCount = computed(() => allPins.value.reduce((acc, p) => acc + Number(p.photo_count || 0), 0));

const pinnedErpIds = computed(() => new Set(allPins.value.filter((p) => p.erp_customer_id).map((p) => Number(p.erp_customer_id))));
const pinnedLeadIds = computed(() => new Set(allPins.value.filter((p) => p.crm_lead_id).map((p) => Number(p.crm_lead_id))));

const unpinnedErpCount = computed(() => (customers.value || []).filter((c) => !pinnedErpIds.value.has(Number(c.id))).length);
const unpinnedLeadCount = computed(() => (leads.value || []).filter((l) => !pinnedLeadIds.value.has(Number(l.id))).length);
const unpinnedTotalCount = computed(() => unpinnedErpCount.value + unpinnedLeadCount.value);
const unpinnedForCurrentKindCount = computed(() => (subjectKind.value === 'erp' ? unpinnedErpCount.value : unpinnedLeadCount.value));

const provinces = computed(() => {
  const set = new Set();
  for (const p of allPins.value) {
    if (p.province && String(p.province).trim()) {
      set.add(String(p.province).trim());
    }
  }
  return Array.from(set).sort((a, b) => a.localeCompare(b, 'th'));
});

const FILTER_TABS = computed(() => [
  { id: 'all', label: 'ทั้งหมด', count: allPins.value.length },
  { id: 'photos', label: '📷 มีรูปถ่าย', count: pinsWithPhotosCount.value },
  { id: 'erp', label: '🏢 ลูกค้า ERP', count: erpPinCount.value },
  { id: 'lead', label: '🌱 ร้านใหม่ (Lead)', count: leadPinCount.value },
  { id: 'visited', label: '✅ เข้าพบแล้ว', count: allPins.value.filter((p) => p.latest_visit).length },
]);

const filteredPins = computed(() => {
  const q = searchQuery.value.trim().toLowerCase();
  return allPins.value.filter((p) => {
    if (filterKind.value === 'photos' && Number(p.photo_count || 0) === 0) return false;
    if (filterKind.value === 'erp' && !p.erp_customer_id) return false;
    if (filterKind.value === 'lead' && p.erp_customer_id) return false;
    if (filterKind.value === 'visited' && !p.latest_visit) return false;
    if (selectedProvince.value && String(p.province || '').trim() !== selectedProvince.value) return false;
    if (!q) return true;
    const haystack = [
      p.customer_name,
      p.shop_name,
      p.erp_customer_code,
      p.province,
      p.district,
      p.phone,
      p.salesperson_name,
      p.latest_visit?.staff_name,
    ]
      .filter(Boolean)
      .join(' ')
      .toLowerCase();
    return haystack.includes(q);
  });
});

const subjectOptions = computed(() => {
  if (subjectKind.value === 'erp') {
    return (customers.value || []).map((c) => {
      const existingPin = allPins.value.find((p) => Number(p.erp_customer_id) === Number(c.id));
      return {
        id: String(c.id),
        code: c.erp_customer_code || `ERP-${c.id}`,
        name: c.customer_name || 'ลูกค้า ERP',
        area: [c.district, c.province].filter(Boolean).join(' '),
        hasPin: Boolean(existingPin),
        pin: existingPin || null,
      };
    });
  }
  return (leads.value || []).map((l) => {
    const existingPin = allPins.value.find((p) => Number(p.crm_lead_id) === Number(l.id));
    return {
      id: String(l.id),
      code: `LEAD-${l.id}`,
      name: l.shop_name || 'ร้านใหม่',
      area: [l.district, l.province].filter(Boolean).join(' '),
      hasPin: Boolean(existingPin),
      pin: existingPin || null,
    };
  });
});

const filteredSubjectOptions = computed(() => {
  const q = storePickerSearch.value.trim().toLowerCase();
  return subjectOptions.value.filter((opt) => {
    if (onlyUnpinned.value && opt.hasPin) return false;
    if (!q) return true;
    return `${opt.name} ${opt.code} ${opt.area}`.toLowerCase().includes(q);
  });
});

const selectedSubjectOption = computed(() =>
  subjectOptions.value.find((o) => String(o.id) === String(subjectId.value)) || null
);

const activeLoc = computed(() => manualLoc.value || gpsLoc.value);

const pickingBannerText = computed(() => {
  if (activeMode.value !== 'pin') return '';
  if (selectedSubjectOption.value) {
    return `กำลังปักหมุด: ${selectedSubjectOption.value.name} — แตะบนแผนที่หรือลากหมุดสีเขียวเพื่อเลือกพิกัด`;
  }
  return 'โหมดปักหมุด: เลือกร้านค้าทางขวา แล้วแตะจุดที่ตั้งร้านบนแผนที่ได้เลย';
});

function resetFilters() {
  searchQuery.value = '';
  filterKind.value = 'all';
  selectedProvince.value = '';
}

function switchToPinMode(modeFilter = '') {
  activeMode.value = 'pin';
  if (modeFilter === 'unpinned') {
    onlyUnpinned.value = true;
  }
}

function selectSubjectForPin(opt) {
  subjectId.value = String(opt.id);
  if (opt.pin) {
    manualLoc.value = {
      latitude: Number(opt.pin.latitude),
      longitude: Number(opt.pin.longitude),
    };
    coordInputText.value = `${opt.pin.latitude}, ${opt.pin.longitude}`;
  }
}

function prefillPinForEdit(pin) {
  activeMode.value = 'pin';
  onlyUnpinned.value = false;
  if (pin.erp_customer_id) {
    subjectKind.value = 'erp';
    subjectId.value = String(pin.erp_customer_id);
  } else if (pin.crm_lead_id) {
    subjectKind.value = 'lead';
    subjectId.value = String(pin.crm_lead_id);
  }
  manualLoc.value = {
    latitude: Number(pin.latitude),
    longitude: Number(pin.longitude),
  };
  coordInputText.value = `${pin.latitude}, ${pin.longitude}`;
}

function onMapPicked(loc) {
  manualLoc.value = loc;
  coordInputText.value = `${loc.latitude}, ${loc.longitude}`;
}

function onPinClicked(pin) {
  if (activeMode.value === 'pin' && !subjectId.value) {
    prefillPinForEdit(pin);
    return;
  }
  selectedPin.value = pin;
}

function focusPinOnMap(pin) {
  selectedPin.value = pin;
}

function openPinModal(pin) {
  selectedPin.value = pin;
}

function openPinLightbox(pin) {
  if (!(pin?.photos || []).length) {
    selectedPin.value = pin;
    return;
  }
  openLightbox(pinLightboxItems(pin), 0);
}

function startVisitFromPin(pin) {
  if (pin.erp_customer_id) {
    openVisitWizard({ kind: 'erp', id: Number(pin.erp_customer_id) });
  } else if (pin.crm_lead_id) {
    openVisitWizard({ kind: 'lead', id: Number(pin.crm_lead_id) });
  }
}

function applyCoordText() {
  const parts = coordInputText.value
    .split(/[,\s]+/)
    .map((s) => Number(s.trim()))
    .filter((n) => Number.isFinite(n));
  if (parts.length < 2 || Math.abs(parts[0]) > 90 || Math.abs(parts[1]) > 180) {
    toast.warning('รูปแบบพิกัดไม่ถูกต้อง', 'กรุณาใส่ละติจูด, ลองจิจูด เช่น 15.2287, 104.8564');
    return;
  }
  manualLoc.value = {
    latitude: Number(parts[0].toFixed(7)),
    longitude: Number(parts[1].toFixed(7)),
  };
  toast.success('เลื่อนหมุดไปที่พิกัดที่ระบุแล้ว');
}

function clearPickedLoc() {
  manualLoc.value = null;
  gpsLoc.value = null;
  coordInputText.value = '';
}

function getGps() {
  if (!navigator.geolocation) {
    toast.warning('เบราว์เซอร์ไม่รองรับ GPS');
    return;
  }
  navigator.geolocation.getCurrentPosition(
    (p) => {
      manualLoc.value = null;
      gpsLoc.value = {
        latitude: Number(p.coords.latitude.toFixed(7)),
        longitude: Number(p.coords.longitude.toFixed(7)),
        accuracy_m: Math.round(p.coords.accuracy),
      };
      coordInputText.value = `${gpsLoc.value.latitude}, ${gpsLoc.value.longitude}`;
      toast.success('รับพิกัด GPS ปัจจุบันแล้ว');
    },
    (err) => toast.error('ไม่สามารถรับพิกัด GPS', err.message),
    { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
  );
}

function getGpsAndSwitchToPin() {
  activeMode.value = 'pin';
  getGps();
}

async function savePin() {
  if (!subjectId.value || !activeLoc.value) return;
  saving.value = true;
  try {
    await crmMutate('/pins', 'POST', {
      ...(subjectKind.value === 'erp' ? { erp_customer_id: Number(subjectId.value) } : { crm_lead_id: Number(subjectId.value) }),
      latitude: activeLoc.value.latitude,
      longitude: activeLoc.value.longitude,
      accuracy_m: activeLoc.value.accuracy_m ?? null,
      source: manualLoc.value ? 'manual' : 'gps',
    });
    manualLoc.value = null;
    toast.success('บันทึกหมุด GPS เรียบร้อยแล้ว');
    await refetchPins();
    invalidateCrmData(['dashboard', 'leads', 'customers', 'pins']);
  } catch (err) {
    toast.error('บันทึกหมุดไม่สำเร็จ', err);
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.map-header-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.map-header-badge {
  background: rgba(255, 255, 255, 0.22);
  border-radius: 99px;
  padding: 1px 8px;
  font-size: 12px;
  margin-left: 6px;
}

.map-kpi-strip {
  margin-bottom: 14px;
}

.map-kpi-card {
  cursor: pointer;
  transition: transform 0.14s ease, border-color 0.14s ease;
}

.map-kpi-card:hover {
  transform: translateY(-2px);
  border-color: #7dd3fc;
}

.map-toolbar-card {
  padding: 12px 16px;
  margin-bottom: 16px;
}

.map-toolbar-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}

.map-filter-pills {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.map-chip {
  border: 1px solid var(--border);
  background: #f8fafc;
  color: var(--muted);
  border-radius: 99px;
  padding: 6px 12px;
  font-size: 13px;
  font-weight: 600;
  min-height: 36px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  transition: all 0.14s ease;
}

.map-chip.active {
  background: var(--blue);
  border-color: var(--blue);
  color: #ffffff;
}

.map-toolbar-inputs {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  flex: 1;
  justify-content: flex-end;
}

.map-select {
  border: 1px solid #d5e0ec;
  background-color: #f8fafc;
  border-radius: 14px;
  padding: 8px 36px 8px 14px;
  font-size: 13.5px;
  min-height: 40px;
  color: var(--ink);
}

.map-search-wrap {
  position: relative;
  min-width: 250px;
  flex: 1;
  max-width: 340px;
}

.map-search-input {
  width: 100%;
  border: 1px solid #d5e0ec;
  background: #f8fafc;
  border-radius: 14px;
  padding: 8px 32px 8px 14px;
  font-size: 13.5px;
  min-height: 40px;
  color: var(--ink);
  outline: none;
}

.map-search-input:focus {
  border-color: var(--blue);
  background: #ffffff;
}

.map-clear-btn {
  position: absolute;
  right: 6px;
  top: 50%;
  transform: translateY(-50%);
  border: 0;
  background: transparent;
  color: var(--muted);
  cursor: pointer;
  padding: 4px 6px;
}

.map-workbench {
  display: grid;
  grid-template-columns: 1.25fr 1fr;
  gap: 16px;
  align-items: start;
}

.map-canvas-card {
  padding: 14px;
}

.map-legend-group {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  font-size: 12.5px;
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

.legend-dot.picked {
  background: #059669;
}

.map-side-card {
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.map-side-tabs {
  display: grid;
  grid-template-columns: 1fr 1fr;
  border-bottom: 1px solid var(--border);
  background: #f8fafc;
}

.map-side-tab {
  border: 0;
  border-bottom: 2.5px solid transparent;
  background: transparent;
  padding: 12px 10px;
  font-size: 13.5px;
  font-weight: 700;
  color: var(--muted);
  cursor: pointer;
  transition: all 0.14s ease;
}

.map-side-tab.active {
  background: #ffffff;
  color: var(--blue);
  border-bottom-color: var(--blue);
}

.map-side-body {
  padding: 14px;
  max-height: 565px;
  overflow-y: auto;
}

.map-store-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.map-store-item {
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 11px 12px;
  background: #ffffff;
  cursor: pointer;
  transition: border-color 0.14s ease, background-color 0.14s ease;
}

.map-store-item:hover,
.map-store-item.is-active {
  border-color: #38bdf8;
  background: #f0f9ff;
}

.map-store-main {
  display: flex;
  gap: 11px;
  align-items: flex-start;
}

.map-store-avatar {
  position: relative;
  width: 58px;
  height: 58px;
  border-radius: 11px;
  border: 1px solid var(--border);
  background: #f1f5f9;
  padding: 0;
  overflow: hidden;
  flex-shrink: 0;
  cursor: pointer;
}

.map-store-avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.map-store-avatar-icon {
  font-size: 24px;
  display: grid;
  place-items: center;
  width: 100%;
  height: 100%;
}

.map-store-photo-count {
  position: absolute;
  bottom: 2px;
  right: 2px;
  background: rgba(15, 23, 42, 0.82);
  color: #ffffff;
  font-size: 10.5px;
  font-weight: 700;
  border-radius: 99px;
  padding: 1px 5px;
}

.map-store-text {
  flex: 1;
  min-width: 0;
}

.map-store-title-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 6px;
}

.map-store-name {
  font-size: 14px;
  color: var(--ink);
  line-height: 1.35;
}

.map-store-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  font-size: 12px;
  color: var(--muted);
  margin-top: 4px;
}

.map-store-visit-line {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 6px;
}

.map-store-actions {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  margin-top: 10px;
  padding-top: 8px;
  border-top: 1px dashed #e2e8f0;
}

.map-act-btn {
  min-height: 32px;
  padding: 4px 10px;
  font-size: 12.5px;
}

.map-empty-state {
  text-align: center;
  padding: 36px 16px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

/* 3-Step Pinning Wizard */
.map-pin-wizard {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.wizard-step-box {
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 12px;
  background: #fcfdff;
}

.wizard-step-head {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 10px;
}

.wizard-step-num {
  width: 26px;
  height: 26px;
  border-radius: 50%;
  background: var(--blue);
  color: #ffffff;
  font-size: 13px;
  font-weight: 800;
  display: grid;
  place-items: center;
  flex-shrink: 0;
}

.pin-kind-toggle {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px;
  margin-bottom: 8px;
}

.pin-Toggle-btn {
  border: 1px solid var(--border);
  background: #f8fafc;
  color: var(--ink);
  border-radius: 9px;
  padding: 7px 10px;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
}

.pin-Toggle-btn.active {
  background: var(--blue-soft);
  border-color: var(--blue);
  color: var(--blue);
}

.pin-filter-row {
  margin-bottom: 8px;
}

.pin-check-label {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  font-size: 13px;
  font-weight: 600;
  color: var(--ink);
  cursor: pointer;
}

.store-picker-list {
  max-height: 175px;
  overflow-y: auto;
  border: 1px solid var(--border);
  border-radius: 10px;
  background: #ffffff;
}

.store-picker-item {
  width: 100%;
  border: 0;
  border-bottom: 1px solid #f1f5f9;
  background: transparent;
  padding: 8px 10px;
  text-align: left;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  cursor: pointer;
}

.store-picker-item:hover {
  background: #f8fafc;
}

.store-picker-item.selected {
  background: #e0f2fe;
}

.store-picker-left {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.store-picker-left strong {
  font-size: 13px;
  color: var(--ink);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.pin-coord-paste {
  margin-top: 10px;
}

.pin-ready-banner {
  margin-top: 10px;
  background: #ecfdf5;
  border: 1px solid #a7f3d0;
  color: #065f46;
  border-radius: 10px;
  padding: 8px 11px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

@media (max-width: 1100px) {
  .map-workbench {
    grid-template-columns: 1fr;
  }
}
</style>
