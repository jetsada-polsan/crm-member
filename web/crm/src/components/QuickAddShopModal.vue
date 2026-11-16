<template>
  <FullScreenMobileForm
    :open="quickAddOpen"
    title="เพิ่มร้านค้า / ลูกค้าใหม่ (Quick Add)"
    step-label="ขั้นตอนด่วน: 3 ช่องบังคับ + ข้อมูลประกอบ"
    :dirty="isDirty"
    @close="closeQuickAdd"
  >
    <div v-if="duplicates.length" class="error" style="margin-bottom:14px">
      <strong>พบข้อมูลร้านค้าที่อาจซ้ำในระบบ ({{ duplicates.length }} รายการ):</strong>
      <ul style="margin:6px 0 6px 18px;padding:0;font-size:12px">
        <li v-for="d in duplicates" :key="`${d.source}-${d.id}`">
          <b>[{{ d.source === 'erp' ? 'ERP' : 'Lead' }} {{ d.code }}]</b>
          {{ d.name }} · {{ d.phone || 'ไม่มีเบอร์' }}
          <span class="tag red" style="margin-left:4px">{{ d.match_reasons.join(', ') }}</span>
        </li>
      </ul>
      <div v-if="forceNeeded" style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
        <button type="button" class="btn danger" @click="submit(true)">
          ยืนยันบันทึกร้านใหม่แม้ข้อมูลคล้าย/ซ้ำ
        </button>
      </div>
    </div>

    <div v-if="uploadActive" style="margin-bottom:14px">
      <UploadProgressBar :percent="uploadPercent" label="กำลังอัปโหลดรูปหน้าร้านและบันทึกข้อมูล..." />
    </div>

    <form id="quickAddForm" @submit.prevent="submit(false)">
      <div class="form">
        <div class="field span2">
          <label>ชื่อร้านค้า *</label>
          <input
            v-model="form.shop_name"
            required
            placeholder="เช่น ร้านบ้านสวนหมูกระทะ"
            @input="debouncedDuplicateCheck"
          />
        </div>
        <div class="field">
          <label>เบอร์โทรศัพท์ *</label>
          <input
            v-model="form.phone"
            required
            type="tel"
            inputmode="tel"
            placeholder="08X-XXX-XXXX"
            @input="debouncedDuplicateCheck"
          />
        </div>
        <div class="field">
          <label>ประเภทร้านค้า *</label>
          <select v-model="form.shop_type" required>
            <option v-for="st in SHOP_TYPES" :key="st" :value="st">{{ st }}</option>
          </select>
        </div>

        <div class="field span2" style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;padding:6px 0">
          <strong class="small" style="color:var(--blue)">พิกัด GPS และภาพหน้าร้าน</strong>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button type="button" class="btn" @click="requestGps">
              📍 {{ gps ? `GPS ±${gps.accuracy_m}ม.` : 'ขอพิกัด GPS' }}
            </button>
            <label class="btn light" style="cursor:pointer;margin:0">
              📷 {{ photo ? 'ถ่าย/เลือกรูปใหม่' : 'ถ่ายภาพหน้าร้าน' }}
              <input
                type="file"
                accept="image/jpeg,image/png,image/webp"
                capture="environment"
                hidden
                @change="onPhotoSelected"
              />
            </label>
          </div>
        </div>

        <div v-if="gps" class="field span2">
          <div class="notice" style="margin-bottom:0">
            พิกัดปัจจุบัน: <b>{{ gps.latitude }}, {{ gps.longitude }}</b> (ความแม่นยำ ~{{ gps.accuracy_m }} เมตร)
          </div>
        </div>

        <div v-if="photo" class="field span2">
          <img :src="photo.previewUrl" alt="ภาพตัวอย่างหน้าร้าน" class="photo-preview" />
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;gap:8px;flex-wrap:wrap">
            <span class="small muted">
              ขนาดไฟล์หลังบีบอัด: <b>{{ formatBytes(photo.compressedSize) }}</b>
              (ลดลง {{ photo.savingsPct }}% จาก {{ formatBytes(photo.originalSize) }})
            </span>
            <button type="button" class="btn danger" style="padding:5px 10px" @click="clearPhoto">ลบรูป</button>
          </div>
        </div>

        <div class="field span2">
          <button
            type="button"
            class="btn"
            style="width:100%"
            @click="showOptional = !showOptional"
          >
            {{ showOptional ? '▲ ซ่อนข้อมูลเพิ่มเติม' : '▼ กรอกข้อมูลเพิ่มเติม (ชื่อผู้ติดต่อ, LINE, จังหวัด, แหล่งซื้อ, สินค้าที่ใช้)' }}
          </button>
        </div>

        <template v-if="showOptional">
          <div class="field">
            <label>ชื่อผู้ติดต่อ</label>
            <input v-model="form.contact_name" placeholder="ชื่อเจ้าของ / ผู้จัดซื้อ" />
          </div>
          <div class="field">
            <label>LINE ID / ชื่อไลน์</label>
            <input v-model="form.line_contact" placeholder="@line_id" />
          </div>
          <div class="field">
            <label>จังหวัด</label>
            <input v-model="form.province" placeholder="เช่น กรุงเทพฯ / อุบลราชธานี" />
          </div>
          <div class="field">
            <label>อำเภอ / เขต</label>
            <input v-model="form.district" placeholder="เขต / อำเภอ" />
          </div>
          <div class="field span2">
            <label>ที่อยู่ร้านค้า</label>
            <textarea v-model="form.address" rows="2" placeholder="บ้านเลขที่ ถนน จุดสังเกต..." />
          </div>
          <div class="field">
            <label>แหล่งที่ซื้อสินค้าปัจจุบัน</label>
            <input v-model="form.supplier_source" placeholder="เช่น แม็คโคร / ยี่ปั๊ว" />
          </div>
          <div class="field">
            <label>หมวดสินค้าที่ใช้</label>
            <input v-model="form.product_categories" placeholder="เช่น เครื่องดื่ม, ซอส, ขนม" />
          </div>
          <div class="field">
            <label>พนักงานขายผู้รับผิดชอบ (รหัสสายขาย)</label>
            <input v-model="form.assigned_salesperson_code" placeholder="เช่น SL01" />
          </div>
          <div class="field">
            <label>รหัสลูกค้า ERP ที่อ้างอิง (ถ้ามี — ใช้ตรวจซ้ำเท่านั้น)</label>
            <input v-model="form.erp_customer_code" placeholder="เช่น AR-001" @input="debouncedDuplicateCheck" />
          </div>
          <div class="field">
            <label>กำหนดวันติดตามผล</label>
            <input v-model="form.next_follow_up_date" type="date" />
          </div>
          <div class="field">
            <label>งานติดตามผลถัดไป</label>
            <input v-model="form.follow_up_action" placeholder="เช่น ส่งใบเสนอราคา" />
          </div>
          <div class="field span2">
            <label>บันทึกเพิ่มเติม</label>
            <textarea v-model="form.notes" rows="2" placeholder="โอกาสปิดการขาย / ความสนใจสินค้า" />
          </div>
        </template>
      </div>
    </form>

    <template #footer="{ requestClose }">
      <button type="button" class="btn" @click="requestClose">ยกเลิก</button>
      <button type="submit" form="quickAddForm" class="btn primary" :disabled="busy">
        {{ busy ? 'กำลังบันทึก...' : '＋ บันทึกร้านค้าใหม่' }}
      </button>
    </template>
  </FullScreenMobileForm>
</template>

<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
import FullScreenMobileForm from '../../../shared/components/FullScreenMobileForm.vue';
import UploadProgressBar from '../../../shared/components/UploadProgressBar.vue';
import { apiUploadWithProgress } from '../../../shared/api/http.js';
import { compressImageFile, formatBytes } from '../../../shared/utils/imageCompress.js';
import { toast } from '../../../shared/composables/useUiStore.js';
import {
  closeQuickAdd,
  crmMutate,
  invalidateCrmData,
  me,
  quickAddOpen,
  SHOP_TYPES,
} from '../composables/useCrmSession.js';

const blankForm = () => ({
  shop_name: '',
  phone: '',
  shop_type: 'หมูกระทะ',
  contact_name: '',
  line_contact: '',
  province: '',
  district: '',
  address: '',
  supplier_source: '',
  product_categories: '',
  assigned_salesperson_code: me.value?.salesperson_code || '',
  erp_customer_code: '',
  next_follow_up_date: '',
  follow_up_action: '',
  notes: '',
});

const form = ref(blankForm());
const showOptional = ref(false);
const gps = ref(null);
const photo = ref(null);
const duplicates = ref([]);
const forceNeeded = ref(false);
const busy = ref(false);
const uploadActive = ref(false);
const uploadPercent = ref(0);

let dupTimer = null;

const isDirty = computed(() =>
  Boolean(
    form.value.shop_name.trim() ||
      form.value.phone.trim() ||
      form.value.notes.trim() ||
      photo.value ||
      gps.value
  )
);

watch(quickAddOpen, (open) => {
  if (open && !form.value.assigned_salesperson_code && me.value?.salesperson_code) {
    form.value.assigned_salesperson_code = me.value.salesperson_code;
  }
});

function clearPhoto() {
  if (photo.value?.previewUrl) URL.revokeObjectURL(photo.value.previewUrl);
  photo.value = null;
}

onUnmounted(() => {
  clearPhoto();
  if (dupTimer) clearTimeout(dupTimer);
});

async function onPhotoSelected(e) {
  const file = e.target.files?.[0];
  e.target.value = '';
  if (!file) return;
  try {
    clearPhoto();
    photo.value = await compressImageFile(file);
    toast.info('บีบอัดภาพตัวอย่างเรียบร้อย', `ขนาด ${formatBytes(photo.value.compressedSize)}`);
  } catch (err) {
    toast.error('ไม่สามารถอ่านไฟล์ภาพได้', err);
  }
}

function requestGps() {
  if (!navigator.geolocation) {
    toast.warning('เบราว์เซอร์ไม่รองรับ GPS', 'คุณสามารถบันทึกร้านค้าโดยไม่มี GPS แล้วปักหมุดภายหลังได้');
    return;
  }
  navigator.geolocation.getCurrentPosition(
    (p) => {
      gps.value = {
        latitude: Number(p.coords.latitude.toFixed(7)),
        longitude: Number(p.coords.longitude.toFixed(7)),
        accuracy_m: Math.round(p.coords.accuracy),
      };
      toast.success('รับพิกัด GPS แล้ว');
      runDuplicateCheck();
    },
    (err) => toast.warning('ไม่สามารถอ่านพิกัด GPS', err.message),
    { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
  );
}

async function runDuplicateCheck() {
  const f = form.value;
  if (!f.shop_name.trim() && !f.phone.trim() && !f.erp_customer_code.trim() && !gps.value) {
    duplicates.value = [];
    return;
  }
  try {
    const res = await crmMutate('/leads/check-duplicate', 'POST', {
      shop_name: f.shop_name || null,
      phone: f.phone || null,
      erp_customer_code: f.erp_customer_code || null,
      ...(gps.value ? { latitude: gps.value.latitude, longitude: gps.value.longitude } : {}),
    });
    duplicates.value = res?.duplicates || [];
  } catch {
    // non-fatal live hint
  }
}

function debouncedDuplicateCheck() {
  if (dupTimer) clearTimeout(dupTimer);
  dupTimer = setTimeout(runDuplicateCheck, 360);
}

async function submit(force = false) {
  busy.value = true;
  uploadPercent.value = 0;
  uploadActive.value = Boolean(photo.value);
  try {
    const f = form.value;
    const fd = new FormData();
    fd.set('shop_name', f.shop_name.trim());
    fd.set('phone', f.phone.trim());
    fd.set('shop_type', f.shop_type);
    const optFields = [
      'contact_name',
      'line_contact',
      'province',
      'district',
      'address',
      'supplier_source',
      'product_categories',
      'assigned_salesperson_code',
      'erp_customer_code',
      'next_follow_up_date',
      'follow_up_action',
      'notes',
    ];
    for (const k of optFields) {
      if (f[k] && String(f[k]).trim()) fd.set(k, String(f[k]).trim());
    }
    if (gps.value) {
      fd.set('latitude', String(gps.value.latitude));
      fd.set('longitude', String(gps.value.longitude));
      if (gps.value.accuracy_m != null) fd.set('accuracy_m', String(gps.value.accuracy_m));
      fd.set('pin_source', 'gps');
    }
    if (photo.value) {
      fd.set('photo', photo.value.file);
      fd.set('captured_at', photo.value.capturedAt);
      fd.set('compressed_on_client', photo.value.compressedOnClient ? '1' : '0');
    }
    if (force) fd.set('force_create', '1');

    const res = await apiUploadWithProgress('/api/crm/leads/quick', fd, {
      csrfEndpoint: '/api/crm/csrf',
      onProgress: ({ percent }) => {
        uploadPercent.value = percent;
      },
    });

    toast.success(
      `บันทึกร้านค้าใหม่ (${res.internal_customer_code || `#${res.id}`})`,
      res.is_minimal_draft ? 'บันทึกข้อมูลขั้นต่ำแล้ว (ไม่สร้างบัญชี ERP อัตโนมัติ)' : 'บันทึกข้อมูลครบถ้วนแล้ว'
    );

    form.value = blankForm();
    gps.value = null;
    clearPhoto();
    duplicates.value = [];
    forceNeeded.value = false;
    closeQuickAdd();
    invalidateCrmData(['dashboard', 'leads', 'master', 'pins', 'tasks']);
  } catch (err) {
    if (err?.status === 409 && err?.data?.duplicates) {
      duplicates.value = err.data.duplicates;
      forceNeeded.value = true;
      toast.warning('พบข้อมูลที่อาจซ้ำในระบบ', 'ตรวจสอบรายการด้านบนหรือกดยืนยันหากต้องการสร้างใหม่');
    } else {
      toast.error('บันทึกร้านค้าไม่สำเร็จ', err);
    }
  } finally {
    busy.value = false;
    uploadActive.value = false;
  }
}
</script>
