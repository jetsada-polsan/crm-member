<template>
  <FullScreenMobileForm
    :open="visitWizardOpen"
    title="บันทึกเข้าพบร้าน"
    :step-label="`ขั้นตอน ${step} / 8`"
    :dirty="isDirty && step < 8"
    @close="handleClose"
  >
    <div class="stepper" aria-label="ขั้นตอนการเข้าพบ">
      <button
        v-for="s in STEPS"
        :key="s.num"
        type="button"
        :class="['step-pill', { active: step === s.num, done: step > s.num && step < 8 }]"
        @click="step < 8 && (step = s.num)"
      >
        {{ s.num }}. {{ s.short }}
      </button>
    </div>

    <!-- Step 1: Select Shop / Lead or Quick Create -->
    <div v-if="step === 1" class="panel-pad" style="padding:4px 0">
      <h4 style="margin-top:0">1. เลือกร้านค้าเป้าหมายที่จะเข้าพบ</h4>
      <div class="form">
        <div class="field">
          <label>ประเภทลูกค้า</label>
          <select v-model="subjectKind" @change="subjectId = ''">
            <option value="lead">ร้านค้าใหม่ (CRM Lead)</option>
            <option value="erp">ลูกค้าเดิม (ERP Mirror)</option>
          </select>
        </div>
        <div class="field">
          <label>ค้นหาชื่อร้าน / รหัส</label>
          <input v-model="subjectFilter" placeholder="พิมพ์ชื่อร้านหรือเบอร์โทร..." />
        </div>
        <div class="field span2">
          <label>เลือกร้านค้า *</label>
          <select v-model="subjectId" size="6" style="min-height:140px">
            <option v-for="opt in filteredSubjects" :key="opt.id" :value="String(opt.id)">
              {{ opt.label }}
            </option>
          </select>
        </div>
      </div>
      <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
        <button type="button" class="btn light" @click="openQuickAdd()">＋ ไม่พบร้านนี้ — สร้างร้านใหม่ด่วน</button>
      </div>
    </div>

    <!-- Step 2: Start Visit / Check-in Timestamp -->
    <div v-else-if="step === 2" class="panel-pad" style="padding:4px 0">
      <h4 style="margin-top:0">2. เริ่มเข้าพบ (Check-in)</h4>
      <div class="notice">
        <strong>ร้านที่เลือก:</strong> {{ selectedSubjectLabel || 'ยังไม่เลือก' }}
      </div>
      <div class="card panel-pad">
        <p class="small muted" style="margin-top:0">
          ระบบจะบันทึก <b>เวลาอุปกรณ์ (Device Time)</b> ทันทีที่กดเริ่มเข้าพบ และแยกบันทึก <b>เวลาเซิร์ฟเวอร์ (Server Time)</b> ณ ตอนส่งข้อมูลเพื่อความโปร่งใส
        </p>
        <div v-if="deviceRecordedAt" class="success" style="margin-bottom:10px">
          Check-in แล้ว (เวลาเครื่องพนักงาน): <strong>{{ new Date(deviceRecordedAt).toLocaleString('th-TH') }}</strong>
        </div>
        <button type="button" class="btn primary" style="width:100%" @click="startCheckIn">
          {{ deviceRecordedAt ? 'อัปเดตเวลาเริ่มเข้าพบใหม่' : '⏱ กดเริ่มเข้าพบ (Check-in)' }}
        </button>
      </div>
    </div>

    <!-- Step 3: Request GPS -->
    <div v-else-if="step === 3" class="panel-pad" style="padding:4px 0">
      <h4 style="margin-top:0">3. บันทึกพิกัด GPS ประกอบการเข้าพบ</h4>
      <div class="card panel-pad">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:8px">
          <span class="small">สถานะสิทธิ์ GPS:</span>
          <span class="tag" :class="gpsPermission === 'granted' ? 'green' : gpsPermission === 'denied' ? 'red' : 'orange'">
            {{ gpsPermission }}
          </span>
        </div>
        <div v-if="gps" class="success">
          พิกัดปัจจุบัน: <b>{{ gps.latitude }}, {{ gps.longitude }}</b> (ความคลาดเคลื่อน ~{{ gps.accuracy_m }} ม.)
        </div>
        <div v-else class="notice">
          ยังไม่ได้รับพิกัด GPS (หากพนักงานหรืออุปกรณ์ปฏิเสธสิทธิ์ สามารถดำเนินการต่อได้โดยระบบจะบันทึกสถานะ permission ตามจริง)
        </div>
        <button type="button" class="btn primary" style="width:100%" @click="requestGps">
          📍 {{ gps ? 'ดึงพิกัด GPS ใหม่อีกครั้ง' : 'ขอรับพิกัด GPS ปัจจุบัน' }}
        </button>
        <p class="small muted" style="margin:10px 0 0">
          หมายเหตุ: พิกัด GPS และภาพถ่ายใช้เป็นข้อมูลอ้างอิงประกอบการทำงานภาคสนามเท่านั้น
        </p>
      </div>
    </div>

    <!-- Step 4: Front-of-shop Photo -->
    <div v-else-if="step === 4" class="panel-pad" style="padding:4px 0">
      <h4 style="margin-top:0">4. ถ่ายภาพหน้าร้านค้า (Storefront)</h4>
      <div class="card panel-pad">
        <label class="btn primary" style="width:100%;margin-bottom:10px">
          📷 {{ storefrontPhoto ? 'ถ่ายใหม่ (Retake)' : 'ถ่ายภาพ / เลือกรูปหน้าร้าน' }}
          <input
            type="file"
            accept="image/jpeg,image/png,image/webp"
            capture="environment"
            hidden
            @change="onPhotoSelect($event, 'storefront')"
          />
        </label>
        <div v-if="storefrontPhoto">
          <img :src="storefrontPhoto.previewUrl" alt="ภาพหน้าร้าน" class="photo-preview" />
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;gap:8px;flex-wrap:wrap">
            <span class="small muted">
              {{ storefrontPhoto.width }}×{{ storefrontPhoto.height }}px · {{ formatBytes(storefrontPhoto.compressedSize) }}
              (ลด {{ storefrontPhoto.savingsPct }}%)
            </span>
            <button type="button" class="btn danger" @click="clearSlot('storefront')">ลบรูป</button>
          </div>
        </div>
        <p v-else class="small muted" style="margin:0">ระบบจะย่อและบีบอัดรูปอัตโนมัติบนเครื่องก่อนอัปโหลด</p>
      </div>
    </div>

    <!-- Step 5: Optional Discussion / Product Photo with Consent -->
    <div v-else-if="step === 5" class="panel-pad" style="padding:4px 0">
      <h4 style="margin-top:0">5. ภาพสินค้า / การพูดคุยกับลูกค้า (ไม่บังคับ)</h4>
      <div class="card panel-pad">
        <div class="field" style="margin-bottom:10px">
          <label>หมวดภาพ</label>
          <select v-model="secondPhotoCategory">
            <option value="products">สินค้า / ชั้นวาง / คู่แข่ง</option>
            <option value="customer_discussion">ภาพถ่ายร่วมกับลูกค้า (ต้องขอความยินยอม)</option>
          </select>
        </div>
        <label v-if="secondPhotoCategory === 'customer_discussion'" style="display:flex;gap:8px;align-items:flex-start;margin-bottom:12px;font-size:12px;background:#fff8eb;padding:10px;border-radius:10px;border:1px solid #fde68a">
          <input v-model="customerConsent" type="checkbox" style="width:auto;margin-top:3px" />
          <span>ยืนยันว่าลูกค้า <b>ได้รับทราบและยินยอม</b> ให้บันทึกภาพถ่ายประกอบการทำงานขายภาคสนามแล้ว</span>
        </label>
        <label class="btn light" style="width:100%;margin-bottom:10px">
          📸 {{ secondPhoto ? 'ถ่ายรูปนี้ใหม่ (Retake)' : 'แนบภาพเพิ่มเติม' }}
          <input
            type="file"
            accept="image/jpeg,image/png,image/webp"
            capture="environment"
            hidden
            @change="onPhotoSelect($event, 'second')"
          />
        </label>
        <div v-if="secondPhoto">
          <img :src="secondPhoto.previewUrl" alt="ภาพเพิ่มเติม" class="photo-preview" />
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px">
            <span class="small muted">{{ formatBytes(secondPhoto.compressedSize) }}</span>
            <button type="button" class="btn danger" @click="clearSlot('second')">ลบรูป</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Step 6: 6-Question Field Visit Survey -->
    <div v-else-if="step === 6" class="panel-pad" style="padding:4px 0">
      <h4 style="margin-top:0">6. แบบสอบถามออกตลาด 6 ข้อ</h4>
      <div class="form">
        <div class="field span2">
          <label>ข้อ 1: ปัจจุบันลูกค้าซื้อสินค้าจากแหล่งใด / ซัพพลายเออร์ไหน</label>
          <input v-model="survey.supplier_name" placeholder="เช่น แม็คโคร / ยี่ปั๊วประจำ" />
        </div>
        <div class="field span2">
          <label>ข้อ 2: สินค้าประเภทใดที่ร้านซื้อหรือสนใจเป็นหลัก</label>
          <input v-model="survey.product_types" placeholder="เช่น น้ำดื่ม, ชาเขียว, ขนมขบเคี้ยว" />
        </div>
        <div class="field span2">
          <label>ข้อ 3: ปัญหาของร้านค้าที่พบ</label>
          <textarea v-model="survey.customer_issues" rows="2" placeholder="เช่น ของขาดช่วงปลายสัปดาห์ / ราคาส่งสูง" />
        </div>
        <div class="field span2">
          <label>ข้อ 4: ความเห็นลูกค้า / โอกาสในการนำเสนอสินค้า Popstar</label>
          <textarea v-model="survey.customer_feedback" rows="2" placeholder="สนใจทดลองรับลังแรก / ขอดูราคาเทียบ" />
        </div>
        <div class="field span2">
          <label>บันทึกเพิ่มเติม (Note)</label>
          <textarea v-model="survey.note" rows="2" placeholder="รายละเอียดเพิ่มเติม..." />
        </div>
      </div>
    </div>

    <!-- Step 7: Outcome, Follow-up & Save -->
    <div v-else-if="step === 7" class="panel-pad" style="padding:4px 0">
      <h4 style="margin-top:0">7. สรุปผลการเข้าพบ &amp; งานติดตาม</h4>
      <div class="form">
        <div class="field">
          <label>ผลการเข้าพบ *</label>
          <select v-model="survey.result">
            <option value="visited">เข้าพบเรียบร้อย</option>
            <option value="interested">สนใจสินค้า</option>
            <option value="follow_up">ต้องติดตามต่อ</option>
            <option value="not_interested">ยังไม่สนใจ</option>
            <option value="not_found">ไม่พบลูกค้า / ร้านปิด</option>
          </select>
        </div>
        <div class="field">
          <label>นัดติดตามวันที่</label>
          <input v-model="survey.follow_up_date" type="date" />
        </div>
        <div class="field span2">
          <label>งานติดตามผลถัดไป (ข้อ 5)</label>
          <input v-model="survey.follow_up_action" placeholder="เช่น ส่งแคตตาล็อก / เสนอราคาสินค้า" />
        </div>
      </div>

      <div v-if="uploadState.active" style="margin-top:14px">
        <UploadProgressBar
          :percent="uploadState.percent"
          :label="uploadState.label"
          :cancellable="true"
          @cancel="cancelSubmit"
        />
      </div>
    </div>

    <!-- Step 8: Completed Summary -->
    <div v-else class="panel-pad" style="padding:4px 0">
      <SuccessCheckAnimation
        :title="summary?.savedOffline ? 'เก็บลง Offline Draft Queue แล้ว' : 'บันทึกหลักฐานการเข้าพบเรียบร้อย'"
        :subtitle="summary?.savedOffline ? 'เมื่ออินเทอร์เน็ตพร้อม กดซิงค์จากแถบ Offline Queue ได้ทันที' : `รหัสเข้าพบ #${summary?.visitId}`"
      />
      <div v-if="summary" class="card panel-pad" style="margin-top:10px">
        <div class="minirow"><span>ร้านค้า</span><b>{{ summary.shopLabel }}</b></div>
        <div class="minirow"><span>เวลาบนเครื่องพนักงาน</span><span>{{ summary.deviceTime || '—' }}</span></div>
        <div class="minirow"><span>เวลาที่เซิร์ฟเวอร์บันทึก</span><span>{{ summary.serverTime || 'รอซิงค์เมื่อออนไลน์' }}</span></div>
        <div class="minirow"><span>GPS Permission</span><span class="tag blue">{{ summary.gpsStatus }}</span></div>
        <div class="minirow"><span>รูปหลักฐานที่แนบ</span><span>{{ summary.photoCount }} รูป</span></div>
        <div v-if="summary.deduplicated" class="notice" style="margin-top:10px;margin-bottom:0">
          รายการนี้ซิงค์ซ้ำด้วย client_draft_id แล้ว — ไม่สร้างรายการซ้ำ
        </div>
      </div>
    </div>

    <template #footer="{ requestClose }">
      <template v-if="step < 8">
        <button v-if="step > 1" type="button" class="btn" :disabled="busy" @click="step--">← ย้อนกลับ</button>
        <button type="button" class="btn" :disabled="busy" @click="saveAsOfflineDraft">💾 เก็บ Draft Offline</button>
        <button v-if="step < 7" type="button" class="btn primary" :disabled="!canNext" @click="nextStep">ถัดไป →</button>
        <button v-else type="button" class="btn primary" :disabled="busy" @click="submitEvidence">
          {{ busy ? 'กำลังบันทึก...' : '✓ บันทึกหลักฐานเข้าพบ' }}
        </button>
      </template>
      <template v-else>
        <button type="button" class="btn primary" @click="finishAndClose">เสร็จสิ้น</button>
      </template>
    </template>
  </FullScreenMobileForm>
</template>

<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import FullScreenMobileForm from '../../../shared/components/FullScreenMobileForm.vue';
import UploadProgressBar from '../../../shared/components/UploadProgressBar.vue';
import SuccessCheckAnimation from '../../../shared/components/SuccessCheckAnimation.vue';
import { apiUploadWithProgress } from '../../../shared/api/http.js';
import { compressImageFile, formatBytes } from '../../../shared/utils/imageCompress.js';
import { generateDraftId, saveOfflineDraft } from '../../../shared/utils/offlineDrafts.js';
import { toast } from '../../../shared/composables/useUiStore.js';
import {
  closeVisitWizard,
  crmGet,
  crmMutate,
  invalidateCrmData,
  openQuickAdd,
  reloadOfflineDrafts,
  visitWizardOpen,
  visitWizardTarget,
} from '../composables/useCrmSession.js';

const STEPS = [
  { num: 1, short: 'เลือกร้าน' },
  { num: 2, short: 'Check-in' },
  { num: 3, short: 'GPS' },
  { num: 4, short: 'หน้าร้าน' },
  { num: 5, short: 'พูดคุย' },
  { num: 6, short: '6 คำถาม' },
  { num: 7, short: 'ผล & บันทึก' },
  { num: 8, short: 'สรุป' },
];

const step = ref(1);
const clientDraftId = ref(generateDraftId('visit'));
const subjectKind = ref('lead');
const subjectId = ref('');
const subjectFilter = ref('');
const deviceRecordedAt = ref('');
const gps = ref(null);
const gpsPermission = ref('prompt');
const storefrontPhoto = ref(null);
const secondPhoto = ref(null);
const secondPhotoCategory = ref('products');
const customerConsent = ref(false);
const survey = ref({
  supplier_name: '',
  product_types: '',
  customer_issues: '',
  customer_feedback: '',
  note: '',
  result: 'visited',
  follow_up_action: '',
  follow_up_date: '',
});
const busy = ref(false);
const uploadState = ref({ active: false, percent: 0, label: '' });
const summary = ref(null);
let uploadAbort = null;

const { data: leadsData } = useQuery({
  queryKey: ['crm', 'wizard-leads'],
  queryFn: ({ signal }) => crmGet('/leads', { signal }),
  enabled: visitWizardOpen,
});

const { data: erpData } = useQuery({
  queryKey: ['crm', 'wizard-customers'],
  queryFn: ({ signal }) => crmGet('/customers', { signal }),
  enabled: visitWizardOpen,
});

const filteredSubjects = computed(() => {
  const q = subjectFilter.value.trim().toLowerCase();
  const list = subjectKind.value === 'erp'
    ? (erpData.value || []).map((c) => ({
        id: c.id,
        label: `[${c.erp_customer_code}] ${c.customer_name} (${c.phone || 'ไม่มีเบอร์'})`,
      }))
    : (leadsData.value || []).map((l) => ({
        id: l.id,
        label: `[LEAD-${l.id}] ${l.shop_name} (${l.phone || 'ไม่มีเบอร์'})`,
      }));
  if (!q) return list;
  return list.filter((x) => x.label.toLowerCase().includes(q));
});

const selectedSubjectLabel = computed(
  () => filteredSubjects.value.find((x) => String(x.id) === String(subjectId.value))?.label || ''
);

const canNext = computed(() => {
  if (step.value === 1) return Boolean(subjectId.value);
  return true;
});

const isDirty = computed(() =>
  Boolean(
    subjectId.value ||
      deviceRecordedAt.value ||
      gps.value ||
      storefrontPhoto.value ||
      secondPhoto.value ||
      survey.value.supplier_name ||
      survey.value.note
  )
);

function resetWizard() {
  step.value = 1;
  clientDraftId.value = generateDraftId('visit');
  subjectKind.value = visitWizardTarget.value?.kind || 'lead';
  subjectId.value = visitWizardTarget.value?.id ? String(visitWizardTarget.value.id) : '';
  subjectFilter.value = '';
  deviceRecordedAt.value = '';
  gps.value = null;
  gpsPermission.value = 'prompt';
  clearSlot('storefront');
  clearSlot('second');
  customerConsent.value = false;
  survey.value = {
    supplier_name: '',
    product_types: '',
    customer_issues: '',
    customer_feedback: '',
    note: '',
    result: 'visited',
    follow_up_action: '',
    follow_up_date: '',
  };
  summary.value = null;
}

watch(visitWizardOpen, (open) => {
  if (open) resetWizard();
});

function clearSlot(slot) {
  const target = slot === 'storefront' ? storefrontPhoto : secondPhoto;
  if (target.value?.previewUrl) URL.revokeObjectURL(target.value.previewUrl);
  target.value = null;
}

onUnmounted(() => {
  clearSlot('storefront');
  clearSlot('second');
});

function startCheckIn() {
  deviceRecordedAt.value = new Date().toISOString();
  toast.success('บันทึกเวลาเริ่มเข้าพบแล้ว');
}

function nextStep() {
  if (step.value === 1 && !deviceRecordedAt.value) {
    deviceRecordedAt.value = new Date().toISOString();
  }
  step.value++;
}

async function requestGps() {
  if (!navigator.geolocation) {
    gpsPermission.value = 'unavailable';
    toast.warning('เบราว์เซอร์นี้ไม่รองรับ GPS');
    return;
  }
  navigator.geolocation.getCurrentPosition(
    (p) => {
      gpsPermission.value = 'granted';
      gps.value = {
        latitude: Number(p.coords.latitude.toFixed(7)),
        longitude: Number(p.coords.longitude.toFixed(7)),
        accuracy_m: Math.round(p.coords.accuracy),
      };
      toast.success('บันทึกพิกัด GPS สำเร็จ');
    },
    (err) => {
      gpsPermission.value = err.code === 1 ? 'denied' : 'unavailable';
      toast.warning('ไม่สามารถรับพิกัด GPS', err.message);
    },
    { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
  );
}

async function onPhotoSelect(e, slot) {
  const file = e.target.files?.[0];
  e.target.value = '';
  if (!file) return;
  try {
    clearSlot(slot);
    const compressed = await compressImageFile(file);
    if (slot === 'storefront') storefrontPhoto.value = compressed;
    else secondPhoto.value = compressed;
    toast.info('บีบอัดภาพเรียบร้อย', `ประหยัดขนาด ${compressed.savingsPct}%`);
  } catch (err) {
    toast.error('ไม่สามารถอ่านรูปภาพได้', err);
  }
}

function buildVisitPayload() {
  const nowIso = deviceRecordedAt.value || new Date().toISOString();
  return {
    ...(subjectKind.value === 'erp'
      ? { erp_customer_id: Number(subjectId.value) }
      : { crm_lead_id: Number(subjectId.value) }),
    client_draft_id: clientDraftId.value,
    visited_at: nowIso,
    device_recorded_at: nowIso,
    shop_type: subjectKind.value === 'erp' ? 'existing' : 'new',
    result: survey.value.result,
    supplier_name: survey.value.supplier_name || null,
    product_types: survey.value.product_types || null,
    customer_issues: survey.value.customer_issues || null,
    customer_feedback: survey.value.customer_feedback || null,
    note: survey.value.note || null,
    follow_up_action: survey.value.follow_up_action || null,
    follow_up_date: survey.value.follow_up_date || null,
    gps_permission_status: gpsPermission.value,
    ...(gps.value
      ? {
          checkin_latitude: gps.value.latitude,
          checkin_longitude: gps.value.longitude,
          checkin_accuracy_m: gps.value.accuracy_m,
        }
      : {}),
  };
}

async function saveAsOfflineDraft() {
  if (!subjectId.value) {
    toast.warning('กรุณาเลือกร้านค้าก่อนเก็บ Draft');
    return;
  }
  const photos = [];
  if (storefrontPhoto.value) {
    photos.push({
      blob: storefrontPhoto.value.file,
      fileName: storefrontPhoto.value.file.name,
      mimeType: storefrontPhoto.value.mimeType,
      category: 'storefront',
      consentConfirmed: true,
      capturedAt: storefrontPhoto.value.capturedAt,
      latitude: gps.value?.latitude ?? null,
      longitude: gps.value?.longitude ?? null,
    });
  }
  if (secondPhoto.value) {
    photos.push({
      blob: secondPhoto.value.file,
      fileName: secondPhoto.value.file.name,
      mimeType: secondPhoto.value.mimeType,
      category: secondPhotoCategory.value,
      consentConfirmed: customerConsent.value,
      capturedAt: secondPhoto.value.capturedAt,
      latitude: gps.value?.latitude ?? null,
      longitude: gps.value?.longitude ?? null,
    });
  }
  await saveOfflineDraft({
    client_draft_id: clientDraftId.value,
    type: 'visit',
    shopLabel: selectedSubjectLabel.value,
    payload: buildVisitPayload(),
    photos,
  });
  await reloadOfflineDrafts();
  summary.value = {
    savedOffline: true,
    shopLabel: selectedSubjectLabel.value,
    deviceTime: new Date(deviceRecordedAt.value || Date.now()).toLocaleString('th-TH'),
    gpsStatus: gpsPermission.value,
    photoCount: photos.length,
  };
  step.value = 8;
  toast.info('เก็บลง Offline Draft Queue แล้ว');
}

function cancelSubmit() {
  uploadAbort?.abort();
}

async function submitEvidence() {
  if (secondPhoto.value && secondPhotoCategory.value === 'customer_discussion' && !customerConsent.value) {
    toast.warning('กรุณายืนยันความยินยอมจากลูกค้า', 'ในขั้นตอนที่ 5 ก่อนอัปโหลดภาพถ่ายคู่กับลูกค้า');
    step.value = 5;
    return;
  }

  busy.value = true;
  uploadAbort = new AbortController();
  try {
    const payload = buildVisitPayload();
    const visitRes = await crmMutate('/field-visits', 'POST', payload);
    const visitId = visitRes.id;

    const filesToSend = [];
    if (storefrontPhoto.value) {
      filesToSend.push({ slot: storefrontPhoto.value, category: 'storefront', consent: true });
    }
    if (secondPhoto.value) {
      filesToSend.push({ slot: secondPhoto.value, category: secondPhotoCategory.value, consent: customerConsent.value });
    }

    for (let i = 0; i < filesToSend.length; i++) {
      const item = filesToSend[i];
      uploadState.value = {
        active: true,
        percent: 0,
        label: `กำลังอัปโหลดภาพหลักฐาน (${i + 1}/${filesToSend.length})...`,
      };
      const fd = new FormData();
      fd.set('photo', item.slot.file);
      fd.set('category', item.category);
      fd.set('consent_confirmed', item.consent ? '1' : '0');
      fd.set('captured_at', item.slot.capturedAt);
      fd.set('image_width', String(item.slot.width));
      fd.set('image_height', String(item.slot.height));
      fd.set('compressed_on_client', item.slot.compressedOnClient ? '1' : '0');
      if (gps.value) {
        fd.set('latitude', String(gps.value.latitude));
        fd.set('longitude', String(gps.value.longitude));
        if (gps.value.accuracy_m != null) fd.set('accuracy_m', String(gps.value.accuracy_m));
      }
      await apiUploadWithProgress(`/api/crm/field-visits/${visitId}/photos`, fd, {
        csrfEndpoint: '/api/crm/csrf',
        signal: uploadAbort.signal,
        onProgress: ({ percent }) => {
          uploadState.value.percent = percent;
        },
      });
    }

    summary.value = {
      savedOffline: false,
      visitId,
      deduplicated: Boolean(visitRes.deduplicated),
      shopLabel: selectedSubjectLabel.value,
      deviceTime: new Date(payload.device_recorded_at).toLocaleString('th-TH'),
      serverTime: visitRes.server_recorded_at
        ? new Date(visitRes.server_recorded_at).toLocaleString('th-TH')
        : new Date().toLocaleString('th-TH'),
      gpsStatus: gpsPermission.value,
      photoCount: filesToSend.length,
    };
    step.value = 8;
    invalidateCrmData(['dashboard', 'visits', 'tasks', 'plans']);
    toast.success('บันทึกหลักฐานเข้าพบเรียบร้อย');
  } catch (err) {
    if (err?.name === 'AbortError') {
      toast.info('ยกเลิกการอัปโหลดแล้ว');
    } else if (err?.status === 0 || !navigator.onLine) {
      await saveAsOfflineDraft();
    } else {
      toast.error('บันทึกหลักฐานไม่สำเร็จ', err);
    }
  } finally {
    busy.value = false;
    uploadState.value.active = false;
    uploadAbort = null;
  }
}

function handleClose() {
  closeVisitWizard();
}

function finishAndClose() {
  resetWizard();
  closeVisitWizard();
}
</script>
